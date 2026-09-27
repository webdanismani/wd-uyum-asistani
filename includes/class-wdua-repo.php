<?php
defined( 'ABSPATH' ) || exit;

/**
 * Veritabanı erişim katmanı.
 */
class WDUA_Repo {

	public static function t_returns() {
		global $wpdb;
		return $wpdb->prefix . 'wdua_returns';
	}

	public static function t_feedback() {
		global $wpdb;
		return $wpdb->prefix . 'wdua_feedback';
	}

	/* --------------------------------------------------------------------
	 * İade kayıtları
	 * ------------------------------------------------------------------ */

	public static function insert_return( array $data ) {
		global $wpdb;
		$now  = current_time( 'mysql', true );
		$data = wp_parse_args(
			$data,
			array(
				'created_at' => $now,
				'updated_at' => $now,
			)
		);
		$ok = $wpdb->insert( self::t_returns(), $data );
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	public static function update_return( $id, array $data ) {
		global $wpdb;
		$data['updated_at'] = current_time( 'mysql', true );
		return false !== $wpdb->update( self::t_returns(), $data, array( 'id' => (int) $id ) );
	}

	public static function delete_return( $id ) {
		global $wpdb;
		return (bool) $wpdb->delete( self::t_returns(), array( 'id' => (int) $id ), array( '%d' ) );
	}

	public static function get_return( $id ) {
		global $wpdb;
		$t = self::t_returns();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", $id ), ARRAY_A );
	}

	public static function returns_for_order( $order_id ) {
		global $wpdb;
		$t = self::t_returns();
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE order_id = %d ORDER BY id DESC", $order_id ), ARRAY_A );
	}

	/** Sipariş kalemi başına iade edilmiş (reddedilmemiş) adet. */
	public static function returned_qty_by_item( $order_id ) {
		global $wpdb;
		$t    = self::t_returns();
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT order_item_id, SUM(qty) AS q FROM {$t} WHERE order_id = %d AND status <> 'rejected' GROUP BY order_item_id", $order_id ),
			ARRAY_A
		);
		$map = array();
		foreach ( $rows as $r ) {
			$map[ (int) $r['order_item_id'] ] = (int) $r['q'];
		}
		return $map;
	}

