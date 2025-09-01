<?php
defined( 'ABSPATH' ) || exit;

/**
 * Add chat management menu
 */
add_action( 'admin_menu', 'init_plugin_suite_chat_engine_register_management_page' );

function init_plugin_suite_chat_engine_register_management_page() {
    $hook_suffix = add_submenu_page(
        INIT_PLUGIN_SUITE_CHAT_ENGINE_SLUG,
        __( 'Chat Management', 'init-chat-engine' ),
        __( 'Management', 'init-chat-engine' ),
        'manage_options',
        'init-chat-management',
        'init_plugin_suite_chat_engine_render_management_page'
    );

    // Enqueue asset đúng screen
    add_action('admin_enqueue_scripts', function($hook) use ($hook_suffix) {
        if ($hook !== $hook_suffix) return;

        $ver = INIT_PLUGIN_SUITE_CHAT_ENGINE_VERSION;

        // Styles
        wp_register_style(
            'init-chat-management',
            INIT_PLUGIN_SUITE_CHAT_ENGINE_ASSETS_URL . 'css/management.css',
            [],
            $ver
        );
        wp_enqueue_style('init-chat-management');

        // Scripts
        wp_register_script(
            'init-chat-management',
            INIT_PLUGIN_SUITE_CHAT_ENGINE_ASSETS_URL . 'js/management.js',
            ['jquery'],
            $ver,
            true // footer
        );

        // (Tuỳ chọn) gắn defer cho vui, WP 6.3+ support
        if (function_exists('wp_script_add_data')) {
            wp_script_add_data('init-chat-management', 'defer', true);
        }

        wp_localize_script('init-chat-management', 'InitChatMgmt', [
            'i18n' => [
                'please_select_action' => __( 'Please select an action.', 'init-chat-engine' ),
                'please_select_item'   => __( 'Please select at least one item.', 'init-chat-engine' ),
                'confirm_delete'       => __( 'Are you sure you want to delete the selected messages?', 'init-chat-engine' ),
                'confirm_ban'          => __( 'Are you sure you want to ban the selected users?', 'init-chat-engine' ),
                'ban_user'             => __( 'Ban User', 'init-chat-engine' ),
                'user_label'           => __( 'User:', 'init-chat-engine' ),
                'ban_duration_label'   => __( 'Ban Duration (hours, 0 = permanent):', 'init-chat-engine' ),
                'cancel'               => __( 'Cancel', 'init-chat-engine' ),
                'view_full_message'    => __( 'View full message', 'init-chat-engine' ),
                'are_you_sure_delete_single' => __( 'Are you sure you want to delete this message?', 'init-chat-engine' ),
                'are_you_sure_unban'   => __( 'Are you sure you want to unban this user?', 'init-chat-engine' ),
                'run_cleanup_confirm'  => __( 'Are you sure you want to run cleanup now? This will permanently delete old messages and expired bans.', 'init-chat-engine' ),
            ],
            'nonce' => [
                'bulk'   => wp_create_nonce('bulk_chat_actions'),
                'search' => wp_create_nonce('init_chat_search'),
                'mgmt'   => wp_create_nonce('init_chat_management'),
                'cleanup'=> wp_create_nonce('init_chat_cleanup'),
            ],
        ]);

        wp_enqueue_script('init-chat-management');
    });
}

/**
 * Render chat management page
 */
