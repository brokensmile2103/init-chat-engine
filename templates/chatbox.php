<?php
defined( 'ABSPATH' ) || exit;

// Get settings from all groups
$settings = init_plugin_suite_chat_engine_get_all_settings();
$allow_guests = ! empty( $settings['allow_guests'] );
$is_logged_in = is_user_logged_in();
$login_url = wp_login_url();

// Get container attributes from shortcode
$container_attrs = isset( $atts ) ? init_plugin_suite_chat_engine_get_container_attributes( $atts ) : [];
$container_id = isset( $container_attrs['id'] ) ? $container_attrs['id'] : 'init-chatbox-root';
$container_class = isset( $container_attrs['class'] ) ? $container_attrs['class'] : 'init-chatbox-wrapper';
$container_style = isset( $container_attrs['style'] ) ? $container_attrs['style'] : '';

// Check if user is banned (this should have been checked in shortcode, but double-check)
$user_ip = init_plugin_suite_chat_engine_get_user_ip();
$user_id = $is_logged_in ? get_current_user_id() : null;
$ban_check = init_plugin_suite_chat_engine_check_user_banned( $user_id, $user_ip );

if ( $ban_check ) {
    echo wp_kses_post( init_plugin_suite_chat_engine_render_banned_message( $ban_check ) );
    return;
}

// Get title from shortcode or use default
$chatbox_title = '';
if ( isset( $atts['title'] ) && ! empty( $atts['title'] ) ) {
    $chatbox_title = sanitize_text_field( $atts['title'] );
}

// Check if features are enabled (only use existing settings)
$show_avatars    = isset( $settings['show_avatars'] ) ? (bool) $settings['show_avatars'] : true;
$show_timestamps = isset( $settings['show_timestamps'] ) ? (bool) $settings['show_timestamps'] : true;

// Override with shortcode attributes if provided
if ( isset( $atts['show_avatars'] ) ) {
    $show_avatars = filter_var( $atts['show_avatars'], FILTER_VALIDATE_BOOLEAN );
}
if ( isset( $atts['show_timestamps'] ) ) {
    $show_timestamps = filter_var( $atts['show_timestamps'], FILTER_VALIDATE_BOOLEAN );
}
?>

