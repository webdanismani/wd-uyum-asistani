<?php
defined( 'ABSPATH' ) || exit;

/**
 * Mağaza tarafı: ürün sayfası kalıp rozeti, Hesabım > Sipariş sayfasında iade talebi ve kalıp geri bildirimi.
 */
class WDUA_Frontend {

	/** @var bool Aynı sayfada rozetin iki kez basılmasını engeller. */
	private $rendered = false;

	public function __construct() {
		$pos = WDUA_Settings::get( 'badge_position' );
		if ( 'after_price' === $pos ) {
			add_action( 'woocommerce_single_product_summary', array( $this, 'output_badge' ), 11 );
			add_filter( 'render_block', array( $this, 'block_theme_badge' ), 10, 3 );
		} elseif ( 'before_add_to_cart' === $pos ) {
			add_action( 'woocommerce_before_add_to_cart_form', array( $this, 'output_badge' ), 5 );
		} elseif ( 'after_add_to_cart' === $pos ) {
			add_action( 'woocommerce_after_add_to_cart_form', array( $this, 'output_badge' ), 5 );
		}

		add_shortcode( 'wdua_kalip', array( $this, 'shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'woocommerce_order_details_after_order_table', array( $this, 'account_section' ), 20 );
		add_action( 'template_redirect', array( $this, 'handle_post' ) );
	}

	/* --------------------------------------------------------------------
	 * Varlıklar
	 * ------------------------------------------------------------------ */

	public function assets() {
		if ( ! function_exists( 'is_product' ) ) {
			return;
		}
		wp_register_style( 'wdua-front', WDUA_URL . 'assets/css/frontend.css', array(), WDUA_VERSION );
		wp_register_script( 'wdua-front', WDUA_URL . 'assets/js/frontend.js', array( 'jquery' ), WDUA_VERSION, true );

		if ( is_product() || is_account_page() ) {
			wp_enqueue_style( 'wdua-front' );
			wp_enqueue_script( 'wdua-front' );
		}
	}

	private function enqueue_late() {
		wp_enqueue_style( 'wdua-front', WDUA_URL . 'assets/css/frontend.css', array(), WDUA_VERSION );
		wp_enqueue_script( 'wdua-front', WDUA_URL . 'assets/js/frontend.js', array( 'jquery' ), WDUA_VERSION, true );
	}

	/* --------------------------------------------------------------------
	 * Ürün sayfası rozeti
	 * ------------------------------------------------------------------ */

	public function output_badge() {
		global $product;
		if ( $this->rendered || ! $product instanceof WC_Product ) {
			return;
		}
		$html = self::badge_html( $product );
		if ( $html ) {
			$this->rendered = true;
			echo $html; // phpcs:ignore -- badge_html içeride kaçışlanır.
		}
	}

	/** Blok temalarda (FSE) fiyat bloğunun ardına rozet ekler. */
	public function block_theme_badge( $content, $block, $instance = null ) {
		if ( $this->rendered || empty( $block['blockName'] ) || 'woocommerce/product-price' !== $block['blockName'] || ! is_product() ) {
			return $content;
		}
		$pid = ( $instance && isset( $instance->context['postId'] ) ) ? (int) $instance->context['postId'] : get_the_ID();
		if ( $pid !== (int) get_queried_object_id() ) {
			return $content;
		}
		$product = wc_get_product( $pid );
		$html    = $product ? self::badge_html( $product ) : '';
		if ( $html ) {
			$this->rendered = true;
			$content       .= $html;
		}
		return $content;
	}

	public function shortcode( $atts ) {
		$atts    = shortcode_atts( array( 'id' => 0 ), $atts, 'wdua_kalip' );
		$id      = $atts['id'] ? absint( $atts['id'] ) : get_the_ID();
		$product = wc_get_product( $id );
		if ( ! $product ) {
			return '';
		}
		$this->enqueue_late();
		$this->rendered = true;
		return self::badge_html( $product );
	}

	/** Tek bir veri kümesinin görünüm modeli. */
	private static function view_model( $agg, array $s ) {
		$vm = array(
			'show'   => false,
			'msg'    => '',
			'type'   => 'none',
			'marker' => 50,
			'scale'  => false,
			'source' => '',
		);
		if ( empty( $agg['count'] ) ) {
			return $vm;
		}
		$v            = WDUA_Stats::verdict( $agg, $s );
		$vm['type']   = $v['type'];
		$vm['marker'] = WDUA_Stats::marker( $agg['mean'] );
		$vm['scale']  = ( 'yes' === $s['show_scale'] && (int) $agg['count'] >= (int) $s['min_samples'] );
		$vm['source'] = WDUA_Stats::fill( $s['text_source'], 0, $agg['count'] );
		if ( 'none' !== $v['type'] ) {
			$vm['show'] = true;
			$vm['msg']  = WDUA_Stats::message( $v, $agg['count'], $s );
		}
		return $vm;
	}

	public static function badge_html( WC_Product $product ) {
		$s = WDUA_Settings::all();
		if ( 'yes' !== $s['badge_enabled'] ) {
			return '';
		}
		$product_id = $product->get_id();
		if ( 'yes' === get_post_meta( $product_id, '_wdua_hide_badge', true ) ) {
			return '';
		}

		$stats  = WDUA_Stats::get( $product_id );
		$manual = trim( (string) get_post_meta( $product_id, '_wdua_manual_note', true ) );

		$overall = self::view_model( $stats ? $stats['overall'] : null, $s );
		if ( '' !== $manual ) {
			$overall['show'] = true;
			$overall['msg']  = $manual;
			$overall['type'] = 'manual';
		}

		$sizes = array();
		if ( $stats && ! empty( $stats['by_size'] ) ) {
			foreach ( $stats['by_size'] as $key => $agg ) {
				$vm = self::view_model( $agg, $s );
				if ( $vm['show'] ) {
					$vm['label']   = (string) $agg['label'];
					$sizes[ $key ] = $vm;
				}
			}
		}

		$color = '';
		if ( $stats && 'yes' === $s['show_color_hint'] && (int) $stats['returns'] >= (int) $s['min_samples'] ) {
			$pct = WDUA_Stats::color_share( $stats );
			if ( $pct >= (int) $s['color_hint_threshold'] ) {
				$color = WDUA_Stats::fill( $s['text_color'], $pct, $stats['returns'] );
			}
		}

		if ( ! $overall['show'] && ! $sizes && '' === $color ) {
			return '';
		}

		// Varyasyon → beden anahtarı eşlemesi (JS tarafı seçilen bedenin verisini gösterir).
		$vmap = array();
		if ( $sizes && $product->is_type( 'variable' ) ) {
			foreach ( $product->get_children() as $vid ) {
				$variation = wc_get_product( $vid );
				if ( ! $variation ) {
					continue;
				}
				$size = WDUA_Service::size_from_pairs( $variation->get_attributes() );
				if ( $size && '' !== $size['key'] ) {
					$vmap[ $vid ] = $size['key'];
				}
			}
		}

		$payload = array(
			'overall'   => $overall,
			'sizes'     => $sizes,
			'vmap'      => (object) $vmap,
			'sizeAttrs' => WDUA_Service::size_attr_keys(),
			'sizeTpl'   => '%s beden için',
		);

		$hidden = $overall['show'] || '' !== $color ? '' : ' wdua-is-hidden';
		$type   = esc_attr( $overall['type'] );

		ob_start();
		?>
		<div class="wdua-fit wdua-fit--<?php echo $type; // phpcs:ignore ?><?php echo esc_attr( $hidden ); ?>" data-wdua="<?php echo esc_attr( wp_json_encode( $payload ) ); ?>" role="note">
			<div class="wdua-fit__head">
				<svg class="wdua-fit__icon" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true"><rect x="2" y="8" width="20" height="8" rx="1.5"/><path d="M6 8v3M10 8v4M14 8v3M18 8v4"/></svg>
				<span class="wdua-fit__title"><?php echo esc_html( $s['text_title'] ); ?></span>
				<span class="wdua-fit__size" hidden></span>
			</div>
			<div class="wdua-fit__scale"<?php echo $overall['scale'] ? '' : ' hidden'; ?>>
				<span class="wdua-fit__end">Dar</span>
				<span class="wdua-fit__track" aria-hidden="true">
					<span class="wdua-fit__mid"></span>
					<span class="wdua-fit__dot" style="left:<?php echo esc_attr( $overall['marker'] ); ?>%"></span>
				</span>
				<span class="wdua-fit__end">Bol</span>
			</div>
			<p class="wdua-fit__msg"><?php echo esc_html( $overall['msg'] ); ?></p>
			<?php if ( $color ) : ?>
				<p class="wdua-fit__color"><?php echo esc_html( $color ); ?></p>
			<?php endif; ?>
			<p class="wdua-fit__src"<?php echo ( $overall['scale'] && 'manual' !== $overall['type'] ) ? '' : ' hidden'; ?>><?php echo esc_html( $overall['source'] ); ?></p>
		</div>
		<?php
		return apply_filters( 'wdua_badge_html', ob_get_clean(), $product, $stats );
	}

	/* --------------------------------------------------------------------
	 * Hesabım > Sipariş detayı
	 * ------------------------------------------------------------------ */

	public function account_section( $order ) {
		if ( ! is_user_logged_in() || ! function_exists( 'is_wc_endpoint_url' ) || ! is_wc_endpoint_url( 'view-order' ) ) {
			return;
		}
		if ( ! $order instanceof WC_Order || (int) $order->get_customer_id() !== get_current_user_id() ) {
			return;
		}

		$existing   = WDUA_Repo::returns_for_order( $order->get_id() );
		$can_return = WDUA_Service::can_request_return( $order );
		$returnable = $can_return ? WDUA_Service::returnable_items( $order ) : array();
		$fb_items   = WDUA_Service::feedback_items( $order );

		if ( ! $existing && ! $returnable && ! $fb_items ) {
			return;
		}

		echo '<section class="wdua-account">';

		if ( $existing ) {
			$this->render_existing( $existing, $order );
		}
		if ( $returnable ) {
			$this->render_return_form( $order, $returnable );
		}
		if ( $fb_items ) {
			$this->render_feedback_form( $order, $fb_items );
		}

		echo '</section>';
	}

	private function render_existing( array $rows, WC_Order $order ) {
		?>
		<h2 class="wdua-h">İade talepleriniz</h2>
		<table class="woocommerce-table shop_table wdua-table">
			<thead><tr><th>Talep</th><th>Ürün</th><th>Adet</th><th>Neden</th><th>Durum</th></tr></thead>
			<tbody>
			<?php
			foreach ( $rows as $r ) :
				$item = $order->get_item( $r['order_item_id'] );
				?>
				<tr>
					<td>#<?php echo esc_html( $r['request_key'] ); ?><br><small><?php echo esc_html( get_date_from_gmt( $r['created_at'], 'd.m.Y' ) ); ?></small></td>
					<td><?php echo esc_html( $item ? $item->get_name() : '—' ); ?></td>
					<td><?php echo (int) $r['qty']; ?></td>
					<td><?php echo esc_html( WDUA_Reasons::label( $r['reason'] ) ); ?></td>
					<td><span class="wdua-status wdua-status--<?php echo esc_attr( $r['status'] ); ?>"><?php echo esc_html( WDUA_Reasons::status_label( $r['status'] ) ); ?></span></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	private function render_return_form( WC_Order $order, array $items ) {
		$reasons  = WDUA_Reasons::customer_reasons();
		$deadline = WDUA_Service::return_deadline( $order );
		$fit      = WDUA_Reasons::fit_labels();
		?>
		<h2 class="wdua-h" id="wdua-return">İade / değişim talebi</h2>
		<?php if ( $deadline ) : ?>
			<p class="wdua-muted">Son talep tarihi: <strong><?php echo esc_html( wp_date( 'd.m.Y', $deadline ) ); ?></strong></p>
		<?php endif; ?>
		<form method="post" class="wdua-return-form" novalidate>
			<div class="wdua-items">
				<?php
				foreach ( $items as $item_id => $data ) :
					$item = $data['item'];
					$size = WDUA_Service::detect_size( $item );
					$base = 'wdua_items[' . (int) $item_id . ']';
					?>
					<div class="wdua-item">
						<label class="wdua-item__pick">
							<input type="checkbox" name="<?php echo esc_attr( $base ); ?>[on]" value="1" class="wdua-item__check">
							<span class="wdua-item__name"><?php echo esc_html( $item->get_name() ); ?>
								<?php if ( $size['label'] ) : ?>
									<small>Beden: <?php echo esc_html( $size['label'] ); ?></small>
								<?php endif; ?>
							</span>
						</label>
						<div class="wdua-item__fields" hidden>
							<label class="wdua-field wdua-field--qty">Adet
								<select name="<?php echo esc_attr( $base ); ?>[qty]">
									<?php for ( $i = 1; $i <= $data['remaining']; $i++ ) : ?>
										<option value="<?php echo (int) $i; ?>"><?php echo (int) $i; ?></option>
									<?php endfor; ?>
								</select>
							</label>
							<label class="wdua-field wdua-field--reason">İade nedeni
								<select name="<?php echo esc_attr( $base ); ?>[reason]" class="wdua-reason">
									<option value="">Seçiniz…</option>
									<?php foreach ( $reasons as $key => $r ) : ?>
										<option value="<?php echo esc_attr( $key ); ?>" data-cat="<?php echo esc_attr( $r['cat'] ); ?>" data-fit="<?php echo esc_attr( (string) $r['fit'] ); ?>"><?php echo esc_html( $r['label'] ); ?></option>
									<?php endforeach; ?>
								</select>
							</label>
							<fieldset class="wdua-fitpick" hidden>
								<legend>Ürün size ne kadar <span class="wdua-fitpick__dir">dar</span> geldi?</legend>
								<div class="wdua-seg">
									<?php foreach ( $fit as $val => $label ) : ?>
										<?php if ( 0 === $val ) { continue; } ?>
										<label class="wdua-seg__opt" data-val="<?php echo (int) $val; ?>">
											<input type="radio" name="<?php echo esc_attr( $base ); ?>[fit]" value="<?php echo (int) $val; ?>">
											<span><?php echo esc_html( $label ); ?></span>
										</label>
									<?php endforeach; ?>
								</div>
							</fieldset>
						</div>
					</div>
				<?php endforeach; ?>
			</div>
			<p class="wdua-field">
				<label for="wdua_note">Açıklama <span class="wdua-muted">(isteğe bağlı)</span></label>
				<textarea id="wdua_note" name="wdua_note" rows="3" maxlength="1000" placeholder="Örn. Bir beden büyüğüyle değiştirmek istiyorum."></textarea>
			</p>
			<input type="hidden" name="wdua_action" value="return">
			<input type="hidden" name="wdua_order_id" value="<?php echo (int) $order->get_id(); ?>">
			<?php wp_nonce_field( 'wdua_return_' . $order->get_id(), '_wdua_nonce' ); ?>
			<button type="submit" class="button wdua-btn">İade talebi gönder</button>
		</form>
		<?php
	}

	private function render_feedback_form( WC_Order $order, array $items ) {
		$fit = WDUA_Reasons::fit_labels();
		?>
		<h2 class="wdua-h" id="wdua-feedback">Ürünlerin kalıbı nasıldı?</h2>
		<p class="wdua-muted">Değerlendirmeniz sonraki alıcılara doğru beden önerisi göstermemizi sağlar.</p>
		<form method="post" class="wdua-fb-form">
			<?php
			foreach ( $items as $item_id => $item ) :
				$size = WDUA_Service::detect_size( $item );
				?>
				<div class="wdua-fb-row">
					<div class="wdua-fb-row__name"><?php echo esc_html( $item->get_name() ); ?>
						<?php if ( $size['label'] ) : ?>
							<small>Beden: <?php echo esc_html( $size['label'] ); ?></small>
						<?php endif; ?>
					</div>
					<div class="wdua-seg wdua-seg--5" role="radiogroup">
						<?php foreach ( $fit as $val => $label ) : ?>
							<label class="wdua-seg__opt" data-val="<?php echo (int) $val; ?>">
								<input type="radio" name="wdua_fb[<?php echo (int) $item_id; ?>]" value="<?php echo (int) $val; ?>">
								<span><?php echo esc_html( $label ); ?></span>
							</label>
						<?php endforeach; ?>
					</div>
				</div>
			<?php endforeach; ?>
			<input type="hidden" name="wdua_action" value="feedback">
			<input type="hidden" name="wdua_order_id" value="<?php echo (int) $order->get_id(); ?>">
			<?php wp_nonce_field( 'wdua_feedback_' . $order->get_id(), '_wdua_nonce' ); ?>
			<button type="submit" class="button wdua-btn">Değerlendirmeyi gönder</button>
		</form>
		<?php
	}

	/* --------------------------------------------------------------------
	 * Form işleme
	 * ------------------------------------------------------------------ */

	public function handle_post() {
		if ( empty( $_POST['wdua_action'] ) || ! is_user_logged_in() ) {
			return;
		}

		$action   = sanitize_key( wp_unslash( $_POST['wdua_action'] ) );
		$order_id = isset( $_POST['wdua_order_id'] ) ? absint( $_POST['wdua_order_id'] ) : 0;
		$nonce    = isset( $_POST['_wdua_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wdua_nonce'] ) ) : '';

		if ( ! in_array( $action, array( 'return', 'feedback' ), true ) ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order || (int) $order->get_customer_id() !== get_current_user_id() ) {
			wc_add_notice( 'Sipariş bulunamadı.', 'error' );
			return;
		}
		if ( ! wp_verify_nonce( $nonce, 'wdua_' . $action . '_' . $order_id ) ) {
			wc_add_notice( 'Oturum süresi doldu, lütfen sayfayı yenileyip tekrar deneyin.', 'error' );
			return;
		}

		$redirect = $order->get_view_order_url();

		if ( 'return' === $action ) {
			$posted = isset( $_POST['wdua_items'] ) ? wp_unslash( $_POST['wdua_items'] ) : array(); // phpcs:ignore -- Service içinde doğrulanır.
			$note   = isset( $_POST['wdua_note'] ) ? wp_unslash( $_POST['wdua_note'] ) : ''; // phpcs:ignore
			$result = WDUA_Service::create_customer_request( $order, $posted, $note );
			if ( is_wp_error( $result ) ) {
				wc_add_notice( $result->get_error_message(), 'error' );
				return; // Aynı sayfada hata gösterilir, form korunur.
			}
			wc_add_notice( 'İade talebiniz alındı. Durumu bu sayfadan takip edebilirsiniz.', 'success' );
			$redirect .= '#wdua-return';
		} else {
			$posted = isset( $_POST['wdua_fb'] ) ? wp_unslash( $_POST['wdua_fb'] ) : array(); // phpcs:ignore
			$saved  = WDUA_Service::save_feedback( $order, $posted );
			if ( $saved ) {
				wc_add_notice( 'Teşekkürler! Değerlendirmeniz kaydedildi.', 'success' );
			} else {
				wc_add_notice( 'Lütfen en az bir ürün için kalıp seçin.', 'notice' );
			}
			$redirect .= '#wdua-feedback';
		}

		wp_safe_redirect( $redirect );
		exit;
	}
}
