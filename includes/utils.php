<?php
/**
 * Shared helper/utility functions for the chat engine.
 *
 * @package Init_Chat_Engine
 */

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
	$settings     = init_plugin_suite_chat_engine_get_all_settings();
	$allow_guests = ! empty( $settings['allow_guests'] );
	$min_days     = isset( $settings['min_account_age_days'] )
		? (int) $settings['min_account_age_days']
		: 0;

	// Rule disabled.
	if ( $min_days <= 0 ) {
		return true;
	}

	// Nếu site cho guest chat thì không áp dụng rule.
	if ( $allow_guests ) {
		return true;
	}

	// Nếu chưa login thì để logic khác xử lý.
	if ( ! is_user_logged_in() ) {
		return true;
	}

	$current_user = wp_get_current_user();

	if ( ! $current_user || ! $current_user->exists() ) {
		return true;
	}

	// user_registered được WordPress core lưu theo giờ UTC/GMT (không phải giờ
	// local site như created_at của plugin) - nên phải so bằng time() (UTC thật),
	// KHÔNG dùng current_time('timestamp') (giờ local) ở đây, nếu không account
	// age sẽ bị lệch đúng bằng UTC offset của site.
	$registered_timestamp = strtotime( $current_user->user_registered );
	$current_timestamp    = time();

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
			array( 'status' => 403 )
		);
	}

	return true;
}

/**
 * Clear all message-related cache
 */
function init_plugin_suite_chat_engine_clear_message_cache() {
	// 1. Xóa thủ công cache phân trang tin nhắn (group '')
	for ( $page = 1; $page <= 10; $page++ ) {
		wp_cache_delete( 'init_chat_messages_' . md5( (string) $page ), '' );
	}

	// Đưa ra ngoài vòng lặp để chỉ xóa đúng 1 lần, đỡ spam Object Cache 10 lần bro nhé.
	wp_cache_delete( 'init_chat_total_messages_' . md5( '' ), '' );

	// 2. Xóa các cache thống kê (group '')
	$current_date = current_time( 'Y-m-d' );
	wp_cache_delete( 'init_chat_stats_' . $current_date, '' );
	wp_cache_delete( 'init_chat_daily_stats_' . $current_date, '' );
	wp_cache_delete( 'init_chat_top_users_' . $current_date, '' );
	wp_cache_delete( 'init_chat_db_size', '' );

	// 3. Admin đổi dữ liệu tin nhắn thì cache phía frontend (REST /messages) cũng
	// phải xóa theo, không thì chat ngoài trang vẫn hiển thị tin đã bị admin xóa.
	init_plugin_suite_chat_engine_clear_frontend_message_cache();
}

/**
 * Xóa cache tin nhắn phía frontend (REST GET /messages - group 'init_chat_engine').
 * Gọi hàm này ở MỌI nơi làm thay đổi danh sách tin nhắn hiển thị cho người dùng:
 * gửi tin mới, xóa/ẩn tin (single/bulk/moderate qua REST), xóa toàn bộ, hoặc cron
 * dọn tin cũ. Tách riêng khỏi init_plugin_suite_chat_engine_clear_message_cache()
 * (cache riêng cho trang quản trị) vì 2 nhóm cache có key/group khác nhau, nhưng
 * hàm đó vẫn gọi lại hàm này để đảm bảo đổi ở admin thì frontend cũng cập nhật theo.
 */
function init_plugin_suite_chat_engine_clear_frontend_message_cache() {
	$cache_group = 'init_chat_engine';

	wp_cache_delete( 'frontend_latest_id', $cache_group );
	wp_cache_delete( 'frontend_latest_messages', $cache_group );
}
