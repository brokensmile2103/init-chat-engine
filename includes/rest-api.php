<?php
defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.UnnecessaryPrepare
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching

/**
 * Register REST API routes for Init Chat Engine
 */
add_action( 'rest_api_init', 'init_plugin_suite_chat_engine_register_rest_routes' );

function init_plugin_suite_chat_engine_register_rest_routes() {
    // Messages endpoint
    register_rest_route( INIT_PLUGIN_SUITE_CHAT_ENGINE_NAMESPACE, '/messages', [
        'methods'  => 'GET',
        'callback' => 'init_plugin_suite_chat_engine_get_messages',
        'permission_callback' => 'init_plugin_suite_chat_engine_messages_permission_check',
        'args' => [
            'after_id' => [
                'type'     => 'integer',
                'required' => false,
                'default'  => 0,
                'validate_callback' => function( $param ) {
                    return is_numeric( $param ) && $param >= 0;
                },
            ],
            'before_id' => [
                'type'     => 'integer',
                'required' => false,
                'default'  => 0,
                'validate_callback' => function( $param ) {
                    return is_numeric( $param ) && $param >= 0;
                },
            ],
            'limit' => [
                'type'     => 'integer',
                'required' => false,
                'default'  => 15,
                'validate_callback' => function( $param ) {
                    return is_numeric( $param ) && $param > 0 && $param <= 50;
                },
            ],
        ],
    ] );

    // Send message endpoint
    register_rest_route( INIT_PLUGIN_SUITE_CHAT_ENGINE_NAMESPACE, '/send', [
        'methods'  => 'POST',
        'callback' => 'init_plugin_suite_chat_engine_send_message',
        'permission_callback' => 'init_plugin_suite_chat_engine_send_permission_check',
        'args' => [
            'message' => [
                'type'     => 'string',
                'required' => true,
                'validate_callback' => 'init_plugin_suite_chat_engine_validate_message',
                'sanitize_callback' => 'sanitize_textarea_field',
            ],
            'display_name' => [
                'type'     => 'string',
                'required' => false,
                'validate_callback' => function( $param ) {
                    return empty( $param ) || ( is_string( $param ) && strlen( trim( $param ) ) <= 100 );
                },
                'sanitize_callback' => 'sanitize_text_field',
            ],
        ],
    ] );

    // User status endpoint
    register_rest_route( INIT_PLUGIN_SUITE_CHAT_ENGINE_NAMESPACE, '/user-status', [
        'methods'  => 'GET',
        'callback' => 'init_plugin_suite_chat_engine_get_user_status',
        'permission_callback' => '__return_true',
    ] );

    // REMOVED: Online users endpoint - XÓA LUÔN CŨNG VÔ DỤNG!
}

/**
 * Permission check for messages endpoint
 */
function init_plugin_suite_chat_engine_messages_permission_check( $request ) {
    // Check if user is banned
    $user_ip = init_plugin_suite_chat_engine_get_user_ip();
    $user_id = is_user_logged_in() ? get_current_user_id() : null;
    $ban_check = init_plugin_suite_chat_engine_check_user_banned( $user_id, $user_ip );
    
    if ( $ban_check ) {
        return new WP_Error( 'user_banned', __( 'You are banned from the chat.', 'init-chat-engine' ), [ 'status' => 403 ] );
    }
    
    return true;
}

/**
 * Permission check for send message endpoint
 */
