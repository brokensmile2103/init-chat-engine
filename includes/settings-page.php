<?php
defined( 'ABSPATH' ) || exit;

/**
 * Add settings menu
 */
add_action( 'admin_menu', 'init_plugin_suite_chat_engine_register_settings_page' );

function init_plugin_suite_chat_engine_register_settings_page() {
    // Main menu page
    add_menu_page(
        __( 'Init Chat Engine', 'init-chat-engine' ),
        __( 'Chat Engine', 'init-chat-engine' ),
        'manage_options',
        INIT_PLUGIN_SUITE_CHAT_ENGINE_SLUG,
        'init_plugin_suite_chat_engine_render_settings_page',
        'dashicons-format-chat',
        102
    );
    
    // Settings submenu (same as main page)
    add_submenu_page(
        INIT_PLUGIN_SUITE_CHAT_ENGINE_SLUG,
        __( 'Settings', 'init-chat-engine' ),
        __( 'Settings', 'init-chat-engine' ),
        'manage_options',
        INIT_PLUGIN_SUITE_CHAT_ENGINE_SLUG,
        'init_plugin_suite_chat_engine_render_settings_page'
    );
}

/**
 * Register settings with validation
 */
add_action( 'admin_init', 'init_plugin_suite_chat_engine_register_settings' );

function init_plugin_suite_chat_engine_register_settings() {
    // Register basic settings
    register_setting(
        'init_chat_basic_settings',
        'init_chat_basic_settings',
        [
            'sanitize_callback' => 'init_plugin_suite_chat_engine_sanitize_basic_settings',
        ]
    );
    
    // Register security settings
    register_setting(
        'init_chat_security_settings',
        'init_chat_security_settings',
        [
            'sanitize_callback' => 'init_plugin_suite_chat_engine_sanitize_security_settings',
        ]
    );
    
    // Register advanced settings
    register_setting(
        'init_chat_advanced_settings',
        'init_chat_advanced_settings',
        [
            'sanitize_callback' => 'init_plugin_suite_chat_engine_sanitize_advanced_settings',
        ]
    );
}

/**
 * Sanitize basic settings
 */
function init_plugin_suite_chat_engine_sanitize_basic_settings( $input ) {
    $sanitized = [];
    $errors = [];

    $sanitized['allow_guests']         = ! empty( $input['allow_guests'] ) ? 1 : 0;
    $sanitized['enable_notifications'] = ! empty( $input['enable_notifications'] ) ? 1 : 0;
    $sanitized['enable_sounds']        = ! empty( $input['enable_sounds'] ) ? 1 : 0;
    $sanitized['show_avatars']         = ! empty( $input['show_avatars'] ) ? 1 : 0;
    $sanitized['show_timestamps']      = ! empty( $input['show_timestamps'] ) ? 1 : 0;

    // Message limits
    $max = isset( $input['max_messages'] ) ? absint( $input['max_messages'] ) : 1000;
    if ( $max < 10 ) {
        $max = 10;
        $errors[] = __( 'Maximum messages cannot be less than 10.', 'init-chat-engine' );
    } elseif ( $max > 10000 ) {
        $max = 10000;
        $errors[] = __( 'Maximum messages cannot exceed 10,000.', 'init-chat-engine' );
    }
    $sanitized['max_messages'] = $max;

    // Message length
    $max_length = isset( $input['max_message_length'] ) ? absint( $input['max_message_length'] ) : 500;
    if ( $max_length < 50 ) {
        $max_length = 50;
        $errors[] = __( 'Maximum message length cannot be less than 50 characters.', 'init-chat-engine' );
    } elseif ( $max_length > 2000 ) {
        $max_length = 2000;
        $errors[] = __( 'Maximum message length cannot exceed 2,000 characters.', 'init-chat-engine' );
    }
    $sanitized['max_message_length'] = $max_length;

    if ( ! empty( $errors ) ) {
        add_settings_error( 'init_chat_basic_settings', 'validation_errors', implode( '<br>', $errors ), 'error' );
    }

    return $sanitized;
}

/**
 * Sanitize security settings
 */
