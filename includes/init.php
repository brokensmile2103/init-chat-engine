<?php
/**
 * Core setup: DB tables, ban handling, stats, pinned message helpers.
 *
 * @package Init_Chat_Engine
 */

defined( 'ABSPATH' ) || exit;

/**
 * SQL định nghĩa bảng messages – dùng chung cho activate() (site mới)
 * và migration schema-only (site cũ), để tránh lệch định nghĩa giữa 2 nơi.
 */
function init_plugin_suite_chat_engine_get_messages_table_sql() {
	global $wpdb;

	$table_name      = $wpdb->prefix . 'init_chatbox_msgs';
	$charset_collate = $wpdb->get_charset_collate();

	return "CREATE TABLE $table_name (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id BIGINT(20) NULL,
        display_name VARCHAR(100) NOT NULL,
        message TEXT NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        is_deleted TINYINT(1) DEFAULT 0,
        ip_address VARCHAR(45) NULL,
        user_agent VARCHAR(255) NULL,
        room VARCHAR(64) NOT NULL DEFAULT '',
        PRIMARY KEY (id),
        KEY idx_user_id (user_id),
        KEY idx_created_at (created_at),
        KEY idx_deleted_id (is_deleted, id),
        KEY idx_room_deleted_id (room, is_deleted, id)
    ) $charset_collate;";
}

/**
 * Plugin activation hook – create custom table with indexes
 */
function init_plugin_suite_chat_engine_activate() {
	global $wpdb;

	$charset_collate = $wpdb->get_charset_collate();

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( init_plugin_suite_chat_engine_get_messages_table_sql() );

	// Create options table for storing chat statistics.
	$stats_table = $wpdb->prefix . 'init_chatbox_stats';
	$stats_sql   = "CREATE TABLE $stats_table (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        stat_key VARCHAR(100) NOT NULL,
        stat_value LONGTEXT NULL,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY idx_stat_key (stat_key)
    ) $charset_collate;";

	dbDelta( $stats_sql );

	// Create banned users table.
	$banned_table = $wpdb->prefix . 'init_chatbox_banned';
	$banned_sql   = "CREATE TABLE $banned_table (
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

	// Initialize default stats.
	init_plugin_suite_chat_engine_init_default_stats();

	// Set plugin version.
	update_option( 'init_plugin_suite_chat_engine_db_version', INIT_PLUGIN_SUITE_CHAT_ENGINE_DB_VERSION );

	// Schedule cleanup event.
	if ( ! wp_next_scheduled( 'init_chat_engine_cleanup_messages' ) ) {
		wp_schedule_event( time(), 'daily', 'init_chat_engine_cleanup_messages' );
	}
}

/**
 * Plugin deactivation hook
 */
function init_plugin_suite_chat_engine_deactivate() {
	// Clear scheduled events.
	wp_clear_scheduled_hook( 'init_chat_engine_cleanup_messages' );
}

/**
 * Initialize default statistics
 */
