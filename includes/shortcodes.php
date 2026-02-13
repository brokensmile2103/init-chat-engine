<?php
defined( 'ABSPATH' ) || exit;

/**
 * Register the [init_chatbox] shortcode
 */
add_shortcode( 'init_chatbox', 'init_plugin_suite_chat_engine_render_shortcode' );

function init_plugin_suite_chat_engine_render_shortcode( $atts = [], $content = null ) {
    // Parse shortcode attributes - REMOVED max_height
    $atts = shortcode_atts( [
        'height'       => '',
        'width'        => '',
        'theme'        => 'default',
        'show_avatars' => '',
        'show_timestamps' => '',
        'title'        => '',
        'class'        => '',
        'id'           => '',
    ], $atts, 'init_chatbox' );

    ob_start();
    
    // Enqueue assets
    init_plugin_suite_chat_engine_enqueue_assets( $atts );
    
    // Check for theme template override
    $theme_template = '';
    if ( $atts['theme'] !== 'default' ) {
        $theme_template = locate_template( "init-chat-engine/themes/{$atts['theme']}/chatbox.php" );
    }
    
    // Check for general template override
    $override = locate_template( 'init-chat-engine/chatbox.php' );
    
    if ( $theme_template ) {
        include $theme_template;
    } elseif ( $override ) {
        include $override;
    } else {
        include INIT_PLUGIN_SUITE_CHAT_ENGINE_TEMPLATES_PATH . 'chatbox.php';
    }
    
    return ob_get_clean();
}

/**
 * Enqueue assets with enhanced options
 */