function init_plugin_suite_chat_engine_render_management_page() {
    // Handle bulk actions
    $nonce = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';
    if ( isset( $_POST['action'] ) && $nonce && wp_verify_nonce( $nonce, 'bulk_chat_actions' ) ) {
        $action = sanitize_text_field( wp_unslash( $_POST['action'] ) );
        $selected_items = isset( $_POST['selected_items'] ) ? array_map( 'absint', $_POST['selected_items'] ) : [];
        
        if ( ! empty( $selected_items ) ) {
            switch ( $action ) {
                case 'bulk_delete':
                    $deleted_count = 0;
                    foreach ( $selected_items as $message_id ) {
                        global $wpdb;
                        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                        $result = $wpdb->update(
                            $wpdb->prefix . 'init_chatbox_msgs',
                            [ 'is_deleted' => 1 ],
                            [ 'id' => $message_id ],
                            [ '%d' ],
                            [ '%d' ]
                        );
                        if ( $result ) $deleted_count++;
                    }
                    
                    echo '<div class="notice notice-success"><p>' . 
                        /* translators: %d: number of messages deleted */
                         sprintf( esc_html__( '%d messages deleted successfully.', 'init-chat-engine' ), esc_html( $deleted_count ) ) . 
                         '</p></div>';
                    break;
                    
                case 'bulk_ban':
                    $ban_duration = isset( $_POST['ban_duration'] ) ? absint( $_POST['ban_duration'] ) : 0;
                    $ban_reason = isset( $_POST['ban_reason'] ) ? sanitize_text_field( wp_unslash( $_POST['ban_reason'] ) ) : 'Bulk ban from management panel';
                    $banned_count = 0;
                    
                    foreach ( $selected_items as $message_id ) {
                        global $wpdb;
                        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                        $message = $wpdb->get_row( $wpdb->prepare(
                            "SELECT user_id, ip_address, display_name FROM `{$wpdb->prefix}init_chatbox_msgs` WHERE id = %d",
                            $message_id
                        ) );
                        
                        if ( $message && ! init_plugin_suite_chat_engine_check_user_banned( $message->user_id, $message->ip_address ) ) {
                            $result = init_plugin_suite_chat_engine_ban_user( 
                                $message->user_id ?: null, 
                                $message->ip_address ?: null, 
                                $message->display_name, 
                                $ban_reason,
                                $ban_duration > 0 ? $ban_duration : null
                            );
                            if ( $result ) $banned_count++;
                        }
                    }
                    
                    echo '<div class="notice notice-success"><p>' . 
                        /* translators: %d: number of users banned */
                         sprintf( esc_html__( '%d users banned successfully.', 'init-chat-engine' ), esc_html( $banned_count ) ) . 
                         '</p></div>';
                    break;
            }
        }
    }
    
    // Handle single actions
    $nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
    if ( isset( $_GET['action'] ) && $nonce && wp_verify_nonce( $nonce, 'init_chat_management' ) ) {
        $action = sanitize_text_field( wp_unslash( $_GET['action'] ) );
        
        switch ( $action ) {
            case 'ban_user':
                $user_id = isset( $_GET['user_id'] ) ? absint( $_GET['user_id'] ) : 0;
                $ip = isset( $_GET['ip'] ) ? sanitize_text_field( wp_unslash( $_GET['ip'] ) ) : '';
                $name = isset( $_GET['name'] ) ? sanitize_text_field( wp_unslash( $_GET['name'] ) ) : '';
                $duration = isset( $_GET['duration'] ) ? absint( $_GET['duration'] ) : 0;
                
                if ( $user_id || $ip ) {
                    $result = init_plugin_suite_chat_engine_ban_user( 
                        $user_id ?: null, 
                        $ip ?: null, 
                        $name, 
                        'Banned from management panel',
                        $duration > 0 ? $duration : null
                    );
                    if ( $result ) {
                        echo '<div class="notice notice-success"><p>' . esc_html__( 'User banned successfully.', 'init-chat-engine' ) . '</p></div>';
                    } else {
                        echo '<div class="notice notice-error"><p>' . esc_html__( 'Failed to ban user.', 'init-chat-engine' ) . '</p></div>';
                    }
                }
                break;
                
            case 'unban_user':
                $ban_id = isset( $_GET['ban_id'] ) ? absint( $_GET['ban_id'] ) : 0;
                if ( $ban_id ) {
                    $result = init_plugin_suite_chat_engine_unban_user( $ban_id );
                    if ( $result ) {
                        echo '<div class="notice notice-success"><p>' . esc_html__( 'User unbanned successfully.', 'init-chat-engine' ) . '</p></div>';
                    } else {
                        echo '<div class="notice notice-error"><p>' . esc_html__( 'Failed to unban user.', 'init-chat-engine' ) . '</p></div>';
                    }
                }
                break;
                
            case 'delete_message':
                $message_id = isset( $_GET['message_id'] ) ? absint( $_GET['message_id'] ) : 0;
                if ( $message_id ) {
                    global $wpdb;
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                    $result = $wpdb->update(
                        $wpdb->prefix . 'init_chatbox_msgs',
                        [ 'is_deleted' => 1 ],
                        [ 'id' => $message_id ],
                        [ '%d' ],
                        [ '%d' ]
                    );
                    if ( $result ) {
                        echo '<div class="notice notice-success"><p>' . esc_html__( 'Message deleted successfully.', 'init-chat-engine' ) . '</p></div>';
                    } else {
                        echo '<div class="notice notice-error"><p>' . esc_html__( 'Failed to delete message.', 'init-chat-engine' ) . '</p></div>';
                    }
                }
                break;
        }
    }
    
    // Handle manual cleanup
    $nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
    if ( isset( $_GET['action'] ) && $_GET['action'] === 'cleanup' && $nonce && wp_verify_nonce( $nonce, 'init_chat_cleanup' ) ) {
        init_plugin_suite_chat_engine_cleanup_messages();
        echo '<div class="notice notice-success"><p>' . esc_html__( 'Cleanup completed successfully.', 'init-chat-engine' ) . '</p></div>';
    }
    
    $active_subtab = isset( $_GET['subtab'] ) ? sanitize_text_field( wp_unslash( $_GET['subtab'] ) ) : 'messages';
    ?>
    <div class="wrap">
        <h1><?php esc_html_e( 'Chat Management', 'init-chat-engine' ); ?></h1>

        <div class="notice notice-info">
            <p>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . INIT_PLUGIN_SUITE_CHAT_ENGINE_SLUG ) ); ?>">
                    <?php esc_html_e( '&larr; Back to Settings', 'init-chat-engine' ); ?>
                </a>
                &nbsp;|&nbsp;
                <strong><?php esc_html_e( 'Version:', 'init-chat-engine' ); ?></strong> <?php echo esc_html( INIT_PLUGIN_SUITE_CHAT_ENGINE_VERSION ); ?>
            </p>
        </div>

        <nav class="nav-tab-wrapper">
            <a href="?page=init-chat-management&subtab=messages" class="nav-tab <?php echo $active_subtab === 'messages' ? 'nav-tab-active' : ''; ?>">
                <?php esc_html_e( 'Recent Messages', 'init-chat-engine' ); ?>
            </a>
            <a href="?page=init-chat-management&subtab=banned" class="nav-tab <?php echo $active_subtab === 'banned' ? 'nav-tab-active' : ''; ?>">
                <?php esc_html_e( 'Banned Users', 'init-chat-engine' ); ?>
            </a>
            <a href="?page=init-chat-management&subtab=stats" class="nav-tab <?php echo $active_subtab === 'stats' ? 'nav-tab-active' : ''; ?>">
                <?php esc_html_e( 'Statistics', 'init-chat-engine' ); ?>
            </a>
        </nav>

        <?php if ( $active_subtab === 'messages' ) : ?>
            <?php init_plugin_suite_chat_engine_render_messages_management(); ?>
        <?php elseif ( $active_subtab === 'banned' ) : ?>
            <?php init_plugin_suite_chat_engine_render_banned_management(); ?>
        <?php elseif ( $active_subtab === 'stats' ) : ?>
            <?php init_plugin_suite_chat_engine_render_stats_management(); ?>
        <?php endif; ?>
    </div>
    <?php
}

