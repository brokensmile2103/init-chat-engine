<?php
defined( 'ABSPATH' ) || exit;

/**
 * Plugin activation hook – create custom table with indexes
 */
function init_plugin_suite_chat_engine_activate() {
    global $wpdb;
    
    $table_name = $wpdb->prefix . 'init_chatbox_msgs';
    $charset_collate = $wpdb->get_charset_collate();
    
    $sql = "CREATE TABLE $table_name (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id BIGINT(20) NULL,
        display_name VARCHAR(100) NOT NULL,
        message TEXT NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        is_deleted TINYINT(1) DEFAULT 0,
        ip_address VARCHAR(45) NULL,
        user_agent VARCHAR(255) NULL,
        PRIMARY KEY (id),
        KEY idx_user_id (user_id),
        KEY idx_created_at (created_at),
        KEY idx_is_deleted (is_deleted)
    ) $charset_collate;";
    
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );
    
    // Create options table for storing chat statistics
    $stats_table = $wpdb->prefix . 'init_chatbox_stats';
    $stats_sql = "CREATE TABLE $stats_table (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        stat_key VARCHAR(100) NOT NULL,
        stat_value LONGTEXT NULL,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY idx_stat_key (stat_key)
    ) $charset_collate;";
    
    dbDelta( $stats_sql );
    
    // Create banned users table
    $banned_table = $wpdb->prefix . 'init_chatbox_banned';
    $banned_sql = "CREATE TABLE $banned_table (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id BIGINT(20) NULL,
        ip_address VARCHAR(45) NULL,
        display_name VARCHAR(100) NULL,
        reason TEXT NULL,
        banned_by BIGINT(20) NOT NULL,
        banned_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        expires_at DATETIME NULL,
        is_active TINYINT(1) DEFAULT 1,
        PRIMARY KEY (id),
        KEY idx_user_id (user_id),
        KEY idx_ip_address (ip_address),
        KEY idx_is_active (is_active),
        KEY idx_expires_at (expires_at)
    ) $charset_collate;";
    
    dbDelta( $banned_sql );
    
    // Initialize default stats
    init_plugin_suite_chat_engine_init_default_stats();
    
    // Set plugin version
    update_option( 'init_plugin_suite_chat_engine_db_version', '1.1.0' );
    
    // Schedule cleanup event
    if ( ! wp_next_scheduled( 'init_chat_engine_cleanup_messages' ) ) {
        wp_schedule_event( time(), 'daily', 'init_chat_engine_cleanup_messages' );
    }
}

/**
 * Plugin deactivation hook
 */
function init_plugin_suite_chat_engine_deactivate() {
    // Clear scheduled events
    wp_clear_scheduled_hook( 'init_chat_engine_cleanup_messages' );
}

/**
 * Initialize default statistics
 */
function init_plugin_suite_chat_engine_init_default_stats() {
    global $wpdb;
    
    $stats_table = $wpdb->prefix . 'init_chatbox_stats';
    $default_stats = [
        'total_messages' => 0,
        'total_users' => 0,
        'messages_today' => 0,
        'active_users_today' => 0,
        'last_cleanup' => current_time( 'mysql' )
    ];
    
    foreach ( $default_stats as $key => $value ) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->replace( 
            $stats_table,
            [
                'stat_key' => $key,
                'stat_value' => $value,
                'updated_at' => current_time( 'mysql' )
            ],
            [ '%s', '%s', '%s' ]
        );
    }
}

/**
 * Database upgrade check
 */
function init_plugin_suite_chat_engine_check_db_upgrade() {
    $current_version = get_option( 'init_plugin_suite_chat_engine_db_version', '1.0.0' );
    
    if ( version_compare( $current_version, '1.1.0', '<' ) ) {
        init_plugin_suite_chat_engine_activate();
    }
}

/**
 * Scheduled cleanup of old messages
 */