function init_plugin_suite_chat_engine_init_default_stats() {
	global $wpdb;

	$stats_table   = $wpdb->prefix . 'init_chatbox_stats';
	$default_stats = array(
		'total_messages'     => 0,
		'total_users'        => 0,
		'messages_today'     => 0,
		'active_users_today' => 0,
		'last_cleanup'       => current_time( 'mysql' ),
	);

	foreach ( $default_stats as $key => $value ) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->replace(
			$stats_table,
			array(
				'stat_key'   => $key,
				'stat_value' => $value,
				'updated_at' => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s' )
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

	// Migration 1.3.5: thêm composite index (is_deleted, id) và xóa idx_is_deleted
	// đơn lẻ (đã bị composite index bao phủ hoàn toàn theo quy tắc leftmost-prefix
	// của MySQL/InnoDB, giữ lại chỉ tốn thêm dung lượng + chậm ghi mỗi INSERT/UPDATE).
	// Gộp chung 1 bước duy nhất vì bản 1.2.0 (bản nháp thêm idx_deleted_id ban đầu)
	// chưa từng release cho site nào, không cần giữ làm mốc trung gian.
	// Cố tình KHÔNG gọi lại activate() ở đây vì activate() sẽ reset total_messages,
	// total_users... về 0 qua init_default_stats() – gây mất số liệu của site đang chạy.
	if ( version_compare( $current_version, '1.3.5', '<' ) ) {
		init_plugin_suite_chat_engine_migrate_db_1_3_5();
	}

	// Migration 1.3.9: thêm cột room + index (room, is_deleted, id) cho tính năng
	// nhiều phòng chat. Tin nhắn cũ nhận room = '' (phòng mặc định) nên vẫn hiển thị
	// y nguyên ở khung chat hiện tại.
	if ( version_compare( $current_version, '1.3.9', '<' ) ) {
		init_plugin_suite_chat_engine_migrate_db_1_3_9();
	}
}

/**
 * Chạy kiểm tra nâng cấp DB sớm ở MỌI request (không chỉ trong wp-admin).
 *
 * Từ 1.3.9 frontend (REST /messages, /send) cần cột room ngay sau khi plugin được
 * cập nhật - kể cả khi cập nhật tự động và chưa có admin nào vào wp-admin. Chi phí
 * khi đã migrate xong chỉ là 1 lần so sánh phiên bản từ option autoload.
 *
 * @return void
 */
function init_plugin_suite_chat_engine_maybe_upgrade_db() {
	$current_version = get_option( 'init_plugin_suite_chat_engine_db_version', '1.0.0' );

	if ( ! version_compare( $current_version, INIT_PLUGIN_SUITE_CHAT_ENGINE_DB_VERSION, '<' ) ) {
		return;
	}

	// Khóa ngắn để nhiều request đồng thời không cùng chạy ALTER TABLE.
	if ( get_transient( 'init_chat_engine_db_upgrading' ) ) {
		return;
	}

	set_transient( 'init_chat_engine_db_upgrading', 1, MINUTE_IN_SECONDS );
	init_plugin_suite_chat_engine_check_db_upgrade();
	delete_transient( 'init_chat_engine_db_upgrading' );
}

/**
 * Migration 1.3.9 – schema-only, an toàn để chạy nhiều lần (dbDelta tự bỏ qua
 * cột / index đã có). Không đụng tới dữ liệu tin nhắn, stats hay lịch cron.
 *
 * @return void
 */
function init_plugin_suite_chat_engine_migrate_db_1_3_9() {
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( init_plugin_suite_chat_engine_get_messages_table_sql() );

	update_option( 'init_plugin_suite_chat_engine_db_version', '1.3.9' );
}

/**
 * Migration 1.3.5 – schema-only, an toàn để chạy nhiều lần:
 * 1. Thêm composite index (is_deleted, id) qua dbDelta (dbDelta tự bỏ qua nếu đã có).
 * 2. Xóa index idx_is_deleted cũ nếu còn tồn tại (dbDelta KHÔNG tự xóa index thừa,
 *    nên phải DROP INDEX thủ công, có kiểm tra tồn tại trước để tránh lỗi khi
 *    chạy lại hoặc trên site đã được migrate).
 * Không đụng tới bảng stats hay lịch cron.
 */
function init_plugin_suite_chat_engine_migrate_db_1_3_5() {
	global $wpdb;

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( init_plugin_suite_chat_engine_get_messages_table_sql() );

	$table_name = esc_sql( $wpdb->prefix . 'init_chatbox_msgs' );

	// Tên bảng là identifier, không thể bind qua $wpdb->prepare() %s (sẽ bị quote
	// thành chuỗi literal). $table_name đã qua esc_sql() và chỉ ghép từ $wpdb->prefix
	// (giá trị nội bộ, không phải input người dùng) nên an toàn để interpolate.
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$old_index_exists = $wpdb->get_var( "SHOW INDEX FROM `{$table_name}` WHERE Key_name = 'idx_is_deleted'" );

	if ( $old_index_exists ) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( "ALTER TABLE `{$table_name}` DROP INDEX idx_is_deleted" );
	}

	update_option( 'init_plugin_suite_chat_engine_db_version', '1.3.5' );
}

/**
 * Scheduled cleanup of old messages
 */
function init_plugin_suite_chat_engine_cleanup_messages() {
	global $wpdb;

	// Đọc đúng nhóm option mà trang Settings thực sự lưu (init_chat_*_settings).
	// Trước 1.3.8 hàm này đọc option cũ INIT_PLUGIN_SUITE_CHAT_ENGINE_OPTION (không
	// còn được trang Settings ghi vào) nên luôn rơi về mặc định 1000/30, bỏ qua
	// cấu hình thật của admin.
	$cleanup_days = (int) init_plugin_suite_chat_engine_get_setting( 'cleanup_days', 30 );

	$table_name = $wpdb->prefix . 'init_chatbox_msgs';
	$did_change = false;

	// Clean up old deleted messages (older than cleanup_days).
	if ( $cleanup_days > 0 ) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$deleted_rows = $wpdb->query(
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"DELETE FROM {$wpdb->prefix}init_chatbox_msgs 
                 WHERE is_deleted = 1 
                 AND created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
				$cleanup_days
			)
		);

		if ( $deleted_rows ) {
			$did_change = true;
		}
	}

	// Maintain message limit - áp dụng RIÊNG cho từng phòng (từ 1.3.9). Phòng có
	// thuộc tính max_messages trong shortcode dùng giới hạn riêng, còn lại dùng
	// Maximum Messages trong Settings.
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$room_counts = $wpdb->get_results(
		"SELECT room, COUNT(*) AS total FROM {$wpdb->prefix}init_chatbox_msgs WHERE is_deleted = 0 GROUP BY room"
	);

	foreach ( (array) $room_counts as $room_count ) {
		$room_config = init_plugin_suite_chat_engine_get_room_config( (string) $room_count->room );

		if ( (int) $room_count->total > $room_config['max_messages'] ) {
			init_plugin_suite_chat_engine_trim_room( (string) $room_count->room, $room_config['max_messages'] );
			$did_change = true;
		}
	}

	// Clean up expired bans.
	init_plugin_suite_chat_engine_cleanup_expired_bans();

	// Update cleanup stats.
	init_plugin_suite_chat_engine_update_stat( 'last_cleanup', current_time( 'mysql' ) );

	// Cleanup có xóa/ẩn tin thật sự -> clear cache liên quan. Đặt ở đây (thay vì chỉ
	// ở nơi gọi thủ công từ admin) để cron tự động chạy hàng ngày cũng được cover,
	// không chỉ khi admin bấm nút "Cleanup" thủ công.
	if ( $did_change ) {
		init_plugin_suite_chat_engine_clear_message_cache();
	}
}