function init_plugin_suite_chat_engine_enqueue_assets( $atts = [] ) {
    // Get all settings from different groups
    $settings = init_plugin_suite_chat_engine_get_all_settings();
    
    // Enqueue CSS
    if ( empty( $settings['disable_css'] ) ) {
        wp_enqueue_style(
            'init-chat-engine-style',
            INIT_PLUGIN_SUITE_CHAT_ENGINE_ASSETS_URL . 'css/style.css',
            [],
            INIT_PLUGIN_SUITE_CHAT_ENGINE_VERSION
        );
        
        // Theme-specific CSS
        if ( ! empty( $atts['theme'] ) && $atts['theme'] !== 'default' ) {
            $theme_css = INIT_PLUGIN_SUITE_CHAT_ENGINE_ASSETS_URL . "css/themes/{$atts['theme']}.css";
            if ( file_exists( INIT_PLUGIN_SUITE_CHAT_ENGINE_ASSETS_PATH . "css/themes/{$atts['theme']}.css" ) ) {
                wp_enqueue_style(
                    "init-chat-engine-theme-{$atts['theme']}",
                    $theme_css,
                    [ 'init-chat-engine-style' ],
                    INIT_PLUGIN_SUITE_CHAT_ENGINE_VERSION
                );
            }
        }
        
        // Custom CSS
        if ( ! empty( $settings['custom_css'] ) ) {
            // Escape CSS để tránh XSS
            $safe_css = wp_strip_all_tags( $settings['custom_css'] );
            wp_add_inline_style( 'init-chat-engine-style', esc_html( $safe_css ) );
        }
    }
    
    // Enqueue JavaScript
    wp_enqueue_script(
        'init-chat-engine',
        INIT_PLUGIN_SUITE_CHAT_ENGINE_ASSETS_URL . 'js/chat.js',
        [],
        INIT_PLUGIN_SUITE_CHAT_ENGINE_VERSION,
        true
    );
    
    // Prepare localized data
    $site_icon_url = function_exists( 'get_site_icon_url' ) ? get_site_icon_url() : '';
    $current_user = wp_get_current_user();
    
    // Override settings with shortcode attributes if provided
    $show_avatars = ! empty( $atts['show_avatars'] ) ? 
        filter_var( $atts['show_avatars'], FILTER_VALIDATE_BOOLEAN ) : 
        ! empty( $settings['show_avatars'] );
        
    $show_timestamps = ! empty( $atts['show_timestamps'] ) ? 
        filter_var( $atts['show_timestamps'], FILTER_VALIDATE_BOOLEAN ) : 
        ! empty( $settings['show_timestamps'] );
    
    $localized_data = [
        'rest_url'             => rest_url( INIT_PLUGIN_SUITE_CHAT_ENGINE_NAMESPACE . '/messages' ),
        'send_url'             => rest_url( INIT_PLUGIN_SUITE_CHAT_ENGINE_NAMESPACE . '/send' ),
        'nonce'                => wp_create_nonce( 'wp_rest' ),
        'current_user'         => $current_user->exists() ? $current_user->display_name : '',
        'current_user_id'      => $current_user->exists() ? $current_user->ID : 0,
        'user_avatar'          => $current_user->exists() ? get_avatar_url( $current_user->ID, [ 'size' => 64 ] ) : '',
        'allow_guests'         => ! empty( $settings['allow_guests'] ),
        'enable_notifications' => ! empty( $settings['enable_notifications'] ),
        'enable_sounds'        => ! empty( $settings['enable_sounds'] ),
        'show_avatars'         => $show_avatars,
        'show_timestamps'      => $show_timestamps,
        'max_message_length'   => isset( $settings['max_message_length'] ) ? (int) $settings['max_message_length'] : 500,
        'rate_limit'           => isset( $settings['rate_limit'] ) ? (int) $settings['rate_limit'] : 60,
        'favicon'              => esc_url( $site_icon_url ),
        'theme'                => ! empty( $atts['theme'] ) ? sanitize_text_field( $atts['theme'] ) : 'default',
        'shortcode_atts'       => [
            'height'       => ! empty( $atts['height'] ) ? sanitize_text_field( $atts['height'] ) : '',
            'width'        => ! empty( $atts['width'] ) ? sanitize_text_field( $atts['width'] ) : '',
            'title'        => ! empty( $atts['title'] ) ? sanitize_text_field( $atts['title'] ) : '',
            'class'        => ! empty( $atts['class'] ) ? sanitize_text_field( $atts['class'] ) : '',
            'id'           => ! empty( $atts['id'] ) ? sanitize_text_field( $atts['id'] ) : '',
        ],
        'i18n' => [
            'empty_message'        => __( 'No messages yet. Be the first to chat!', 'init-chat-engine' ),
            'missing_name'         => __( 'Display name is required.', 'init-chat-engine' ),
            'send_failed'          => __( 'Failed to send message.', 'init-chat-engine' ),
            'network_error'        => __( 'Network error.', 'init-chat-engine' ),
            'new_message'          => __( 'New messages', 'init-chat-engine' ),
            'new_message_title'    => __( 'New message in the chat!', 'init-chat-engine' ),
            'now'                  => __( 'now', 'init-chat-engine' ),
            'banned_message'       => __( 'You have been banned from the chat.', 'init-chat-engine' ),
            'message_too_long'     => __( 'Message is too long.', 'init-chat-engine' ),
            'rate_limit_exceeded'  => __( 'You are sending messages too quickly. Please slow down.', 'init-chat-engine' ),
            'message_blocked'      => __( 'Your message contains blocked words.', 'init-chat-engine' ),
            'typing'               => __( 'is typing...', 'init-chat-engine' ),
            'online'               => __( 'Online', 'init-chat-engine' ),
            'offline'              => __( 'Offline', 'init-chat-engine' ),
            'load_more'            => __( 'Load more messages', 'init-chat-engine' ),
            'loading'              => __( 'Loading...', 'init-chat-engine' ),
            'connection_lost'      => __( 'Connection lost. Trying to reconnect...', 'init-chat-engine' ),
            'connected'            => __( 'Connected', 'init-chat-engine' ),
            'minutes_ago'          => __( 'minutes ago', 'init-chat-engine' ),
            'hours_ago'            => __( 'hours ago', 'init-chat-engine' ),
            'days_ago'             => __( 'days ago', 'init-chat-engine' ),
        ],
    ];
    
    wp_localize_script( 'init-chat-engine', 'InitChatEngineData', $localized_data );
}

