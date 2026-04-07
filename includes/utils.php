<?php
defined( 'ABSPATH' ) || exit;

/**
 * Check minimum account age requirement
 *
 * Only applies when:
 * - Guests are NOT allowed
 * - User is logged in
 * - min_account_age_days > 0
 *
 * @return true|WP_Error
 */
function init_plugin_suite_chat_engine_check_account_age_requirement() {
    $settings = init_plugin_suite_chat_engine_get_all_settings();
    $allow_guests = ! empty( $settings['allow_guests'] );
    $min_days = isset( $settings['min_account_age_days'] )
        ? (int) $settings['min_account_age_days']
        : 0;

    // Rule disabled
    if ( $min_days <= 0 ) {
        return true;
    }

    // Nếu site cho guest chat thì không áp dụng rule
    if ( $allow_guests ) {
        return true;
    }

    // Nếu chưa login thì để logic khác xử lý
    if ( ! is_user_logged_in() ) {
        return true;
    }

    $current_user = wp_get_current_user();

    if ( ! $current_user || ! $current_user->exists() ) {
        return true;
    }

    $registered_timestamp = strtotime( $current_user->user_registered );
    $current_timestamp    = current_time( 'timestamp' );

    if ( ! $registered_timestamp ) {
        return true;
    }

    $account_age_days = floor(
        ( $current_timestamp - $registered_timestamp ) / DAY_IN_SECONDS
    );

    if ( $account_age_days < $min_days ) {

        return new WP_Error(
            'account_too_new',
            sprintf(
                /* translators: %d: minimum required account age in days */
                __( 'Your account must be at least %d days old to participate in the chat.', 'init-chat-engine' ),
                $min_days
            ),
            [ 'status' => 403 ]
        );
    }

    return true;
}

/**
 * Clear all message-related cache
 */
function init_plugin_suite_chat_engine_clear_message_cache() {
    global $wpdb;

    // Xóa toàn bộ keys trong group (Redis/Memcached)
    wp_cache_flush_group( 'init_chat_engine' );

    // Fallback: xóa thủ công các cache key phổ biến
    for ( $page = 1; $page <= 10; $page++ ) {
        wp_cache_delete( 'init_chat_messages_' . md5( '' . $page ), '' );
        wp_cache_delete( 'init_chat_total_messages_' . md5( '' ), '' );
    }

    // Xóa cache stats
    wp_cache_delete( 'init_chat_stats_' . current_time( 'Y-m-d' ), '' );
    wp_cache_delete( 'init_chat_daily_stats_' . current_time( 'Y-m-d' ), '' );
    wp_cache_delete( 'init_chat_top_users_' . current_time( 'Y-m-d' ), '' );
    wp_cache_delete( 'init_chat_db_size', '' );
}