function init_plugin_suite_chat_engine_sanitize_security_settings( $input ) {
    $sanitized = [];
    $errors = [];

    // Rate limit
    $rate_limit = isset( $input['rate_limit'] ) ? absint( $input['rate_limit'] ) : 60;
    if ( $rate_limit < 1 ) {
        $rate_limit = 1;
        $errors[] = __( 'Rate limit cannot be less than 1 message per minute.', 'init-chat-engine' );
    } elseif ( $rate_limit > 100 ) {
        $rate_limit = 100;
        $errors[] = __( 'Rate limit cannot exceed 100 messages per minute.', 'init-chat-engine' );
    }
    $sanitized['rate_limit'] = $rate_limit;

    $sanitized['enable_word_filter'] = ! empty( $input['enable_word_filter'] ) ? 1 : 0;
    $sanitized['blocked_words'] = isset( $input['blocked_words'] ) ? sanitize_textarea_field( $input['blocked_words'] ) : '';
    $sanitized['require_moderation'] = ! empty( $input['require_moderation'] ) ? 1 : 0;

    // Cleanup settings
    $cleanup_days = isset( $input['cleanup_days'] ) ? absint( $input['cleanup_days'] ) : 30;
    if ( $cleanup_days > 0 && $cleanup_days < 1 ) {
        $cleanup_days = 1;
        $errors[] = __( 'Cleanup days must be at least 1 day or 0 to disable.', 'init-chat-engine' );
    }
    $sanitized['cleanup_days'] = $cleanup_days;

        // Exempt roles from word filtering
    $exempt_roles_input = isset( $input['word_filter_exempt_roles'] ) ? (array) $input['word_filter_exempt_roles'] : array();
    $exempt_roles_input = array_map( 'sanitize_key', $exempt_roles_input );

    // Only keep valid, existing roles
    if ( function_exists( 'wp_roles' ) ) {
        $all_roles = wp_roles()->roles;
        $valid_role_slugs = array_keys( $all_roles );
        $exempt_roles_input = array_values( array_intersect( $exempt_roles_input, $valid_role_slugs ) );
    }

    // Default to administrator if nothing selected
    if ( empty( $exempt_roles_input ) ) {
        $exempt_roles_input = array( 'administrator' );
    }

    $sanitized['word_filter_exempt_roles'] = $exempt_roles_input;

    if ( ! empty( $errors ) ) {
        add_settings_error( 'init_chat_security_settings', 'validation_errors', implode( '<br>', $errors ), 'error' );
    }

    return $sanitized;
}

/**
 * Sanitize advanced settings
 */
function init_plugin_suite_chat_engine_sanitize_advanced_settings( $input ) {
    $sanitized = [];

    $sanitized['disable_css'] = ! empty( $input['disable_css'] ) ? 1 : 0;
    $sanitized['custom_css'] = isset( $input['custom_css'] ) ? wp_strip_all_tags( $input['custom_css'] ) : '';

    return $sanitized;
}

/**
 * Get combined settings from all groups
 */
function init_plugin_suite_chat_engine_get_all_settings() {
    $basic = get_option( 'init_chat_basic_settings', [] );
    $security = get_option( 'init_chat_security_settings', [] );
    $advanced = get_option( 'init_chat_advanced_settings', [] );
    
    return array_merge( $basic, $security, $advanced );
}

/**
 * Render main settings page
 */
