<?php
// Bảo mật: chỉ chạy khi gỡ plugin qua WP
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// Xóa options
delete_option( 'init_plugin_suite_chat_engine_settings' );

// Xóa custom table
global $wpdb;
$table = esc_sql( $wpdb->prefix . 'init_chatbox_msgs' );

// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
