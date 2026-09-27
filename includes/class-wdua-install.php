<?php
defined( 'ABSPATH' ) || exit;

/**
 * Kurulum: tablolar, zamanlanmış görev, sürüm yükseltme.
 */
class WDUA_Install {

	const DB_VERSION = '1.0.0';
	const CRON_HOOK  = 'wdua_daily';

	public static function activate() {
		self::create_tables();
		add_option( WDUA_Settings::OPTION, WDUA_Settings::defaults() );
		update_option( 'wdua_db_version', self::DB_VERSION );
		self::schedule();
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	public static function maybe_upgrade() {
		if ( get_option( 'wdua_db_version' ) !== self::DB_VERSION ) {
			self::create_tables();
			update_option( 'wdua_db_version', self::DB_VERSION );
		}
		self::schedule();
	}

	private static function schedule() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	public static function create_tables() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset  = $wpdb->get_charset_collate();
		$returns  = $wpdb->prefix . 'wdua_returns';
		$feedback = $wpdb->prefix . 'wdua_feedback';

		$sql = "CREATE TABLE {$returns} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  request_key varchar(20) NOT NULL DEFAULT '',
  order_id bigint(20) unsigned NOT NULL DEFAULT 0,
  order_item_id bigint(20) unsigned NOT NULL DEFAULT 0,
  product_id bigint(20) unsigned NOT NULL DEFAULT 0,
  variation_id bigint(20) unsigned NOT NULL DEFAULT 0,
  customer_id bigint(20) unsigned NOT NULL DEFAULT 0,
  size_key varchar(100) NOT NULL DEFAULT '',
  size_label varchar(191) NOT NULL DEFAULT '',
  qty int(11) NOT NULL DEFAULT 1,
  reason varchar(40) NOT NULL DEFAULT '',
  reason_cat varchar(20) NOT NULL DEFAULT '',
  fit tinyint(4) DEFAULT NULL,
  customer_note text NULL,
  admin_note text NULL,
  status varchar(20) NOT NULL DEFAULT 'requested',
  source varchar(20) NOT NULL DEFAULT 'customer',
  item_value decimal(12,2) NOT NULL DEFAULT 0,
  cost_ship_out decimal(12,2) NOT NULL DEFAULT 0,
  cost_ship_back decimal(12,2) NOT NULL DEFAULT 0,
  cost_labor decimal(12,2) NOT NULL DEFAULT 0,
  cost_depreciation decimal(12,2) NOT NULL DEFAULT 0,
  cost_total decimal(12,2) NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY order_id (order_id),
  KEY order_item_id (order_item_id),
  KEY product_id (product_id),
  KEY status (status),
  KEY created_at (created_at)
) {$charset};
CREATE TABLE {$feedback} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  order_id bigint(20) unsigned NOT NULL DEFAULT 0,
  order_item_id bigint(20) unsigned NOT NULL DEFAULT 0,
  product_id bigint(20) unsigned NOT NULL DEFAULT 0,
  variation_id bigint(20) unsigned NOT NULL DEFAULT 0,
  customer_id bigint(20) unsigned NOT NULL DEFAULT 0,
  size_key varchar(100) NOT NULL DEFAULT '',
  size_label varchar(191) NOT NULL DEFAULT '',
  fit tinyint(4) NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY order_item_id (order_item_id),
  KEY product_id (product_id)
) {$charset};";

		dbDelta( $sql );
	}
}