function init_plugin_suite_chat_engine_render_settings_page() {
    // Handle manual cleanup
    if (
        isset( $_GET['action'], $_GET['_wpnonce'] ) &&
        $_GET['action'] === 'cleanup' &&
        wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'init_chat_cleanup' )
    ) {
        init_plugin_suite_chat_engine_cleanup_messages();
        echo '<div class="notice notice-success"><p>' . esc_html__( 'Cleanup completed successfully.', 'init-chat-engine' ) . '</p></div>';
    }
    
    $settings = init_plugin_suite_chat_engine_get_all_settings();
    $active_tab = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : 'basic';
    ?>
    <div class="wrap">
        <h1><?php esc_html_e( 'Init Chat Engine Settings', 'init-chat-engine' ); ?></h1>

        <?php settings_errors(); ?>

        <div class="notice notice-info">
            <p>
                <?php esc_html_e( 'To embed the chatbox, use the shortcode:', 'init-chat-engine' ); ?>
                <code>[init_chatbox]</code>
                <br>
                <strong><?php esc_html_e( 'Version:', 'init-chat-engine' ); ?></strong> <?php echo esc_html( INIT_PLUGIN_SUITE_CHAT_ENGINE_VERSION ); ?>
                &nbsp;|&nbsp;
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=init-chat-management' ) ); ?>">
                    <?php esc_html_e( 'Manage Chat &rarr;', 'init-chat-engine' ); ?>
                </a>
            </p>
        </div>

        <nav class="nav-tab-wrapper">
            <a href="?page=<?php echo esc_attr( INIT_PLUGIN_SUITE_CHAT_ENGINE_SLUG ); ?>&tab=basic" class="nav-tab <?php echo $active_tab === 'basic' ? 'nav-tab-active' : ''; ?>">
                <?php esc_html_e( 'Basic Settings', 'init-chat-engine' ); ?>
            </a>
            <a href="?page=<?php echo esc_attr( INIT_PLUGIN_SUITE_CHAT_ENGINE_SLUG ); ?>&tab=security" class="nav-tab <?php echo $active_tab === 'security' ? 'nav-tab-active' : ''; ?>">
                <?php esc_html_e( 'Security', 'init-chat-engine' ); ?>
            </a>
            <a href="?page=<?php echo esc_attr( INIT_PLUGIN_SUITE_CHAT_ENGINE_SLUG ); ?>&tab=advanced" class="nav-tab <?php echo $active_tab === 'advanced' ? 'nav-tab-active' : ''; ?>">
                <?php esc_html_e( 'Advanced', 'init-chat-engine' ); ?>
            </a>
        </nav>

        <?php if ( $active_tab === 'basic' ) : ?>
            <form method="post" action="options.php">
                <?php settings_fields( 'init_chat_basic_settings' ); ?>
                <?php init_plugin_suite_chat_engine_render_basic_settings( $settings ); ?>
                <?php submit_button(); ?>
            </form>
        <?php elseif ( $active_tab === 'security' ) : ?>
            <form method="post" action="options.php">
                <?php settings_fields( 'init_chat_security_settings' ); ?>
                <?php init_plugin_suite_chat_engine_render_security_settings( $settings ); ?>
                <?php submit_button(); ?>
            </form>
        <?php elseif ( $active_tab === 'advanced' ) : ?>
            <form method="post" action="options.php">
                <?php settings_fields( 'init_chat_advanced_settings' ); ?>
                <?php init_plugin_suite_chat_engine_render_advanced_settings( $settings ); ?>
                <?php submit_button(); ?>
            </form>
        <?php endif; ?>
    </div>
    <?php
}

/**
 * Render basic settings tab
 */