/**
 * Render messages management
 */
function init_plugin_suite_chat_engine_render_messages_management() {
    global $wpdb;
    
    $table_name = $wpdb->prefix . 'init_chatbox_msgs';
    $per_page = 20;
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    $current_page = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
    $offset = ( $current_page - 1 ) * $per_page;
    
    // Handle search with nonce verification
    $search = '';
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    if ( isset( $_GET['s'] ) && ! empty( $_GET['s'] ) ) {
        // Verify nonce for search
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $search_nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
        if ( wp_verify_nonce( $search_nonce, 'init_chat_search' ) ) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $search = sanitize_text_field( wp_unslash( $_GET['s'] ) );
        }
    }
    
    // Get total count
    $cache_key = 'init_chat_total_messages_' . md5( $search );
    $total_items = wp_cache_get( $cache_key );
    if ( false === $total_items ) {
        if ( $search ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $total_items = (int) $wpdb->get_var( 
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM `{$wpdb->prefix}init_chatbox_msgs` 
                     WHERE is_deleted = %d 
                     AND (message LIKE %s OR display_name LIKE %s OR ip_address LIKE %s)",
                    0,
                    '%' . $wpdb->esc_like( $search ) . '%',
                    '%' . $wpdb->esc_like( $search ) . '%',
                    '%' . $wpdb->esc_like( $search ) . '%'
                )
            );
        } else {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $total_items = (int) $wpdb->get_var( 
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM `{$wpdb->prefix}init_chatbox_msgs` WHERE is_deleted = %d",
                    0
                )
            );
        }
        wp_cache_set( $cache_key, $total_items, '', 300 ); // Cache for 5 minutes
    }
    
    $total_pages = ceil( $total_items / $per_page );
    
    // Get messages
    $cache_key_messages = 'init_chat_messages_' . md5( $search . $current_page );
    $messages = wp_cache_get( $cache_key_messages );
    if ( false === $messages ) {
        if ( $search ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $messages = $wpdb->get_results( 
                $wpdb->prepare(
                    "SELECT * FROM `{$wpdb->prefix}init_chatbox_msgs` 
                     WHERE is_deleted = %d 
                     AND (message LIKE %s OR display_name LIKE %s OR ip_address LIKE %s)
                     ORDER BY created_at DESC 
                     LIMIT %d OFFSET %d",
                    0,
                    '%' . $wpdb->esc_like( $search ) . '%',
                    '%' . $wpdb->esc_like( $search ) . '%',
                    '%' . $wpdb->esc_like( $search ) . '%',
                    $per_page,
                    $offset
                )
            );
        } else {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $messages = $wpdb->get_results( 
                $wpdb->prepare(
                    "SELECT * FROM `{$wpdb->prefix}init_chatbox_msgs` 
                     WHERE is_deleted = %d
                     ORDER BY created_at DESC 
                     LIMIT %d OFFSET %d",
                    0,
                    $per_page,
                    $offset
                )
            );
        }
        wp_cache_set( $cache_key_messages, $messages, '', 300 ); // Cache for 5 minutes
    }
    ?>
    <div class="tablenav top">
        <div class="alignleft actions">
            <form method="get" style="display: inline-block;">
                <input type="hidden" name="page" value="init-chat-management">
                <input type="hidden" name="subtab" value="messages">
                <?php wp_nonce_field( 'init_chat_search' ); ?>
                <input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search messages...', 'init-chat-engine' ); ?>">
                <input type="submit" class="button" value="<?php esc_attr_e( 'Search', 'init-chat-engine' ); ?>">
                <?php if ( $search ) : ?>
                    <a href="?page=init-chat-management&subtab=messages" class="button"><?php esc_html_e( 'Clear', 'init-chat-engine' ); ?></a>
                <?php endif; ?>
            </form>
        </div>
        <div class="alignright">
            <p class="search-box">
                <?php
                printf( 
                    /* translators: 1: start number, 2: end number, 3: total number */
                    esc_html__( 'Showing %1$d-%2$d of %3$d messages', 'init-chat-engine' ),
                    (int) ( $offset + 1 ),
                    (int) min( $offset + $per_page, $total_items ),
                    (int) $total_items
                ); 
                ?>
            </p>
        </div>
    </div>
    
    <!-- Bulk Actions Form -->
    <form id="bulk-action-form" method="post">
        <?php wp_nonce_field( 'bulk_chat_actions' ); ?>
        
        <div class="bulk-actions">
            <label for="bulk-action-selector" class="screen-reader-text"><?php esc_html_e( 'Select bulk action', 'init-chat-engine' ); ?></label>
            <select name="action" id="bulk-action-selector">
                <option value="-1"><?php esc_html_e( 'Bulk Actions', 'init-chat-engine' ); ?></option>
                <option value="bulk_delete"><?php esc_html_e( 'Delete Selected', 'init-chat-engine' ); ?></option>
                <option value="bulk_ban"><?php esc_html_e( 'Ban Selected Users', 'init-chat-engine' ); ?></option>
            </select>
            <input type="submit" class="button" value="<?php esc_attr_e( 'Apply', 'init-chat-engine' ); ?>">
            <span style="margin-left: 10px;">
                <?php esc_html_e( 'Selected:', 'init-chat-engine' ); ?> <strong id="bulk-selected-count">0</strong>
            </span>
            
            <div class="ban-options">
                <label>
                    <?php esc_html_e( 'Ban Duration (hours, 0 = permanent):', 'init-chat-engine' ); ?>
                    <input type="number" name="ban_duration" value="24" min="0" style="width: 80px;">
                </label>
                <label style="margin-left: 15px;">
                    <?php esc_html_e( 'Reason:', 'init-chat-engine' ); ?>
                    <input type="text" name="ban_reason" value="Violation of chat rules" style="width: 200px;">
                </label>
            </div>
        </div>
    
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th scope="col" class="manage-column column-cb check-column">
                        <input type="checkbox" id="cb-select-all-1" />
                    </th>
                    <th scope="col" style="width: 15%;"><?php esc_html_e( 'User', 'init-chat-engine' ); ?></th>
                    <th scope="col" style="width: 40%;"><?php esc_html_e( 'Message', 'init-chat-engine' ); ?></th>
                    <th scope="col" style="width: 15%;"><?php esc_html_e( 'Date', 'init-chat-engine' ); ?></th>
                    <th scope="col" style="width: 15%;"><?php esc_html_e( 'IP Address', 'init-chat-engine' ); ?></th>
                    <th scope="col" style="width: 15%;"><?php esc_html_e( 'Actions', 'init-chat-engine' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if ( empty( $messages ) ) : ?>
                <tr>
                    <td colspan="6" class="no-items"><?php esc_html_e( 'No messages found.', 'init-chat-engine' ); ?></td>
                </tr>
                <?php else : ?>
                <?php foreach ( $messages as $message ) : ?>
                <tr>
                    <th scope="row" class="check-column">
                        <input type="checkbox" value="<?php echo esc_attr( $message->id ); ?>" />
                    </th>
                    <td>
                        <?php if ( $message->user_id ) : ?>
                            <?php $user = get_user_by( 'ID', $message->user_id ); ?>
                            <strong><?php echo esc_html( $message->display_name ); ?></strong>
                            <?php if ( $user ) : ?>
                                <br><small><?php echo esc_html( $user->user_login ); ?></small>
                            <?php endif; ?>
                        <?php else : ?>
                            <em><?php echo esc_html( $message->display_name ); ?></em>
                            <br><small><?php esc_html_e( 'Guest', 'init-chat-engine' ); ?></small>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div style="max-height: 60px; overflow: hidden;">
                            <?php echo wp_kses_post( wp_trim_words( $message->message, 20 ) ); ?>
                        </div>
                        <?php if ( str_word_count( wp_strip_all_tags( $message->message ) ) > 20 ) : ?>
                            <small>
                                <a href="#"
                                   class="init-chat-view-full-message"
                                   data-message="<?php echo esc_attr( wp_strip_all_tags( $message->message ) ); ?>">
                                    <?php esc_html_e( 'View full message', 'init-chat-engine' ); ?>
                                </a>
                            </small>
                        <?php endif; ?>
                    </td>
                    <td>
                        <abbr title="<?php echo esc_attr( $message->created_at ); ?>">
                            <?php echo esc_html( human_time_diff( strtotime( $message->created_at ), current_time( 'timestamp' ) ) ); ?>
                            <?php esc_html_e( 'ago', 'init-chat-engine' ); ?>
                        </abbr>
                    </td>
                    <td>
                        <code><?php echo esc_html( $message->ip_address ?: __( 'N/A', 'init-chat-engine' ) ); ?></code>
                    </td>
                    <td>
                        <?php $nonce = wp_create_nonce( 'init_chat_management' ); ?>
                        <div class="row-actions">
                            <span class="delete">
                                <a href="<?php echo esc_url( add_query_arg([
                                    'action' => 'delete_message',
                                    'message_id' => $message->id,
                                    '_wpnonce' => $nonce
                                ]) ); ?>" 
                                   class="submitdelete init-chat-confirm-delete">
                                   <?php esc_html_e( 'Delete', 'init-chat-engine' ); ?>
                                </a>
                            </span>
                            
                            <?php if ( ! init_plugin_suite_chat_engine_check_user_banned( $message->user_id, $message->ip_address ) ) : ?>
                                | <span class="ban">
                                    <a href="<?php echo esc_url( add_query_arg([
                                        'action' => 'ban_user',
                                        'user_id' => $message->user_id,
                                        'ip' => $message->ip_address,
                                        'name' => $message->display_name,
                                        '_wpnonce' => $nonce
                                    ]) ); ?>" 
                                       class="ban-with-duration"
                                       data-user-id="<?php echo esc_attr( $message->user_id ); ?>"
                                       data-ip="<?php echo esc_attr( $message->ip_address ); ?>"
                                       data-name="<?php echo esc_attr( $message->display_name ); ?>">
                                        <?php esc_html_e( 'Ban User', 'init-chat-engine' ); ?>
                                    </a>
                                </span>
                            <?php else : ?>
                                | <span class="description"><?php esc_html_e( 'Banned', 'init-chat-engine' ); ?></span>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
            <tfoot>
                <tr>
                    <th scope="col" class="manage-column column-cb check-column">
                        <input type="checkbox" id="cb-select-all-2" />
                    </th>
                    <th scope="col"><?php esc_html_e( 'User', 'init-chat-engine' ); ?></th>
                    <th scope="col"><?php esc_html_e( 'Message', 'init-chat-engine' ); ?></th>
                    <th scope="col"><?php esc_html_e( 'Date', 'init-chat-engine' ); ?></th>
                    <th scope="col"><?php esc_html_e( 'IP Address', 'init-chat-engine' ); ?></th>
                    <th scope="col"><?php esc_html_e( 'Actions', 'init-chat-engine' ); ?></th>
                </tr>
            </tfoot>
        </table>
    </form>
    
    <?php if ( $total_pages > 1 ) : ?>
    <div class="tablenav bottom">
        <div class="tablenav-pages">
            <?php
            $page_links = paginate_links([
                'base' => add_query_arg( 'paged', '%#%' ),
                'format' => '',
                'prev_text' => __( '&laquo;', 'init-chat-engine' ),
                'next_text' => __( '&raquo;', 'init-chat-engine' ),
                'total' => $total_pages,
                'current' => $current_page
            ]);
            echo wp_kses_post( $page_links );
            ?>
        </div>
    </div>
    <?php endif; ?>
    <?php
}