/**
 * Render banned message - FIXED TIMEZONE
 */
function init_plugin_suite_chat_engine_render_banned_message( $ban_info ) {
    ob_start();
    ?>
    <div class="init-chatbox-wrapper init-chatbox-banned">
        <div class="init-chatbox-ban-notice">
            <div class="init-chatbox-ban-icon">🚫</div>
            <div class="init-chatbox-ban-content">
                <h4><?php esc_html_e( 'Access Restricted', 'init-chat-engine' ); ?></h4>
                <p><?php esc_html_e( 'You have been banned from participating in the chat.', 'init-chat-engine' ); ?></p>
                
                <?php if ( ! empty( $ban_info->reason ) ) : ?>
                    <p><strong><?php esc_html_e( 'Reason:', 'init-chat-engine' ); ?></strong> <?php echo esc_html( $ban_info->reason ); ?></p>
                <?php endif; ?>
                
                <?php if ( ! empty( $ban_info->expires_at ) ) : ?>
                    <?php
                    // FIX: Vì expires_at đã lưu theo WordPress timezone rồi
                    // Nên chỉ cần format lại, KHÔNG convert timezone nữa
                    $expires_timestamp = strtotime( $ban_info->expires_at );
                    $date_format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
                    ?>
                    <p>
                        <strong><?php esc_html_e( 'Ban expires:', 'init-chat-engine' ); ?></strong> 
                        <?php echo esc_html( date_i18n( $date_format, $expires_timestamp ) ); ?>
                    </p>
                <?php else : ?>
                    <p><em><?php esc_html_e( 'This ban is permanent.', 'init-chat-engine' ); ?></em></p>
                <?php endif; ?>
                
                <small><?php esc_html_e( 'If you believe this is an error, please contact the site administrator.', 'init-chat-engine' ); ?></small>
            </div>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

/**
 * Register additional shortcodes
 */
add_shortcode( 'init_chat_stats', 'init_plugin_suite_chat_engine_stats_shortcode' );

function init_plugin_suite_chat_engine_stats_shortcode( $atts = [] ) {
    $atts = shortcode_atts( [
        'type' => 'basic', // basic, detailed, chart
        'period' => '30', // days
        'show' => 'messages,users', // comma-separated: messages,users,activity
    ], $atts, 'init_chat_stats' );
    
    if ( ! current_user_can( 'manage_options' ) && $atts['type'] !== 'basic' ) {
        return '<p>' . esc_html__( 'You do not have permission to view detailed statistics.', 'init-chat-engine' ) . '</p>';
    }
    
    global $wpdb;
    
    $show_items = array_map( 'trim', explode( ',', $atts['show'] ) );
    $period = absint( $atts['period'] );
    
    ob_start();
    ?>
    <div class="init-chat-stats-widget init-chat-stats-<?php echo esc_attr( $atts['type'] ); ?>">
        <?php if ( in_array( 'messages', $show_items ) ) : ?>
            <?php
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $total_messages = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}init_chatbox_msgs WHERE is_deleted = 0" );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $recent_messages = $wpdb->get_var( 
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->prefix}init_chatbox_msgs 
                     WHERE is_deleted = 0 AND created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)",
                    $period
                )
            );
            ?>
            <div class="init-chat-stat-item">
                <span class="init-chat-stat-label"><?php esc_html_e( 'Total Messages:', 'init-chat-engine' ); ?></span>
                <span class="init-chat-stat-value"><?php echo esc_html( number_format_i18n( $total_messages ) ); ?></span>
                <?php if ( $atts['type'] !== 'basic' ) : ?>
                    <small class="init-chat-stat-detail">
                        (<?php echo esc_html( number_format_i18n( $recent_messages ) ); ?>
                        <?php
                        /* translators: %d: number of days */
                        printf( esc_html__( 'in last %d days', 'init-chat-engine' ), esc_html( $period ) );
                        ?>)
                    </small>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        
        <?php if ( in_array( 'users', $show_items ) ) : ?>
            <?php
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $total_users = $wpdb->get_var( "SELECT COUNT(DISTINCT user_id) FROM {$wpdb->prefix}init_chatbox_msgs WHERE user_id IS NOT NULL AND is_deleted = 0" );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $guest_messages = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}init_chatbox_msgs WHERE user_id IS NULL AND is_deleted = 0" );
            ?>
            <div class="init-chat-stat-item">
                <span class="init-chat-stat-label"><?php esc_html_e( 'Active Users:', 'init-chat-engine' ); ?></span>
                <span class="init-chat-stat-value"><?php echo esc_html( number_format_i18n( $total_users ) ); ?></span>
                <?php if ( $atts['type'] !== 'basic' ) : ?>
                    <small class="init-chat-stat-detail">
                        (<?php echo esc_html( number_format_i18n( $guest_messages ) ); ?> 
                        <?php esc_html_e( 'guest messages', 'init-chat-engine' ); ?>)
                    </small>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        
        <?php if ( in_array( 'activity', $show_items ) && $atts['type'] !== 'basic' ) : ?>
            <?php
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $today_messages = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}init_chatbox_msgs WHERE DATE(created_at) = CURDATE() AND is_deleted = 0" );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $yesterday_messages = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}init_chatbox_msgs WHERE DATE(created_at) = DATE_SUB(CURDATE(), INTERVAL 1 DAY) AND is_deleted = 0" );
            ?>
            <div class="init-chat-stat-item">
                <span class="init-chat-stat-label"><?php esc_html_e( 'Today:', 'init-chat-engine' ); ?></span>
                <span class="init-chat-stat-value"><?php echo esc_html( number_format_i18n( $today_messages ) ); ?></span>
                <small class="init-chat-stat-detail">
                    (<?php esc_html_e( 'Yesterday:', 'init-chat-engine' ); ?> <?php echo esc_html( number_format_i18n( $yesterday_messages ) ); ?>)
                </small>
            </div>
        <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
}