function init_plugin_suite_chat_engine_send_permission_check( $request ) {
    // Check nonce for logged in users
    if ( is_user_logged_in() ) {
        $valid = wp_verify_nonce(
            $request->get_header( 'X-WP-Nonce' ),
            'wp_rest'
        );
        if ( ! $valid ) {
            return new WP_Error( 'invalid_nonce', __( 'Invalid nonce.', 'init-chat-engine' ), [ 'status' => 403 ] );
        }
    }

    // Check if user is banned
    $user_ip = init_plugin_suite_chat_engine_get_user_ip();
    $user_id = is_user_logged_in() ? get_current_user_id() : null;
    $ban_check = init_plugin_suite_chat_engine_check_user_banned( $user_id, $user_ip );
    
    if ( $ban_check ) {
        return new WP_Error( 'user_banned', __( 'You are banned from the chat.', 'init-chat-engine' ), [ 'status' => 403 ] );
    }

    // Check rate limiting
    if ( ! init_plugin_suite_chat_engine_check_rate_limit( $user_ip, $user_id ) ) {
        return new WP_Error( 'rate_limit_exceeded', __( 'You are sending messages too quickly. Please slow down.', 'init-chat-engine' ), [ 'status' => 429 ] );
    }

    return true;
}

/**
 * Validate message content
 */
function init_plugin_suite_chat_engine_validate_message( $message ) {
    if ( empty( trim( $message ) ) ) {
        return false;
    }

    $settings = init_plugin_suite_chat_engine_get_all_settings();
    $max_length = isset( $settings['max_message_length'] ) ? (int) $settings['max_message_length'] : 500;
    
    if ( strlen( $message ) > $max_length ) {
        return false;
    }

    // Check word filtering
    if ( ! init_plugin_suite_chat_engine_check_message_content( $message ) ) {
        return false;
    }

    return true;
}

/**
 * GET /messages – Return list of messages with timestamp updates
 * Trả kèm profile_url của user (mặc định: author archive).
 * Có thể override bằng filter: 'init_plugin_suite_chat_engine_get_user_profile_url'.
 */