function init_plugin_suite_chat_engine_render_basic_settings( $settings ) {
    $allow_guests         = ! empty( $settings['allow_guests'] );
    $max_messages         = isset( $settings['max_messages'] ) ? (int) $settings['max_messages'] : 1000;
    $max_message_length   = isset( $settings['max_message_length'] ) ? (int) $settings['max_message_length'] : 500;
    $enable_notifications = ! empty( $settings['enable_notifications'] );
    $enable_sounds        = ! empty( $settings['enable_sounds'] );
    $show_avatars         = isset( $settings['show_avatars'] ) ? $settings['show_avatars'] : 1;
    $show_timestamps      = isset( $settings['show_timestamps'] ) ? $settings['show_timestamps'] : 1;
    ?>
    <table class="form-table" role="presentation">
        <tr>
            <th scope="row"><?php esc_html_e( 'User Access', 'init-chat-engine' ); ?></th>
            <td>
                <fieldset>
                    <label for="init_plugin_suite_chat_engine_allow_guests">
                        <input type="checkbox" id="init_plugin_suite_chat_engine_allow_guests" 
                               name="init_chat_basic_settings[allow_guests]" 
                               value="1" <?php checked( $allow_guests, true ); ?>>
                        <?php esc_html_e( 'Allow guests to send messages', 'init-chat-engine' ); ?>
                    </label>
                    <p class="description"><?php esc_html_e( 'When enabled, visitors who are not logged in can participate in the chat.', 'init-chat-engine' ); ?></p>
                </fieldset>
            </td>
        </tr>

        <tr>
            <th scope="row">
                <label for="init_plugin_suite_chat_engine_max_messages">
                    <?php esc_html_e( 'Maximum Messages', 'init-chat-engine' ); ?>
                </label>
            </th>
            <td>
                <input type="number" min="10" max="10000" step="1" 
                       id="init_plugin_suite_chat_engine_max_messages" 
                       name="init_chat_basic_settings[max_messages]" 
                       value="<?php echo esc_attr( $max_messages ); ?>" class="small-text">
                <p class="description"><?php esc_html_e( 'Maximum number of messages to keep in the database. Older messages will be automatically deleted.', 'init-chat-engine' ); ?></p>
            </td>
        </tr>

        <tr>
            <th scope="row">
                <label for="init_plugin_suite_chat_engine_max_message_length">
                    <?php esc_html_e( 'Maximum Message Length', 'init-chat-engine' ); ?>
                </label>
            </th>
            <td>
                <input type="number" min="50" max="2000" step="1" 
                       id="init_plugin_suite_chat_engine_max_message_length" 
                       name="init_chat_basic_settings[max_message_length]" 
                       value="<?php echo esc_attr( $max_message_length ); ?>" class="small-text">
                <p class="description"><?php esc_html_e( 'Maximum number of characters allowed per message.', 'init-chat-engine' ); ?></p>
            </td>
        </tr>

        <tr>
            <th scope="row"><?php esc_html_e( 'Display Options', 'init-chat-engine' ); ?></th>
            <td>
                <fieldset>
                    <label for="init_plugin_suite_chat_engine_show_avatars">
                        <input type="checkbox" id="init_plugin_suite_chat_engine_show_avatars" 
                               name="init_chat_basic_settings[show_avatars]" 
                               value="1" <?php checked( $show_avatars, true ); ?>>
                        <?php esc_html_e( 'Show user avatars', 'init-chat-engine' ); ?>
                    </label><br>

                    <label for="init_plugin_suite_chat_engine_show_timestamps">
                        <input type="checkbox" id="init_plugin_suite_chat_engine_show_timestamps" 
                               name="init_chat_basic_settings[show_timestamps]" 
                               value="1" <?php checked( $show_timestamps, true ); ?>>
                        <?php esc_html_e( 'Show message timestamps', 'init-chat-engine' ); ?>
                    </label>
                </fieldset>
            </td>
        </tr>

        <tr>
            <th scope="row"><?php esc_html_e( 'Notifications', 'init-chat-engine' ); ?></th>
            <td>
                <fieldset>
                    <label for="init_plugin_suite_chat_engine_enable_notifications">
                        <input type="checkbox" id="init_plugin_suite_chat_engine_enable_notifications" 
                               name="init_chat_basic_settings[enable_notifications]" 
                               value="1" <?php checked( $enable_notifications, true ); ?>>
                        <?php esc_html_e( 'Enable browser notifications', 'init-chat-engine' ); ?>
                    </label>
                    <p class="description"><?php esc_html_e( 'Show browser notifications when the tab is not focused. Requires HTTPS and user permission.', 'init-chat-engine' ); ?></p>

                    <label for="init_plugin_suite_chat_engine_enable_sounds">
                        <input type="checkbox" id="init_plugin_suite_chat_engine_enable_sounds" 
                               name="init_chat_basic_settings[enable_sounds]" 
                               value="1" <?php checked( $enable_sounds, true ); ?>>
                        <?php esc_html_e( 'Enable notification sounds', 'init-chat-engine' ); ?>
                    </label>
                </fieldset>
            </td>
        </tr>
    </table>
    <?php
}

/**
 * Render security settings tab
 */