/**
 * Return true = cho phép; false = chặn
 * TÔN TRỌNG CÀI ĐẶT HIỆN CÓ:
 * - enable_word_filter (1/0)
 * - blocked_words (textarea, 1 từ/line)
 * - word_filter_exempt_roles (slug)
 *
 * CHỈ BỔ SUNG QUA FILTER (tuỳ chọn):
 * - init_plugin_suite_chat_engine_bypass_filter( bool $bypass, string $message, WP_User $user|null, array $settings )
 * - init_plugin_suite_chat_engine_blocked_words( array $words, array $settings, string $message ) : array
 * - init_plugin_suite_chat_engine_word_filter_strategy( string $strategy, array $settings, string $message ) : 'word'|'substring'|'regex'
 * - init_plugin_suite_chat_engine_word_block_hit( string $hit, string $raw_message, string $strategy ) : action
 */
function init_plugin_suite_chat_engine_check_message_content( $message ) {
    if ( ! is_string( $message ) ) return false;
    if ( preg_match( '/^\s*$/u', $message ) ) return false;

    $settings = init_plugin_suite_chat_engine_get_all_settings();

    // === 1) BYPASS (cài đặt role exempt + admin)
    $bypass = false;
    $user   = null;

    if ( is_user_logged_in() ) {
        $user = wp_get_current_user();

        if ( user_can( $user, 'manage_options' ) ) {
            $bypass = true;
        } else {
            $user_roles   = array_map( 'strtolower', (array) $user->roles );
            $exempt_roles = array_map( 'strtolower', (array) ( $settings['word_filter_exempt_roles'] ?? [] ) );
            if ( array_intersect( $user_roles, $exempt_roles ) ) {
                $bypass = true;
            }
        }
    }

    // Cho phép bypass thêm bằng code
    $bypass = (bool) apply_filters( 'init_plugin_suite_chat_engine_bypass_filter', $bypass, $message, $user, $settings );
    if ( $bypass ) return true;

    // === 2) Tôn trọng setting bật / tắt
    if ( empty( $settings['enable_word_filter'] ) ) return true;

    // === 3) Lấy danh sách từ cấm từ setting
    $raw_list = isset( $settings['blocked_words'] ) ? (string) $settings['blocked_words'] : '';
    $blocked_words = preg_split( '/\R/u', $raw_list ) ?: [];
    $blocked_words = array_values( array_filter( array_map( 'trim', $blocked_words ), function($x){
        return $x !== '' && strpos( ltrim($x), '#' ) !== 0;
    } ) );

    // Cho phép can thiệp vào list
    $blocked_words = (array) apply_filters( 'init_plugin_suite_chat_engine_blocked_words', $blocked_words, $settings, $message );

    if ( empty( $blocked_words ) ) return true;

    // === 4) Chuẩn hoá Unicode + lower (chỉ dùng cho substring/word)
    $normalize = static function( $str ) {
        if ( class_exists('\Normalizer') ) $str = \Normalizer::normalize( $str, \Normalizer::FORM_C );
        return function_exists('mb_strtolower') ? mb_strtolower( $str, 'UTF-8' ) : strtolower( $str );
    };
    $msg_norm = $normalize( $message );

    // === 5) Chiến lược mặc định: substring (hung hãn)
    $strategy = apply_filters( 'init_plugin_suite_chat_engine_word_filter_strategy', 'substring', $settings, $message );

    foreach ( $blocked_words as $w ) {
        if ( $w === '' ) continue;

        if ( $strategy === 'regex' ) {
            // DÙNG PATTERN THÔ - KHÔNG normalize/lowercase
            $pattern = $w;
            if ( @preg_match( $pattern, '' ) === false ) continue;
            if ( preg_match( $pattern, $message ) ) { // so trên raw message để giữ nguyên ngữ cảnh regex
                do_action( 'init_plugin_suite_chat_engine_word_block_hit', $w, $message, 'regex' );
                return false;
            }
        }
        elseif ( $strategy === 'word' ) {
            $needle = $normalize( $w );
            if ( $needle === '' ) continue;
            $pattern = '/(?<!\pL)' . preg_quote( $needle, '/' ) . '(?!\pL)/u';
            if ( preg_match( $pattern, $msg_norm ) ) {
                do_action( 'init_plugin_suite_chat_engine_word_block_hit', $w, $message, 'word' );
                return false;
            }
        }
        else { // === default SUBSTRING ===
            $needle = $normalize( $w );
            if ( $needle === '' ) continue;
            if ( function_exists('mb_stripos') ? mb_stripos( $msg_norm, $needle, 0, 'UTF-8' ) !== false : stripos( $msg_norm, $needle ) !== false ) {
                do_action( 'init_plugin_suite_chat_engine_word_block_hit', $w, $message, 'substring' );
                return false;
            }
        }
    }

    return true;
}

/**
 * Get chatbox container attributes from shortcode - CLEANED UP
 */
function init_plugin_suite_chat_engine_get_container_attributes( $atts ) {
    $attributes = [];
    
    if ( ! empty( $atts['id'] ) ) {
        $attributes['id'] = sanitize_text_field( $atts['id'] );
    }
    
    $classes = [ 'init-chatbox-wrapper' ];
    if ( ! empty( $atts['class'] ) ) {
        $classes[] = sanitize_text_field( $atts['class'] );
    }
    if ( ! empty( $atts['theme'] ) && $atts['theme'] !== 'default' ) {
        $classes[] = 'init-chatbox-theme-' . sanitize_text_field( $atts['theme'] );
    }
    $attributes['class'] = implode( ' ', $classes );
    
    $styles = [];
    if ( ! empty( $atts['width'] ) ) {
        $styles[] = 'width: ' . sanitize_text_field( $atts['width'] );
    }
    if ( ! empty( $atts['height'] ) ) {
        $styles[] = 'height: ' . sanitize_text_field( $atts['height'] );
    }
    // REMOVED max_height from styles too
    if ( ! empty( $styles ) ) {
        $attributes['style'] = implode( '; ', $styles );
    }
    
    return $attributes;
}