function init_plugin_suite_chat_engine_get_messages( WP_REST_Request $request ) {
    global $wpdb;

    $table     = esc_sql( $wpdb->prefix . 'init_chatbox_msgs' );
    $after_id  = (int) $request->get_param( 'after_id' );
    $before_id = (int) $request->get_param( 'before_id' );
    $limit     = (int) $request->get_param( 'limit' );

    // Limit an toàn
    if ( $limit <= 0 ) {
        $limit = 20;
    } elseif ( $limit > 100 ) {
        $limit = 100;
    }

    // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

    $messages         = [];
    $updated_messages = [];

    // Build query dựa theo tham số
    if ( $after_id > 0 ) {
        // Lấy message mới hơn (realtime updates)
        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, user_id, display_name, message, created_at 
                 FROM {$table} 
                 WHERE id > %d AND is_deleted = 0
                 ORDER BY id ASC 
                 LIMIT %d",
                $after_id, $limit
            ),
            ARRAY_A
        );
        $messages = $results;

        // Lấy 50 message mới nhất để refresh timestamp
        $recent_messages = $wpdb->get_results(
            // $limit cố định 50 để không phụ thuộc client
            "SELECT id, user_id, display_name, message, created_at 
             FROM {$table} 
             WHERE is_deleted = 0
             ORDER BY id DESC 
             LIMIT 50",
            ARRAY_A
        );
        $updated_messages = $recent_messages;

    } elseif ( $before_id > 0 ) {
        // Phân trang lùi (older messages)
        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, user_id, display_name, message, created_at 
                 FROM {$table} 
                 WHERE id < %d AND is_deleted = 0
                 ORDER BY id ASC 
                 LIMIT %d",
                $before_id, $limit
            ),
            ARRAY_A
        );
        $messages = $results;

    } else {
        // Lần đầu: lấy mới nhất (DESC), FE tự đảo
        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, user_id, display_name, message, created_at 
                 FROM {$table} 
                 WHERE is_deleted = 0
                 ORDER BY id DESC 
                 LIMIT %d",
                $limit
            ),
            ARRAY_A
        );
        $messages = $results;
    }

    // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

    $settings      = init_plugin_suite_chat_engine_get_all_settings();
    $show_avatars  = ! empty( $settings['show_avatars'] );

    /**
     * Trả về URL profile của user. Mặc định dùng author archive.
     * Có thể override qua filter 'init_plugin_suite_chat_engine_get_user_profile_url'.
     *
     * @param int $user_id
     * @return string
     */
    $get_profile_url = function( $user_id ) {
        if ( $user_id <= 0 ) {
            return '';
        }

        // Mặc định: trang tác giả (public)
        $url = get_author_posts_url( $user_id );

        /**
         * Cho phép tùy biến:
         * - Về trang admin edit: admin_url( 'user-edit.php?user_id=' . $user_id )
         * - Về trang profile tùy chỉnh (BuddyPress/UM/BBPress…)
         */
        $url = apply_filters( 'init_plugin_suite_chat_engine_get_user_profile_url', $url, $user_id );

        // Bảo vệ đầu ra
        return esc_url_raw( $url );
    };

    // Hàm format 1 row message
    $format_message = function( &$row ) use ( $show_avatars, $get_profile_url ) {
        // Time
        $created_timestamp       = strtotime( $row['created_at'] );
        $row['created_at_human'] = human_time_diff( $created_timestamp, current_time( 'timestamp' ) );
        $row['created_at_iso']   = gmdate( 'c', $created_timestamp );
        $row['created_timestamp']= $created_timestamp;

        // Avatar
        $row['avatar_url'] = '';
        $uid = ! empty( $row['user_id'] ) ? (int) $row['user_id'] : 0;
        if ( $show_avatars && $uid > 0 ) {
            $avatar_url = get_avatar_url( $uid, [ 'size' => 64 ] );
            if ( $avatar_url ) {
                $row['avatar_url'] = esc_url_raw( $avatar_url );
            }
        }

        // User flags
        $row['is_current_user'] = is_user_logged_in() && $uid > 0 && $uid === get_current_user_id();
        $row['user_type']       = $uid > 0 ? 'registered' : 'guest';

        // Profile URL (yêu cầu của bro)
        $row['profile_url'] = $uid > 0 ? $get_profile_url( $uid ) : '';

        // Sanitize message
        $row['message'] = wp_kses_post( $row['message'] );

        // Cho phép theme/plugin khác filter nội dung message
        $row['message'] = apply_filters( 'init_plugin_suite_chat_engine_format_message', $row['message'], $row );

        // Tên hiển thị + HTML sẵn để FE khỏi lặp code
        $display_name = isset( $row['display_name'] ) ? wp_strip_all_tags( $row['display_name'] ) : '';
        if ( $row['profile_url'] ) {
            // target+rel phòng khi render ngoài site
            $row['display_name_html'] = sprintf(
                '<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
                esc_url( $row['profile_url'] ),
                esc_html( $display_name )
            );
        } else {
            $row['display_name_html'] = esc_html( $display_name );
        }

        $row = apply_filters( 'init_plugin_suite_chat_engine_enrich_message_row', $row, $uid );
    };

    // Format main messages
    foreach ( $messages as &$row ) {
        $format_message( $row );
    }
    unset( $row );

    // Format updated messages (refresh timestamp)
    foreach ( $updated_messages as &$row ) {
        $format_message( $row );
    }
    unset( $row );

    // Update stats
    init_plugin_suite_chat_engine_update_stat( 'last_activity', current_time( 'mysql' ) );

    $response = [
        'success'   => true,
        'messages'  => $messages,
        'count'     => count( $messages ),
        'has_more'  => count( $messages ) === $limit,
    ];

    // Chỉ trả updated_messages khi có after_id (polling realtime)
    if ( $after_id > 0 && ! empty( $updated_messages ) ) {
        $response['updated_messages'] = $updated_messages;
    }

    return rest_ensure_response( $response );
}

/**
 * POST /send – Send a message
 */