/**
 * Ẩn (soft delete) các tin cũ nhất của 1 phòng khi vượt quá giới hạn.
 *
 * @param string $room         Room name.
 * @param int    $max_messages Số tin tối đa được giữ lại trong phòng.
 * @return bool True nếu có tin bị ẩn.
 */
function init_plugin_suite_chat_engine_trim_room( $room, $max_messages ) {
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$total = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->prefix}init_chatbox_msgs WHERE room = %s AND is_deleted = 0",
			$room
		)
	);

	if ( $total <= $max_messages ) {
		return false;
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query(
		$wpdb->prepare(
			"UPDATE {$wpdb->prefix}init_chatbox_msgs 
             SET is_deleted = 1 
             WHERE room = %s AND is_deleted = 0 
             ORDER BY id ASC 
             LIMIT %d",
			$room,
			$total - $max_messages
		)
	);

	return true;
}

/**
 * Update statistics
 *
 * @param string $key   Stat key to update.
 * @param mixed  $value New value to store.
 * @return void
 */
function init_plugin_suite_chat_engine_update_stat( $key, $value ) {
	global $wpdb;

	$stats_table = $wpdb->prefix . 'init_chatbox_stats';

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->replace(
		$stats_table,
		array(
			'stat_key'   => $key,
			'stat_value' => $value,
			'updated_at' => current_time( 'mysql' ),
		),
		array( '%s', '%s', '%s' )
	);
}

/**
 * Tăng một stat dạng số nguyên theo kiểu nguyên tử (atomic) trong 1 query duy nhất.
 *
 * Thay cho cặp get_stat() + update_stat() (2 query đọc + 2 query ghi mỗi lần gửi
 * tin): vừa nhanh hơn, vừa tránh mất số đếm khi nhiều request /send chạy đồng thời
 * (2 request cùng đọc giá trị cũ rồi cùng ghi đè +1).
 *
 * @param string $key Stat key to increment.
 * @param int    $by  Amount to add (default 1).
 * @return void
 */
function init_plugin_suite_chat_engine_increment_stat( $key, $by = 1 ) {
	global $wpdb;

	$by  = (int) $by;
	$now = current_time( 'mysql' );

	// Không in lỗi SQL ra output (vd: chèn HTML lỗi vào JSON của REST /send khi
	// bật WP_DEBUG_DISPLAY) - lỗi đã có nhánh fallback xử lý ngay bên dưới.
	$suppress = $wpdb->suppress_errors( true );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$result = $wpdb->query(
		$wpdb->prepare(
			"INSERT INTO `{$wpdb->prefix}init_chatbox_stats` (stat_key, stat_value, updated_at)
             VALUES (%s, %d, %s)
             ON DUPLICATE KEY UPDATE stat_value = CAST(stat_value AS SIGNED) + %d, updated_at = %s",
			$key,
			$by,
			$now,
			$by,
			$now
		)
	);

	$wpdb->suppress_errors( $suppress );

	// Lưới an toàn: nếu DB từ chối query trên (vd: giá trị cũ không phải số trong
	// SQL strict mode) thì quay về cách đọc + ghi như trước 1.3.8.
	if ( false === $result ) {
		$current = (int) init_plugin_suite_chat_engine_get_stat( $key, 0 );
		init_plugin_suite_chat_engine_update_stat( $key, $current + $by );
	}
}

/**
 * Ghi nhận "last_activity" nhưng có throttle để tránh ghi DB (REPLACE INTO)
 * trên mỗi request GET /messages. Chat được poll mỗi vài giây bởi mọi client
 * đang mở tab, nên nếu ghi thẳng mỗi lần sẽ tạo áp lực ghi DB rất lớn ở site
 * đông người dùng. Ở đây chỉ cho phép ghi thật tối đa 1 lần / phút.
 */
function init_plugin_suite_chat_engine_touch_last_activity() {
	$throttle_key = 'init_chat_engine_last_activity_throttle';

	if ( false !== get_transient( $throttle_key ) ) {
		return;
	}

	init_plugin_suite_chat_engine_update_stat( 'last_activity', current_time( 'mysql' ) );
	set_transient( $throttle_key, 1, MINUTE_IN_SECONDS );
}

/**
 * Get statistics
 *
 * @param string $key           Stat key to fetch.
 * @param mixed  $default_value Value returned when the stat isn't set yet.
 * @return mixed
 */
function init_plugin_suite_chat_engine_get_stat( $key, $default_value = null ) {
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

	return null !== $value ? $value : $default_value;
}

/**
 * Get user IP address
 */
