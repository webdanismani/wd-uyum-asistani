<?php
defined( 'ABSPATH' ) || exit;

/**
 * İş kuralları: iade talebi, maliyet hesaplama, WooCommerce iade senkronu, geri bildirim, zamanlanmış görevler.
 */
class WDUA_Service {

	/** wc_create_refund çağrımız sırasında refund kancasını yok saymak için. */
	private static $in_refund = false;

	public static function init() {
		add_action( 'woocommerce_order_refunded', array( __CLASS__, 'on_order_refunded' ), 20, 2 );
		add_action( WDUA_Install::CRON_HOOK, array( __CLASS__, 'cron_daily' ) );
	}

	/* --------------------------------------------------------------------
	 * Uygunluk
	 * ------------------------------------------------------------------ */

	public static function status_allowed( WC_Order $order ) {
		$allowed = (array) WDUA_Settings::get( 'return_statuses' );
		return in_array( 'wc-' . $order->get_status(), $allowed, true );
	}

	/** İade talebi için son tarih (timestamp) veya null (sınırsız). */
	public static function return_deadline( WC_Order $order ) {
		$days = (int) WDUA_Settings::get( 'return_window_days' );
		if ( $days <= 0 ) {
			return null;
		}
		$base = $order->get_date_completed();
		if ( ! $base ) {
			$base = $order->get_date_paid() ? $order->get_date_paid() : $order->get_date_created();
		}
		return $base ? $base->getTimestamp() + $days * DAY_IN_SECONDS : null;
	}

	public static function can_request_return( WC_Order $order ) {
		if ( ! WDUA_Settings::yes( 'returns_enabled' ) || ! self::status_allowed( $order ) ) {
			return false;
		}
		$deadline = self::return_deadline( $order );
		return null === $deadline || time() <= $deadline;
	}