function init_plugin_suite_chat_engine_send_message( WP_REST_Request $request ) {
    global $wpdb;

    $settings = init_plugin_suite_chat_engine_get_all_settings();
    $allow_guests = ! empty( $settings['allow_guests'] );

    $current_user = wp_get_current_user();
    $user_id = $current_user->exists() ? $current_user->ID : null;

    $message = wp_strip_all_tags( trim( $request->get_param( 'message' ) ) );
    $display_name = $user_id ? wp_strip_all_tags( $current_user->display_name ) : wp_strip_all_tags( trim( $request->get_param( 'display_name' ) ) );

    // Additional validation
    if ( ! $user_id && ! $allow_guests ) {
        return new WP_Error( 'unauthorized', __( 'Guests are not allowed to chat.', 'init-chat-engine' ), [ 'status' => 403 ] );
    }

    if ( ! $display_name ) {
        return new WP_Error( 'missing_name', __( 'Display name is required.', 'init-chat-engine' ), [ 'status' => 400 ] );
    }

    // Check word filtering again (double check)
    if ( ! init_plugin_suite_chat_engine_check_message_content( $message ) ) {
        return new WP_Error( 'message_blocked', __( 'Your message contains blocked words.', 'init-chat-engine' ), [ 'status' => 400 ] );
    }

    // Get user IP and User Agent
    $user_ip = init_plugin_suite_chat_engine_get_user_ip();
    $user_agent = isset( $_SERVER['HTTP_USER_AGENT'] )
        ? substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 255 )
        : '';

    $table_name = $wpdb->prefix . 'init_chatbox_msgs';
    
    // Insert message
    $result = $wpdb->insert( $table_name, [
        'user_id'      => $user_id,
        'display_name' => $display_name,
        'message'      => $message,
        'ip_address'   => $user_ip,
        'user_agent'   => $user_agent,
        'created_at'   => current_time( 'mysql' )
    ], [
        '%d', '%s', '%s', '%s', '%s', '%s'
    ] );

    if ( ! $result ) {
        return new WP_Error( 'insert_failed', __( 'Failed to save message.', 'init-chat-engine' ), [ 'status' => 500 ] );
    }

    $message_id = $wpdb->insert_id;

    do_action( 'init_plugin_suite_chat_engine_message_saved', $message_id, $message, $user_id, $display_name );

    // Update statistics
    $total_messages = init_plugin_suite_chat_engine_get_stat( 'total_messages', 0 );
    $messages_today = init_plugin_suite_chat_engine_get_stat( 'messages_today', 0 );
    
    init_plugin_suite_chat_engine_update_stat( 'total_messages', $total_messages + 1 );
    init_plugin_suite_chat_engine_update_stat( 'messages_today', $messages_today + 1 );

    // Cleanup if over limit (using new soft delete)
    $max = isset( $settings['max_messages'] ) ? (int) $settings['max_messages'] : 1000;
    $total = $wpdb->get_var( 
        "SELECT COUNT(*) FROM {$wpdb->prefix}init_chatbox_msgs WHERE is_deleted = 0"
    );
    
    if ( $total > $max ) {
        $delete_limit = $total - $max;
        $wpdb->query( 
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

    // Return success with message data
    return rest_ensure_response( [
        'success' => true,
        'message_id' => $message_id,
        'message' => [
            'id' => $message_id,
            'user_id' => $user_id,
            'display_name' => $display_name,
            'message' => wp_kses_post( $message ),
            'created_at_human' => __( 'now', 'init-chat-engine' ),
            'created_at_iso' => gmdate( 'c' ),
            'created_timestamp' => time(),
            'avatar_url' => $user_id ? get_avatar_url( $user_id, [ 'size' => 64 ] ) : '',
            'is_current_user' => true,
            'user_type' => $user_id ? 'registered' : 'guest'
        ]
    ] );
}

/**
 * GET /user-status – Get current user status and chat info
 */
function init_plugin_suite_chat_engine_get_user_status( WP_REST_Request $request ) {
    $settings = init_plugin_suite_chat_engine_get_all_settings();
    $current_user = wp_get_current_user();
    $user_ip = init_plugin_suite_chat_engine_get_user_ip();
    
    // Check if user is banned
    $ban_check = init_plugin_suite_chat_engine_check_user_banned( 
        $current_user->exists() ? $current_user->ID : null, 
        $user_ip 
    );
    
    $status = [
        'is_logged_in' => $current_user->exists(),
        'user_id' => $current_user->exists() ? $current_user->ID : 0,
        'display_name' => $current_user->exists() ? $current_user->display_name : '',
        'avatar_url' => $current_user->exists() ? get_avatar_url( $current_user->ID, [ 'size' => 64 ] ) : '',
        'allow_guests' => ! empty( $settings['allow_guests'] ),
        'is_banned' => (bool) $ban_check,
        'ban_info' => $ban_check ?: null,
        'settings' => [
            'show_avatars' => ! empty( $settings['show_avatars'] ),
            'show_timestamps' => ! empty( $settings['show_timestamps'] ),
            'enable_notifications' => ! empty( $settings['enable_notifications'] ),
            'enable_sounds' => ! empty( $settings['enable_sounds'] ),
            'max_message_length' => isset( $settings['max_message_length'] ) ? (int) $settings['max_message_length'] : 500,
            'rate_limit' => isset( $settings['rate_limit'] ) ? (int) $settings['rate_limit'] : 10,
        ]
    ];
    
    return rest_ensure_response( $status );
}

// REMOVED: Online users function - XÓA LUÔN VÌ VÔ DỤNG!

/**
 * Check if there are any messages
 */
function init_plugin_suite_chat_engine_has_messages() {
    global $wpdb;
    
    $exists = $wpdb->get_var( 
        "SELECT 1 FROM {$wpdb->prefix}init_chatbox_msgs WHERE is_deleted = 0 LIMIT 1"
    );

    return (bool) $exists;
}

/**
 * Get message count for current user (for rate limiting display)
 */
function init_plugin_suite_chat_engine_get_user_message_count( $user_id = null, $user_ip = null ) {
    if ( ! $user_id && ! $user_ip ) {
        return 0;
    }
    
    $transient_key = 'init_chat_rate_limit_' . md5( ( $user_ip ?: '' ) . ( $user_id ? '_' . $user_id : '' ) );
    $count = get_transient( $transient_key );
    
    return $count ?: 0;
}

/**
 * Admin endpoint to moderate messages (for future use)
 */
add_action( 'rest_api_init', function() {
    register_rest_route( INIT_PLUGIN_SUITE_CHAT_ENGINE_NAMESPACE, '/admin/moderate', [
        'methods'  => 'POST',
        'callback' => 'init_plugin_suite_chat_engine_moderate_message',
        'permission_callback' => function() {
            return current_user_can( 'manage_options' );
        },
        'args' => [
            'message_id' => [
                'type' => 'integer',
                'required' => true,
            ],
            'action' => [
                'type' => 'string',
                'required' => true,
                'enum' => [ 'approve', 'delete', 'ban_user' ],
            ],
        ],
    ] );
} );

/**
 * Moderate message (admin only)
 */
function init_plugin_suite_chat_engine_moderate_message( WP_REST_Request $request ) {
    global $wpdb;
    
    $message_id = (int) $request->get_param( 'message_id' );
    $action = $request->get_param( 'action' );
    
    $message = $wpdb->get_row( 
        $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}init_chatbox_msgs WHERE id = %d",
            $message_id
        )
    );
    
    if ( ! $message ) {
        return new WP_Error( 'message_not_found', __( 'Message not found.', 'init-chat-engine' ), [ 'status' => 404 ] );
    }
    
    switch ( $action ) {
        case 'delete':
            $wpdb->update(
                $wpdb->prefix . 'init_chatbox_msgs',
                [ 'is_deleted' => 1 ],
                [ 'id' => $message_id ],
                [ '%d' ],
                [ '%d' ]
            );
            break;
            
        case 'ban_user':
            if ( $message->user_id || $message->ip_address ) {
                init_plugin_suite_chat_engine_ban_user(
                    $message->user_id ?: null,
                    $message->ip_address ?: null,
                    $message->display_name,
                    'Banned by moderator'
                );
            }
            break;
            
        case 'approve':
            // For future moderation system
            break;
    }
    
    return rest_ensure_response( [ 'success' => true, 'action' => $action ] );
}