function init_plugin_suite_chat_engine_get_user_ip() {
	// Kết quả không đổi trong 1 request nhưng hàm được gọi nhiều lần (permission
	// check, ban check, rate limit, insert...) - cache tĩnh theo request.
	static $cached_ip = null;

	if ( null !== $cached_ip ) {
		return $cached_ip;
	}

	$ip_keys = array( 'HTTP_CF_CONNECTING_IP', 'HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED', 'HTTP_FORWARDED_FOR', 'HTTP_FORWARDED', 'REMOTE_ADDR' );

	/**
	 * Cho phép site tự chọn header nào được tin cậy để lấy IP.
	 *
	 * Mặc định giữ nguyên danh sách cũ (tương thích site chạy sau Cloudflare/proxy).
	 * Site KHÔNG chạy sau proxy có thể trả về array( 'REMOTE_ADDR' ) để chặn việc
	 * giả mạo header X-Forwarded-For nhằm lách ban IP / rate limit.
	 *
	 * @param string[] $ip_keys Danh sách key trong $_SERVER, theo thứ tự ưu tiên.
	 */
	$ip_keys = (array) apply_filters( 'init_plugin_suite_chat_engine_ip_headers', $ip_keys );

	foreach ( $ip_keys as $key ) {
		if ( is_string( $key ) && ! empty( $_SERVER[ $key ] ) ) {
			$ip = sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) );
			if ( strpos( $ip, ',' ) !== false ) {
				$ip = trim( explode( ',', $ip )[0] );
			}
			if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
				$cached_ip = $ip;
				return $cached_ip;
			}
		}
	}

	// Fallback: REMOTE_ADDR (có thể là IP nội bộ khi chạy localhost/proxy) - vẫn
	// validate để không bao giờ lưu chuỗi rác vào cột ip_address VARCHAR(45).
	$remote_addr = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	$cached_ip   = filter_var( $remote_addr, FILTER_VALIDATE_IP ) ? $remote_addr : '127.0.0.1';

	return $cached_ip;
}

/**
 * Ban user from chat - FIXED FOR REAL
 *
 * @param int|null    $user_id        User ID to ban, if registered.
 * @param string|null $ip_address     IP address to ban, if guest.
 * @param string|null $display_name   Display name shown in the ban list.
 * @param string      $reason         Ban reason.
 * @param int|null    $duration_hours Ban duration in hours, or null for permanent.
 * @return int|false Insert ID on success, false on failure.
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
	$banned_by    = get_current_user_id();
	$banned_at    = current_time( 'mysql' );
	$expires_at   = null;

	if ( $duration_hours ) {
		// Lấy timezone của WordPress.
		$timezone_string = get_option( 'timezone_string' );
		if ( empty( $timezone_string ) ) {
			$gmt_offset      = get_option( 'gmt_offset' );
			$timezone_string = timezone_name_from_abbr( '', $gmt_offset * 3600, 0 );
			if ( false === $timezone_string ) {
				$timezone_string = 'UTC';
			}
		}

		try {
			$timezone  = new DateTimeZone( $timezone_string );
			$banned_dt = new DateTime( $banned_at, $timezone );
			$banned_dt->modify( "+{$duration_hours} hours" );
			$expires_at = $banned_dt->format( 'Y-m-d H:i:s' );
		} catch ( Exception $e ) {
			// Fallback nếu có lỗi.
			$expires_at = null;
		}
	}

	$data = array(
		'user_id'      => $user_id,
		'ip_address'   => $ip_address,
		'display_name' => $display_name,
		'reason'       => $reason,
		'banned_by'    => $banned_by,
		'banned_at'    => $banned_at,
		'expires_at'   => $expires_at,
		'is_active'    => 1,
	);

	// Debug - chỉ khi WP_DEBUG = true.
	if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log(
			sprintf(
				'Chat Engine Ban Debug - Duration: %s hours, Banned At: %s (%d), Expires At: %s (%d)',
				$duration_hours ? $duration_hours : 'permanent',
				$banned_at,
				isset( $banned_timestamp ) ? $banned_timestamp : 0,
				$expires_at ? $expires_at : 'never',
				isset( $expires_timestamp ) ? $expires_timestamp : 0
			)
		);
	}

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$result = $wpdb->insert( $banned_table, $data );

	if ( $result ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log(
				sprintf(
					'Chat Engine: User banned - ID: %s, IP: %s, Name: %s, By: %s, Reason: %s, Expires: %s',
					$user_id ? $user_id : 'N/A',
					$ip_address ? $ip_address : 'N/A',
					$display_name ? $display_name : 'N/A',
					$banned_by,
					$reason,
					$expires_at ? $expires_at : 'Never'
				)
			);
		}

		// Clear cache.
		if ( $user_id ) {
			wp_cache_delete( 'banned_uid_' . $user_id, 'init_chat_engine' );
		}
		if ( $ip_address ) {
			wp_cache_delete( 'banned_ip_' . md5( $ip_address ), 'init_chat_engine' );
		}

		return $wpdb->insert_id;
	}

	return false;
}

/**
 * Unban user from chat
 *
 * @param int|null    $ban_id     Ban record ID to remove.
 * @param int|null    $user_id    User ID to unban, if registered.
 * @param string|null $ip_address IP address to unban, if guest.
 * @return bool
 */