function init_plugin_suite_chat_engine_cleanup_messages() {
    global $wpdb;
    
    $options = get_option( INIT_PLUGIN_SUITE_CHAT_ENGINE_OPTION, [] );
    $max_messages = isset( $options['max_messages'] ) ? (int) $options['max_messages'] : 1000;
    $cleanup_days = isset( $options['cleanup_days'] ) ? (int) $options['cleanup_days'] : 30;
    
    $table_name = $wpdb->prefix . 'init_chatbox_msgs';
    
    // Clean up old deleted messages (older than cleanup_days)
    if ( $cleanup_days > 0 ) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->query( 
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->prepare(
                "DELETE FROM {$wpdb->prefix}init_chatbox_msgs 
                 WHERE is_deleted = 1 
                 AND created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
                $cleanup_days
            )
        );
    }
    
    // Maintain message limit
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $total_messages = $wpdb->get_var( 
        "SELECT COUNT(*) FROM {$wpdb->prefix}init_chatbox_msgs WHERE is_deleted = 0"
    );
    
    if ( $total_messages > $max_messages ) {
        $delete_limit = $total_messages - $max_messages;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->query( 
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->prepare(
                "UPDATE {$wpdb->prefix}init_chatbox_msgs 
                 SET is_deleted = 1 
                 WHERE is_deleted = 0 
                 ORDER BY id ASC 
                 LIMIT %d",
                $delete_limit
            )
        );
    }
    
    // Clean up expired bans
    init_plugin_suite_chat_engine_cleanup_expired_bans();
    
    // Update cleanup stats
    init_plugin_suite_chat_engine_update_stat( 'last_cleanup', current_time( 'mysql' ) );
}

/**
 * Update statistics
 */
function init_plugin_suite_chat_engine_update_stat( $key, $value ) {
    global $wpdb;
    
    $stats_table = $wpdb->prefix . 'init_chatbox_stats';
    
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $wpdb->replace( 
        $stats_table,
        [
            'stat_key' => $key,
            'stat_value' => $value,
            'updated_at' => current_time( 'mysql' )
        ],
        [ '%s', '%s', '%s' ]
    );
}

/**
 * Get statistics
 */
function init_plugin_suite_chat_engine_get_stat( $key, $default = null ) {
    global $wpdb;
    
    $stats_table = $wpdb->prefix . 'init_chatbox_stats';
    
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $value = $wpdb->get_var( 
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->prepare(
            "SELECT stat_value FROM {$wpdb->prefix}init_chatbox_stats WHERE stat_key = %s",
            $key
        )
    );
    
    return $value !== null ? $value : $default;
}

/**
 * Get user IP address
 */
function init_plugin_suite_chat_engine_get_user_ip() {
    $ip_keys = ['HTTP_CF_CONNECTING_IP', 'HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED', 'HTTP_FORWARDED_FOR', 'HTTP_FORWARDED', 'REMOTE_ADDR'];
    
    foreach ( $ip_keys as $key ) {
        if ( array_key_exists( $key, $_SERVER ) && !empty( $_SERVER[$key] ) ) {
            $ip = sanitize_text_field( wp_unslash( $_SERVER[$key] ) );
            if ( strpos( $ip, ',' ) !== false ) {
                $ip = trim( explode( ',', $ip )[0] );
            }
            if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
                return $ip;
            }
        }
    }
    
    return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '127.0.0.1';
}

/**
 * Ban user from chat
 */