/**
 * Render banned users management
 */
function init_plugin_suite_chat_engine_render_banned_management() {
    $banned_users = init_plugin_suite_chat_engine_get_banned_users( true );
    ?>
    <h2><?php esc_html_e( 'Banned Users', 'init-chat-engine' ); ?></h2>
    
    <?php if ( ! empty( $banned_users ) ) : ?>
    <div class="notice notice-warning">
        <p><?php 
        /* translators: %d: number of active bans */
        printf( esc_html__( 'Found %d active bans. Expired bans are automatically cleaned up daily.', 'init-chat-engine' ), count( $banned_users ) ); ?></p>
    </div>
    <?php endif; ?>
    
    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th scope="col" style="width: 15%;"><?php esc_html_e( 'User', 'init-chat-engine' ); ?></th>
                <th scope="col" style="width: 15%;"><?php esc_html_e( 'IP Address', 'init-chat-engine' ); ?></th>
                <th scope="col" style="width: 20%;"><?php esc_html_e( 'Reason', 'init-chat-engine' ); ?></th>
                <th scope="col" style="width: 15%;"><?php esc_html_e( 'Banned By', 'init-chat-engine' ); ?></th>
                <th scope="col" style="width: 15%;"><?php esc_html_e( 'Banned Date', 'init-chat-engine' ); ?></th>
                <th scope="col" style="width: 15%;"><?php esc_html_e( 'Expires', 'init-chat-engine' ); ?></th>
                <th scope="col" style="width: 5%;"><?php esc_html_e( 'Actions', 'init-chat-engine' ); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php if ( empty( $banned_users ) ) : ?>
            <tr>
                <td colspan="7" class="no-items"><?php esc_html_e( 'No banned users found.', 'init-chat-engine' ); ?></td>
            </tr>
            <?php else : ?>
            <?php foreach ( $banned_users as $ban ) : ?>
            <tr>
                <td>
                    <?php if ( $ban->user_id ) : ?>
                        <?php $user = get_user_by( 'ID', $ban->user_id ); ?>
                        <strong><?php echo esc_html( $ban->display_name ); ?></strong>
                        <?php if ( $user ) : ?>
                            <br><small><?php echo esc_html( $user->user_login ); ?></small>
                        <?php endif; ?>
                    <?php else : ?>
                        <em><?php echo esc_html( $ban->display_name ?: __( 'Unknown', 'init-chat-engine' ) ); ?></em>
                        <br><small><?php esc_html_e( 'Guest User', 'init-chat-engine' ); ?></small>
                    <?php endif; ?>
                </td>
                <td>
                    <code><?php echo esc_html( $ban->ip_address ?: __( 'N/A', 'init-chat-engine' ) ); ?></code>
                </td>
                <td>
                    <?php echo esc_html( $ban->reason ?: __( 'No reason provided', 'init-chat-engine' ) ); ?>
                </td>
                <td>
                    <?php echo esc_html( $ban->banned_by_name ?: __( 'Unknown', 'init-chat-engine' ) ); ?>
                </td>
                <td>
                    <abbr title="<?php echo esc_attr( $ban->banned_at ); ?>">
                        <?php echo esc_html( wp_date( get_option( 'date_format' ), strtotime( $ban->banned_at ) ) ); ?>
                    </abbr>
                </td>
                <td>
                    <?php if ( $ban->expires_at ) : ?>
                        <abbr title="<?php echo esc_attr( $ban->expires_at ); ?>">
                            <?php 
                            $expires_timestamp = strtotime( $ban->expires_at );
                            $current_timestamp = current_time( 'timestamp' );
                            if ( $expires_timestamp > $current_timestamp ) {
                                echo '<span style="color: #d63638;">' . esc_html( human_time_diff( $current_timestamp, $expires_timestamp ) ) . ' ' . esc_html__( 'remaining', 'init-chat-engine' ) . '</span>';
                            } else {
                                echo '<span style="color: #00a32a;">' . esc_html__( 'Expired', 'init-chat-engine' ) . '</span>';
                            }
                            ?>
                        </abbr>
                    <?php else : ?>
                        <em style="color: #d63638;"><?php esc_html_e( 'Permanent', 'init-chat-engine' ); ?></em>
                    <?php endif; ?>
                </td>
                <td>
                    <?php $nonce = wp_create_nonce( 'init_chat_management' ); ?>
                    <a href="<?php echo esc_url( add_query_arg([
                        'action' => 'unban_user',
                        'ban_id' => $ban->id,
                        '_wpnonce' => $nonce
                    ]) ); ?>" 
                       class="button button-small button-primary init-chat-confirm-unban">
                       <?php esc_html_e( 'Unban', 'init-chat-engine' ); ?>
                    </a>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
    <?php
}