function init_plugin_suite_chat_engine_unban_user( $ban_id = null, $user_id = null, $ip_address = null ) {
	global $wpdb;

	if ( ! current_user_can( 'manage_options' ) ) {
		return false;
	}

	$banned_table = $wpdb->prefix . 'init_chatbox_banned';
	$where        = array();
	$formats      = array();

	if ( $ban_id ) {
		$where['id'] = $ban_id;
		$formats[]   = '%d';
	} elseif ( $user_id ) {
		$where['user_id'] = $user_id;
		$formats[]        = '%d';
	} elseif ( $ip_address ) {
		$where['ip_address'] = $ip_address;
		$formats[]           = '%s';
	} else {
		return false;
	}

	$where['is_active'] = 1;
	$formats[]          = '%d';

	// Lấy record trước khi update.
	$record = null;

	if ( $ban_id ) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$record = $wpdb->get_row(
            // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->prepare(
				"SELECT user_id, ip_address FROM {$banned_table} WHERE id = %d",
				$ban_id
			)
            // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$result = $wpdb->update(
		$banned_table,
		array( 'is_active' => 0 ),    // data to update.
		$where,                   // where conditions (key-value pairs).
		array( '%d' ),                // format for data.
		$formats                 // format for where conditions.
	);

	if ( false !== $result ) {
		// Log the unban action - only in debug mode.
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log(
				sprintf(
					'Chat Engine: User unbanned - Ban ID: %s, User ID: %s, IP: %s, By: %s',
					$ban_id ? $ban_id : 'N/A',
					$user_id ? $user_id : 'N/A',
					$ip_address ? $ip_address : 'N/A',
					get_current_user_id()
				)
			);
		}

		if ( $record ) {
			if ( $record->user_id ) {
				wp_cache_delete( 'banned_uid_' . $record->user_id, 'init_chat_engine' );
			}
			if ( $record->ip_address ) {
				wp_cache_delete( 'banned_ip_' . md5( $record->ip_address ), 'init_chat_engine' );
			}
		}

		// Unban theo user_id / IP (không có ban_id) cũng phải xóa cache tương ứng,
		// nếu không người dùng vẫn bị coi là đang bị ban tới khi cache hết hạn.
		if ( $user_id ) {
			wp_cache_delete( 'banned_uid_' . $user_id, 'init_chat_engine' );
		}
		if ( $ip_address ) {
			wp_cache_delete( 'banned_ip_' . md5( $ip_address ), 'init_chat_engine' );
		}

		return true;
	}

	return false;
}

/**
 * Check if user is banned (cached)
 *
 * Từ 1.3.8 mỗi định danh (user_id / IP) được tra cứu + cache RIÊNG theo đúng key
 * của nó, và trường hợp "không bị ban" được cache bằng giá trị 'none' thay vì
 * false. Trước đây negative cache lưu false - mà wp_cache_get() cũng trả false khi
 * cache miss - nên không bao giờ hit, khiến MỌI lần poll đều phải query bảng ban
 * (1-2 query/poll/client). Tách key theo định danh cũng giúp ban_user()/unban_user()
 * xóa đúng cache cần xóa, không bị kết quả cũ của định danh còn lại che mất.
 *
 * @param int|null    $user_id    User ID to check, if registered.
 * @param string|null $ip_address IP address to check, if guest.
 * @return object|false Ban record on match, false otherwise.
 */
function init_plugin_suite_chat_engine_check_user_banned( $user_id = null, $ip_address = null ) {
	if ( empty( $user_id ) && empty( $ip_address ) ) {
		return false;
	}

	if ( $user_id ) {
		$ban_record = init_plugin_suite_chat_engine_lookup_ban( 'user_id', $user_id );
		if ( $ban_record ) {
			return $ban_record;
		}
	}

	if ( $ip_address ) {
		$ban_record = init_plugin_suite_chat_engine_lookup_ban( 'ip_address', $ip_address );
		if ( $ban_record ) {
			return $ban_record;
		}
	}

	return false;
}

/**
 * Tra cứu ban đang hiệu lực cho 1 định danh (user_id hoặc IP), có object cache.
 *
 * @param string     $field 'user_id' hoặc 'ip_address'.
 * @param int|string $value Giá trị định danh cần tra.
 * @return object|false Ban record on match, false otherwise.
 */
function init_plugin_suite_chat_engine_lookup_ban( $field, $value ) {
	global $wpdb;

	$is_user     = ( 'user_id' === $field );
	$cache_group = 'init_chat_engine';
	$cache_key   = $is_user ? 'banned_uid_' . (int) $value : 'banned_ip_' . md5( (string) $value );
	$cache_ttl   = 10 * MINUTE_IN_SECONDS;
	$now_mysql   = current_time( 'mysql' );

	$cached = wp_cache_get( $cache_key, $cache_group );

	if ( false !== $cached ) {
		if ( ! is_object( $cached ) ) {
			// 'none' = đã xác nhận không bị ban.
			return false;
		}

		// Ban có thời hạn vẫn còn hiệu lực -> dùng cache. Hết hạn trong lúc nằm
		// cache thì bỏ qua cache, query lại cho chắc.
		if ( empty( $cached->expires_at ) || $cached->expires_at > $now_mysql ) {
			return $cached;
		}
	}

	if ( $is_user ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ban_record = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM `{$wpdb->prefix}init_chatbox_banned` 
                 WHERE user_id = %d 
                 AND is_active = 1 
                 AND (expires_at IS NULL OR expires_at > %s) 
                 LIMIT 1",
				(int) $value,
				$now_mysql
			)
		);
	} else {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ban_record = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM `{$wpdb->prefix}init_chatbox_banned` 
                 WHERE ip_address = %s 
                 AND is_active = 1 
                 AND (expires_at IS NULL OR expires_at > %s) 
                 LIMIT 1",
				(string) $value,
				$now_mysql
			)
		);
	}

	if ( $ban_record ) {
		// Không giữ cache lâu hơn thời điểm ban hết hạn.
		if ( ! empty( $ban_record->expires_at ) ) {
			$seconds_left = strtotime( $ban_record->expires_at ) - strtotime( $now_mysql );
			$cache_ttl    = (int) max( 1, min( $cache_ttl, $seconds_left ) );
		}

		wp_cache_set( $cache_key, $ban_record, $cache_group, $cache_ttl );
		return $ban_record;
	}

	// Negative cache: dùng 'none' (KHÔNG dùng false - false trùng với cache miss).
	wp_cache_set( $cache_key, 'none', $cache_group, $cache_ttl );

	return false;
}