<div class="<?php echo esc_attr( $container_class ); ?>" 
     id="<?php echo esc_attr( $container_id ); ?>"
     <?php if ( $container_style ) : ?>style="<?php echo esc_attr( $container_style ); ?>"<?php endif; ?>
     data-show-avatars="<?php echo $show_avatars ? 'true' : 'false'; ?>"
     data-show-timestamps="<?php echo $show_timestamps ? 'true' : 'false'; ?>">

    <?php if ( $chatbox_title ) : ?>
    <div class="init-chatbox-header">
        <h3 class="init-chatbox-title"><?php echo esc_html( $chatbox_title ); ?></h3>
    </div>
    <?php endif; ?>

    <!-- Message display area - LUÔN HIỂN THỊ -->
    <div class="init-chatbox-messages<?php echo init_plugin_suite_chat_engine_has_messages() ? ' expand' : ' shrink'; ?>" 
         id="init-chatbox-messages">
        
        <!-- Loading indicator -->
        <div class="init-chatbox-loading" id="init-chatbox-loading">
            <div class="init-chatbox-loading-spinner"></div>
            <span><?php esc_html_e( 'Loading messages...', 'init-chat-engine' ); ?></span>
        </div>
        
        <!-- Load more button (at top) -->
        <div class="init-chatbox-load-more ice-hidden" id="init-chatbox-load-more">
            <button type="button" class="init-chatbox-load-more-btn" id="init-chatbox-load-more-btn">
                <?php esc_html_e( 'Load older messages', 'init-chat-engine' ); ?>
            </button>
        </div>
        
        <!-- Messages will be injected here by JavaScript -->
        <div class="init-chatbox-messages-list" id="init-chatbox-messages-list"></div>
    </div>

    <!-- Connection status -->
    <div class="init-chatbox-connection-status ice-hidden" id="init-chatbox-connection-status">
        <span class="init-chatbox-connection-text"></span>
    </div>

    <?php if ( ! $is_logged_in && ! $allow_guests ) : ?>
        <!-- Login required message (thay thế input area) -->
        <div class="init-chatbox-input-wrapper">
            <div class="init-chatbox-login-required">
                <div class="init-chatbox-login-icon">🔒</div>
                <div class="init-chatbox-login-content">
                    <p>
                        <?php
                        echo wp_kses_post(
                            sprintf(
                                // translators: %s is the login URL wrapped around "log in" link text.
                                __( 'You must <a href="%s">log in</a> to join the chat.', 'init-chat-engine' ),
                                esc_url( $login_url )
                            )
                        );
                        ?>
                    </p>
                    <small><?php esc_html_e( 'Please log in to participate in the conversation.', 'init-chat-engine' ); ?></small>
                </div>
            </div>
        </div>

    <?php elseif ( $is_logged_in ) : ?>
        <!-- Logged in user interface -->
        <div class="init-chatbox-input-wrapper">
            <form id="init-chatbox-form" class="init-chatbox-form">
                <div class="init-chatbox-input-group">
                    <input type="text" 
                           id="init-chatbox-message" 
                           class="init-chatbox-input-message" 
                           placeholder="<?php esc_attr_e( 'Type a message...', 'init-chat-engine' ); ?>" 
                           maxlength="<?php echo esc_attr( isset( $settings['max_message_length'] ) ? $settings['max_message_length'] : 500 ); ?>"
                           autocomplete="off"
                           required />
                    
                    <button type="submit" class="init-chatbox-submit" id="init-chatbox-submit">
                        <span class="init-chatbox-submit-text"><?php esc_html_e( 'Send', 'init-chat-engine' ); ?></span>
                        <span class="init-chatbox-submit-icon"><svg width="20" height="20" viewBox="0 0 32 32" fill="#fff"><path d="M10.4 21.8 16 32 32 1zM0 15l9.3 5.6L31 0z" fill-rule="evenodd"/></svg></span>
                    </button>
                </div>
                
                <div class="init-chatbox-input-info">
                    <span class="init-chatbox-char-count" id="init-chatbox-char-count">
                        <span class="init-chatbox-char-current">0</span>/<span class="init-chatbox-char-max"><?php echo esc_html( isset( $settings['max_message_length'] ) ? $settings['max_message_length'] : 500 ); ?></span>
                    </span>
                    
                    <span class="init-chatbox-rate-limit ice-hidden" id="init-chatbox-rate-limit">
                        <?php esc_html_e( 'Please slow down...', 'init-chat-engine' ); ?>
                    </span>
                </div>
            </form>
        </div>

    <?php elseif ( $allow_guests ) : ?>
        <!-- Guest interface -->
        <div class="init-chatbox-guest-wrapper">
            <!-- Name input form -->
            <div class="init-chatbox-name-form" id="init-chatbox-name-form">
                <div class="init-chatbox-name-header">
                    <h4><?php esc_html_e( 'Join the conversation', 'init-chat-engine' ); ?></h4>
                    <p><?php esc_html_e( 'Enter your name to start chatting', 'init-chat-engine' ); ?></p>
                </div>
                
                <div class="init-chatbox-name-input-group">
                    <input type="text" 
                           id="init-chatbox-guest-name" 
                           class="init-chatbox-input-name" 
                           placeholder="<?php esc_attr_e( 'Your name...', 'init-chat-engine' ); ?>" 
                           maxlength="50"
                           autocomplete="name"
                           required />
                    
                    <button type="button" id="init-chatbox-set-name" class="init-chatbox-submit">
                        <span class="init-chatbox-submit-text"><?php esc_html_e( 'Join Chat', 'init-chat-engine' ); ?></span>
                        <span class="init-chatbox-submit-icon">👋</span>
                    </button>
                </div>
                
                <small class="init-chatbox-guest-notice">
                    <?php esc_html_e( 'By joining, you agree to follow the community guidelines.', 'init-chat-engine' ); ?>
                </small>
            </div>

            <!-- Active chat interface (hidden initially) -->
            <div class="init-chatbox-active ice-hidden" id="init-chatbox-active">
                <div class="init-chatbox-user-info">
                    <div class="init-chatbox-current-user">
                        <span class="init-chatbox-user-label"><?php esc_html_e( 'Chatting as', 'init-chat-engine' ); ?></span>
                        <strong class="init-chatbox-user-name" id="init-chatbox-current-display"></strong>
                        <button type="button" id="init-chatbox-change-name" class="init-chatbox-change-name" title="<?php esc_attr_e( 'Change name', 'init-chat-engine' ); ?>">
                            <span class="init-chatbox-change-icon">✏️</span>
                            <span class="init-chatbox-change-text"><?php esc_html_e( 'Change', 'init-chat-engine' ); ?></span>
                        </button>
                    </div>
                </div>

                <form id="init-chatbox-form" class="init-chatbox-form">
                    <div class="init-chatbox-input-group">
                        <input type="text" 
                               id="init-chatbox-message" 
                               class="init-chatbox-input-message" 
                               placeholder="<?php esc_attr_e( 'Type a message...', 'init-chat-engine' ); ?>" 
                               maxlength="<?php echo esc_attr( isset( $settings['max_message_length'] ) ? $settings['max_message_length'] : 500 ); ?>"
                               autocomplete="off"
                               required />
                        
                        <button type="submit" class="init-chatbox-submit" id="init-chatbox-submit-guest">
                            <span class="init-chatbox-submit-text"><?php esc_html_e( 'Send', 'init-chat-engine' ); ?></span>
                            <span class="init-chatbox-submit-icon">📤</span>
                        </button>
                    </div>
                    
                    <div class="init-chatbox-input-info">
                        <span class="init-chatbox-char-count" id="init-chatbox-char-count-guest">
                            <span class="init-chatbox-char-current">0</span>/<span class="init-chatbox-char-max"><?php echo esc_html( isset( $settings['max_message_length'] ) ? $settings['max_message_length'] : 500 ); ?></span>
                        </span>
                        
                        <span class="init-chatbox-rate-limit ice-hidden" id="init-chatbox-rate-limit-guest">
                            <?php esc_html_e( 'Please slow down...', 'init-chat-engine' ); ?>
                        </span>
                    </div>
                </form>
            </div>
        </div>

    <?php else : ?>
        <!-- Fallback: This should not happen, but just in case -->
        <div class="init-chatbox-input-wrapper">
            <div class="init-chatbox-login-required">
                <div class="init-chatbox-login-icon">🔒</div>
                <div class="init-chatbox-login-content">
                    <p>
                        <?php
                        echo wp_kses_post(
                            sprintf(
                                // translators: %s is the login URL wrapped around "log in" link text.
                                __( 'You must <a href="%s">log in</a> to join the chat.', 'init-chat-engine' ),
                                esc_url( $login_url )
                            )
                        );
                        ?>
                    </p>
                    <small><?php esc_html_e( 'Please log in to participate in the conversation.', 'init-chat-engine' ); ?></small>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Error messages -->
    <div class="init-chatbox-error ice-hidden" id="init-chatbox-error">
        <span class="init-chatbox-error-icon">⚠️</span>
        <span class="init-chatbox-error-text" id="init-chatbox-error-text"></span>
        <button type="button" class="init-chatbox-error-close" id="init-chatbox-error-close">×</button>
    </div>
</div>
