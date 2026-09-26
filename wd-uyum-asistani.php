<?php
/**
 * Plugin Name:       WD Uyum Asistanı – İade Verisinden Beden/Kalıp Asistanı
 * Plugin URI:        https://oblifex.com
 * Description:       İade nedenlerini ürün ve beden bazında toplar, gerçek iade + alıcı geri bildirimi verisinden "Alıcıların %62'si bir beden büyük tercih etti" gibi kalıp bilgisi üretir ve iadesi en pahalı ürünleri raporlar.
 * Version:           1.0.0
 * Author:            Web Danışmanı
 * Author URI:        https://webdanismani.com
 * Text Domain:       wd-uyum-asistani
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * WC requires at least: 7.0
 * WC tested up to:   9.9
 * License:           GPLv2 or later
 */

defined( 'ABSPATH' ) || exit;

define( 'WDUA_VERSION', '1.0.0' );
define( 'WDUA_FILE', __FILE__ );
define( 'WDUA_DIR', plugin_dir_path( __FILE__ ) );
define( 'WDUA_URL', plugin_dir_url( __FILE__ ) );
define( 'WDUA_SUPPORT_URL', 'https://oblifex.com' );
define( 'WDUA_REPO_URL', 'https://github.com/webdanismani/wd-uyum-asistani' );

require_once WDUA_DIR . 'includes/class-wdua-settings.php';
require_once WDUA_DIR . 'includes/class-wdua-reasons.php';
require_once WDUA_DIR . 'includes/class-wdua-install.php';
require_once WDUA_DIR . 'includes/class-wdua-repo.php';
require_once WDUA_DIR . 'includes/class-wdua-stats.php';
require_once WDUA_DIR . 'includes/class-wdua-service.php';
require_once WDUA_DIR . 'includes/class-wdua-frontend.php';
require_once WDUA_DIR . 'includes/class-wdua-admin.php';

register_activation_hook( __FILE__, array( 'WDUA_Install', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'WDUA_Install', 'deactivate' ) );

// HPOS (Yüksek performanslı sipariş depolama) uyumluluğu.
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);

add_action(
	'plugins_loaded',
	function () {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action(
				'admin_notices',
				function () {
					echo '<div class="notice notice-error"><p><strong>WD Uyum Asistanı</strong> çalışmak için WooCommerce eklentisine ihtiyaç duyar.</p></div>';
				}
			);
			return;
		}

		WDUA_Install::maybe_upgrade();
		WDUA_Service::init();
		new WDUA_Frontend();

		if ( is_admin() ) {
			new WDUA_Admin();
		}
	},
	20
);