/**
 * Get all banned users - FIXED VERSION
 *
 * @param bool $active_only Whether to return only currently active bans.
 * @return array
 */
function init_plugin_suite_chat_engine_get_banned_users( $active_only = true ) {
	global $wpdb;

	if ( ! current_user_can( 'manage_options' ) ) {
		return array();
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
			0,
			1
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
 *
 * @param string   $user_ip Guest IP address.
 * @param int|null $user_id User ID, if logged in.
 * @return bool True if within limit (allowed to send).
 */
function init_plugin_suite_chat_engine_check_rate_limit( $user_ip, $user_id = null ) {
	$transient_key = 'init_chat_rate_limit_' . md5( $user_ip . ( $user_id ? '_' . $user_id : '' ) );
	$attempts      = get_transient( $transient_key );

	// Đọc đúng option mà trang Settings lưu (trước 1.3.8 đọc nhầm option cũ nên giá
	// trị admin cấu hình bị bỏ qua, luôn dùng mặc định 10 tin/phút).
	$rate_limit = (int) init_plugin_suite_chat_engine_get_setting( 'rate_limit', 10 ); // messages per minute.
	if ( $rate_limit < 1 ) {
		$rate_limit = 1;
	}

	if ( false === $attempts ) {
		set_transient( $transient_key, 1, 60 ); // 1 minute
		return true;
	}

	if ( $attempts >= $rate_limit ) {
		return false;
	}

	set_transient( $transient_key, $attempts + 1, 60 );
	return true;
}

// Register hooks.
register_activation_hook( INIT_PLUGIN_SUITE_CHAT_ENGINE_PATH . 'init-chat-engine.php', 'init_plugin_suite_chat_engine_activate' );
register_deactivation_hook( INIT_PLUGIN_SUITE_CHAT_ENGINE_PATH . 'init-chat-engine.php', 'init_plugin_suite_chat_engine_deactivate' );

// Check for database upgrades (mọi request, xem init_plugin_suite_chat_engine_maybe_upgrade_db()).
add_action( 'init', 'init_plugin_suite_chat_engine_maybe_upgrade_db', 5 );

// Register cleanup hook.
add_action( 'init_chat_engine_cleanup_messages', 'init_plugin_suite_chat_engine_cleanup_messages' );

// Update daily stats.
add_action(
	'init',
	function () {
		$today            = gmdate( 'Y-m-d' );
		$last_stat_update = get_option( 'init_chat_last_daily_stat_update', '' );

		if ( $last_stat_update !== $today ) {
			// Reset daily counters.
			init_plugin_suite_chat_engine_update_stat( 'messages_today', 0 );
			init_plugin_suite_chat_engine_update_stat( 'active_users_today', 0 );
			update_option( 'init_chat_last_daily_stat_update', $today );
		}
	}
);

/**
 * Delete all chat messages (nuclear cleanup)
 * - Only accessible by administrators
 * - Resets message statistics
 * - Keeps database integrity (TRUNCATE for performance)
 */
function init_plugin_suite_chat_engine_delete_all_messages() {
	// Chỉ admin mới được phép.
	if ( ! current_user_can( 'manage_options' ) ) {
		return new WP_Error(
			'unauthorized',
			__( 'You do not have permission to perform this action.', 'init-chat-engine' ),
			array( 'status' => 403 )
		);
	}

	global $wpdb;
	$table_name  = $wpdb->prefix . 'init_chatbox_msgs';
	$stats_table = $wpdb->prefix . 'init_chatbox_stats';

	// Bắt đầu transaction (nếu DB hỗ trợ).
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query( 'START TRANSACTION' );

	try {
		// Truncate bảng message — nhanh, sạch, reset AUTO_INCREMENT.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$wpdb->query( "TRUNCATE TABLE {$table_name}" );

		// Reset các thống kê liên quan.
		$default_stats = array(
			'total_messages'     => 0,
			'messages_today'     => 0,
			'active_users_today' => 0,
			'last_cleanup'       => current_time( 'mysql' ),
		);

		foreach ( $default_stats as $key => $value ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->replace(
				$stats_table,
				array(
					'stat_key'   => $key,
					'stat_value' => $value,
					'updated_at' => current_time( 'mysql' ),
				),
				array( '%s', '%s', '%s' )
			);
		}

		// Commit transaction.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( 'COMMIT' );

		// Toàn bộ tin nhắn đã bị xóa sạch -> phải xóa cache liên quan, không thì
		// frontend vẫn hiển thị tin cũ (đã cache) cho tới khi hết TTL. Xóa cả cache
		// pinned_message vì tin đang ghim (nếu có) giờ cũng không còn tồn tại nữa.
		init_plugin_suite_chat_engine_clear_message_cache();

		// Bỏ ghim luôn (ở mọi phòng): pinned dùng snapshot lưu riêng trong bảng stats
		// nên nếu chỉ xóa cache, banner ghim vẫn hiện lại nội dung của tin đã bị xóa.
		init_plugin_suite_chat_engine_unpin_all_rooms();

		// Ghi log (nếu WP_DEBUG bật).
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log(
				sprintf(
					'Init Chat Engine: All chat messages deleted by admin #%d at %s',
					get_current_user_id(),
					current_time( 'mysql' )
				)
			);
		}

		return true;

	} catch ( Exception $e ) {
		// Rollback nếu lỗi.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( 'ROLLBACK' );
		return new WP_Error( 'db_error', 'Failed to delete messages: ' . $e->getMessage() );
	}
}

/**
 * Xóa vĩnh viễn toàn bộ tin nhắn của 1 phòng (chỉ admin).
 *
 * @param string $room Room name ('' = default room).
 * @return int|WP_Error Number of deleted messages.
 */
function init_plugin_suite_chat_engine_delete_room_messages( $room ) {
	if ( ! current_user_can( 'manage_options' ) ) {
		return new WP_Error(
			'unauthorized',
			__( 'You do not have permission to perform this action.', 'init-chat-engine' ),
			array( 'status' => 403 )
		);
	}

	global $wpdb;

	$room = init_plugin_suite_chat_engine_sanitize_room( $room );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$deleted = $wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->prefix}init_chatbox_msgs WHERE room = %s",
			$room
		)
	);

	if ( false === $deleted ) {
		return new WP_Error( 'db_error', __( 'Failed to delete messages.', 'init-chat-engine' ) );
	}

	init_plugin_suite_chat_engine_clear_message_cache();
	init_plugin_suite_chat_engine_unpin_message( $room );

	return (int) $deleted;
}

