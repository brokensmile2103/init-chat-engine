<?php
/**
 * Uninstall routine: removes plugin options and the custom messages table.
 *
 * Bảo mật: chỉ chạy khi gỡ plugin qua WP.
 *
 * @package Init_Chat_Engine
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// Xóa options (option cũ + các nhóm settings đang dùng + option nội bộ).
delete_option( 'init_plugin_suite_chat_engine_settings' );
delete_option( 'init_chat_basic_settings' );
delete_option( 'init_chat_security_settings' );
delete_option( 'init_chat_advanced_settings' );
delete_option( 'init_plugin_suite_chat_engine_db_version' );
delete_option( 'init_chat_last_daily_stat_update' );
delete_transient( 'init_chat_engine_last_activity_throttle' );

// Xóa lịch cron dọn dẹp (phòng trường hợp plugin bị xóa mà không deactivate trước).
wp_clear_scheduled_hook( 'init_chat_engine_cleanup_messages' );

// Xóa tùy chọn "Messages per page" (Screen Options) của mọi admin.
delete_metadata( 'user', 0, 'init_chat_messages_per_page', '', true );

// Xóa custom tables: messages + stats + banned (trước 1.3.8 chỉ xóa bảng messages,
// 2 bảng còn lại bị bỏ sót trong DB sau khi gỡ plugin).
global $wpdb;

$init_chat_engine_tables = array(
	$wpdb->prefix . 'init_chatbox_msgs',
	$wpdb->prefix . 'init_chatbox_stats',
	$wpdb->prefix . 'init_chatbox_banned',
);

foreach ( $init_chat_engine_tables as $init_chat_engine_table ) {
	$init_chat_engine_table = esc_sql( $init_chat_engine_table );

	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
	$wpdb->query( "DROP TABLE IF EXISTS `{$init_chat_engine_table}`" );
}