	public static function open_returns_for_item( $order_id, $item_id ) {
		global $wpdb;
		$t = self::t_returns();
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$t} WHERE order_id = %d AND order_item_id = %d AND status IN ('requested','approved','received') ORDER BY id ASC",
				$order_id,
				$item_id
			),
			ARRAY_A
		);
	}

	public static function list_returns( array $args ) {
		global $wpdb;
		$t    = self::t_returns();
		$args = wp_parse_args(
			$args,
			array(
				'status'   => '',
				'order_id' => 0,
				'search'   => '',
				'per_page' => 20,
				'page'     => 1,
			)
		);

		$where  = array( '1=1' );
		$params = array();

		if ( $args['status'] ) {
			$where[]  = 'status = %s';
			$params[] = $args['status'];
		}
		if ( $args['order_id'] ) {
			$where[]  = 'order_id = %d';
			$params[] = (int) $args['order_id'];
		}
		if ( '' !== $args['search'] ) {
			$ids = get_posts(
				array(
					'post_type'      => 'product',
					'post_status'    => 'any',
					's'              => $args['search'],
					'fields'         => 'ids',
					'posts_per_page' => 200,
				)
			);
			$ids = array_map( 'intval', $ids );
			if ( ctype_digit( $args['search'] ) ) {
				$where[]  = '(order_id = %d' . ( $ids ? ' OR product_id IN (' . implode( ',', $ids ) . ')' : '' ) . ')';
				$params[] = (int) $args['search'];
			} elseif ( $ids ) {
				$where[] = 'product_id IN (' . implode( ',', $ids ) . ')';
			} else {
				$where[] = '1=0';
			}
		}

		$where_sql = implode( ' AND ', $where );
		$per_page  = max( 1, (int) $args['per_page'] );
		$offset    = ( max( 1, (int) $args['page'] ) - 1 ) * $per_page;

		$count_sql = "SELECT COUNT(*) FROM {$t} WHERE {$where_sql}";
		$rows_sql  = "SELECT * FROM {$t} WHERE {$where_sql} ORDER BY id DESC LIMIT {$per_page} OFFSET {$offset}";

		if ( $params ) {
			$count_sql = $wpdb->prepare( $count_sql, $params ); // phpcs:ignore
			$rows_sql  = $wpdb->prepare( $rows_sql, $params ); // phpcs:ignore
		}

		return array(
			'total' => (int) $wpdb->get_var( $count_sql ), // phpcs:ignore
			'rows'  => $wpdb->get_results( $rows_sql, ARRAY_A ), // phpcs:ignore
		);
	}

	public static function status_counts() {
		global $wpdb;
		$t    = self::t_returns();
		$rows = $wpdb->get_results( "SELECT status, COUNT(*) AS c FROM {$t} GROUP BY status", ARRAY_A ); // phpcs:ignore
		$out  = array();
		foreach ( $rows as $r ) {
			$out[ $r['status'] ] = (int) $r['c'];
		}
		return $out;
	}

	public static function pending_count() {
		global $wpdb;
		$t = self::t_returns();
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t} WHERE status = 'requested'" ); // phpcs:ignore
	}

	/* --------------------------------------------------------------------
	 * Geri bildirim
	 * ------------------------------------------------------------------ */

	public static function insert_feedback( array $data ) {
		global $wpdb;
		$t = self::t_feedback();
		// UNIQUE(order_item_id): aynı kalem için ikinci oy yok sayılır.
		return (bool) $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$t} (order_id, order_item_id, product_id, variation_id, customer_id, size_key, size_label, fit, created_at) VALUES (%d, %d, %d, %d, %d, %s, %s, %d, %s)",
				$data['order_id'],
				$data['order_item_id'],
				$data['product_id'],
				$data['variation_id'],
				$data['customer_id'],
				$data['size_key'],
				$data['size_label'],
				$data['fit'],
				current_time( 'mysql', true )
			)
		);
	}

	public static function feedback_for_order( $order_id ) {
		global $wpdb;
		$t    = self::t_feedback();
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT order_item_id, fit FROM {$t} WHERE order_id = %d", $order_id ), ARRAY_A );
		$map  = array();
		foreach ( $rows as $r ) {
			$map[ (int) $r['order_item_id'] ] = (int) $r['fit'];
		}
		return $map;
	}

	/* --------------------------------------------------------------------
	 * İstatistik kaynakları
	 * ------------------------------------------------------------------ */

	/** Bir ürünün tüm sinyalleri (iadeler + geri bildirimler). */
	public static function product_signals( $product_id ) {
		global $wpdb;
		$r = self::t_returns();
		$f = self::t_feedback();

		$returns = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT fit, size_key, size_label, reason_cat, created_at, 'return' AS src FROM {$r} WHERE product_id = %d AND status <> 'rejected'",
				$product_id
			),
			ARRAY_A
		);
		$feedback = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT fit, size_key, size_label, '' AS reason_cat, created_at, 'feedback' AS src FROM {$f} WHERE product_id = %d",
				$product_id
			),
			ARRAY_A
		);

		return array_merge( $returns ? $returns : array(), $feedback ? $feedback : array() );
	}

	public static function products_with_signals() {
		global $wpdb;
		$r   = self::t_returns();
		$f   = self::t_feedback();
		$ids = $wpdb->get_col( "SELECT DISTINCT product_id FROM {$r} WHERE product_id > 0 UNION SELECT DISTINCT product_id FROM {$f} WHERE product_id > 0" ); // phpcs:ignore
		$old = $wpdb->get_col( "SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wdua_stats'" ); // phpcs:ignore
		return array_values( array_unique( array_map( 'intval', array_merge( $ids, $old ) ) ) );
	}

	/* --------------------------------------------------------------------
	 * Raporlar
	 * ------------------------------------------------------------------ */

	private static function cost_status_sql() {
		return "'" . implode( "','", array_map( 'esc_sql', WDUA_Reasons::cost_statuses() ) ) . "'";
	}

	/** Ürün bazında iade maliyeti. $since: GMT datetime veya ''. */
	public static function cost_report( $since = '', $limit = 100 ) {
		global $wpdb;
		$t      = self::t_returns();
		$st     = self::cost_status_sql();
		$where  = "status IN ({$st})";
		$params = array();
		if ( $since ) {
			$where   .= ' AND created_at >= %s';
			$params[] = $since;
		}
		$limit = max( 1, (int) $limit );
		$sql   = "SELECT product_id, COUNT(*) AS cnt, SUM(qty) AS qty,
				SUM(cost_ship_out) AS ship_out, SUM(cost_ship_back) AS ship_back,
				SUM(cost_labor) AS labor, SUM(cost_depreciation) AS dep, SUM(cost_total) AS total,
				SUM(CASE WHEN reason_cat = 'fit' THEN qty ELSE 0 END) AS fit_qty
			FROM {$t} WHERE {$where} GROUP BY product_id ORDER BY total DESC LIMIT {$limit}";
		if ( $params ) {
			$sql = $wpdb->prepare( $sql, $params ); // phpcs:ignore
		}
		$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore
		if ( ! $rows ) {
			return array();
		}

		$ids = array_map( 'intval', wp_list_pluck( $rows, 'product_id' ) );

		// Ürün başına en sık neden.
		$in       = implode( ',', $ids );
		$rsql     = "SELECT product_id, reason, SUM(qty) AS q FROM {$t} WHERE {$where} AND product_id IN ({$in}) GROUP BY product_id, reason ORDER BY q DESC, (reason = 'belirtilmedi') ASC";
		$rsql     = $params ? $wpdb->prepare( $rsql, $params ) : $rsql; // phpcs:ignore
		$reasons  = $wpdb->get_results( $rsql, ARRAY_A ); // phpcs:ignore
		$top      = array();
		foreach ( $reasons as $r ) {
			$pid = (int) $r['product_id'];
			if ( ! isset( $top[ $pid ] ) ) {
				$top[ $pid ] = $r['reason'];
			}
		}

		$sold = self::sold_qty( $ids, $since );

		foreach ( $rows as &$row ) {
			$pid                = (int) $row['product_id'];
			$row['top_reason']  = isset( $top[ $pid ] ) ? $top[ $pid ] : '';
			$row['sold']        = isset( $sold[ $pid ] ) ? (int) $sold[ $pid ] : null;
			$row['return_rate'] = ( $row['sold'] ) ? ( (int) $row['qty'] / $row['sold'] ) : null;
		}
		return $rows;
	}

	/** Seçili ürünlerin satış adedi (WooCommerce Analytics lookup tablosundan). */
	public static function sold_qty( array $product_ids = array(), $since_gmt = '' ) {
		global $wpdb;
		$lookup = $wpdb->prefix . 'wc_order_product_lookup';
		$stats  = $wpdb->prefix . 'wc_order_stats';
		if ( ! self::table_exists( $lookup ) || ! self::table_exists( $stats ) ) {
			return self::sold_qty_from_orders( $product_ids, $since_gmt );
		}

		$where  = "s.status NOT IN ('wc-failed','wc-cancelled','wc-pending','wc-checkout-draft','trash','auto-draft')";
		$params = array();
		if ( $product_ids ) {
			$where .= ' AND l.product_id IN (' . implode( ',', array_map( 'intval', $product_ids ) ) . ')';
		}
		if ( $since_gmt ) {
			$where   .= ' AND s.date_created_gmt >= %s';
			$params[] = $since_gmt;
		}
		$sql = "SELECT l.product_id, SUM(l.product_qty) AS q FROM {$lookup} l INNER JOIN {$stats} s ON s.order_id = l.order_id WHERE {$where} GROUP BY l.product_id";
		if ( $params ) {
			$sql = $wpdb->prepare( $sql, $params ); // phpcs:ignore
		}
		$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore
		if ( ! $rows ) {
			// Analytics kapalıysa veya henüz senkronize olmadıysa doğrudan sipariş kalemlerinden say.
			return self::sold_qty_from_orders( $product_ids, $since_gmt );
		}
		$map = array();
		foreach ( (array) $rows as $r ) {
			$map[ (int) $r['product_id'] ] = (int) $r['q'];
		}
		return $map;
	}

	/** Yedek: satış adedini sipariş kalemlerinden hesaplar (HPOS ve klasik depolama). */
	private static function sold_qty_from_orders( array $product_ids, $since_gmt ) {
		global $wpdb;
		$hpos = class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();

		if ( $hpos ) {
			$orders = "{$wpdb->prefix}wc_orders";
			$join   = "INNER JOIN {$orders} o ON o.id = oi.order_id";
			$where  = "o.type = 'shop_order' AND o.status NOT IN ('wc-failed','wc-cancelled','wc-pending','wc-checkout-draft','trash','auto-draft')";
			$date   = 'o.date_created_gmt';
		} else {
			$join  = "INNER JOIN {$wpdb->posts} o ON o.ID = oi.order_id";
			$where = "o.post_type = 'shop_order' AND o.post_status NOT IN ('wc-failed','wc-cancelled','wc-pending','wc-checkout-draft','trash','auto-draft')";
			$date  = 'o.post_date_gmt';
		}

		$params = array();
		if ( $product_ids ) {
			$where .= ' AND CAST(pm.meta_value AS UNSIGNED) IN (' . implode( ',', array_map( 'intval', $product_ids ) ) . ')';
		}
		if ( $since_gmt ) {
			$where   .= " AND {$date} >= %s";
			$params[] = $since_gmt;
		}

		$sql = "SELECT CAST(pm.meta_value AS UNSIGNED) AS product_id, SUM(CAST(qm.meta_value AS SIGNED)) AS q
			FROM {$wpdb->prefix}woocommerce_order_items oi
			{$join}
			INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta pm ON pm.order_item_id = oi.order_item_id AND pm.meta_key = '_product_id'
			INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta qm ON qm.order_item_id = oi.order_item_id AND qm.meta_key = '_qty'
			WHERE oi.order_item_type = 'line_item' AND {$where}
			GROUP BY product_id";
		if ( $params ) {
			$sql = $wpdb->prepare( $sql, $params ); // phpcs:ignore
		}
		$map = array();
		foreach ( (array) $wpdb->get_results( $sql, ARRAY_A ) as $r ) { // phpcs:ignore
			$map[ (int) $r['product_id'] ] = (int) $r['q'];
		}
		return $map;
	}

	public static function total_sold( $since_gmt = '' ) {
		return array_sum( self::sold_qty( array(), $since_gmt ) );
	}

	public static function kpis( $since = '' ) {
		global $wpdb;
		$t      = self::t_returns();
		$st     = self::cost_status_sql();
		$where  = "status <> 'rejected'";
		$params = array();
		if ( $since ) {
			$where   .= ' AND created_at >= %s';
			$params[] = $since;
		}
		$sql = "SELECT COUNT(*) AS cnt, COALESCE(SUM(qty),0) AS qty,
				COALESCE(SUM(CASE WHEN reason_cat = 'fit' THEN qty ELSE 0 END),0) AS fit_qty,
				COALESCE(SUM(CASE WHEN status IN ({$st}) THEN cost_total ELSE 0 END),0) AS cost,
				COALESCE(SUM(CASE WHEN status IN ({$st}) THEN 1 ELSE 0 END),0) AS cost_cnt,
				COALESCE(SUM(CASE WHEN status IN ({$st}) THEN cost_ship_out ELSE 0 END),0) AS ship_out,
				COALESCE(SUM(CASE WHEN status IN ({$st}) THEN cost_ship_back ELSE 0 END),0) AS ship_back,
				COALESCE(SUM(CASE WHEN status IN ({$st}) THEN cost_labor ELSE 0 END),0) AS labor,
				COALESCE(SUM(CASE WHEN status IN ({$st}) THEN cost_depreciation ELSE 0 END),0) AS dep
			FROM {$t} WHERE {$where}";
		if ( $params ) {
			$sql = $wpdb->prepare( $sql, $params ); // phpcs:ignore
		}
		$row = $wpdb->get_row( $sql, ARRAY_A ); // phpcs:ignore
		return $row ? $row : array();
	}

	public static function reason_distribution( $since = '' ) {
		global $wpdb;
		$t      = self::t_returns();
		$st     = self::cost_status_sql();
		$where  = "status <> 'rejected'";
		$params = array();
		if ( $since ) {
			$where   .= ' AND created_at >= %s';
			$params[] = $since;
		}
		$sql = "SELECT reason, COUNT(*) AS cnt, SUM(qty) AS qty,
				SUM(CASE WHEN status IN ({$st}) THEN cost_total ELSE 0 END) AS cost
			FROM {$t} WHERE {$where} GROUP BY reason ORDER BY qty DESC";
		if ( $params ) {
			$sql = $wpdb->prepare( $sql, $params ); // phpcs:ignore
		}
		return $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore
	}

	public static function feedback_count( $since = '' ) {
		global $wpdb;
		$t = self::t_feedback();
		if ( $since ) {
			return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE created_at >= %s", $since ) ); // phpcs:ignore
		}
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t}" ); // phpcs:ignore
	}

	private static function table_exists( $table ) {
		global $wpdb;
		static $cache = array();
		if ( ! isset( $cache[ $table ] ) ) {
			$cache[ $table ] = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) === $table );
		}
		return $cache[ $table ];
	}
}