/**
 * Ghim một tin nhắn (chỉ admin).
 * Lưu message_id vào stats table với key 'pinned_message_id' (theo từng phòng).
 * Lưu snapshot nội dung để tránh query thêm khi render.
 *
 * @param  int $message_id  ID của tin nhắn cần ghim.
 * @return true|WP_Error
 */
function init_plugin_suite_chat_engine_pin_message( int $message_id ) {
	if ( ! current_user_can( 'manage_options' ) ) {
		return new WP_Error( 'unauthorized', __( 'Permission denied.', 'init-chat-engine' ), array( 'status' => 403 ) );
	}

	global $wpdb;

	// Kiểm tra message có tồn tại và chưa bị xoá không.
	$msg = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->prepare(
			"SELECT id, user_id, display_name, message, created_at, room
             FROM `{$wpdb->prefix}init_chatbox_msgs`
             WHERE id = %d AND is_deleted = 0
             LIMIT 1",
			$message_id
		)
	);

	if ( ! $msg ) {
		return new WP_Error( 'not_found', __( 'Message not found.', 'init-chat-engine' ), array( 'status' => 404 ) );
	}

	// Tin được ghim vào đúng phòng chứa nó.
	$room = (string) $msg->room;

	// Lưu ID.
	init_plugin_suite_chat_engine_update_stat( init_plugin_suite_chat_engine_room_key( 'pinned_message_id', $room ), $message_id );

	// Lưu snapshot (JSON) để GET /messages không cần query thêm.
	$snapshot = wp_json_encode(
		array(
			'id'           => (int) $msg->id,
			'user_id'      => $msg->user_id ? (int) $msg->user_id : null,
			'display_name' => $msg->display_name,
			'message'      => $msg->message,
			'created_at'   => $msg->created_at,
			'pinned_by'    => get_current_user_id(),
			'pinned_at'    => current_time( 'mysql' ),
		)
	);
	init_plugin_suite_chat_engine_update_stat( init_plugin_suite_chat_engine_room_key( 'pinned_message_snapshot', $room ), $snapshot );

	// Xoá cache.
	wp_cache_delete( init_plugin_suite_chat_engine_room_key( 'pinned_message', $room ), 'init_chat_engine' );

	if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log(
			sprintf(
				'Init Chat Engine: Message #%d pinned by admin #%d',
				$message_id,
				get_current_user_id()
			)
		);
	}

	return true;
}

/**
 * Bỏ ghim tin nhắn (chỉ admin).
 *
 * @param string $room Room name ('' = default room).
 * @return true|WP_Error
 */
function init_plugin_suite_chat_engine_unpin_message( $room = '' ) {
	if ( ! current_user_can( 'manage_options' ) ) {
		return new WP_Error( 'unauthorized', __( 'Permission denied.', 'init-chat-engine' ), array( 'status' => 403 ) );
	}

	$room = (string) $room;

	init_plugin_suite_chat_engine_update_stat( init_plugin_suite_chat_engine_room_key( 'pinned_message_id', $room ), '' );
	init_plugin_suite_chat_engine_update_stat( init_plugin_suite_chat_engine_room_key( 'pinned_message_snapshot', $room ), '' );

	wp_cache_delete( init_plugin_suite_chat_engine_room_key( 'pinned_message', $room ), 'init_chat_engine' );

	return true;
}