/**
 * Render statistics management
 */
function init_plugin_suite_chat_engine_render_stats_management() {
    global $wpdb;
    
    // Get statistics with caching
    $cache_key = 'init_chat_stats_' . current_time( 'Y-m-d' );
    $stats = wp_cache_get( $cache_key );
    
    if ( false === $stats ) {
        $table_name = $wpdb->prefix . 'init_chatbox_msgs';
        $banned_table = $wpdb->prefix . 'init_chatbox_banned';
        
        $stats = [
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            'total_messages' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$wpdb->prefix}init_chatbox_msgs` WHERE is_deleted = %d", 0 ) ),
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            'messages_today' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$wpdb->prefix}init_chatbox_msgs` WHERE DATE(created_at) = CURDATE() AND is_deleted = %d", 0 ) ),
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            'total_users' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT user_id) FROM `{$wpdb->prefix}init_chatbox_msgs` WHERE user_id IS NOT NULL AND is_deleted = %d", 0 ) ),
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            'total_guests' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$wpdb->prefix}init_chatbox_msgs` WHERE user_id IS NULL AND is_deleted = %d", 0 ) ),
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            'active_bans' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$wpdb->prefix}init_chatbox_banned` WHERE is_active = %d", 1 ) ),
        ];
        
        wp_cache_set( $cache_key, $stats, '', 3600 ); // Cache for 1 hour
    }
    
    $last_cleanup = init_plugin_suite_chat_engine_get_stat( 'last_cleanup', '' );
    
    // Get daily message counts for the last 30 days with caching
    $daily_cache_key = 'init_chat_daily_stats_' . current_time( 'Y-m-d' );
    $daily_stats = wp_cache_get( $daily_cache_key );
    
    if ( false === $daily_stats ) {
        $table_name = $wpdb->prefix . 'init_chatbox_msgs';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $daily_stats = $wpdb->get_results( 
            $wpdb->prepare(
                "SELECT DATE(created_at) as date, COUNT(*) as count 
                 FROM `{$wpdb->prefix}init_chatbox_msgs` 
                 WHERE created_at >= DATE_SUB(NOW(), INTERVAL %d DAY) 
                 AND is_deleted = %d
                 GROUP BY DATE(created_at) 
                 ORDER BY date DESC 
                 LIMIT %d",
                30, 0, 30
            )
        );
        wp_cache_set( $daily_cache_key, $daily_stats, '', 3600 ); // Cache for 1 hour
    }
    
    // Get top users with caching
    $top_users_cache_key = 'init_chat_top_users_' . current_time( 'Y-m-d' );
    $top_users = wp_cache_get( $top_users_cache_key );
    
    if ( false === $top_users ) {
        $table_name = $wpdb->prefix . 'init_chatbox_msgs';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $top_users = $wpdb->get_results( 
            $wpdb->prepare(
                "SELECT display_name, COUNT(*) as message_count, 
                        MAX(created_at) as last_message
                 FROM `{$wpdb->prefix}init_chatbox_msgs` 
                 WHERE is_deleted = %d 
                 GROUP BY display_name 
                 ORDER BY message_count DESC 
                 LIMIT %d",
                0, 10
            )
        );
        wp_cache_set( $top_users_cache_key, $top_users, '', 3600 ); // Cache for 1 hour
    }
    ?>
    <h2><?php esc_html_e( 'Chat Statistics', 'init-chat-engine' ); ?></h2>
    
    <div class="init-chat-stats-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin: 20px 0;">
        <div class="init-chat-stat-card" style="background: #fff; padding: 20px; border: 1px solid #ccd0d4; border-radius: 4px; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
            <h3 style="margin: 0 0 10px 0; color: #1d2327; font-size: 14px;"><?php esc_html_e( 'Total Messages', 'init-chat-engine' ); ?></h3>
            <p style="font-size: 32px; font-weight: bold; margin: 0; color: #135e96;"><?php echo esc_html( number_format_i18n( $stats['total_messages'] ) ); ?></p>
        </div>
        
        <div class="init-chat-stat-card" style="background: #fff; padding: 20px; border: 1px solid #ccd0d4; border-radius: 4px; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
            <h3 style="margin: 0 0 10px 0; color: #1d2327; font-size: 14px;"><?php esc_html_e( 'Messages Today', 'init-chat-engine' ); ?></h3>
            <p style="font-size: 32px; font-weight: bold; margin: 0; color: #00a32a;"><?php echo esc_html( number_format_i18n( $stats['messages_today'] ) ); ?></p>
        </div>
        
        <div class="init-chat-stat-card" style="background: #fff; padding: 20px; border: 1px solid #ccd0d4; border-radius: 4px; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
            <h3 style="margin: 0 0 10px 0; color: #1d2327; font-size: 14px;"><?php esc_html_e( 'Registered Users', 'init-chat-engine' ); ?></h3>
            <p style="font-size: 32px; font-weight: bold; margin: 0; color: #8c8f94;"><?php echo esc_html( number_format_i18n( $stats['total_users'] ) ); ?></p>
        </div>
        
        <div class="init-chat-stat-card" style="background: #fff; padding: 20px; border: 1px solid #ccd0d4; border-radius: 4px; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
            <h3 style="margin: 0 0 10px 0; color: #1d2327; font-size: 14px;"><?php esc_html_e( 'Guest Messages', 'init-chat-engine' ); ?></h3>
            <p style="font-size: 32px; font-weight: bold; margin: 0; color: #8c8f94;"><?php echo esc_html( number_format_i18n( $stats['total_guests'] ) ); ?></p>
        </div>
        
        <div class="init-chat-stat-card" style="background: #fff; padding: 20px; border: 1px solid #ccd0d4; border-radius: 4px; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
            <h3 style="margin: 0 0 10px 0; color: #1d2327; font-size: 14px;"><?php esc_html_e( 'Active Bans', 'init-chat-engine' ); ?></h3>
            <p style="font-size: 32px; font-weight: bold; margin: 0; color: #d63638;"><?php echo esc_html( number_format_i18n( $stats['active_bans'] ) ); ?></p>
        </div>
        
        <div class="init-chat-stat-card" style="background: #fff; padding: 20px; border: 1px solid #ccd0d4; border-radius: 4px; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
            <h3 style="margin: 0 0 10px 0; color: #1d2327; font-size: 14px;"><?php esc_html_e( 'Last Cleanup', 'init-chat-engine' ); ?></h3>
            <p style="font-size: 16px; margin: 0; color: #50575e; line-height: 1.4;">
                <?php 
                if ( $last_cleanup ) {
                    echo esc_html( human_time_diff( strtotime( $last_cleanup ), current_time( 'timestamp' ) ) );
                    echo ' ' . esc_html__( 'ago', 'init-chat-engine' );
                } else {
                    esc_html_e( 'Never', 'init-chat-engine' );
                }
                ?>
            </p>
        </div>
    </div>
    
    <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 20px; margin: 20px 0;">
        
        <?php if ( ! empty( $daily_stats ) ) : ?>
        <div>
            <h3><?php esc_html_e( 'Daily Message Activity (Last 30 Days)', 'init-chat-engine' ); ?></h3>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th scope="col"><?php esc_html_e( 'Date', 'init-chat-engine' ); ?></th>
                        <th scope="col"><?php esc_html_e( 'Messages', 'init-chat-engine' ); ?></th>
                        <th scope="col"><?php esc_html_e( 'Activity', 'init-chat-engine' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $max_count = max( array_column( $daily_stats, 'count' ) );
                    foreach ( array_slice( $daily_stats, 0, 15 ) as $stat ) : 
                        $percentage = $max_count > 0 ? ( $stat->count / $max_count ) * 100 : 0;
                    ?>
                    <tr>
                        <td><?php echo esc_html( wp_date( get_option( 'date_format' ), strtotime( $stat->date ) ) ); ?></td>
                        <td><strong><?php echo esc_html( number_format_i18n( $stat->count ) ); ?></strong></td>
                        <td>
                            <div style="background: #f0f0f1; height: 20px; border-radius: 3px; overflow: hidden; width: 150px;">
                                <div style="background: #135e96; height: 100%; width: <?php echo esc_attr( $percentage ); ?>%; transition: width 0.3s ease;"></div>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
        
        <?php if ( ! empty( $top_users ) ) : ?>
        <div>
            <h3><?php esc_html_e( 'Top 10 Most Active Users', 'init-chat-engine' ); ?></h3>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th scope="col"><?php esc_html_e( 'User', 'init-chat-engine' ); ?></th>
                        <th scope="col"><?php esc_html_e( 'Messages', 'init-chat-engine' ); ?></th>
                        <th scope="col"><?php esc_html_e( 'Last Activity', 'init-chat-engine' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $top_users as $user ) : ?>
                    <tr>
                        <td><strong><?php echo esc_html( $user->display_name ); ?></strong></td>
                        <td><?php echo esc_html( number_format_i18n( $user->message_count ) ); ?></td>
                        <td>
                            <abbr title="<?php echo esc_attr( $user->last_message ); ?>">
                                <?php echo esc_html( human_time_diff( strtotime( $user->last_message ), current_time( 'timestamp' ) ) ); ?>
                                <?php esc_html_e( 'ago', 'init-chat-engine' ); ?>
                            </abbr>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
        
    </div>
    
    <div style="background: #fff; padding: 20px; border: 1px solid #ccd0d4; border-radius: 4px; margin: 20px 0;">
        <h3><?php esc_html_e( 'Quick Actions', 'init-chat-engine' ); ?></h3>
        <p>
            <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=init-chat-management&action=cleanup' ), 'init_chat_cleanup' ) ); ?>" 
               class="button button-secondary init-chat-confirm-cleanup">
                <?php esc_html_e( 'Run Cleanup Now', 'init-chat-engine' ); ?>
            </a>
            
            <a href="?page=init-chat-management&subtab=messages" class="button">
                <?php esc_html_e( 'View All Messages', 'init-chat-engine' ); ?>
            </a>
            
            <a href="?page=init-chat-management&subtab=banned" class="button">
                <?php esc_html_e( 'Manage Bans', 'init-chat-engine' ); ?>
            </a>
        </p>
        
        <h4><?php esc_html_e( 'System Information', 'init-chat-engine' ); ?></h4>
        <table class="form-table">
            <tr>
                <th><?php esc_html_e( 'Database Version:', 'init-chat-engine' ); ?></th>
                <td><?php echo esc_html( get_option( 'init_plugin_suite_chat_engine_db_version', '1.0.0' ) ); ?></td>
            </tr>
            <tr>
                <th><?php esc_html_e( 'Plugin Version:', 'init-chat-engine' ); ?></th>
                <td><?php echo esc_html( INIT_PLUGIN_SUITE_CHAT_ENGINE_VERSION ); ?></td>
            </tr>
            <tr>
                <th><?php esc_html_e( 'Database Size:', 'init-chat-engine' ); ?></th>
                <td>
                    <?php
                    // Get database size with caching
                    $db_size_cache_key = 'init_chat_db_size';
                    $db_size = wp_cache_get( $db_size_cache_key );
                    
                    if ( false === $db_size ) {
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
                        wp_cache_set( $db_size_cache_key, $db_size, '', 3600 ); // Cache for 1 hour
                    }
                    echo esc_html( $db_size ?: '0' ) . ' MB';
                    ?>
                </td>
            </tr>
            <tr>
                <th><?php esc_html_e( 'Next Scheduled Cleanup:', 'init-chat-engine' ); ?></th>
                <td>
                    <?php
                    $next_cleanup = wp_next_scheduled( 'init_chat_engine_cleanup_messages' );
                    if ( $next_cleanup ) {
                        echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $next_cleanup ) );
                    } else {
                        esc_html_e( 'Not scheduled', 'init-chat-engine' );
                    }
                    ?>
                </td>
            </tr>
        </table>
    </div>
    <?php
}
