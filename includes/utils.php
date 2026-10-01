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
 * Get a single setting value.
 *
 * Đọc từ các nhóm option mà trang Settings thực sự lưu (init_chat_*_settings), có
 * fallback sang option cũ INIT_PLUGIN_SUITE_CHAT_ENGINE_OPTION cho site nâng cấp từ
 * bản rất cũ (khi đó admin chưa từng lưu lại Settings), cuối cùng mới tới mặc định.
 *
 * @param string $key           Setting key.
 * @param mixed  $default_value Value returned when the setting isn't saved anywhere.
 * @return mixed
 */
function init_plugin_suite_chat_engine_get_setting( $key, $default_value = null ) {
	$settings = init_plugin_suite_chat_engine_get_all_settings();

	if ( isset( $settings[ $key ] ) ) {
		return $settings[ $key ];
	}

	$legacy = get_option( INIT_PLUGIN_SUITE_CHAT_ENGINE_OPTION, array() );

	if ( is_array( $legacy ) && isset( $legacy[ $key ] ) ) {
		return $legacy[ $key ];
	}

	return $default_value;
}

/**
 * Lấy "phiên bản" hiện tại của cache trang quản trị tin nhắn.
 *
 * Giá trị này được ghép vào cache key danh sách/tổng số tin ở trang Management.
 * Mỗi khi dữ liệu tin nhắn đổi chỉ cần đổi giá trị này (1 lần ghi cache) là toàn
 * bộ cache cũ - mọi trang, mọi từ khóa tìm kiếm, mọi tùy chọn per_page - tự động
 * bị bỏ qua, thay vì phải đoán và xóa từng key như trước.
 *
 * @return string
 */
function init_plugin_suite_chat_engine_get_admin_cache_salt() {
	$salt = wp_cache_get( 'admin_last_changed', 'init_chat_engine' );

	if ( false === $salt ) {
		$salt = microtime();
		wp_cache_set( 'admin_last_changed', $salt, 'init_chat_engine' );
	}

	return (string) $salt;
}

/**
 * Vô hiệu hóa toàn bộ cache danh sách tin nhắn ở trang quản trị.
 *
 * @return void
 */
function init_plugin_suite_chat_engine_bump_admin_cache() {
	wp_cache_set( 'admin_last_changed', microtime(), 'init_chat_engine' );
}

/**
 * Clear all message-related cache
 */
function init_plugin_suite_chat_engine_clear_message_cache() {
	// 1. Cache danh sách + tổng số tin ở trang quản trị (mọi trang / từ khóa / per_page).
	init_plugin_suite_chat_engine_bump_admin_cache();

	// Key cũ (trước 1.3.8, không có salt) - vẫn xóa để không sót dữ liệu cũ còn
	// nằm trong persistent object cache ngay sau khi nâng cấp.
	for ( $page = 1; $page <= 10; $page++ ) {
		wp_cache_delete( 'init_chat_messages_' . md5( (string) $page ), '' );
	}
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

	// Cờ "có tin nhắn hay chưa" (quyết định class expand/shrink của khung chat khi
	// render shortcode) - trước đây cache 1 ngày mà không bao giờ bị xóa, nên khung
	// chat có thể giữ trạng thái "rỗng" cả ngày dù đã có tin mới.
	wp_cache_delete( 'has_messages', $cache_group );
}

/**
 * Multibyte-safe string length (đếm theo ký tự UTF-8, không phải byte).
 *
 * @param string $text Text to measure.
 * @return int
 */
function init_plugin_suite_chat_engine_strlen( $text ) {
	$text = (string) $text;

	return function_exists( 'mb_strlen' ) ? mb_strlen( $text, 'UTF-8' ) : strlen( $text );
}