function init_plugin_suite_chat_engine_render_security_settings( $settings ) {
    $rate_limit = isset( $settings['rate_limit'] ) ? (int) $settings['rate_limit'] : 60;
    $enable_word_filter = ! empty( $settings['enable_word_filter'] );
    $blocked_words = isset( $settings['blocked_words'] ) ? $settings['blocked_words'] : '';
    $require_moderation = ! empty( $settings['require_moderation'] );
    $cleanup_days = isset( $settings['cleanup_days'] ) ? (int) $settings['cleanup_days'] : 30;
    ?>
    <table class="form-table" role="presentation">
        <tr>
            <th scope="row">
                <label for="init_plugin_suite_chat_engine_rate_limit">
                    <?php esc_html_e( 'Rate Limiting', 'init-chat-engine' ); ?>
                </label>
            </th>
            <td>
                <input type="number" min="1" max="100" step="1" 
                       id="init_plugin_suite_chat_engine_rate_limit" 
                       name="init_chat_security_settings[rate_limit]" 
                       value="<?php echo esc_attr( $rate_limit ); ?>" class="small-text">
                <span><?php esc_html_e( 'messages per minute', 'init-chat-engine' ); ?></span>
                <p class="description"><?php esc_html_e( 'Maximum number of messages a user can send per minute to prevent spam.', 'init-chat-engine' ); ?></p>
            </td>
        </tr>

        <tr>
            <th scope="row"><?php esc_html_e( 'Word Filtering', 'init-chat-engine' ); ?></th>
            <td>
                <fieldset>
                    <label for="init_plugin_suite_chat_engine_enable_word_filter">
                        <input type="checkbox" id="init_plugin_suite_chat_engine_enable_word_filter" 
                               name="init_chat_security_settings[enable_word_filter]" 
                               value="1" <?php checked( $enable_word_filter, true ); ?>>
                        <?php esc_html_e( 'Enable word filtering', 'init-chat-engine' ); ?>
                    </label>
                    <p class="description"><?php esc_html_e( 'Block messages containing prohibited words.', 'init-chat-engine' ); ?></p>

                    <label for="init_plugin_suite_chat_engine_blocked_words">
                        <?php esc_html_e( 'Blocked Words', 'init-chat-engine' ); ?>
                    </label>
                    <textarea id="init_plugin_suite_chat_engine_blocked_words" 
                              name="init_chat_security_settings[blocked_words]" 
                              rows="5" cols="50" class="large-text"><?php echo esc_textarea( $blocked_words ); ?></textarea>
                    <p class="description"><?php esc_html_e( 'Enter blocked words/phrases, one per line. Case-insensitive matching.', 'init-chat-engine' ); ?></p>
                </fieldset>
            </td>
        </tr>

        <?php
        // Default: administrator is checked if not saved yet
        $exempt_roles = isset( $settings['word_filter_exempt_roles'] )
            ? (array) $settings['word_filter_exempt_roles']
            : array( 'administrator' );

        if ( function_exists( 'wp_roles' ) ) {
            $all_roles = wp_roles()->roles;
        } else {
            $all_roles = array();
        }
        ?>
        <tr>
            <th scope="row"><?php esc_html_e( 'Word Filter Exceptions', 'init-chat-engine' ); ?></th>
            <td>
                <fieldset>
                    <legend class="screen-reader-text">
                        <span><?php esc_html_e( 'Roles allowed to bypass word filter', 'init-chat-engine' ); ?></span>
                    </legend>

                    <?php foreach ( $all_roles as $role_slug => $role_obj ) : ?>
                        <label style="display:inline-block;margin-right:10px !important;">
                            <input type="checkbox"
                                   name="init_chat_security_settings[word_filter_exempt_roles][]"
                                   value="<?php echo esc_attr( $role_slug ); ?>"
                                   <?php checked( in_array( $role_slug, $exempt_roles, true ), true ); ?>>
                            <?php echo esc_html( translate_user_role( $role_obj['name'] ) ); ?>
                        </label>
                    <?php endforeach; ?>

                    <p class="description">
                        <?php esc_html_e( 'Selected roles can send messages containing blocked words (bypass the word filter).', 'init-chat-engine' ); ?>
                    </p>
                </fieldset>
            </td>
        </tr>

        <tr>
            <th scope="row"><?php esc_html_e( 'Moderation', 'init-chat-engine' ); ?></th>
            <td>
                <fieldset>
                    <label for="init_plugin_suite_chat_engine_require_moderation">
                        <input type="checkbox" id="init_plugin_suite_chat_engine_require_moderation" 
                               name="init_chat_security_settings[require_moderation]" 
                               value="1" <?php checked( $require_moderation, true ); ?>>
                        <?php esc_html_e( 'Require message moderation', 'init-chat-engine' ); ?>
                    </label>
                    <p class="description"><?php esc_html_e( 'All messages must be approved before appearing in the chat.', 'init-chat-engine' ); ?></p>
                </fieldset>
            </td>
        </tr>

        <tr>
            <th scope="row">
                <label for="init_plugin_suite_chat_engine_cleanup_days">
                    <?php esc_html_e( 'Automatic Cleanup', 'init-chat-engine' ); ?>
                </label>
            </th>
            <td>
                <input type="number" min="0" max="365" step="1" 
                       id="init_plugin_suite_chat_engine_cleanup_days" 
                       name="init_chat_security_settings[cleanup_days]" 
                       value="<?php echo esc_attr( $cleanup_days ); ?>" class="small-text">
                <span><?php esc_html_e( 'days', 'init-chat-engine' ); ?></span>
                <p class="description"><?php esc_html_e( 'Automatically delete old messages after this many days. Set to 0 to disable automatic cleanup.', 'init-chat-engine' ); ?></p>
            </td>
        </tr>
    </table>
    <?php
}

