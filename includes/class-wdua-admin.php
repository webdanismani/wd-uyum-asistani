<?php
defined( 'ABSPATH' ) || exit;

/**
 * Yönetim paneli: genel bakış, iade talepleri, maliyet raporu, ürün uyum analizi, ayarlar,
 * sipariş ve ürün düzenleme kutuları.
 */
class WDUA_Admin {

	const CAP = 'manage_woocommerce';

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );

		add_action( 'admin_post_wdua_save_return', array( $this, 'handle_save_return' ) );
		add_action( 'admin_post_wdua_new_return', array( $this, 'handle_new_return' ) );
		add_action( 'admin_post_wdua_delete_return', array( $this, 'handle_delete_return' ) );
		add_action( 'admin_post_wdua_refund_return', array( $this, 'handle_refund_return' ) );
		add_action( 'admin_post_wdua_save_settings', array( $this, 'handle_save_settings' ) );
		add_action( 'admin_post_wdua_rebuild', array( $this, 'handle_rebuild' ) );
		add_action( 'admin_post_wdua_export', array( $this, 'handle_export' ) );

		add_action( 'add_meta_boxes', array( $this, 'meta_boxes' ) );
		add_action( 'save_post_product', array( $this, 'save_product_meta' ) );

		add_filter( 'plugin_action_links_' . plugin_basename( WDUA_FILE ), array( $this, 'action_links' ) );
		add_filter( 'plugin_row_meta', array( $this, 'row_meta' ), 10, 2 );
		add_filter( 'admin_footer_text', array( $this, 'footer_text' ) );
	}

	/* ====================================================================
	 * Altyapı
	 * ================================================================== */

	public function menu() {
		$pending = WDUA_Repo::pending_count();
		$bubble  = $pending ? ' <span class="awaiting-mod">' . (int) $pending . '</span>' : '';

		add_menu_page( 'WD Uyum Asistanı', 'Uyum Asistanı' . $bubble, self::CAP, 'wdua', array( $this, 'page_dashboard' ), 'dashicons-image-flip-vertical', 56 );
		add_submenu_page( 'wdua', 'Genel Bakış', 'Genel Bakış', self::CAP, 'wdua', array( $this, 'page_dashboard' ) );
		add_submenu_page( 'wdua', 'İade Talepleri', 'İade Talepleri' . $bubble, self::CAP, 'wdua-returns', array( $this, 'page_returns' ) );
		add_submenu_page( 'wdua', 'Maliyet Raporu', 'Maliyet Raporu', self::CAP, 'wdua-costs', array( $this, 'page_costs' ) );
		add_submenu_page( 'wdua', 'Ürün Uyum Analizi', 'Ürün Uyum Analizi', self::CAP, 'wdua-fit', array( $this, 'page_fit' ) );
		add_submenu_page( 'wdua', 'Ayarlar', 'Ayarlar', self::CAP, 'wdua-settings', array( $this, 'page_settings' ) );
	}

	public function assets( $hook ) {
		$page = isset( $_GET['page'] ) ? sanitize_key( $_GET['page'] ) : ''; // phpcs:ignore
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$is_ours = 0 === strpos( $page, 'wdua' );
		$is_edit = $screen && in_array( $screen->id, array( 'product', 'shop_order', 'woocommerce_page_wc-orders' ), true );
		if ( ! $is_ours && ! $is_edit ) {
			return;
		}
		wp_enqueue_style( 'wdua-admin', WDUA_URL . 'assets/css/admin.css', array(), WDUA_VERSION );
		if ( $is_ours ) {
			wp_enqueue_script( 'wdua-admin', WDUA_URL . 'assets/js/admin.js', array( 'jquery' ), WDUA_VERSION, true );
			wp_localize_script(
				'wdua-admin',
				'wduaPrice',
				array(
					'format'   => get_woocommerce_price_format(),
					'symbol'   => html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' ),
					'decimals' => wc_get_price_decimals(),
					'decSep'   => wc_get_price_decimal_separator(),
					'thouSep'  => wc_get_price_thousand_separator(),
				)
			);
		}
	}

	public function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( self::url( 'wdua-settings' ) ) . '">Ayarlar</a>' );
		return $links;
	}

	public function row_meta( $links, $file ) {
		if ( plugin_basename( WDUA_FILE ) === $file ) {
			$links[] = '<a href="' . esc_url( WDUA_SUPPORT_URL ) . '" target="_blank" rel="noopener">Destek (oblifex.com)</a>';
			$links[] = '<a href="' . esc_url( WDUA_REPO_URL ) . '" target="_blank" rel="noopener">GitHub</a>';
		}
		return $links;
	}

	public function footer_text( $text ) {
		$page = isset( $_GET['page'] ) ? sanitize_key( $_GET['page'] ) : ''; // phpcs:ignore
		if ( 0 !== strpos( $page, 'wdua' ) ) {
			return $text;
		}
		return sprintf(
			'WD Uyum Asistanı ücretsizdir. Soru, öneri ve destek için <a href="%s" target="_blank" rel="noopener">oblifex.com</a> webmaster forumuna katılın. · <a href="%s" target="_blank" rel="noopener">Web Danışmanı</a>',
			esc_url( WDUA_SUPPORT_URL ),
			esc_url( 'https://webdanismani.com' )
		);
	}

	private static function url( $page, array $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => $page ), $args ), admin_url( 'admin.php' ) );
	}

	private static function check() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'Bu işlem için yetkiniz yok.', 403 );
		}
	}

	private static function redirect( $page, array $args = array() ) {
		wp_safe_redirect( self::url( $page, $args ) );
		exit;
	}

	private static function money( $v ) {
		return wc_price( (float) $v );
	}

	/** Tutar girişini mağazanın para birimi konumuna göre sembolle sarar (₺ 45 / 45 ₺). */
	private static function money_input( $input ) {
		$symbol = '<span class="wdua-cur">' . get_woocommerce_currency_symbol() . '</span>';
		switch ( get_option( 'woocommerce_currency_pos', 'left' ) ) {
			case 'left':
				return $symbol . $input;
			case 'left_space':
				return $symbol . ' ' . $input;
			case 'right':
				return $input . $symbol;
			default:
				return $input . ' ' . $symbol;
		}
	}

	/** CSV için mağazanın ondalık ayracıyla, binlik ayraçsız sayı. */
	private static function csv_num( $v, $dec = 2 ) {
		return number_format( (float) $v, $dec, wc_get_price_decimal_separator(), '' );
	}

	private static function pct( $v, $dec = 1 ) {
		return null === $v ? '—' : '%' . number_format_i18n( $v * 100, $dec );
	}

	private static function period_since( $days ) {
		$days = (int) $days;
		return $days > 0 ? gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ) : '';
	}

	private static function current_period() {
		$d = isset( $_GET['period'] ) ? (int) $_GET['period'] : 90; // phpcs:ignore
		return in_array( $d, array( 30, 90, 180, 365, 0 ), true ) ? $d : 90;
	}

	private static function product_link( $product_id ) {
		$title = get_the_title( $product_id );
		if ( ! $title ) {
			return '#' . (int) $product_id . ' (silinmiş)';
		}
		return '<a href="' . esc_url( get_edit_post_link( $product_id ) ) . '">' . esc_html( $title ) . '</a>';
	}

	private function header( $title, $actions = '' ) {
		$notice = isset( $_GET['wdua_notice'] ) ? sanitize_key( $_GET['wdua_notice'] ) : ''; // phpcs:ignore
		$error  = isset( $_GET['wdua_error'] ) ? sanitize_text_field( wp_unslash( $_GET['wdua_error'] ) ) : ''; // phpcs:ignore
		$msgs   = array(
			'saved'    => 'Değişiklikler kaydedildi.',
			'created'  => 'İade kaydı oluşturuldu.',
			'deleted'  => 'Kayıt silindi.',
			'refunded' => 'WooCommerce iade kaydı oluşturuldu ve ürün stoğa eklendi.',
			'rebuilt'  => 'Tüm ürün istatistikleri yeniden hesaplandı.',
		);
		echo '<div class="wrap wdua-wrap">';
		echo '<div class="wdua-top"><h1 class="wp-heading-inline">' . esc_html( $title ) . '</h1>' . $actions . '<span class="wdua-brand">WD Uyum Asistanı</span></div>'; // phpcs:ignore
		echo '<hr class="wp-header-end">';
		if ( isset( $msgs[ $notice ] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $msgs[ $notice ] ) . '</p></div>';
		}
		if ( $error ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $error ) . '</p></div>';
		}
	}

	private function footer() {
		echo '</div>';
	}

	private function period_tabs( $page, $current, array $extra = array() ) {
		$opts = array(
			30  => 'Son 30 gün',
			90  => 'Son 90 gün',
			180 => 'Son 6 ay',
			365 => 'Son 1 yıl',
			0   => 'Tümü',
		);
		echo '<div class="wdua-periods">';
		foreach ( $opts as $d => $label ) {
			printf(
				'<a class="%s" href="%s">%s</a>',
				$d === $current ? 'is-active' : '',
				esc_url( self::url( $page, array_merge( $extra, array( 'period' => $d ) ) ) ),
				esc_html( $label )
			);
		}
		echo '</div>';
	}

	private static function scale_html( $agg ) {
		if ( empty( $agg['count'] ) ) {
			return '<span class="wdua-dim">—</span>';
		}
		return sprintf(
			'<span class="wdua-mini-scale" title="Ortalama kalıp: %s"><i style="left:%s%%"></i></span>',
			esc_attr( number_format_i18n( $agg['mean'], 2 ) ),
			esc_attr( WDUA_Stats::marker( $agg['mean'] ) )
		);
	}

	/* ====================================================================
	 * Genel bakış
	 * ================================================================== */

	public function page_dashboard() {
		self::check();
		$period = self::current_period();
		$since  = self::period_since( $period );
		$k      = WDUA_Repo::kpis( $since );
		$sold   = WDUA_Repo::total_sold( $since );
		$qty    = (int) ( isset( $k['qty'] ) ? $k['qty'] : 0 );
		$rate   = $sold ? $qty / $sold : null;
		$fit    = $qty ? (int) $k['fit_qty'] / $qty : null;
		$cost   = (float) ( isset( $k['cost'] ) ? $k['cost'] : 0 );
		$avg    = ( (int) $k['cost_cnt'] ) ? $cost / (int) $k['cost_cnt'] : 0;

		$this->header( 'Genel Bakış', '<a href="' . esc_url( self::url( 'wdua-returns', array( 'action' => 'new' ) ) ) . '" class="page-title-action">İade kaydı ekle</a>' );
		$this->period_tabs( 'wdua', $period );
		?>
		<div class="wdua-cards">
			<div class="wdua-card">
				<span class="wdua-card__label">İade edilen adet</span>
				<strong class="wdua-card__value"><?php echo esc_html( number_format_i18n( $qty ) ); ?></strong>
				<span class="wdua-card__sub"><?php echo esc_html( number_format_i18n( (int) $k['cnt'] ) ); ?> kayıt</span>
			</div>
			<div class="wdua-card">
				<span class="wdua-card__label">İade oranı</span>
				<strong class="wdua-card__value"><?php echo esc_html( self::pct( $rate ) ); ?></strong>
				<span class="wdua-card__sub"><?php echo $sold ? esc_html( number_format_i18n( $sold ) ) . ' adet satış içinde' : 'Satış verisi için WooCommerce Analytics gerekli'; ?></span>
			</div>
			<div class="wdua-card wdua-card--accent">
				<span class="wdua-card__label">Toplam iade maliyeti</span>
				<strong class="wdua-card__value"><?php echo self::money( $cost ); // phpcs:ignore ?></strong>
				<span class="wdua-card__sub">İade başına ort. <?php echo self::money( $avg ); // phpcs:ignore ?></span>
			</div>
			<div class="wdua-card">
				<span class="wdua-card__label">Beden kaynaklı iade</span>
				<strong class="wdua-card__value"><?php echo esc_html( self::pct( $fit ) ); ?></strong>
				<span class="wdua-card__sub">Kalıp bilgisiyle önlenebilir pay</span>
			</div>
			<div class="wdua-card">
				<span class="wdua-card__label">Kalıp geri bildirimi</span>
				<strong class="wdua-card__value"><?php echo esc_html( number_format_i18n( WDUA_Repo::feedback_count( $since ) ) ); ?></strong>
				<span class="wdua-card__sub">İade etmeyen alıcılardan</span>
			</div>
		</div>

		<div class="wdua-grid">
			<div class="wdua-panel">
				<h2>Maliyet dağılımı</h2>
				<?php $this->cost_breakdown( $k ); ?>
			</div>
			<div class="wdua-panel">
				<h2>İade nedenleri</h2>
				<?php $this->reason_bars( WDUA_Repo::reason_distribution( $since ) ); ?>
			</div>
		</div>

		<div class="wdua-panel">
			<h2>İadesi en pahalı 5 ürün <a class="wdua-more" href="<?php echo esc_url( self::url( 'wdua-costs', array( 'period' => $period ) ) ); ?>">Tüm rapor →</a></h2>
			<?php $this->cost_table( WDUA_Repo::cost_report( $since, 5 ), true ); ?>
		</div>

		<div class="wdua-panel">
			<h2>Son iade talepleri <a class="wdua-more" href="<?php echo esc_url( self::url( 'wdua-returns' ) ); ?>">Tümü →</a></h2>
			<?php
			$list = WDUA_Repo::list_returns( array( 'per_page' => 8 ) );
			$this->returns_table( $list['rows'] );
			?>
		</div>
		<?php
		$this->footer();
	}

	private function cost_breakdown( $k ) {
		$parts = array(
			'ship_out'  => array( 'Gidiş kargosu (kayıp)', '#4c6ef5' ),
			'ship_back' => array( 'Dönüş kargosu', '#15aabf' ),
			'labor'     => array( 'İşçilik (kontrol, paketleme)', '#fab005' ),
			'dep'       => array( 'Değer kaybı', '#fa5252' ),
		);
		$total = 0;
		foreach ( $parts as $key => $p ) {
			$total += (float) $k[ $key ];
		}
		if ( $total <= 0 ) {
			echo '<p class="wdua-empty">Bu dönemde gerçekleşmiş (onaylı/teslim alınmış/tamamlanmış) iade yok.</p>';
			return;
		}
		echo '<div class="wdua-stack">';
		foreach ( $parts as $key => $p ) {
			$w = 100 * (float) $k[ $key ] / $total;
			if ( $w > 0 ) {
				printf( '<span style="width:%s%%;background:%s" title="%s"></span>', esc_attr( round( $w, 2 ) ), esc_attr( $p[1] ), esc_attr( $p[0] ) );
			}
		}
		echo '</div><ul class="wdua-legend">';
		foreach ( $parts as $key => $p ) {
			printf(
				'<li><i style="background:%s"></i>%s <strong>%s</strong> <span class="wdua-dim">%s</span></li>',
				esc_attr( $p[1] ),
				esc_html( $p[0] ),
				self::money( $k[ $key ] ), // phpcs:ignore
				esc_html( '%' . number_format_i18n( 100 * (float) $k[ $key ] / $total, 0 ) )
			);
		}
		echo '</ul>';
	}

	private function reason_bars( $rows ) {
		if ( ! $rows ) {
			echo '<p class="wdua-empty">Henüz iade kaydı yok.</p>';
			return;
		}
		$max  = max( array_map( 'intval', wp_list_pluck( $rows, 'qty' ) ) );
		$cats = array();
		foreach ( WDUA_Reasons::all() as $key => $r ) {
			$cats[ $key ] = $r['cat'];
		}
		echo '<div class="wdua-bars">';
		foreach ( $rows as $r ) {
			$w = $max ? 100 * (int) $r['qty'] / $max : 0;
			printf(
				'<div class="wdua-bar wdua-bar--%s"><span class="wdua-bar__label">%s</span><span class="wdua-bar__track"><span style="width:%s%%"></span></span><span class="wdua-bar__val">%s adet · %s</span></div>',
				esc_attr( isset( $cats[ $r['reason'] ] ) ? $cats[ $r['reason'] ] : 'other' ),
				esc_html( WDUA_Reasons::label( $r['reason'] ) ),
				esc_attr( round( $w, 1 ) ),
				esc_html( number_format_i18n( (int) $r['qty'] ) ),
				self::money( $r['cost'] ) // phpcs:ignore
			);
		}
		echo '</div>';
	}

	/* ====================================================================
	 * İade talepleri
	 * ================================================================== */

	public function page_returns() {
		self::check();
		$action = isset( $_GET['action'] ) ? sanitize_key( $_GET['action'] ) : ''; // phpcs:ignore
		if ( 'edit' === $action ) {
			$this->render_edit( isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0 ); // phpcs:ignore
			return;
		}
		if ( 'new' === $action ) {
			$this->render_new( isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0 ); // phpcs:ignore
			return;
		}

		$status = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : ''; // phpcs:ignore
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : ''; // phpcs:ignore
		$paged  = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1; // phpcs:ignore
		if ( $status && ! isset( WDUA_Reasons::statuses()[ $status ] ) ) {
			$status = '';
		}

		$list   = WDUA_Repo::list_returns(
			array(
				'status'   => $status,
				'search'   => $search,
				'page'     => $paged,
				'per_page' => 25,
			)
		);
		$counts = WDUA_Repo::status_counts();

		$this->header( 'İade Talepleri', '<a href="' . esc_url( self::url( 'wdua-returns', array( 'action' => 'new' ) ) ) . '" class="page-title-action">İade kaydı ekle</a>' );

		echo '<ul class="subsubsub">';
		$links   = array( '' => 'Tümü (' . array_sum( $counts ) . ')' );
		foreach ( WDUA_Reasons::statuses() as $key => $label ) {
			$links[ $key ] = $label . ' (' . ( isset( $counts[ $key ] ) ? $counts[ $key ] : 0 ) . ')';
		}
		$i = 0;
		foreach ( $links as $key => $label ) {
			printf(
				'<li>%s<a href="%s" class="%s">%s</a></li>',
				$i++ ? ' | ' : '',
				esc_url( self::url( 'wdua-returns', $key ? array( 'status' => $key ) : array() ) ),
				$key === $status ? 'current' : '',
				esc_html( $label )
			);
		}
		echo '</ul>';
		?>
		<form method="get" class="wdua-search">
			<input type="hidden" name="page" value="wdua-returns">
			<?php if ( $status ) : ?>
				<input type="hidden" name="status" value="<?php echo esc_attr( $status ); ?>">
			<?php endif; ?>
			<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="Sipariş no veya ürün adı">
			<button class="button">Ara</button>
		</form>
		<div class="clear"></div>
		<?php
		$this->returns_table( $list['rows'] );

		$pages = (int) ceil( $list['total'] / 25 );
		if ( $pages > 1 ) {
			echo '<div class="tablenav"><div class="tablenav-pages">';
			echo paginate_links( // phpcs:ignore
				array(
					'base'    => add_query_arg( 'paged', '%#%' ),
					'format'  => '',
					'current' => $paged,
					'total'   => $pages,
				)
			);
			echo '</div></div>';
		}
		$this->footer();
	}

	private function returns_table( $rows ) {
		if ( ! $rows ) {
			echo '<p class="wdua-empty">Kayıt bulunamadı.</p>';
			return;
		}
		?>
		<table class="widefat striped wdua-table">
			<thead>
			<tr>
				<th>Talep</th><th>Tarih</th><th>Sipariş</th><th>Ürün</th><th>Adet</th><th>Neden</th><th>Kalıp</th><th>Durum</th><th class="num">Maliyet</th><th></th>
			</tr>
			</thead>
			<tbody>
			<?php
			foreach ( $rows as $r ) :
				$order = wc_get_order( $r['order_id'] );
				$edit  = self::url(
					'wdua-returns',
					array(
						'action' => 'edit',
						'id'     => $r['id'],
					)
				);
				?>
				<tr>
					<td><a href="<?php echo esc_url( $edit ); ?>"><strong>#<?php echo esc_html( $r['request_key'] ); ?></strong></a><br><span class="wdua-dim"><?php echo esc_html( self::source_label( $r['source'] ) ); ?></span></td>
					<td><?php echo esc_html( get_date_from_gmt( $r['created_at'], 'd.m.Y H:i' ) ); ?></td>
					<td>
						<?php if ( $order ) : ?>
							<a href="<?php echo esc_url( $order->get_edit_order_url() ); ?>">#<?php echo esc_html( $order->get_order_number() ); ?></a><br><span class="wdua-dim"><?php echo esc_html( $order->get_formatted_billing_full_name() ); ?></span>
						<?php else : ?>
							#<?php echo (int) $r['order_id']; ?>
						<?php endif; ?>
					</td>
					<td><?php echo self::product_link( $r['product_id'] ); // phpcs:ignore ?><?php echo $r['size_label'] ? '<br><span class="wdua-dim">Beden: ' . esc_html( $r['size_label'] ) . '</span>' : ''; ?></td>
					<td><?php echo (int) $r['qty']; ?></td>
					<td><?php echo esc_html( WDUA_Reasons::label( $r['reason'] ) ); ?></td>
					<td><?php echo esc_html( WDUA_Reasons::fit_label( $r['fit'] ) ); ?></td>
					<td><span class="wdua-status wdua-status--<?php echo esc_attr( $r['status'] ); ?>"><?php echo esc_html( WDUA_Reasons::status_label( $r['status'] ) ); ?></span></td>
					<td class="num"><?php echo self::money( $r['cost_total'] ); // phpcs:ignore ?></td>
					<td><a class="button button-small" href="<?php echo esc_url( $edit ); ?>">Yönet</a></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	private static function source_label( $source ) {
		$m = array(
			'customer' => 'Müşteri talebi',
			'admin'    => 'Yönetici kaydı',
			'refund'   => 'WooCommerce iadesi',
		);
		return isset( $m[ $source ] ) ? $m[ $source ] : $source;
	}

	private function reason_select( $name, $selected, $admin = true ) {
		$reasons = $admin ? WDUA_Reasons::all() : WDUA_Reasons::customer_reasons();
		echo '<select name="' . esc_attr( $name ) . '" class="wdua-reason-select">';
		foreach ( WDUA_Reasons::categories() as $cat => $cat_label ) {
			echo '<optgroup label="' . esc_attr( $cat_label ) . '">';
			foreach ( $reasons as $key => $r ) {
				if ( $r['cat'] !== $cat ) {
					continue;
				}
				printf( '<option value="%s" data-cat="%s" %s>%s</option>', esc_attr( $key ), esc_attr( $r['cat'] ), selected( $selected, $key, false ), esc_html( $r['label'] ) );
			}
			echo '</optgroup>';
		}
		echo '</select>';
	}

	private function fit_select( $name, $selected ) {
		echo '<select name="' . esc_attr( $name ) . '" class="wdua-fit-select">';
		echo '<option value="">Nedenden otomatik</option>';
		foreach ( WDUA_Reasons::fit_labels() as $val => $label ) {
			if ( 0 === $val ) {
				continue;
			}
			printf( '<option value="%d" %s>%s</option>', (int) $val, ( null !== $selected && '' !== $selected && (int) $selected === $val ) ? 'selected' : '', esc_html( $label ) );
		}
		echo '</select>';
	}

	private function status_select( $name, $selected ) {
		echo '<select name="' . esc_attr( $name ) . '">';
		foreach ( WDUA_Reasons::statuses() as $key => $label ) {
			printf( '<option value="%s" %s>%s</option>', esc_attr( $key ), selected( $selected, $key, false ), esc_html( $label ) );
		}
		echo '</select>';
	}

	private function render_edit( $id ) {
		$r = WDUA_Repo::get_return( $id );
		if ( ! $r ) {
			$this->header( 'İade kaydı' );
			echo '<p>Kayıt bulunamadı.</p>';
			$this->footer();
			return;
		}
		$order = wc_get_order( $r['order_id'] );
		$item  = $order ? $order->get_item( $r['order_item_id'] ) : null;

		$this->header( 'İade #' . $r['request_key'], '<a href="' . esc_url( self::url( 'wdua-returns' ) ) . '" class="page-title-action">← Listeye dön</a>' );
		?>
		<div class="wdua-edit">
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wdua-panel wdua-edit__main">
				<input type="hidden" name="action" value="wdua_save_return">
				<input type="hidden" name="id" value="<?php echo (int) $r['id']; ?>">
				<?php wp_nonce_field( 'wdua_save_return_' . $r['id'] ); ?>

				<h2>İade bilgileri</h2>
				<table class="form-table wdua-form">
					<tr><th>Durum</th><td><?php $this->status_select( 'status', $r['status'] ); ?>
						<label class="wdua-inline"><input type="checkbox" name="notify" value="1" <?php checked( WDUA_Settings::yes( 'notify_customer' ) ); ?>> Durum değişirse müşteriye e-posta gönder</label></td></tr>
					<tr><th>İade nedeni</th><td><?php $this->reason_select( 'reason', $r['reason'] ); ?></td></tr>
					<tr class="wdua-fit-row"><th>Kalıp</th><td><?php $this->fit_select( 'fit', $r['fit'] ); ?><p class="description">Yalnızca beden/kalıp nedenlerinde istatistiğe girer.</p></td></tr>
					<tr><th>Adet</th><td><input type="number" name="qty" min="1" max="<?php echo $item ? (int) $item->get_quantity() : 99; ?>" value="<?php echo (int) $r['qty']; ?>" class="small-text"></td></tr>
				</table>

				<h2>Maliyet</h2>
				<p class="description">Varsayılanlar ayarlardan hesaplandı; gerçek tutarlarla güncelleyebilirsiniz. Ürün değeri: <strong><?php echo self::money( $r['item_value'] ); // phpcs:ignore ?></strong></p>
				<table class="form-table wdua-form wdua-costs">
					<?php
					$fields = array(
						'cost_ship_out'     => 'Gidiş kargosu (kayıp)',
						'cost_ship_back'    => 'Dönüş kargosu',
						'cost_labor'        => 'İşçilik',
						'cost_depreciation' => 'Değer kaybı',
					);
					foreach ( $fields as $key => $label ) :
						?>
						<tr><th><?php echo esc_html( $label ); ?></th><td><?php echo self::money_input( '<input type="number" step="0.01" min="0" name="' . esc_attr( $key ) . '" value="' . esc_attr( $r[ $key ] ) . '" class="regular-text wdua-cost-input">' ); // phpcs:ignore ?></td></tr>
					<?php endforeach; ?>
					<tr class="wdua-total"><th>Toplam</th><td><strong class="wdua-cost-total"><?php echo self::money( $r['cost_total'] ); // phpcs:ignore ?></strong></td></tr>
				</table>

				<h2>Notlar</h2>
				<table class="form-table wdua-form">
					<tr><th>Yönetici notu</th><td><textarea name="admin_note" rows="3" class="large-text"><?php echo esc_textarea( (string) $r['admin_note'] ); ?></textarea></td></tr>
					<tr><th>Müşteriye mesaj</th><td><textarea name="customer_message" rows="2" class="large-text" placeholder="Durum e-postasına eklenir (isteğe bağlı)"></textarea></td></tr>
				</table>

				<p><button class="button button-primary button-large">Kaydet</button></p>
			</form>

			<aside class="wdua-edit__side">
				<div class="wdua-panel">
					<h2>Özet</h2>
					<dl class="wdua-dl">
						<dt>Sipariş</dt><dd><?php echo $order ? '<a href="' . esc_url( $order->get_edit_order_url() ) . '">#' . esc_html( $order->get_order_number() ) . '</a>' : '#' . (int) $r['order_id']; ?></dd>
						<dt>Müşteri</dt><dd><?php echo $order ? esc_html( $order->get_formatted_billing_full_name() ) . '<br><span class="wdua-dim">' . esc_html( $order->get_billing_email() ) . '</span>' : '—'; ?></dd>
						<dt>Ürün</dt><dd><?php echo self::product_link( $r['product_id'] ); // phpcs:ignore ?></dd>
						<dt>Beden</dt><dd><?php echo esc_html( $r['size_label'] ? $r['size_label'] : '—' ); ?></dd>
						<dt>Kaynak</dt><dd><?php echo esc_html( self::source_label( $r['source'] ) ); ?></dd>
						<dt>Oluşturulma</dt><dd><?php echo esc_html( get_date_from_gmt( $r['created_at'], 'd.m.Y H:i' ) ); ?></dd>
					</dl>
					<?php if ( $r['customer_note'] ) : ?>
						<h3>Müşteri açıklaması</h3>
						<blockquote class="wdua-quote"><?php echo nl2br( esc_html( $r['customer_note'] ) ); ?></blockquote>
					<?php endif; ?>
				</div>

				<?php if ( $item && ! in_array( $r['status'], array( 'refunded', 'rejected', 'exchanged' ), true ) ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wdua-panel" data-confirm="WooCommerce'te iade kaydı oluşturulacak ve ürün stoğa eklenecek. Ödeme sağlayıcısına otomatik para iadesi GÖNDERİLMEZ. Devam edilsin mi?">
						<input type="hidden" name="action" value="wdua_refund_return">
						<input type="hidden" name="id" value="<?php echo (int) $r['id']; ?>">
						<?php wp_nonce_field( 'wdua_refund_return_' . $r['id'] ); ?>
						<h2>İadeyi tamamla</h2>
						<p class="description">WooCommerce'te <?php echo (int) $r['qty']; ?> adet için iade kaydı oluşturur, stoğu günceller ve durumu "İade tamamlandı" yapar. Parayı ödeme panelinizden ayrıca iade edin.</p>
						<button class="button">WooCommerce iadesi oluştur</button>
					</form>
				<?php endif; ?>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wdua-panel wdua-danger" data-confirm="Bu iade kaydı kalıcı olarak silinecek. Emin misiniz?">
					<input type="hidden" name="action" value="wdua_delete_return">
					<input type="hidden" name="id" value="<?php echo (int) $r['id']; ?>">
					<?php wp_nonce_field( 'wdua_delete_return_' . $r['id'] ); ?>
					<button class="button-link wdua-delete">Kaydı sil</button>
				</form>
			</aside>
		</div>
		<?php
		$this->footer();
	}

	private function render_new( $order_id ) {
		$this->header( 'Yeni iade kaydı', '<a href="' . esc_url( self::url( 'wdua-returns' ) ) . '" class="page-title-action">← Listeye dön</a>' );
		$order = $order_id ? wc_get_order( $order_id ) : null;

		if ( ! $order || $order instanceof WC_Order_Refund ) {
			?>
			<form method="get" class="wdua-panel wdua-narrow">
				<input type="hidden" name="page" value="wdua-returns">
				<input type="hidden" name="action" value="new">
				<h2>Sipariş seçin</h2>
				<p class="description">Telefonla, kargoyla veya mağazada gelen iadeleri kayda geçirin; kalıp istatistiği ve maliyet raporu bu kayıtları da kullanır.</p>
				<?php if ( $order_id ) : ?>
					<p class="wdua-error">#<?php echo (int) $order_id; ?> numaralı sipariş bulunamadı.</p>
				<?php endif; ?>
				<p><input type="number" name="order_id" min="1" placeholder="Sipariş ID" class="regular-text" required> <button class="button button-primary">Devam</button></p>
			</form>
			<?php
			$this->footer();
			return;
		}

		$items = WDUA_Service::returnable_items( $order );
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wdua-panel wdua-narrow">
			<input type="hidden" name="action" value="wdua_new_return">
			<input type="hidden" name="order_id" value="<?php echo (int) $order->get_id(); ?>">
			<?php wp_nonce_field( 'wdua_new_return' ); ?>
			<h2>Sipariş <a href="<?php echo esc_url( $order->get_edit_order_url() ); ?>">#<?php echo esc_html( $order->get_order_number() ); ?></a> – <?php echo esc_html( $order->get_formatted_billing_full_name() ); ?></h2>
			<?php if ( ! $items ) : ?>
				<p class="wdua-empty">Bu siparişte iade edilebilir adet kalmamış.</p>
			<?php else : ?>
				<table class="form-table wdua-form">
					<tr><th>Ürün</th><td><select name="item_id" required>
						<?php
						foreach ( $items as $item_id => $d ) :
							$size = WDUA_Service::detect_size( $d['item'] );
							?>
							<option value="<?php echo (int) $item_id; ?>"><?php echo esc_html( $d['item']->get_name() . ( $size['label'] ? ' – ' . $size['label'] : '' ) . ' (en fazla ' . $d['remaining'] . ')' ); ?></option>
						<?php endforeach; ?>
					</select></td></tr>
					<tr><th>Adet</th><td><input type="number" name="qty" value="1" min="1" class="small-text"></td></tr>
					<tr><th>İade nedeni</th><td><?php $this->reason_select( 'reason', 'dar_geldi' ); ?></td></tr>
					<tr class="wdua-fit-row"><th>Kalıp</th><td><?php $this->fit_select( 'fit', '' ); ?></td></tr>
					<tr><th>Durum</th><td><?php $this->status_select( 'status', 'received' ); ?></td></tr>
					<tr><th>Not</th><td><textarea name="admin_note" rows="2" class="large-text"></textarea></td></tr>
				</table>
				<p><button class="button button-primary button-large">Kaydı oluştur</button></p>
			<?php endif; ?>
		</form>
		<?php
		$this->footer();
	}

	/* ---------------- İade talebi işleyicileri ---------------- */

	public function handle_save_return() {
		self::check();
		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		check_admin_referer( 'wdua_save_return_' . $id );

		$in = array();
		foreach ( array( 'status', 'reason' ) as $k ) {
			if ( isset( $_POST[ $k ] ) ) {
				$in[ $k ] = sanitize_key( wp_unslash( $_POST[ $k ] ) );
			}
		}
		$in['fit'] = ( isset( $_POST['fit'] ) && '' !== $_POST['fit'] ) ? (int) $_POST['fit'] : null;
		foreach ( array( 'qty', 'cost_ship_out', 'cost_ship_back', 'cost_labor', 'cost_depreciation', 'admin_note', 'customer_message' ) as $k ) {
			if ( isset( $_POST[ $k ] ) ) {
				$in[ $k ] = wp_unslash( $_POST[ $k ] ); // phpcs:ignore -- Service içinde temizlenir.
			}
		}

		$res  = WDUA_Service::update_return( $id, $in, ! empty( $_POST['notify'] ) );
		$args = array(
			'action' => 'edit',
			'id'     => $id,
		);
		if ( is_wp_error( $res ) ) {
			$args['wdua_error'] = rawurlencode( $res->get_error_message() );
		} else {
			$args['wdua_notice'] = 'saved';
		}
		self::redirect( 'wdua-returns', $args );
	}

	public function handle_new_return() {
		self::check();
		check_admin_referer( 'wdua_new_return' );
		$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		$order    = wc_get_order( $order_id );
		if ( ! $order ) {
			self::redirect( 'wdua-returns', array( 'action' => 'new', 'wdua_error' => rawurlencode( 'Sipariş bulunamadı.' ) ) );
		}
		$res = WDUA_Service::create_admin_return(
			$order,
			isset( $_POST['item_id'] ) ? absint( $_POST['item_id'] ) : 0,
			isset( $_POST['qty'] ) ? absint( $_POST['qty'] ) : 1,
			isset( $_POST['reason'] ) ? sanitize_key( wp_unslash( $_POST['reason'] ) ) : '',
			( isset( $_POST['fit'] ) && '' !== $_POST['fit'] ) ? (int) $_POST['fit'] : null,
			isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : 'received',
			isset( $_POST['admin_note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['admin_note'] ) ) : ''
		);
		if ( is_wp_error( $res ) ) {
			self::redirect(
				'wdua-returns',
				array(
					'action'     => 'new',
					'order_id'   => $order_id,
					'wdua_error' => rawurlencode( $res->get_error_message() ),
				)
			);
		}
		self::redirect(
			'wdua-returns',
			array(
				'action'      => 'edit',
				'id'          => $res,
				'wdua_notice' => 'created',
			)
		);
	}

	public function handle_delete_return() {
		self::check();
		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		check_admin_referer( 'wdua_delete_return_' . $id );
		WDUA_Service::delete_return( $id );
		self::redirect( 'wdua-returns', array( 'wdua_notice' => 'deleted' ) );
	}

	public function handle_refund_return() {
		self::check();
		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		check_admin_referer( 'wdua_refund_return_' . $id );
		$res  = WDUA_Service::create_wc_refund( $id );
		$args = array(
			'action' => 'edit',
			'id'     => $id,
		);
		if ( is_wp_error( $res ) ) {
			$args['wdua_error'] = rawurlencode( $res->get_error_message() );
		} else {
			$args['wdua_notice'] = 'refunded';
		}
		self::redirect( 'wdua-returns', $args );
	}

	/* ====================================================================
	 * Maliyet raporu
	 * ================================================================== */

	public function page_costs() {
		self::check();
		$period = self::current_period();
		$rows   = WDUA_Repo::cost_report( self::period_since( $period ), 200 );

		$export = wp_nonce_url(
			add_query_arg(
				array(
					'action' => 'wdua_export',
					'period' => $period,
				),
				admin_url( 'admin-post.php' )
			),
			'wdua_export'
		);

		$this->header( 'Maliyet Raporu', '<a href="' . esc_url( $export ) . '" class="page-title-action">CSV indir</a>' );
		echo '<p class="description wdua-lead">İadesi en pahalı ürünler: gidiş kargosu (kaybedilen), dönüş kargosu, işçilik ve değer kaybı toplamına göre sıralanır. Yalnızca onaylanan, teslim alınan ve tamamlanan iadeler dahildir.</p>';
		$this->period_tabs( 'wdua-costs', $period );
		$this->cost_table( $rows, false );
		$this->footer();
	}

	private function cost_table( $rows, $compact ) {
		if ( ! $rows ) {
			echo '<p class="wdua-empty">Bu dönemde maliyet oluşturan iade yok.</p>';
			return;
		}
		$max = max( array_map( 'floatval', wp_list_pluck( $rows, 'total' ) ) );
		?>
		<table class="widefat striped wdua-table wdua-cost-table">
			<thead>
			<tr>
				<th>Ürün</th>
				<th class="num">İade adedi</th>
				<?php if ( ! $compact ) : ?>
					<th class="num">Satış</th>
				<?php endif; ?>
				<th class="num">İade oranı</th>
				<?php if ( ! $compact ) : ?>
					<th class="num">Kargo</th><th class="num">İşçilik</th><th class="num">Değer kaybı</th>
				<?php endif; ?>
				<th class="num">Toplam maliyet</th>
				<th class="num">İade başına</th>
				<th>En sık neden</th>
				<th>Beden payı</th>
			</tr>
			</thead>
			<tbody>
			<?php
			foreach ( $rows as $r ) :
				$w = $max > 0 ? 100 * (float) $r['total'] / $max : 0;
				?>
				<tr>
					<td><?php echo self::product_link( $r['product_id'] ); // phpcs:ignore ?></td>
					<td class="num"><?php echo esc_html( number_format_i18n( (int) $r['qty'] ) ); ?></td>
					<?php if ( ! $compact ) : ?>
						<td class="num"><?php echo null === $r['sold'] ? '—' : esc_html( number_format_i18n( $r['sold'] ) ); ?></td>
					<?php endif; ?>
					<td class="num"><?php echo esc_html( self::pct( $r['return_rate'] ) ); ?></td>
					<?php if ( ! $compact ) : ?>
						<td class="num"><?php echo self::money( (float) $r['ship_out'] + (float) $r['ship_back'] ); // phpcs:ignore ?></td>
						<td class="num"><?php echo self::money( $r['labor'] ); // phpcs:ignore ?></td>
						<td class="num"><?php echo self::money( $r['dep'] ); // phpcs:ignore ?></td>
					<?php endif; ?>
					<td class="num wdua-total-cell"><span class="wdua-inbar" style="width:<?php echo esc_attr( round( $w, 1 ) ); ?>%"></span><strong><?php echo self::money( $r['total'] ); // phpcs:ignore ?></strong></td>
					<td class="num"><?php echo self::money( (float) $r['total'] / max( 1, (int) $r['cnt'] ) ); // phpcs:ignore ?></td>
					<td><?php echo esc_html( WDUA_Reasons::label( $r['top_reason'] ) ); ?></td>
					<td><?php echo esc_html( self::pct( (int) $r['qty'] ? (int) $r['fit_qty'] / (int) $r['qty'] : 0, 0 ) ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	public function handle_export() {
		self::check();
		check_admin_referer( 'wdua_export' );
		$period = self::current_period();
		$rows   = WDUA_Repo::cost_report( self::period_since( $period ), 100000 );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=iade-maliyet-raporu-' . gmdate( 'Y-m-d' ) . '.csv' );

		$out = fopen( 'php://output', 'w' );
		fwrite( $out, "\xEF\xBB\xBF" ); // Excel için UTF-8 BOM.
		fputcsv( $out, array( 'Ürün ID', 'Ürün', 'SKU', 'İade adedi', 'Satış adedi', 'İade oranı (%)', 'Gidiş kargo', 'Dönüş kargo', 'İşçilik', 'Değer kaybı', 'Toplam maliyet', 'İade başına maliyet', 'En sık neden', 'Beden kaynaklı pay (%)' ), ';', '"', '\\' );
		foreach ( $rows as $r ) {
			$p = wc_get_product( $r['product_id'] );
			fputcsv(
				$out,
				array(
					$r['product_id'],
					$p ? $p->get_name() : '#' . $r['product_id'],
					$p ? $p->get_sku() : '',
					(int) $r['qty'],
					null === $r['sold'] ? '' : (int) $r['sold'],
					null === $r['return_rate'] ? '' : self::csv_num( $r['return_rate'] * 100 ),
					self::csv_num( $r['ship_out'] ),
					self::csv_num( $r['ship_back'] ),
					self::csv_num( $r['labor'] ),
					self::csv_num( $r['dep'] ),
					self::csv_num( $r['total'] ),
					self::csv_num( (float) $r['total'] / max( 1, (int) $r['cnt'] ) ),
					WDUA_Reasons::label( $r['top_reason'] ),
					(int) $r['qty'] ? self::csv_num( 100 * (int) $r['fit_qty'] / (int) $r['qty'], 1 ) : '0',
				),
				';',
				'"',
				'\\'
			);
		}
		fclose( $out );
		exit;
	}

	/* ====================================================================
	 * Ürün uyum analizi
	 * ================================================================== */

	public function page_fit() {
		self::check();
		$s    = WDUA_Settings::all();
		$ids  = WDUA_Repo::products_with_signals();
		$rows = array();
		foreach ( $ids as $pid ) {
			$st = WDUA_Stats::get( $pid );
			if ( $st ) {
				$rows[ $pid ] = $st;
			}
		}
		uasort(
			$rows,
			function ( $a, $b ) {
				return (int) $b['overall']['count'] - (int) $a['overall']['count'];
			}
		);

		$rebuild = '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline">' . wp_nonce_field( 'wdua_rebuild', '_wpnonce', true, false ) . '<input type="hidden" name="action" value="wdua_rebuild"><button class="page-title-action">Yeniden hesapla</button></form>';
		$this->header( 'Ürün Uyum Analizi', $rebuild );
		printf(
			'<p class="description wdua-lead">Ürün sayfasında rozet için en az <strong>%d</strong> veri ve <strong>%%%d</strong> güven alt sınırı gerekir. Veriler %d günlük yarı ömürle ağırlıklandırılır. <a href="%s">Ayarları değiştir</a></p>',
			(int) $s['min_samples'],
			(int) $s['confidence'],
			(int) $s['half_life_days'],
			esc_url( self::url( 'wdua-settings' ) )
		);

		if ( ! $rows ) {
			echo '<p class="wdua-empty">Henüz kalıp verisi yok. Müşteri iade talepleri ve "kalıp nasıldı?" geri bildirimleri geldikçe burada görünecek.</p>';
			$this->footer();
			return;
		}
		?>
		<table class="widefat striped wdua-table">
			<thead>
			<tr>
				<th>Ürün</th><th class="num">Veri</th><th class="num">Dar</th><th class="num">Tam</th><th class="num">Bol</th><th>Kalıp</th><th>Ürün sayfasında</th><th>Bedenlere göre</th><th>Öneri</th>
			</tr>
			</thead>
			<tbody>
			<?php
			foreach ( $rows as $pid => $st ) :
				$o   = $st['overall'];
				$v   = WDUA_Stats::verdict( $o, $s );
				$msg = WDUA_Stats::message( $v, $o['count'], $s );
				$man = trim( (string) get_post_meta( $pid, '_wdua_manual_note', true ) );
				$hid = 'yes' === get_post_meta( $pid, '_wdua_hide_badge', true );
				?>
				<tr>
					<td><?php echo self::product_link( $pid ); // phpcs:ignore ?><br><span class="wdua-dim"><?php echo (int) $st['returns']; ?> iade kaydı</span></td>
					<td class="num"><?php echo (int) $o['count']; ?></td>
					<td class="num"><?php echo esc_html( self::pct( $o['p_small'], 0 ) ); ?></td>
					<td class="num"><?php echo esc_html( self::pct( $o['p_true'], 0 ) ); ?></td>
					<td class="num"><?php echo esc_html( self::pct( $o['p_large'], 0 ) ); ?></td>
					<td><?php echo self::scale_html( $o ); // phpcs:ignore ?></td>
					<td>
						<?php
						if ( $hid ) {
							echo '<span class="wdua-dim">Rozet gizli</span>';
						} elseif ( $man ) {
							echo '<em>' . esc_html( $man ) . '</em> <span class="wdua-dim">(manuel)</span>';
						} elseif ( $msg ) {
							echo '<span class="wdua-verdict wdua-verdict--' . esc_attr( $v['type'] ) . '">' . esc_html( $msg ) . '</span>';
						} elseif ( (int) $o['count'] < (int) $s['min_samples'] ) {
							echo '<span class="wdua-dim">Yetersiz veri (' . (int) $o['count'] . '/' . (int) $s['min_samples'] . ')</span>';
						} else {
							echo '<span class="wdua-dim">Karışık sinyal – gösterilmiyor</span>';
						}
						?>
					</td>
					<td>
						<?php
						if ( ! empty( $st['by_size'] ) ) {
							echo '<div class="wdua-sizes">';
							foreach ( $st['by_size'] as $agg ) {
								$sv = WDUA_Stats::verdict( $agg, $s );
								printf(
									'<span class="wdua-size wdua-size--%s" title="%d veri · ort. %s">%s</span>',
									esc_attr( $sv['type'] ),
									(int) $agg['count'],
									esc_attr( number_format_i18n( $agg['mean'], 2 ) ),
									esc_html( $agg['label'] )
								);
							}
							echo '</div>';
						} else {
							echo '<span class="wdua-dim">—</span>';
						}
						?>
					</td>
					<td class="wdua-recs">
						<?php
						$recs = WDUA_Stats::recommendations( $st, $s );
						echo $recs ? '<ul><li>' . implode( '</li><li>', array_map( 'esc_html', $recs ) ) . '</li></ul>' : '<span class="wdua-dim">—</span>';
						?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<p class="description">Beden etiketleri: <span class="wdua-size wdua-size--small">dar</span> <span class="wdua-size wdua-size--true">tam</span> <span class="wdua-size wdua-size--large">bol</span> <span class="wdua-size wdua-size--none">belirsiz</span></p>
		<?php
		$this->footer();
	}

	public function handle_rebuild() {
		self::check();
		check_admin_referer( 'wdua_rebuild' );
		WDUA_Stats::rebuild_all();
		self::redirect( 'wdua-fit', array( 'wdua_notice' => 'rebuilt' ) );
	}

	/* ====================================================================
	 * Ayarlar
	 * ================================================================== */

	private static function settings_schema() {
		return array(
			'Ürün sayfası rozeti' => array(
				'badge_enabled'        => array( 'checkbox', 'Kalıp bilgisini ürün sayfasında göster' ),
				'badge_position'       => array(
					'select',
					'Konum',
					array(
						'after_price'        => 'Fiyatın altında',
						'before_add_to_cart' => 'Sepete ekle formunun üstünde',
						'after_add_to_cart'  => 'Sepete ekle butonunun altında',
						'shortcode'          => 'Yalnızca kısa kod ile [wdua_kalip]',
					),
				),
				'show_scale'           => array( 'checkbox', 'Dar–Bol ölçeğini göster' ),
				'show_true_badge'      => array( 'checkbox', '"Tam kalıp" sonucunu da göster' ),
				'show_color_hint'      => array( 'checkbox', 'Renk farkı uyarısını göster (iadelerde renk şikâyeti yüksekse)' ),
				'color_hint_threshold' => array( 'number', 'Renk uyarısı eşiği (%)', array( 'min' => 1, 'max' => 100 ) ),
			),
			'Hesaplama'           => array(
				'min_samples'     => array( 'number', 'Rozet için en az veri sayısı', array( 'min' => 1, 'max' => 1000 ), 'Bu sayının altındaki ürünlerde rozet gösterilmez.' ),
				'confidence'      => array( 'number', 'Güven alt sınırı (%)', array( 'min' => 0, 'max' => 100 ), 'Wilson güven aralığının alt sınırı bu değeri geçerse sonuç gösterilir. Yükseltirseniz daha çok veri gerekir. Önerilen: 35–50.' ),
				'half_life_days'  => array( 'number', 'Veri yarı ömrü (gün)', array( 'min' => 0, 'max' => 3650 ), 'X gün önceki veri, bugünkünün yarısı kadar etkili sayılır. Tedarikçi kalıbı değiştiğinde eski veri kendiliğinden söner. 0 = zaman ağırlığı yok.' ),
				'size_attributes' => array( 'text', 'Beden nitelikleri', array(), 'Virgülle ayırın. Örn: pa_beden, beden, pa_numara' ),
			),
			'İade talepleri'      => array(
				'returns_enabled'    => array( 'checkbox', 'Müşteriler Hesabım > Siparişler sayfasından iade talebi oluşturabilsin' ),
				'return_window_days' => array( 'number', 'İade talep süresi (gün)', array( 'min' => 0, 'max' => 365 ), 'Sipariş tamamlanma tarihinden itibaren. 0 = sınırsız.' ),
				'return_statuses'    => array( 'statuses', 'İade/geri bildirime açık sipariş durumları' ),
				'notify_admin'       => array( 'checkbox', 'Yeni talepte yöneticiye e-posta gönder' ),
				'notify_customer'    => array( 'checkbox', 'Durum değişince müşteriye bildirim gönder (sipariş notu e-postası)' ),
				'track_refunds'      => array( 'checkbox', 'WooCommerce\'te elle yapılan iadeleri de otomatik kayda geçir' ),
			),
			'Kalıp geri bildirimi' => array(
				'feedback_enabled'    => array( 'checkbox', 'İade etmeyen alıcılardan "kalıp nasıldı?" geri bildirimi topla', array(), 'İstatistiği dengeler: yalnızca iade verisi, memnun alıcıları görmez.' ),
				'feedback_email'      => array( 'checkbox', 'Geri bildirim isteği e-postası gönder' ),
				'feedback_delay_days' => array( 'number', 'E-posta gecikmesi (gün)', array( 'min' => 1, 'max' => 60 ), 'Sipariş tamamlandıktan kaç gün sonra gönderilsin.' ),
			),
			'Maliyet varsayılanları' => array(
				'cost_ship_out'      => array( 'money', 'Gidiş kargo ücreti (gönderi başı)' ),
				'ship_out_lost'      => array( 'checkbox', 'Gidiş kargosunu iade maliyetine dahil et (adede oranlanır)' ),
				'cost_ship_back'     => array( 'money', 'Dönüş kargo ücreti (iade gönderisi başı)' ),
				'cost_labor'         => array( 'money', 'İşçilik (adet başı: kontrol, ütü, yeniden paketleme)' ),
				'exchange_ship_cost' => array( 'checkbox', 'Değişimde yeni ürünün gönderim kargosunu otomatik ekle' ),
				'dep_fit'            => array( 'number', 'Değer kaybı – beden/kalıp (%)', array( 'min' => 0, 'max' => 100 ) ),
				'dep_color'          => array( 'number', 'Değer kaybı – renk (%)', array( 'min' => 0, 'max' => 100 ) ),
				'dep_quality'        => array( 'number', 'Değer kaybı – kalite (%)', array( 'min' => 0, 'max' => 100 ) ),
				'dep_defect'         => array( 'number', 'Değer kaybı – kusurlu/hasarlı (%)', array( 'min' => 0, 'max' => 100 ) ),
				'dep_wrong'          => array( 'number', 'Değer kaybı – yanlış gönderim (%)', array( 'min' => 0, 'max' => 100 ) ),
				'dep_other'          => array( 'number', 'Değer kaybı – diğer (%)', array( 'min' => 0, 'max' => 100 ) ),
			),
			'Metinler'            => array(
				'text_title'  => array( 'text', 'Rozet başlığı' ),
				'text_small'  => array( 'text', 'Dar kalıp mesajı', array(), '{pct} yüzdeyi Türkçe ekiyle yazar (62\'si, 40\'ı, 100\'ü).' ),
				'text_large'  => array( 'text', 'Bol kalıp mesajı' ),
				'text_true'   => array( 'text', 'Tam kalıp mesajı' ),
				'text_color'  => array( 'text', 'Renk uyarısı' ),
				'text_source' => array( 'text', 'Kaynak satırı', array(), '{n} veri sayısıdır.' ),
			),
			'Gelişmiş'            => array(
				'delete_on_uninstall' => array( 'checkbox', 'Eklenti silinirken tüm verileri (tablolar, ayarlar) sil' ),
			),
		);
	}

	public function page_settings() {
		self::check();
		$s = WDUA_Settings::all();
		$this->header( 'Ayarlar' );
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wdua-settings">
			<input type="hidden" name="action" value="wdua_save_settings">
			<?php wp_nonce_field( 'wdua_save_settings' ); ?>
			<?php foreach ( self::settings_schema() as $section => $fields ) : ?>
				<div class="wdua-panel">
					<h2><?php echo esc_html( $section ); ?></h2>
					<table class="form-table">
						<?php foreach ( $fields as $key => $f ) : ?>
							<tr>
								<th scope="row"><label for="wdua_<?php echo esc_attr( $key ); ?>"><?php echo 'checkbox' === $f[0] ? '' : esc_html( $f[1] ); ?></label></th>
								<td><?php $this->field( $key, $f, $s[ $key ] ); ?></td>
							</tr>
						<?php endforeach; ?>
					</table>
				</div>
			<?php endforeach; ?>
			<div class="wdua-panel">
				<h2>Kısa kod</h2>
				<p><code>[wdua_kalip]</code> ürün sayfasında, <code>[wdua_kalip id="123"]</code> herhangi bir yerde kalıp rozetini gösterir. Ürün düzenleme ekranından ürün bazında rozet gizlenebilir veya manuel kalıp notu yazılabilir.</p>
			</div>
			<?php submit_button( 'Ayarları kaydet' ); ?>
		</form>
		<?php
		$this->footer();
	}

	private function field( $key, array $f, $value ) {
		$name  = 'wdua[' . $key . ']';
		$id    = 'wdua_' . $key;
		$attrs = isset( $f[2] ) && is_array( $f[2] ) ? $f[2] : array();
		$desc  = isset( $f[3] ) ? $f[3] : '';

		switch ( $f[0] ) {
			case 'checkbox':
				printf( '<label><input type="checkbox" id="%s" name="%s" value="yes" %s> %s</label>', esc_attr( $id ), esc_attr( $name ), checked( 'yes', $value, false ), esc_html( $f[1] ) );
				if ( isset( $f[3] ) ) {
					$desc = $f[3];
				}
				break;
			case 'select':
				echo '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '">';
				foreach ( $f[2] as $k => $l ) {
					printf( '<option value="%s" %s>%s</option>', esc_attr( $k ), selected( $value, $k, false ), esc_html( $l ) );
				}
				echo '</select>';
				$desc = '';
				break;
			case 'number':
				printf(
					'<input type="number" id="%s" name="%s" value="%s" class="small-text" min="%s" max="%s" step="1">',
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr( $value ),
					esc_attr( isset( $attrs['min'] ) ? $attrs['min'] : 0 ),
					esc_attr( isset( $attrs['max'] ) ? $attrs['max'] : '' )
				);
				break;
			case 'money':
				echo self::money_input( sprintf( '<input type="number" id="%s" name="%s" value="%s" class="small-text" min="0" step="0.01">', esc_attr( $id ), esc_attr( $name ), esc_attr( $value ) ) ); // phpcs:ignore
				break;
			case 'statuses':
				echo '<fieldset class="wdua-checks">';
				foreach ( wc_get_order_statuses() as $k => $l ) {
					if ( in_array( $k, array( 'wc-pending', 'wc-failed', 'wc-cancelled', 'wc-refunded', 'wc-checkout-draft' ), true ) ) {
						continue;
					}
					printf( '<label><input type="checkbox" name="%s[]" value="%s" %s> %s</label>', esc_attr( $name ), esc_attr( $k ), checked( in_array( $k, (array) $value, true ), true, false ), esc_html( $l ) );
				}
				echo '</fieldset>';
				break;
			default:
				printf( '<input type="text" id="%s" name="%s" value="%s" class="large-text">', esc_attr( $id ), esc_attr( $name ), esc_attr( $value ) );
		}
		if ( $desc ) {
			echo '<p class="description">' . esc_html( $desc ) . '</p>';
		}
	}

	public function handle_save_settings() {
		self::check();
		check_admin_referer( 'wdua_save_settings' );
		$in       = isset( $_POST['wdua'] ) && is_array( $_POST['wdua'] ) ? wp_unslash( $_POST['wdua'] ) : array(); // phpcs:ignore
		$defaults = WDUA_Settings::defaults();
		$out      = array();

		foreach ( self::settings_schema() as $fields ) {
			foreach ( $fields as $key => $f ) {
				$v = isset( $in[ $key ] ) ? $in[ $key ] : null;
				switch ( $f[0] ) {
					case 'checkbox':
						$out[ $key ] = 'yes' === $v ? 'yes' : 'no';
						break;
					case 'select':
						$out[ $key ] = ( is_string( $v ) && isset( $f[2][ $v ] ) ) ? $v : $defaults[ $key ];
						break;
					case 'number':
						$min         = isset( $f[2]['min'] ) ? $f[2]['min'] : 0;
						$max         = isset( $f[2]['max'] ) ? $f[2]['max'] : PHP_INT_MAX;
						$out[ $key ] = ( null === $v || '' === $v ) ? $defaults[ $key ] : max( $min, min( $max, (int) $v ) );
						break;
					case 'money':
						$out[ $key ] = ( null === $v || '' === $v ) ? $defaults[ $key ] : max( 0, round( (float) wc_format_decimal( $v ), 2 ) );
						break;
					case 'statuses':
						$valid       = array_keys( wc_get_order_statuses() );
						$out[ $key ] = array_values( array_intersect( array_map( 'sanitize_text_field', (array) $v ), $valid ) );
						break;
					default:
						$out[ $key ] = sanitize_text_field( (string) $v );
						if ( '' === $out[ $key ] ) {
							$out[ $key ] = $defaults[ $key ];
						}
				}
			}
		}

		WDUA_Settings::save( $out );
		WDUA_Stats::rebuild_all(); // Beden nitelikleri / yarı ömür değişmiş olabilir.
		self::redirect( 'wdua-settings', array( 'wdua_notice' => 'saved' ) );
	}

	/* ====================================================================
	 * Sipariş ve ürün düzenleme kutuları
	 * ================================================================== */

	public function meta_boxes() {
		$order_screen = 'shop_order';
		if ( class_exists( '\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController' ) && function_exists( 'wc_get_container' ) ) {
			try {
				$ctrl = wc_get_container()->get( \Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class );
				if ( $ctrl->custom_orders_table_usage_is_enabled() ) {
					$order_screen = wc_get_page_screen_id( 'shop-order' );
				}
			} catch ( \Exception $e ) { // phpcs:ignore
				// HPOS kapalı.
			}
		}
		add_meta_box( 'wdua_order', 'Uyum Asistanı – İadeler', array( $this, 'order_box' ), $order_screen, 'side', 'default' );
		add_meta_box( 'wdua_product', 'Kalıp Bilgisi (Uyum Asistanı)', array( $this, 'product_box' ), 'product', 'side', 'default' );
	}

	public function order_box( $post_or_order ) {
		$order = $post_or_order instanceof WP_Post ? wc_get_order( $post_or_order->ID ) : $post_or_order;
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		$rows = WDUA_Repo::returns_for_order( $order->get_id() );
		if ( $rows ) {
			echo '<ul class="wdua-box-list">';
			foreach ( $rows as $r ) {
				$item = $order->get_item( $r['order_item_id'] );
				printf(
					'<li><a href="%s"><strong>#%s</strong></a> %s × %d<br><span class="wdua-status wdua-status--%s">%s</span> <span class="wdua-dim">%s</span></li>',
					esc_url(
						self::url(
							'wdua-returns',
							array(
								'action' => 'edit',
								'id'     => $r['id'],
							)
						)
					),
					esc_html( $r['request_key'] ),
					esc_html( $item ? $item->get_name() : '—' ),
					(int) $r['qty'],
					esc_attr( $r['status'] ),
					esc_html( WDUA_Reasons::status_label( $r['status'] ) ),
					esc_html( WDUA_Reasons::label( $r['reason'] ) )
				);
			}
			echo '</ul>';
		} else {
			echo '<p class="wdua-dim">Bu sipariş için iade kaydı yok.</p>';
		}
		printf(
			'<p><a class="button" href="%s">İade kaydı ekle</a></p>',
			esc_url(
				self::url(
					'wdua-returns',
					array(
						'action'   => 'new',
						'order_id' => $order->get_id(),
					)
				)
			)
		);
	}

	public function product_box( $post ) {
		$st     = WDUA_Stats::get( $post->ID );
		$s      = WDUA_Settings::all();
		$manual = get_post_meta( $post->ID, '_wdua_manual_note', true );
		$hide   = get_post_meta( $post->ID, '_wdua_hide_badge', true );
		wp_nonce_field( 'wdua_product_meta', '_wdua_product_nonce' );

		if ( $st && ! empty( $st['overall']['count'] ) ) {
			$o   = $st['overall'];
			$v   = WDUA_Stats::verdict( $o, $s );
			$msg = WDUA_Stats::message( $v, $o['count'], $s );
			printf(
				'<p><strong>%d</strong> veri · %d iade kaydı</p><p>Dar %s · Tam %s · Bol %s</p><p>%s</p>',
				(int) $o['count'],
				(int) $st['returns'],
				esc_html( self::pct( $o['p_small'], 0 ) ),
				esc_html( self::pct( $o['p_true'], 0 ) ),
				esc_html( self::pct( $o['p_large'], 0 ) ),
				self::scale_html( $o ) // phpcs:ignore
			);
			echo $msg ? '<p class="wdua-verdict wdua-verdict--' . esc_attr( $v['type'] ) . '">' . esc_html( $msg ) . '</p>' : '<p class="wdua-dim">Ürün sayfasında otomatik mesaj yok (veri yetersiz veya karışık).</p>';
		} else {
			echo '<p class="wdua-dim">Bu ürün için henüz kalıp verisi yok.</p>';
		}
		?>
		<p><label for="wdua_manual_note"><strong>Manuel kalıp notu</strong></label>
			<input type="text" id="wdua_manual_note" name="wdua_manual_note" value="<?php echo esc_attr( $manual ); ?>" class="widefat" placeholder="Örn. Kalıbı dar, bir beden büyük alın.">
			<span class="description">Doluysa otomatik mesajın yerine gösterilir.</span></p>
		<p><label><input type="checkbox" name="wdua_hide_badge" value="yes" <?php checked( 'yes', $hide ); ?>> Bu üründe rozeti gizle</label></p>
		<?php
	}

	public function save_product_meta( $post_id ) {
		if ( ! isset( $_POST['_wdua_product_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wdua_product_nonce'] ) ), 'wdua_product_meta' ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ! current_user_can( 'edit_product', $post_id ) ) {
			return;
		}
		$note = isset( $_POST['wdua_manual_note'] ) ? sanitize_text_field( wp_unslash( $_POST['wdua_manual_note'] ) ) : '';
		if ( '' === $note ) {
			delete_post_meta( $post_id, '_wdua_manual_note' );
		} else {
			update_post_meta( $post_id, '_wdua_manual_note', $note );
		}
		if ( ! empty( $_POST['wdua_hide_badge'] ) ) {
			update_post_meta( $post_id, '_wdua_hide_badge', 'yes' );
		} else {
			delete_post_meta( $post_id, '_wdua_hide_badge' );
		}
	}
}
