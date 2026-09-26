<?php
/**
 * Eklenti silinirken çalışır. Veriler yalnızca ayarlarda "tüm verileri sil" işaretliyse kaldırılır.
 */
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$wdua_settings = get_option( 'wdua_settings', array() );

wp_clear_scheduled_hook( 'wdua_daily' );

if ( is_array( $wdua_settings ) && isset( $wdua_settings['delete_on_uninstall'] ) && 'yes' === $wdua_settings['delete_on_uninstall'] ) {
	global $wpdb;
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wdua_returns" ); // phpcs:ignore
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wdua_feedback" ); // phpcs:ignore
	$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key IN ('_wdua_stats','_wdua_manual_note','_wdua_hide_badge')" ); // phpcs:ignore
	delete_option( 'wdua_settings' );
	delete_option( 'wdua_db_version' );
}