function init_plugin_suite_chat_engine_ban_user( $user_id = null, $ip_address = null, $display_name = null, $reason = '', $duration_hours = null ) {
    global $wpdb;
    
    if ( ! current_user_can( 'manage_options' ) ) {
        return false;
    }
    
    if ( empty( $user_id ) && empty( $ip_address ) ) {
        return false;
    }
    
    $banned_table = $wpdb->prefix . 'init_chatbox_banned';
    $banned_by = get_current_user_id();
    $expires_at = $duration_hours ? gmdate( 'Y-m-d H:i:s', strtotime( "+{$duration_hours} hours" ) ) : null;
    
    $data = [
        'user_id' => $user_id,
        'ip_address' => $ip_address,
        'display_name' => $display_name,
        'reason' => $reason,
        'banned_by' => $banned_by,
        'banned_at' => current_time( 'mysql' ),
        'expires_at' => $expires_at,
        'is_active' => 1
    ];
    
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $result = $wpdb->insert( $banned_table, $data );
    
    if ( $result ) {
        // Log the ban action - only in debug mode
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log( sprintf( 
                'Chat Engine: User banned - ID: %s, IP: %s, Name: %s, By: %s, Reason: %s',
                $user_id ?: 'N/A',
                $ip_address ?: 'N/A', 
                $display_name ?: 'N/A',
                $banned_by,
                $reason
            ) );
        }
        
        return $wpdb->insert_id;
    }
    
    return false;
}

/**
 * Unban user from chat
 */
function init_plugin_suite_chat_engine_unban_user( $ban_id = null, $user_id = null, $ip_address = null ) {
    global $wpdb;
    
    if ( ! current_user_can( 'manage_options' ) ) {
        return false;
    }
    
    $banned_table = $wpdb->prefix . 'init_chatbox_banned';
    $where = [];
    $formats = [];
    
    if ( $ban_id ) {
        $where['id'] = $ban_id;
        $formats[] = '%d';
    } elseif ( $user_id ) {
        $where['user_id'] = $user_id;
        $formats[] = '%d';
    } elseif ( $ip_address ) {
        $where['ip_address'] = $ip_address;
        $formats[] = '%s';
    } else {
        return false;
    }
    
    $where['is_active'] = 1;
    $formats[] = '%d';
    
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $result = $wpdb->update(
        $banned_table,
        [ 'is_active' => 0 ],    // data to update
        $where,                   // where conditions (key-value pairs)
        [ '%d' ],                // format for data
        $formats                 // format for where conditions
    );
    
    if ( $result !== false ) {
        // Log the unban action - only in debug mode
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log( sprintf( 
                'Chat Engine: User unbanned - Ban ID: %s, User ID: %s, IP: %s, By: %s',
                $ban_id ?: 'N/A',
                $user_id ?: 'N/A',
                $ip_address ?: 'N/A',
                get_current_user_id()
            ) );
        }
        
        return true;
    }
    
    return false;
}

/**
 * Check if user is banned - FIXED VERSION
 */
function init_plugin_suite_chat_engine_check_user_banned( $user_id = null, $ip_address = null ) {
    global $wpdb;
    
    if ( empty( $user_id ) && empty( $ip_address ) ) {
        return false;
    }
    
    $banned_table = $wpdb->prefix . 'init_chatbox_banned';
    
    if ( $user_id && $ip_address ) {
        // Check both user ID and IP
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $ban_record = $wpdb->get_row( 
            $wpdb->prepare(
                "SELECT * FROM `{$wpdb->prefix}init_chatbox_banned` 
                 WHERE (user_id = %d OR ip_address = %s) 
                 AND is_active = 1 
                 AND (expires_at IS NULL OR expires_at > NOW()) 
                 LIMIT 1",
                $user_id, $ip_address
            )
        );
    } elseif ( $user_id ) {
        // Check only user ID
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $ban_record = $wpdb->get_row( 
            $wpdb->prepare(
                "SELECT * FROM `{$wpdb->prefix}init_chatbox_banned` 
                 WHERE user_id = %d 
                 AND is_active = 1 
                 AND (expires_at IS NULL OR expires_at > NOW()) 
                 LIMIT 1",
                $user_id
            )
        );
    } else {
        // Check only IP address
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $ban_record = $wpdb->get_row( 
            $wpdb->prepare(
                "SELECT * FROM `{$wpdb->prefix}init_chatbox_banned` 
                 WHERE ip_address = %s 
                 AND is_active = 1 
                 AND (expires_at IS NULL OR expires_at > NOW()) 
                 LIMIT 1",
                $ip_address
            )
        );
    }
    
    return $ban_record ? $ban_record : false;
}