/**
 * Bỏ ghim nếu tin đang ghim nằm trong danh sách tin vừa bị admin xóa/ẩn.
 *
 * Tin ghim được lưu dạng snapshot (không query lại bảng messages), nên nếu không
 * bỏ ghim thì nội dung tin đã bị xóa vẫn tiếp tục hiển thị trên banner. Chỉ gọi ở
 * các thao tác xóa CHỦ ĐỘNG của admin (không gọi khi tự động cắt bớt tin cũ theo
 * max_messages, để thông báo ghim lâu ngày không tự biến mất).
 *
 * @param int[] $message_ids IDs of the messages that were just deleted.
 * @return void
 */
function init_plugin_suite_chat_engine_maybe_unpin_deleted( $message_ids ) {
	global $wpdb;

	$message_ids = array_values( array_filter( array_map( 'intval', (array) $message_ids ) ) );

	if ( empty( $message_ids ) ) {
		return;
	}

	$placeholders = implode( ', ', array_fill( 0, count( $message_ids ), '%d' ) );

	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $placeholders chỉ gồm chuỗi '%d' do code tự sinh, giá trị thật được bind qua prepare().
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$rooms = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT DISTINCT room FROM `{$wpdb->prefix}init_chatbox_msgs` WHERE id IN ( {$placeholders} )",
			$message_ids
		)
	);
	// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

	foreach ( (array) $rooms as $room ) {
		$room      = (string) $room;
		$pinned_id = (int) init_plugin_suite_chat_engine_get_stat( init_plugin_suite_chat_engine_room_key( 'pinned_message_id', $room ), 0 );

		if ( $pinned_id > 0 && in_array( $pinned_id, $message_ids, true ) ) {
			init_plugin_suite_chat_engine_unpin_message( $room );
		}
	}
}

/**
 * Bỏ ghim ở MỌI phòng (dùng khi xóa sạch toàn bộ tin nhắn).
 *
 * @return void
 */
function init_plugin_suite_chat_engine_unpin_all_rooms() {
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$keys = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT stat_key FROM `{$wpdb->prefix}init_chatbox_stats` WHERE stat_key = %s OR stat_key LIKE %s",
			'pinned_message_id',
			$wpdb->esc_like( 'pinned_message_id@' ) . '%'
		)
	);

	foreach ( (array) $keys as $key ) {
		$room = 0 === strpos( $key, 'pinned_message_id@' ) ? substr( $key, strlen( 'pinned_message_id@' ) ) : '';
		init_plugin_suite_chat_engine_unpin_message( $room );
	}

	// Phòng mặc định luôn được reset (kể cả khi chưa từng có key).
	init_plugin_suite_chat_engine_unpin_message( '' );
}

/**
 * Lấy tin nhắn đang ghim (có cache).
 * Trả về array data hoặc null nếu chưa ghim.
 *
 * @param string $room Room name ('' = default room).
 * @return array|null
 */
function init_plugin_suite_chat_engine_get_pinned_message( $room = '' ) {
	$room        = (string) $room;
	$cache_group = 'init_chat_engine';
	$cache_key   = init_plugin_suite_chat_engine_room_key( 'pinned_message', $room );
	$cached      = wp_cache_get( $cache_key, $cache_group );

	if ( false !== $cached ) {
		return $cached ? $cached : null; // false = cache miss, '' = no pin.
	}

	$pinned_id = init_plugin_suite_chat_engine_get_stat( init_plugin_suite_chat_engine_room_key( 'pinned_message_id', $room ), '' );

	if ( empty( $pinned_id ) ) {
		wp_cache_set( $cache_key, '', $cache_group, 5 * MINUTE_IN_SECONDS );
		return null;
	}

	$snapshot_json = init_plugin_suite_chat_engine_get_stat( init_plugin_suite_chat_engine_room_key( 'pinned_message_snapshot', $room ), '' );

	if ( ! empty( $snapshot_json ) ) {
		$data = json_decode( $snapshot_json, true );
		if ( $data ) {
			wp_cache_set( $cache_key, $data, $cache_group, 5 * MINUTE_IN_SECONDS );
			return $data;
		}
	}

	// Fallback: query trực tiếp nếu snapshot bị mất.
	global $wpdb;
	$msg = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->prepare(
			"SELECT id, user_id, display_name, message, created_at
             FROM `{$wpdb->prefix}init_chatbox_msgs`
             WHERE id = %d AND is_deleted = 0 AND room = %s
             LIMIT 1",
			(int) $pinned_id,
			$room
		),
		ARRAY_A
	);

	if ( ! $msg ) {
		// Message bị xoá → tự động bỏ ghim (chỉ khi có quyền, unpin tự kiểm tra).
		init_plugin_suite_chat_engine_unpin_message( $room );
		return null;
	}

	wp_cache_set( $cache_key, $msg, $cache_group, 5 * MINUTE_IN_SECONDS );
	return $msg;
}