/**
 * Render advanced settings tab
 */
function init_plugin_suite_chat_engine_render_advanced_settings( $settings ) {
    $disable_css = ! empty( $settings['disable_css'] );
    $custom_css = isset( $settings['custom_css'] ) ? $settings['custom_css'] : '';
    ?>
    <table class="form-table" role="presentation">
        <tr>
            <th scope="row"><?php esc_html_e( 'Styling', 'init-chat-engine' ); ?></th>
            <td>
                <fieldset>
                    <label for="init_plugin_suite_chat_engine_disable_css">
                        <input type="checkbox" id="init_plugin_suite_chat_engine_disable_css" 
                               name="init_chat_advanced_settings[disable_css]" 
                               value="1" <?php checked( $disable_css, true ); ?>>
                        <?php esc_html_e( 'Disable plugin CSS', 'init-chat-engine' ); ?>
                    </label>
                    <p class="description"><?php esc_html_e( 'Disable the default plugin styles to use your own custom CSS.', 'init-chat-engine' ); ?></p>

                    <label for="init_plugin_suite_chat_engine_custom_css">
                        <?php esc_html_e( 'Custom CSS', 'init-chat-engine' ); ?>
                    </label>
                    <textarea id="init_plugin_suite_chat_engine_custom_css" 
                              name="init_chat_advanced_settings[custom_css]" 
                              rows="10" cols="50" class="large-text code"><?php echo esc_textarea( $custom_css ); ?></textarea>
                    <p class="description"><?php esc_html_e( 'Add custom CSS styles for the chatbox. This will be added to the page when the shortcode is used.', 'init-chat-engine' ); ?></p>
                </fieldset>
            </td>
        </tr>

        <tr>
            <th scope="row"><?php esc_html_e( 'Database Information', 'init-chat-engine' ); ?></th>
            <td>
                <?php
                global $wpdb;
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                $total_messages = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}init_chatbox_msgs WHERE is_deleted = 0" );
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                $total_banned = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}init_chatbox_banned WHERE is_active = 1" );
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                $db_size = $wpdb->get_var( 
                    $wpdb->prepare(
                        "SELECT ROUND(SUM((data_length + index_length) / 1024 / 1024), 2) AS 'DB Size in MB' 
                         FROM information_schema.tables 
                         WHERE table_schema=%s 
                         AND table_name LIKE %s",
                        $wpdb->dbname,
                        $wpdb->prefix . 'init_chatbox_%'
                    )
                );
                ?>
                <p>
                    <strong><?php esc_html_e( 'Total Messages:', 'init-chat-engine' ); ?></strong> <?php echo esc_html( number_format_i18n( $total_messages ) ); ?><br>
                    <strong><?php esc_html_e( 'Active Bans:', 'init-chat-engine' ); ?></strong> <?php echo esc_html( number_format_i18n( $total_banned ) ); ?><br>
                    <strong><?php esc_html_e( 'Database Size:', 'init-chat-engine' ); ?></strong> <?php echo esc_html( $db_size ); ?> MB
                </p>
            </td>
        </tr>

        <tr>
            <th scope="row"><?php esc_html_e( 'Maintenance', 'init-chat-engine' ); ?></th>
            <td>
                <p>
                    <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=' . INIT_PLUGIN_SUITE_CHAT_ENGINE_SLUG . '&action=cleanup' ), 'init_chat_cleanup' ) ); ?>" 
                       class="button button-secondary" 
                       onclick="return confirm('<?php esc_attr_e( 'Are you sure you want to run cleanup now? This will permanently delete old messages.', 'init-chat-engine' ); ?>')">
                        <?php esc_html_e( 'Run Cleanup Now', 'init-chat-engine' ); ?>
                    </a>
                </p>
                <p class="description"><?php esc_html_e( 'Manually run the cleanup process to remove old messages and expired bans.', 'init-chat-engine' ); ?></p>
            </td>
        </tr>
    </table>
    <?php
}