/**
 * Get all banned users - FIXED VERSION
 */
function init_plugin_suite_chat_engine_get_banned_users( $active_only = true ) {
    global $wpdb;
    
    if ( ! current_user_can( 'manage_options' ) ) {
        return [];
    }
    
    if ( $active_only ) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $results = $wpdb->get_results( 
            $wpdb->prepare(
                "SELECT b.*, u.display_name as banned_by_name 
                 FROM `{$wpdb->prefix}init_chatbox_banned` b 
                 LEFT JOIN `{$wpdb->users}` u ON b.banned_by = u.ID 
                 WHERE b.is_active = %d
                 ORDER BY b.banned_at DESC",
                1
            )
        );
    } else {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $results = $wpdb->get_results( 
            $wpdb->prepare(
                "SELECT b.*, u.display_name as banned_by_name 
                 FROM `{$wpdb->prefix}init_chatbox_banned` b 
                 LEFT JOIN `{$wpdb->users}` u ON b.banned_by = u.ID 
                 ORDER BY b.banned_at DESC LIMIT %d",
                9999
            )
        );
    }
    
    return $results;
}

/**
 * Clean up expired bans - FIXED VERSION
 */
function init_plugin_suite_chat_engine_cleanup_expired_bans() {
    global $wpdb;
    
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $result = $wpdb->query(
        $wpdb->prepare(
            "UPDATE `{$wpdb->prefix}init_chatbox_banned` 
             SET is_active = %d 
             WHERE is_active = %d 
             AND expires_at IS NOT NULL 
             AND expires_at <= NOW()",
            0, 1
        )
    );
    
    if ( $result && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
        error_log( "Chat Engine: Cleaned up {$result} expired bans" );
    }
    
    return $result;
}

/**
 * Check rate limit for chat messages
 */
function init_plugin_suite_chat_engine_check_rate_limit( $user_ip, $user_id = null ) {
    $transient_key = 'init_chat_rate_limit_' . md5( $user_ip . ( $user_id ? '_' . $user_id : '' ) );
    $attempts = get_transient( $transient_key );
    
    $options = get_option( INIT_PLUGIN_SUITE_CHAT_ENGINE_OPTION, [] );
    $rate_limit = isset( $options['rate_limit'] ) ? (int) $options['rate_limit'] : 10; // messages per minute
    
    if ( $attempts === false ) {
        set_transient( $transient_key, 1, 60 ); // 1 minute
        return true;
    }
    
    if ( $attempts >= $rate_limit ) {
        return false;
    }
    
    set_transient( $transient_key, $attempts + 1, 60 );
    return true;
}

// Register hooks
register_activation_hook( INIT_PLUGIN_SUITE_CHAT_ENGINE_PATH . 'init-chat-engine.php', 'init_plugin_suite_chat_engine_activate' );
register_deactivation_hook( INIT_PLUGIN_SUITE_CHAT_ENGINE_PATH . 'init-chat-engine.php', 'init_plugin_suite_chat_engine_deactivate' );

// Check for database upgrades on admin_init
add_action( 'admin_init', 'init_plugin_suite_chat_engine_check_db_upgrade' );

// Register cleanup hook
add_action( 'init_chat_engine_cleanup_messages', 'init_plugin_suite_chat_engine_cleanup_messages' );

// Update daily stats
add_action( 'init', function() {
    $today = gmdate( 'Y-m-d' );
    $last_stat_update = get_option( 'init_chat_last_daily_stat_update', '' );
    
    if ( $last_stat_update !== $today ) {
        // Reset daily counters
        init_plugin_suite_chat_engine_update_stat( 'messages_today', 0 );
        init_plugin_suite_chat_engine_update_stat( 'active_users_today', 0 );
        update_option( 'init_chat_last_daily_stat_update', $today );
    }
});