	/** İade edilebilir kalemler: [item_id => ['item' => WC_Order_Item_Product, 'remaining' => int]] */
	public static function returnable_items( WC_Order $order ) {
		$returned = WDUA_Repo::returned_qty_by_item( $order->get_id() );
		$out      = array();
		foreach ( $order->get_items() as $item_id => $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}
			$used      = max(
				isset( $returned[ $item_id ] ) ? $returned[ $item_id ] : 0,
				abs( (int) $order->get_qty_refunded_for_item( $item_id ) )
			);
			$remaining = (int) $item->get_quantity() - $used;
			if ( $remaining > 0 ) {
				$out[ $item_id ] = array(
					'item'      => $item,
					'remaining' => $remaining,
				);
			}
		}
		return $out;
	}

	/** Kalıp geri bildirimi verilebilecek kalemler. */
	public static function feedback_items( WC_Order $order ) {
		if ( ! WDUA_Settings::yes( 'feedback_enabled' ) || ! self::status_allowed( $order ) ) {
			return array();
		}
		$given    = WDUA_Repo::feedback_for_order( $order->get_id() );
		$returned = WDUA_Repo::returned_qty_by_item( $order->get_id() );
		$out      = array();
		foreach ( $order->get_items() as $item_id => $item ) {
			if ( ! $item instanceof WC_Order_Item_Product || isset( $given[ $item_id ] ) || ! empty( $returned[ $item_id ] ) ) {
				continue;
			}
			if ( abs( (int) $order->get_qty_refunded_for_item( $item_id ) ) >= (int) $item->get_quantity() ) {
				continue;
			}
			// Kalıp sorusu yalnızca bedeni olan ürünlerde anlamlıdır.
			$sized = '' !== self::detect_size( $item )['key'];
			if ( ! apply_filters( 'wdua_item_accepts_feedback', $sized, $item, $order ) ) {
				continue;
			}
			$out[ $item_id ] = $item;
		}
		return $out;
	}

	/* --------------------------------------------------------------------
	 * Beden tespiti
	 * ------------------------------------------------------------------ */

	public static function size_attr_keys() {
		$raw  = (string) WDUA_Settings::get( 'size_attributes' );
		$keys = array_filter( array_map( 'trim', explode( ',', strtolower( $raw ) ) ) );
		return array_values( array_unique( $keys ) );
	}

	public static function is_size_key( $key ) {
		$k    = preg_replace( '/^attribute_/', '', strtolower( (string) $key ) );
		$bare = preg_replace( '/^pa_/', '', $k );
		foreach ( self::size_attr_keys() as $x ) {
			if ( $x === $k || preg_replace( '/^pa_/', '', $x ) === $bare ) {
				return true;
			}
		}
		return false;
	}

	public static function size_from_pairs( $pairs ) {
		foreach ( (array) $pairs as $k => $v ) {
			if ( ! is_scalar( $v ) || '' === (string) $v || 0 === strpos( (string) $k, '_' ) ) {
				continue;
			}
			if ( self::is_size_key( $k ) ) {
				return self::size_value( $k, (string) $v );
			}
		}
		return null;
	}

	public static function size_value( $attr, $raw ) {
		$attr  = preg_replace( '/^attribute_/', '', strtolower( (string) $attr ) );
		$label = (string) $raw;
		if ( taxonomy_exists( $attr ) ) {
			$term = get_term_by( 'slug', $raw, $attr );
			if ( $term && ! is_wp_error( $term ) ) {
				$label = $term->name;
			}
		}
		return array(
			'key'   => sanitize_title( $raw ),
			'label' => $label,
		);
	}

	public static function detect_size( WC_Order_Item_Product $item ) {
		$pairs = array();
		foreach ( $item->get_meta_data() as $meta ) {
			$d                   = $meta->get_data();
			$pairs[ $d['key'] ] = $d['value'];
		}
		$size = self::size_from_pairs( $pairs );
		if ( ! $size && $item->get_variation_id() ) {
			$v = wc_get_product( $item->get_variation_id() );
			if ( $v ) {
				$size = self::size_from_pairs( $v->get_attributes() );
			}
		}
		return $size ? $size : array(
			'key'   => '',
			'label' => '',
		);
	}

	/* --------------------------------------------------------------------
	 * Maliyet ve kalıp normalizasyonu
	 * ------------------------------------------------------------------ */

	public static function compute_costs( $cat, $item_value, $qty, $order_qty, $request_qty ) {
		$s           = WDUA_Settings::all();
		$order_qty   = max( 1, (int) $order_qty );
		$request_qty = max( 1, (int) $request_qty );
		$qty         = max( 1, (int) $qty );

		$out   = ( 'yes' === $s['ship_out_lost'] ) ? (float) $s['cost_ship_out'] * $qty / $order_qty : 0.0;
		$back  = (float) $s['cost_ship_back'] * $qty / $request_qty;
		$labor = (float) $s['cost_labor'] * $qty;
		$key   = 'dep_' . $cat;
		$pct   = isset( $s[ $key ] ) ? (float) $s[ $key ] : (float) $s['dep_other'];
		$dep   = (float) $item_value * $pct / 100;

		$costs = array(
			'item_value'        => round( (float) $item_value, 2 ),
			'cost_ship_out'     => round( $out, 2 ),
			'cost_ship_back'    => round( $back, 2 ),
			'cost_labor'        => round( $labor, 2 ),
			'cost_depreciation' => round( $dep, 2 ),
		);
		$costs['cost_total'] = round( $costs['cost_ship_out'] + $costs['cost_ship_back'] + $costs['cost_labor'] + $costs['cost_depreciation'], 2 );

		return apply_filters( 'wdua_computed_costs', $costs, $cat, $item_value, $qty );
	}

	/**
	 * Beden kaynaklı nedenlerde kalıp değerini nedenle tutarlı hale getirir,
	 * diğer nedenlerde null döner (istatistiğe girmez).
	 */
	public static function normalize_fit( $reason, $fit ) {
		$r = WDUA_Reasons::get( $reason );
		if ( ! $r || 'fit' !== $r['cat'] ) {
			return null;
		}
		$default = (int) $r['fit'];
		if ( null === $fit || '' === $fit ) {
			return $default;
		}
		$fit = max( -2, min( 2, (int) $fit ) );
		if ( 0 === $fit || ( $fit < 0 ) !== ( $default < 0 ) ) {
			return $default;
		}
		return $fit;
	}

	/* --------------------------------------------------------------------
	 * Kayıt oluşturma
	 * ------------------------------------------------------------------ */

	private static function insert_row( WC_Order $order, WC_Order_Item_Product $item, $qty, $reason, $fit, $status, $source, $request_key, $request_qty, $customer_note = '', $admin_note = '' ) {
		$r     = WDUA_Reasons::get( $reason );
		$size  = self::detect_size( $item );
		$unit  = (float) $item->get_total() / max( 1, (int) $item->get_quantity() );
		$costs = self::compute_costs( $r['cat'], $unit * $qty, $qty, $order->get_item_count(), $request_qty );

		return WDUA_Repo::insert_return(
			array_merge(
				array(
					'request_key'   => $request_key,
					'order_id'      => $order->get_id(),
					'order_item_id' => $item->get_id(),
					'product_id'    => $item->get_product_id(),
					'variation_id'  => $item->get_variation_id(),
					'customer_id'   => $order->get_customer_id(),
					'size_key'      => $size['key'],
					'size_label'    => $size['label'],
					'qty'           => (int) $qty,
					'reason'        => $reason,
					'reason_cat'    => $r['cat'],
					'fit'           => self::normalize_fit( $reason, $fit ),
					'customer_note' => $customer_note,
					'admin_note'    => $admin_note,
					'status'        => $status,
					'source'        => $source,
				),
				$costs
			)
		);
	}

	private static function new_request_key() {
		return strtoupper( wp_generate_password( 8, false, false ) );
	}

	/**
	 * Müşteri formundan gelen talep.
	 *
	 * @return int[]|WP_Error Oluşan kayıt ID'leri.
	 */
	public static function create_customer_request( WC_Order $order, $posted, $note = '' ) {
		if ( ! self::can_request_return( $order ) ) {
			return new WP_Error( 'wdua_window', 'Bu sipariş için iade talep süresi dolmuş veya sipariş durumu iade için uygun değil.' );
		}

		$reasons = WDUA_Reasons::customer_reasons();
		$items   = self::returnable_items( $order );
		$picked  = array();

		foreach ( (array) $posted as $item_id => $row ) {
			$item_id = absint( $item_id );
			if ( empty( $row['on'] ) || ! isset( $items[ $item_id ] ) ) {
				continue;
			}
			$qty = isset( $row['qty'] ) ? absint( $row['qty'] ) : 1;
			if ( $qty < 1 || $qty > $items[ $item_id ]['remaining'] ) {
				return new WP_Error( 'wdua_qty', 'Geçersiz iade adedi.' );
			}
			$reason = isset( $row['reason'] ) ? sanitize_key( $row['reason'] ) : '';
			if ( ! isset( $reasons[ $reason ] ) ) {
				return new WP_Error( 'wdua_reason', 'Lütfen seçtiğiniz her ürün için bir iade nedeni belirtin.' );
			}
			$fit      = ( isset( $row['fit'] ) && '' !== $row['fit'] ) ? (int) $row['fit'] : null;
			$picked[] = array(
				'item'   => $items[ $item_id ]['item'],
				'qty'    => $qty,
				'reason' => $reason,
				'fit'    => $fit,
			);
		}

		if ( ! $picked ) {
			return new WP_Error( 'wdua_empty', 'Lütfen iade etmek istediğiniz en az bir ürünü seçin.' );
		}

		$note        = function_exists( 'mb_substr' ) ? mb_substr( sanitize_textarea_field( $note ), 0, 1000 ) : substr( sanitize_textarea_field( $note ), 0, 1000 );
		$key         = self::new_request_key();
		$request_qty = array_sum( wp_list_pluck( $picked, 'qty' ) );
		$ids         = array();
		$lines       = array();

		foreach ( $picked as $p ) {
			$id = self::insert_row( $order, $p['item'], $p['qty'], $p['reason'], $p['fit'], 'requested', 'customer', $key, $request_qty, $note );
			if ( $id ) {
				$ids[]   = $id;
				$lines[] = sprintf( '%s × %d (%s)', $p['item']->get_name(), $p['qty'], WDUA_Reasons::label( $p['reason'] ) );
			}
		}

		if ( ! $ids ) {
			return new WP_Error( 'wdua_db', 'Talep kaydedilemedi, lütfen tekrar deneyin.' );
		}

		$order->add_order_note( sprintf( 'Müşteri iade talebi oluşturdu (#%s): %s', $key, implode( ', ', $lines ) ) );

		if ( WDUA_Settings::yes( 'notify_admin' ) ) {
			self::notify_admin_new( $order, $key, $lines, $note );
		}

		$pids = array();
		foreach ( $picked as $p ) {
			$pids[] = $p['item']->get_product_id();
		}
		WDUA_Stats::rebuild_products( $pids );
		do_action( 'wdua_request_created', $ids, $order );

		return $ids;
	}

	/** Yöneticinin elle eklediği iade kaydı (telefonla/kargoyla gelen iadeler). */
	public static function create_admin_return( WC_Order $order, $item_id, $qty, $reason, $fit, $status, $admin_note = '' ) {
		$items = self::returnable_items( $order );
		if ( ! isset( $items[ $item_id ] ) ) {
			return new WP_Error( 'wdua_item', 'Bu kalem için iade edilebilir adet kalmamış.' );
		}
		$qty = absint( $qty );
		if ( $qty < 1 || $qty > $items[ $item_id ]['remaining'] ) {
			return new WP_Error( 'wdua_qty', sprintf( 'Adet 1 ile %d arasında olmalı.', $items[ $item_id ]['remaining'] ) );
		}
		if ( ! WDUA_Reasons::get( $reason ) ) {
			return new WP_Error( 'wdua_reason', 'Geçersiz iade nedeni.' );
		}
		if ( ! isset( WDUA_Reasons::statuses()[ $status ] ) ) {
			$status = 'received';
		}

		$id = self::insert_row( $order, $items[ $item_id ]['item'], $qty, $reason, $fit, $status, 'admin', self::new_request_key(), $qty, '', $admin_note );
		if ( ! $id ) {
			return new WP_Error( 'wdua_db', 'Kayıt oluşturulamadı.' );
		}
		$order->add_order_note( sprintf( 'Uyum Asistanı: %s × %d için iade kaydı eklendi (%s).', $items[ $item_id ]['item']->get_name(), $qty, WDUA_Reasons::label( $reason ) ) );
		WDUA_Stats::rebuild_product( $items[ $item_id ]['item']->get_product_id() );
		return $id;
	}

	/* --------------------------------------------------------------------
	 * Güncelleme
	 * ------------------------------------------------------------------ */

	/**
	 * @param array $in status, reason, fit, qty, cost_ship_out, cost_ship_back, cost_labor, cost_depreciation, admin_note, customer_message
	 */
	public static function update_return( $id, array $in, $notify = false ) {
		$row = WDUA_Repo::get_return( $id );
		if ( ! $row ) {
			return new WP_Error( 'wdua_404', 'Kayıt bulunamadı.' );
		}

		$reason = ( isset( $in['reason'] ) && WDUA_Reasons::get( $in['reason'] ) ) ? $in['reason'] : $row['reason'];
		$status = ( isset( $in['status'] ) && isset( WDUA_Reasons::statuses()[ $in['status'] ] ) ) ? $in['status'] : $row['status'];
		$fit_in = array_key_exists( 'fit', $in ) ? $in['fit'] : $row['fit'];
		$qty    = isset( $in['qty'] ) ? max( 1, absint( $in['qty'] ) ) : (int) $row['qty'];

		$order = wc_get_order( $row['order_id'] );
		if ( $order && $qty !== (int) $row['qty'] ) {
			$item = $order->get_item( $row['order_item_id'] );
			if ( $item ) {
				$returned = WDUA_Repo::returned_qty_by_item( $order->get_id() );
				$others   = ( isset( $returned[ $item->get_id() ] ) ? $returned[ $item->get_id() ] : 0 ) - ( 'rejected' !== $row['status'] ? (int) $row['qty'] : 0 );
				$qty      = min( $qty, max( 1, (int) $item->get_quantity() - $others ) );
			}
		}

		$num = function ( $k ) use ( $in, $row ) {
			return isset( $in[ $k ] ) ? round( max( 0, (float) wc_format_decimal( $in[ $k ] ) ), 2 ) : (float) $row[ $k ];
		};

		$costs = array(
			'cost_ship_out'     => $num( 'cost_ship_out' ),
			'cost_ship_back'    => $num( 'cost_ship_back' ),
			'cost_labor'        => $num( 'cost_labor' ),
			'cost_depreciation' => $num( 'cost_depreciation' ),
		);

		// Değişime geçişte yeni ürünün gönderim kargosu otomatik eklenir.
		if ( 'exchanged' === $status && 'exchanged' !== $row['status'] && WDUA_Settings::yes( 'exchange_ship_cost' ) ) {
			$costs['cost_ship_out'] += (float) WDUA_Settings::get( 'cost_ship_out' );
		}

		$costs['cost_total'] = round( array_sum( $costs ), 2 );

		$data = array_merge(
			$costs,
			array(
				'status'     => $status,
				'reason'     => $reason,
				'reason_cat' => WDUA_Reasons::get( $reason )['cat'],
				'fit'        => self::normalize_fit( $reason, $fit_in ),
				'qty'        => $qty,
				'admin_note' => isset( $in['admin_note'] ) ? sanitize_textarea_field( $in['admin_note'] ) : $row['admin_note'],
			)
		);

		WDUA_Repo::update_return( $id, $data );

		if ( $order && $status !== $row['status'] ) {
			$order->add_order_note(
				sprintf(
					'Uyum Asistanı: iade #%s (%s) durumu "%s" → "%s".',
					$row['request_key'],
					self::item_name( $order, $row['order_item_id'] ),
					WDUA_Reasons::status_label( $row['status'] ),
					WDUA_Reasons::status_label( $status )
				)
			);

			if ( $notify && WDUA_Settings::yes( 'notify_customer' ) ) {
				$msg = WDUA_Reasons::customer_status_message( $status );
				$extra = isset( $in['customer_message'] ) ? trim( sanitize_textarea_field( $in['customer_message'] ) ) : '';
				if ( $msg || $extra ) {
					$text = sprintf( 'İade talebi #%s – %s: %s', $row['request_key'], self::item_name( $order, $row['order_item_id'] ), $msg );
					if ( $extra ) {
						$text .= "\n\n" . $extra;
					}
					$order->add_order_note( $text, true ); // Müşteriye e-posta gider.
				}
			}
		}

		WDUA_Stats::rebuild_product( $row['product_id'] );
		do_action( 'wdua_return_updated', $id, $row, $data );
		return true;
	}

	public static function delete_return( $id ) {
		$row = WDUA_Repo::get_return( $id );
		if ( ! $row ) {
			return false;
		}
		WDUA_Repo::delete_return( $id );
		WDUA_Stats::rebuild_product( $row['product_id'] );
		return true;
	}

	private static function item_name( WC_Order $order, $item_id ) {
		$item = $order->get_item( $item_id );
		return $item ? $item->get_name() : '#' . $item_id;
	}

	/* --------------------------------------------------------------------
	 * WooCommerce iade (refund) entegrasyonu
	 * ------------------------------------------------------------------ */

	/**
	 * Uyum Asistanı kaydından WooCommerce iade kaydı oluşturur, ürünü stoğa ekler.
	 * Ödeme ağ geçidine para iadesi GÖNDERMEZ (refund_payment = false).
	 */
	public static function create_wc_refund( $id ) {
		$row = WDUA_Repo::get_return( $id );
		if ( ! $row ) {
			return new WP_Error( 'wdua_404', 'Kayıt bulunamadı.' );
		}
		$order = wc_get_order( $row['order_id'] );
		if ( ! $order ) {
			return new WP_Error( 'wdua_order', 'Sipariş bulunamadı.' );
		}
		$item = $order->get_item( $row['order_item_id'] );
		if ( ! $item instanceof WC_Order_Item_Product ) {
			return new WP_Error( 'wdua_item', 'Sipariş kalemi bulunamadı.' );
		}

		$qty      = (int) $row['qty'];
		$item_qty = max( 1, (int) $item->get_quantity() );
		$already  = abs( (int) $order->get_qty_refunded_for_item( $item->get_id() ) );
		if ( $already + $qty > $item_qty ) {
			return new WP_Error( 'wdua_refunded', 'Bu kalem için WooCommerce\'te zaten iade kaydı var.' );
		}

		$line_total = (float) $item->get_total() / $item_qty * $qty;
		$taxes      = $item->get_taxes();
		$refund_tax = array();
		if ( ! empty( $taxes['total'] ) ) {
			foreach ( $taxes['total'] as $tax_id => $tax_amount ) {
				$refund_tax[ $tax_id ] = wc_format_decimal( (float) $tax_amount / $item_qty * $qty, wc_get_price_decimals() );
			}
		}
		$amount = wc_format_decimal( $line_total + array_sum( array_map( 'floatval', $refund_tax ) ), wc_get_price_decimals() );

		self::$in_refund = true;
		$refund          = wc_create_refund(
			array(
				'amount'         => $amount,
				'reason'         => sprintf( 'İade #%s – %s', $row['request_key'], WDUA_Reasons::label( $row['reason'] ) ),
				'order_id'       => $order->get_id(),
				'line_items'     => array(
					$item->get_id() => array(
						'qty'          => $qty,
						'refund_total' => wc_format_decimal( $line_total, wc_get_price_decimals() ),
						'refund_tax'   => $refund_tax,
					),
				),
				'refund_payment' => false,
				'restock_items'  => true,
			)
		);
		self::$in_refund = false;

		if ( is_wp_error( $refund ) ) {
			return $refund;
		}

		return self::update_return( $id, array( 'status' => 'refunded' ), true );
	}

	/** WooCommerce'te manuel yapılan iadeleri de kayda geçirir (neden sonradan düzenlenebilir). */
	public static function on_order_refunded( $order_id, $refund_id ) {
		if ( self::$in_refund || ! WDUA_Settings::yes( 'track_refunds' ) ) {
			return;
		}
		$order  = wc_get_order( $order_id );
		$refund = wc_get_order( $refund_id );
		if ( ! $order || ! $refund ) {
			return;
		}

		$touched = array();

		foreach ( $refund->get_items() as $ritem ) {
			$orig_id = (int) $ritem->get_meta( '_refunded_item_id' );
			$qty     = abs( (int) $ritem->get_quantity() );
			if ( ! $orig_id || ! $qty ) {
				continue;
			}
			$orig = $order->get_item( $orig_id );
			if ( ! $orig instanceof WC_Order_Item_Product ) {
				continue;
			}

			// Açık talepler varsa onları "iade tamamlandı" yap.
			$open = WDUA_Repo::open_returns_for_item( $order_id, $orig_id );
			if ( $open ) {
				foreach ( $open as $o ) {
					if ( $qty <= 0 ) {
						break;
					}
					self::update_return( (int) $o['id'], array( 'status' => 'refunded' ), false );
					$qty -= (int) $o['qty'];
				}
				continue;
			}

			// Takipte olmayan iade: kalan adet kadar kayıt aç.
			$returned = WDUA_Repo::returned_qty_by_item( $order_id );
			$tracked  = isset( $returned[ $orig_id ] ) ? $returned[ $orig_id ] : 0;
			$new_qty  = min( $qty, (int) $orig->get_quantity() - $tracked );
			if ( $new_qty > 0 ) {
				self::insert_row( $order, $orig, $new_qty, 'belirtilmedi', null, 'refunded', 'refund', self::new_request_key(), $new_qty, '', 'WooCommerce iadesinden otomatik oluşturuldu.' );
				$touched[] = $orig->get_product_id();
			}
		}

		if ( $touched ) {
			WDUA_Stats::rebuild_products( $touched );
		}
	}

	/* --------------------------------------------------------------------
	 * Kalıp geri bildirimi
	 * ------------------------------------------------------------------ */

	public static function save_feedback( WC_Order $order, $posted ) {
		$items = self::feedback_items( $order );
		$saved = 0;
		$pids  = array();
		foreach ( (array) $posted as $item_id => $fit ) {
			$item_id = absint( $item_id );
			if ( ! isset( $items[ $item_id ] ) || '' === $fit || ! is_numeric( $fit ) ) {
				continue;
			}
			$fit = (int) $fit;
			if ( $fit < -2 || $fit > 2 ) {
				continue;
			}
			$item = $items[ $item_id ];
			$size = self::detect_size( $item );
			if ( WDUA_Repo::insert_feedback(
				array(
					'order_id'      => $order->get_id(),
					'order_item_id' => $item_id,
					'product_id'    => $item->get_product_id(),
					'variation_id'  => $item->get_variation_id(),
					'customer_id'   => $order->get_customer_id(),
					'size_key'      => $size['key'],
					'size_label'    => $size['label'],
					'fit'           => $fit,
				)
			) ) {
				++$saved;
				$pids[] = $item->get_product_id();
			}
		}
		if ( $pids ) {
			WDUA_Stats::rebuild_products( $pids );
		}
		return $saved;
	}

	/* --------------------------------------------------------------------
	 * Bildirimler ve zamanlanmış görevler
	 * ------------------------------------------------------------------ */

	private static function notify_admin_new( WC_Order $order, $key, array $lines, $note ) {
		$to      = apply_filters( 'wdua_admin_email', get_option( 'admin_email' ) );
		$subject = sprintf( '[%s] Yeni iade talebi #%s – Sipariş #%s', wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ), $key, $order->get_order_number() );
		$body    = sprintf( "Sipariş #%s için yeni iade talebi oluşturuldu.\n\nMüşteri: %s\n\n%s\n", $order->get_order_number(), $order->get_formatted_billing_full_name(), '- ' . implode( "\n- ", $lines ) );
		if ( $note ) {
			$body .= "\nMüşteri açıklaması:\n" . $note . "\n";
		}
		$body .= "\nTalepleri yönet: " . admin_url( 'admin.php?page=wdua-returns&status=requested' ) . "\n";
		wp_mail( $to, $subject, $body );
	}

	public static function cron_daily() {
		// Zaman ağırlığı her gün değiştiği için istatistikler yenilenir.
		WDUA_Stats::rebuild_all();
		self::send_feedback_emails();
	}

	public static function send_feedback_emails() {
		if ( ! WDUA_Settings::yes( 'feedback_enabled' ) || ! WDUA_Settings::yes( 'feedback_email' ) || ! function_exists( 'WC' ) ) {
			return;
		}

		$delay    = max( 1, (int) WDUA_Settings::get( 'feedback_delay_days' ) );
		$end      = time() - $delay * DAY_IN_SECONDS;
		$start    = $end - 7 * DAY_IN_SECONDS;
		$statuses = array_map(
			function ( $s ) {
				return preg_replace( '/^wc-/', '', $s );
			},
			(array) WDUA_Settings::get( 'return_statuses' )
		);
		if ( ! $statuses ) {
			return;
		}

		$orders = wc_get_orders(
			array(
				'status'         => $statuses,
				'date_completed' => $start . '...' . $end,
				'limit'          => 100,
				'return'         => 'objects',
			)
		);

		$mailer = WC()->mailer();

		foreach ( $orders as $order ) {
			if ( ! $order instanceof WC_Order || ! $order->get_customer_id() || $order->get_meta( '_wdua_fb_mail' ) ) {
				continue;
			}
			$items = self::feedback_items( $order );
			if ( $items ) {
				$heading = 'Ürünlerin kalıbı nasıldı?';
				$list    = '';
				foreach ( $items as $item ) {
					$list .= '<li>' . esc_html( $item->get_name() ) . '</li>';
				}
				$url  = $order->get_view_order_url() . '#wdua-feedback';
				$body = sprintf(
					'<p>Merhaba %1$s,</p><p>#%2$s numaralı siparişinizdeki ürünlerin size nasıl olduğunu tek tıkla paylaşır mısınız? Değerlendirmeniz sonraki alıcılara doğru beden önerisi göstermemizi sağlıyor.</p><ul>%3$s</ul><p><a href="%4$s" style="display:inline-block;padding:12px 22px;background:#111;color:#fff;text-decoration:none;border-radius:6px">Kalıbı değerlendir</a></p>',
					esc_html( $order->get_billing_first_name() ),
					esc_html( $order->get_order_number() ),
					$list,
					esc_url( $url )
				);
				$mailer->send( $order->get_billing_email(), $heading, $mailer->wrap_message( $heading, $body ) );
			}
			$order->update_meta_data( '_wdua_fb_mail', time() );
			$order->save_meta_data();
		}
	}
}
