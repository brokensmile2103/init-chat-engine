<?php
/**
 * REST API endpoints for the chat engine (messages, send, user-status, pin/unpin).
 *
 * @package Init_Chat_Engine
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.UnnecessaryPrepare
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching

/**
 * Register REST API routes for Init Chat Engine
 */
add_action( 'rest_api_init', 'init_plugin_suite_chat_engine_register_rest_routes' );

/**
 * Register all REST routes used by the chat engine.
 *
 * @return void
 */
function init_plugin_suite_chat_engine_register_rest_routes() {
	// Messages endpoint.
	register_rest_route(
		INIT_PLUGIN_SUITE_CHAT_ENGINE_NAMESPACE,
		'/messages',
		array(
			'methods'             => 'GET',
			'callback'            => 'init_plugin_suite_chat_engine_get_messages',
			'permission_callback' => 'init_plugin_suite_chat_engine_messages_permission_check',
			'args'                => array(
				'after_id'  => array(
					'type'              => 'integer',
					'required'          => false,
					'default'           => 0,
					'validate_callback' => function ( $param ) {
						return is_numeric( $param ) && $param >= 0;
					},
				),
				'before_id' => array(
					'type'              => 'integer',
					'required'          => false,
					'default'           => 0,
					'validate_callback' => function ( $param ) {
						return is_numeric( $param ) && $param >= 0;
					},
				),
				'limit'     => array(
					'type'              => 'integer',
					'required'          => false,
					'default'           => 15,
					'validate_callback' => function ( $param ) {
						return is_numeric( $param ) && $param > 0 && $param <= 50;
					},
				),
			),
		)
	);

	// Send message endpoint.
	register_rest_route(
		INIT_PLUGIN_SUITE_CHAT_ENGINE_NAMESPACE,
		'/send',
		array(
			'methods'             => 'POST',
			'callback'            => 'init_plugin_suite_chat_engine_send_message',
			'permission_callback' => 'init_plugin_suite_chat_engine_send_permission_check',
			'args'                => array(
				'message'      => array(
					'type'              => 'string',
					'required'          => true,
					'validate_callback' => 'init_plugin_suite_chat_engine_validate_message',
					'sanitize_callback' => 'sanitize_textarea_field',
				),
				'display_name' => array(
					'type'              => 'string',
					'required'          => false,
					'validate_callback' => function ( $param ) {
						return empty( $param ) || ( is_string( $param ) && init_plugin_suite_chat_engine_strlen( trim( $param ) ) <= 100 );
					},
					'sanitize_callback' => 'sanitize_text_field',
				),
			),
		)
	);

	// User status endpoint.
	register_rest_route(
		INIT_PLUGIN_SUITE_CHAT_ENGINE_NAMESPACE,
		'/user-status',
		array(
			'methods'             => 'GET',
			'callback'            => 'init_plugin_suite_chat_engine_get_user_status',
			'permission_callback' => '__return_true',
		)
	);

	// POST  /pin  → ghim tin nhắn.
	register_rest_route(
		INIT_PLUGIN_SUITE_CHAT_ENGINE_NAMESPACE,
		'/pin',
		array(
			'methods'             => 'POST',
			'callback'            => 'init_plugin_suite_chat_engine_rest_pin_message',
			'permission_callback' => 'init_plugin_suite_chat_engine_admin_permission_check',
			'args'                => array(
				'message_id' => array(
					'type'              => 'integer',
					'required'          => true,
					'minimum'           => 1,
					'validate_callback' => function ( $param ) {
						return is_numeric( $param ) && $param > 0;
					},
				),
			),
		)
	);

	// DELETE /pin  → bỏ ghim.
	register_rest_route(
		INIT_PLUGIN_SUITE_CHAT_ENGINE_NAMESPACE,
		'/pin',
		array(
			'methods'             => 'DELETE',
			'callback'            => 'init_plugin_suite_chat_engine_rest_unpin_message',
			'permission_callback' => 'init_plugin_suite_chat_engine_admin_permission_check',
		)
	);
}

/**
 * Permission check for messages endpoint
 *
 * @param WP_REST_Request $request Request object (required by REST API callback signature, unused here).
 * @return bool
 */
function init_plugin_suite_chat_engine_messages_permission_check( $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- $request required by WP_REST_Server callback signature.
	// Check if user is banned.
	$user_ip   = init_plugin_suite_chat_engine_get_user_ip();
	$user_id   = is_user_logged_in() ? get_current_user_id() : null;
	$ban_check = init_plugin_suite_chat_engine_check_user_banned( $user_id, $user_ip );

	if ( $ban_check ) {
		return new WP_Error( 'user_banned', __( 'You are banned from the chat.', 'init-chat-engine' ), array( 'status' => 403 ) );
	}

	return true;
}

/**
 * Permission check for send message endpoint
 *
 * @param WP_REST_Request $request Request object.
 * @return true|WP_Error
 */
function init_plugin_suite_chat_engine_send_permission_check( $request ) {
	// Check nonce for logged in users.
	if ( is_user_logged_in() ) {
		$valid = wp_verify_nonce(
			$request->get_header( 'X-WP-Nonce' ),
			'wp_rest'
		);
		if ( ! $valid ) {
			return new WP_Error( 'invalid_nonce', __( 'Invalid nonce.', 'init-chat-engine' ), array( 'status' => 403 ) );
		}
	}

	// Check if user is banned.
	$user_ip   = init_plugin_suite_chat_engine_get_user_ip();
	$user_id   = is_user_logged_in() ? get_current_user_id() : null;
	$ban_check = init_plugin_suite_chat_engine_check_user_banned( $user_id, $user_ip );

	if ( $ban_check ) {
		return new WP_Error( 'user_banned', __( 'You are banned from the chat.', 'init-chat-engine' ), array( 'status' => 403 ) );
	}

	// Check rate limiting.
	if ( ! init_plugin_suite_chat_engine_check_rate_limit( $user_ip, $user_id ) ) {
		return new WP_Error( 'rate_limit_exceeded', __( 'You are sending messages too quickly. Please slow down.', 'init-chat-engine' ), array( 'status' => 429 ) );
	}

	return true;
}

/**
 * Validate message content
 *
 * @param string $message Raw message text to validate.
 * @return bool
 */
function init_plugin_suite_chat_engine_validate_message( $message ) {
	if ( ! is_string( $message ) || '' === trim( $message ) ) {
		return false;
	}

	$settings   = init_plugin_suite_chat_engine_get_all_settings();
	$max_length = isset( $settings['max_message_length'] ) ? (int) $settings['max_message_length'] : 500;

	// Đếm theo KÝ TỰ (không phải byte) - khớp với bộ đếm ký tự ở client và mô tả
	// "Maximum number of characters" trong Settings. Trước 1.3.8 dùng strlen() nên
	// tin tiếng Việt / emoji (2-4 byte mỗi ký tự) bị chặn sớm hơn giới hạn thật.
	if ( init_plugin_suite_chat_engine_strlen( $message ) > $max_length ) {
		return false;
	}

	// Check word filtering.
	if ( ! init_plugin_suite_chat_engine_check_message_content( $message ) ) {
		return false;
	}

	return true;
}

/**
 * GET /messages – Return list of messages with timestamp updates
 * Trả kèm profile_url của user (mặc định: author archive).
 * Có thể override bằng filter: 'init_plugin_suite_chat_engine_get_user_profile_url'.
 *
 * @param WP_REST_Request $request Request object.
 * @return WP_REST_Response
 */
function init_plugin_suite_chat_engine_get_messages( WP_REST_Request $request ) {
	global $wpdb;

	$table     = esc_sql( $wpdb->prefix . 'init_chatbox_msgs' );
	$after_id  = (int) $request->get_param( 'after_id' );
	$before_id = (int) $request->get_param( 'before_id' );
	$limit     = (int) $request->get_param( 'limit' );

	// Limit an toàn.
	if ( $limit <= 0 ) {
		$limit = 20;
	} elseif ( $limit > 100 ) {
		$limit = 100;
	}

	$cache_group = 'init_chat_engine';

    // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	$messages = array();

	// Build query dựa theo tham số.
	if ( $after_id > 0 ) {
		// Polling realtime: đại đa số các lần poll KHÔNG có tin nhắn mới (chat không
		// sôi động liên tục 24/7). Cache 1 giá trị "ID tin nhắn mới nhất hiện có" -
		// nếu client đã có ID này rồi (after_id >= cache) thì trả rỗng ngay, KHÔNG
		// chạm DB. Cache được xóa chủ động mỗi khi có tin mới/bị xóa (xem
		// init_plugin_suite_chat_engine_clear_frontend_message_cache()), TTL chỉ là
		// lưới an toàn dự phòng.
		$cached_latest_id = wp_cache_get( 'frontend_latest_id', $cache_group );

		if ( false !== $cached_latest_id && $after_id >= (int) $cached_latest_id ) {
			$messages = array();
		} else {
			// Lấy message mới hơn (realtime updates).
			$results  = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, user_id, display_name, message, created_at 
                     FROM {$table} 
                     WHERE id > %d AND is_deleted = 0
                     ORDER BY id ASC 
                     LIMIT %d",
					$after_id,
					$limit
				),
				ARRAY_A
			);
			$messages = $results;

			if ( ! empty( $messages ) ) {
				// Có tin mới thật sự -> cache lại ID cao nhất vừa xác nhận được.
				// Đây là giá trị ĐÃ XÁC NHẬN từ kết quả query thật, an toàn để cache.
				$newest_id = (int) end( $messages )['id'];
				wp_cache_set( 'frontend_latest_id', $newest_id, $cache_group, 2 * MINUTE_IN_SECONDS );
			}
			// KHÔNG cache khi $messages rỗng. Trước đây nhánh này tự suy ra
			// "known_max = max(after_id, cached_latest_id)" rồi cache lại - đây là
			// một race condition: nếu giữa lúc query (thấy rỗng) và lúc set cache ở
			// đây có 1 request /send khác vừa insert xong và gọi
			// clear_frontend_message_cache(), thì dòng set cache ở đây sẽ CHẠY SAU
			// và ghi đè lên, làm cache lại giữ 1 giá trị đã cũ/sai (thấp hơn ID thật
			// mới nhất). Vì cache 'frontend_latest_id' dùng chung cho MỌI client
			// (không phân biệt theo after_id), hậu quả là toàn bộ client bị chặn
			// không thấy tin nhắn mới ở dòng kiểm tra "$after_id >= $cached_latest_id"
			// phía trên, cho tới khi có 1 tin nhắn KẾ TIẾP xoá cache lần nữa - đúng
			// hiện tượng "tin thứ 2 không hiện, gửi tin thứ 3 thì cả 2 hiện cùng lúc".
			// Bỏ cache negative ở đây: cache chỉ được ghi từ kết quả query đã xác nhận
			// (nhánh if phía trên) hoặc bị xoá chủ động khi có insert/xoá tin, nên
			// luôn phản ánh đúng trạng thái DB, không suy đoán.
		}

		// Lưu ý: KHÔNG query thêm 50 tin gần nhất để "refresh timestamp" ở đây nữa.
		// Trước đây mỗi lần poll (mỗi 2-12s/client) đều chạy thêm 1 query + tính
		// human_time_diff() cho 50 dòng dù không có gì thay đổi. Hiển thị dạng
		// "x phút trước" giờ được tính trực tiếp ở client (chat.js) dựa vào
		// created_at_iso đã trả sẵn, không cần round-trip lên server.

	} elseif ( $before_id > 0 ) {
		// Phân trang lùi (older messages) - mỗi client dừng cuộn ở vị trí khác nhau
		// nên tỉ lệ cache hit sẽ thấp, cố tình không cache nhánh này.
		$results  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, user_id, display_name, message, created_at 
                 FROM {$table} 
                 WHERE id < %d AND is_deleted = 0
                 ORDER BY id DESC 
                 LIMIT %d",
				$before_id,
				$limit
			),
			ARRAY_A
		);
		$messages = $results;

	} else {
		// Lần đầu tải trang: MỌI client mới vào đều gọi đúng 1 dạng query giống hệt
		// nhau (is_deleted=0 ORDER BY id DESC), chỉ khác $limit (JS dùng limit=15 cho
		// tải trang, limit=1 cho check pinned message ban đầu). Cache chung 1 lần 50
		// dòng mới nhất (mức trần limit cho phép ở REST arg), rồi cắt theo $limit thực
		// tế từng request bằng array_slice - không cần query lại DB cho từng lượt tải
		// trang mới, dù có bao nhiêu người cùng vào chat một lúc.
		$cached_latest = wp_cache_get( 'frontend_latest_messages', $cache_group );

		if ( false === $cached_latest ) {
			$cached_latest = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, user_id, display_name, message, created_at 
                     FROM {$table} 
                     WHERE is_deleted = 0
                     ORDER BY id DESC 
                     LIMIT %d",
					50
				),
				ARRAY_A
			);
			// TTL ngắn chỉ làm lưới an toàn dự phòng - chủ yếu dựa vào việc chủ động
			// xóa cache mỗi khi có tin mới/bị xóa.
			wp_cache_set( 'frontend_latest_messages', $cached_latest, $cache_group, 30 );
		}

		$messages = array_slice( $cached_latest, 0, $limit );
	}

    // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	$settings        = init_plugin_suite_chat_engine_get_all_settings();
	$show_avatars    = ! empty( $settings['show_avatars'] );
	$current_user_id = is_user_logged_in() ? get_current_user_id() : 0;

	/**
	 * Trả về URL profile của user. Mặc định dùng author archive.
	 * Có thể override qua filter 'init_plugin_suite_chat_engine_get_user_profile_url'.
	 *
	 * @param int $user_id
	 * @return string
	 */
	$get_profile_url = function ( $user_id ) {
		if ( $user_id <= 0 ) {
			return '';
		}

		// Mặc định: trang tác giả (public).
		$url = get_author_posts_url( $user_id );

		/**
		 * Cho phép tùy biến:
		 * - Về trang admin edit: admin_url( 'user-edit.php?user_id=' . $user_id )
		 * - Về trang profile tùy chỉnh (BuddyPress/UM/BBPress…)
		 */
		$url = apply_filters( 'init_plugin_suite_chat_engine_get_user_profile_url', $url, $user_id );

		// Bảo vệ đầu ra.
		return esc_url_raw( $url );
	};

	// Hàm format 1 row message.
	$format_message = function ( &$row ) use ( $show_avatars, $get_profile_url, $current_user_id ) {
		// Time
		// Lưu ý quan trọng: $row['created_at'] được lưu bằng current_time('mysql')
		// (giờ ĐỊA PHƯƠNG theo timezone site, KHÔNG phải UTC). WordPress ép PHP
		// timezone mặc định về UTC, nên strtotime() trên chuỗi này sẽ hiểu NHẦM giờ
		// địa phương thành giờ UTC.
		//
		// human_time_diff() dưới đây vẫn ĐÚNG dù dùng $created_timestamp "lệch", vì
		// current_time('timestamp') cũng bị lệch giống hệt - lấy hiệu số nên sai số
		// tự triệt tiêu. Nhưng created_at_iso thì KHÔNG được lấy hiệu số ở server -
		// client (chat.js) parse thẳng thành mốc UTC tuyệt đối rồi so với Date.now()
		// thật, nên phải quy đổi đúng qua GMT bằng get_gmt_from_date() trước, nếu
		// không tin nhắn sẽ luôn hiện "vừa xong" (site múi giờ dương) hoặc "X giờ
		// trước" ngay khi vừa gửi (site múi giờ âm), tùy UTC offset của site.
		$created_timestamp = strtotime( $row['created_at'] );
		// phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested -- Cố ý: $created_timestamp ở trên bị lệch giống hệt current_time('timestamp') (xem giải thích phía trên), lấy hiệu số nên sai số tự triệt tiêu. Đổi sang time() sẽ làm SAI kết quả.
		$row['created_at_human'] = human_time_diff( $created_timestamp, current_time( 'timestamp' ) );

		$created_timestamp_utc    = strtotime( get_gmt_from_date( $row['created_at'] ) );
		$row['created_at_iso']    = gmdate( 'c', $created_timestamp_utc );
		$row['created_timestamp'] = $created_timestamp_utc;

		// Avatar.
		$row['avatar_url'] = '';
		$uid               = ! empty( $row['user_id'] ) ? (int) $row['user_id'] : 0;
		if ( $show_avatars && $uid > 0 ) {
			$avatar_url = get_avatar_url( $uid, array( 'size' => 64 ) );
			if ( $avatar_url ) {
				$row['avatar_url'] = esc_url_raw( $avatar_url );
			}
		}

		// User flags.
		$row['is_current_user'] = $current_user_id > 0 && $current_user_id === $uid;
		$row['user_type']       = $uid > 0 ? 'registered' : 'guest';

		// Profile URL (yêu cầu của bro).
		$row['profile_url'] = $uid > 0 ? $get_profile_url( $uid ) : '';

		// Sanitize message.
		$row['message'] = wp_kses_post( $row['message'] );

		// Cho phép theme/plugin khác filter nội dung message.
		$row['message'] = apply_filters( 'init_plugin_suite_chat_engine_format_message', $row['message'], $row );

		// Tên hiển thị + HTML sẵn để FE khỏi lặp code.
		$display_name = isset( $row['display_name'] ) ? wp_strip_all_tags( $row['display_name'] ) : '';
		if ( $row['profile_url'] ) {
			// target+rel phòng khi render ngoài site.
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

	// Format main messages.
	foreach ( $messages as &$row ) {
		$format_message( $row );
	}
	unset( $row );

	// Update stats (có throttle, tối đa ghi DB 1 lần/phút - xem init.php).
	init_plugin_suite_chat_engine_touch_last_activity();

	$response = array(
		'success'        => true,
		'messages'       => $messages,
		'count'          => count( $messages ),
		'has_more'       => count( $messages ) === $limit,
		'pinned_message' => init_plugin_suite_chat_engine_get_pinned_message(),
	);

	return rest_ensure_response( $response );
}

/**
 * POST /send – Send a message
 *
 * @param WP_REST_Request $request Request object.
 * @return WP_REST_Response|WP_Error
 */
function init_plugin_suite_chat_engine_send_message( WP_REST_Request $request ) {
	global $wpdb;

	$settings     = init_plugin_suite_chat_engine_get_all_settings();
	$allow_guests = ! empty( $settings['allow_guests'] );

	$current_user = wp_get_current_user();
	$user_id      = $current_user->exists() ? $current_user->ID : null;

	$message      = wp_strip_all_tags( trim( $request->get_param( 'message' ) ) );
	$display_name = $user_id ? wp_strip_all_tags( $current_user->display_name ) : wp_strip_all_tags( trim( $request->get_param( 'display_name' ) ) );

	// Additional validation.
	if ( ! $user_id && ! $allow_guests ) {
		return new WP_Error( 'unauthorized', __( 'Guests are not allowed to chat.', 'init-chat-engine' ), array( 'status' => 403 ) );
	}

	// Check account age requirement.
	$account_age_check = init_plugin_suite_chat_engine_check_account_age_requirement();

	if ( is_wp_error( $account_age_check ) ) {
		return $account_age_check;
	}

	if ( ! $display_name ) {
		return new WP_Error( 'missing_name', __( 'Display name is required.', 'init-chat-engine' ), array( 'status' => 400 ) );
	}

	// Check word filtering again (double check).
	if ( ! init_plugin_suite_chat_engine_check_message_content( $message ) ) {
		return new WP_Error( 'message_blocked', __( 'Your message contains blocked words.', 'init-chat-engine' ), array( 'status' => 400 ) );
	}

	// Get user IP and User Agent.
	$user_ip    = init_plugin_suite_chat_engine_get_user_ip();
	$user_agent = isset( $_SERVER['HTTP_USER_AGENT'] )
		? substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 255 )
		: '';

	$table_name = $wpdb->prefix . 'init_chatbox_msgs';

	// Insert message.
	$result = $wpdb->insert(
		$table_name,
		array(
			'user_id'      => $user_id,
			'display_name' => $display_name,
			'message'      => $message,
			'ip_address'   => $user_ip,
			'user_agent'   => $user_agent,
			'created_at'   => current_time( 'mysql' ),
		),
		array(
			'%d',
			'%s',
			'%s',
			'%s',
			'%s',
			'%s',
		)
	);

	if ( ! $result ) {
		return new WP_Error( 'insert_failed', __( 'Failed to save message.', 'init-chat-engine' ), array( 'status' => 500 ) );
	}

	$message_id = $wpdb->insert_id;

	// Có tin mới -> xóa cache "tin mới nhất" phía frontend ngay, đảm bảo mọi client
	// (kể cả người vừa mở tab) thấy tin này ngay lần poll/tải trang kế tiếp.
	init_plugin_suite_chat_engine_clear_frontend_message_cache();

	// Danh sách tin ở trang quản trị cũng cần thấy tin mới ngay (không đợi TTL).
	init_plugin_suite_chat_engine_bump_admin_cache();

	do_action( 'init_plugin_suite_chat_engine_message_saved', $message_id, $message, $user_id, $display_name );

	// Update statistics (atomic +1, 1 query mỗi stat thay vì đọc rồi ghi).
	init_plugin_suite_chat_engine_increment_stat( 'total_messages' );
	init_plugin_suite_chat_engine_increment_stat( 'messages_today' );

	// Cleanup if over limit (using new soft delete).
	$max   = isset( $settings['max_messages'] ) ? (int) $settings['max_messages'] : 1000;
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

		// Trim tin cũ nhất khi vượt max_messages - thường không đụng tới cache "tin
		// mới nhất" (đã clear ở trên rồi), nhưng clear thêm lần nữa cho chắc để tránh
		// trường hợp hiếm: 1 request GET khác chen vào đúng lúc giữa insert và trim,
		// cache lại dữ liệu trước khi trim.
		init_plugin_suite_chat_engine_clear_message_cache();
	}

	// Return success with message data.
	return rest_ensure_response(
		array(
			'success'    => true,
			'message_id' => $message_id,
			'message'    => array(
				'id'                => $message_id,
				'user_id'           => $user_id,
				'display_name'      => $display_name,
				'message'           => wp_kses_post( $message ),
				'created_at_human'  => __( 'now', 'init-chat-engine' ),
				'created_at_iso'    => gmdate( 'c' ),
				'created_timestamp' => time(),
				'avatar_url'        => $user_id ? get_avatar_url( $user_id, array( 'size' => 64 ) ) : '',
				'is_current_user'   => true,
				'user_type'         => $user_id ? 'registered' : 'guest',
			),
		)
	);
}

/**
 * GET /user-status – Get current user status and chat info
 *
 * @param WP_REST_Request $request Request object (required by REST API callback signature, unused here).
 * @return WP_REST_Response
 */
function init_plugin_suite_chat_engine_get_user_status( WP_REST_Request $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- $request required by WP_REST_Server callback signature.
	$settings     = init_plugin_suite_chat_engine_get_all_settings();
	$current_user = wp_get_current_user();
	$user_ip      = init_plugin_suite_chat_engine_get_user_ip();

	// Check if user is banned.
	$ban_check = init_plugin_suite_chat_engine_check_user_banned(
		$current_user->exists() ? $current_user->ID : null,
		$user_ip
	);

	$status = array(
		'is_logged_in' => $current_user->exists(),
		'user_id'      => $current_user->exists() ? $current_user->ID : 0,
		'display_name' => $current_user->exists() ? $current_user->display_name : '',
		'avatar_url'   => $current_user->exists() ? get_avatar_url( $current_user->ID, array( 'size' => 64 ) ) : '',
		'allow_guests' => ! empty( $settings['allow_guests'] ),
		'is_banned'    => (bool) $ban_check,
		// Chỉ trả các trường cần cho người bị ban, không lộ ID admin đã ban,
		// IP lưu trong DB hay dữ liệu nội bộ khác của bản ghi ban.
		'ban_info'     => $ban_check ? array(
			'reason'     => isset( $ban_check->reason ) ? $ban_check->reason : '',
			'banned_at'  => isset( $ban_check->banned_at ) ? $ban_check->banned_at : null,
			'expires_at' => isset( $ban_check->expires_at ) ? $ban_check->expires_at : null,
		) : null,
		'settings'     => array(
			'show_avatars'         => ! empty( $settings['show_avatars'] ),
			'show_timestamps'      => ! empty( $settings['show_timestamps'] ),
			'enable_notifications' => ! empty( $settings['enable_notifications'] ),
			'enable_sounds'        => ! empty( $settings['enable_sounds'] ),
			'max_message_length'   => isset( $settings['max_message_length'] ) ? (int) $settings['max_message_length'] : 500,
			'rate_limit'           => isset( $settings['rate_limit'] ) ? (int) $settings['rate_limit'] : 10,
		),
	);

	return rest_ensure_response( $status );
}

// REMOVED: Online users function - XÓA LUÔN VÌ VÔ DỤNG!

/**
 * Check if there are any messages
 */
function init_plugin_suite_chat_engine_has_messages() {
	global $wpdb;

	$cache_key   = 'has_messages';
	$cache_group = 'init_chat_engine';

	// Try cache first.
	$cached = wp_cache_get( $cache_key, $cache_group );
	if ( false !== $cached ) {
		return (bool) $cached;
	}

	// Query DB.
	$exists = $wpdb->get_var(
		"SELECT 1 FROM {$wpdb->prefix}init_chatbox_msgs WHERE is_deleted = 0 LIMIT 1"
	);

	$result = (bool) $exists;

	// Cache for 1 day (86400 seconds).
	wp_cache_set( $cache_key, $result, $cache_group, DAY_IN_SECONDS );

	return $result;
}

/**
 * Get message count for current user (for rate limiting display)
 *
 * @param int|null    $user_id User ID, if logged in.
 * @param string|null $user_ip Guest IP address, if not logged in.
 * @return int
 */
function init_plugin_suite_chat_engine_get_user_message_count( $user_id = null, $user_ip = null ) {
	if ( ! $user_id && ! $user_ip ) {
		return 0;
	}

	$transient_key = 'init_chat_rate_limit_' . md5( ( $user_ip ? $user_ip : '' ) . ( $user_id ? '_' . $user_id : '' ) );
	$count         = get_transient( $transient_key );

	return $count ? $count : 0;
}

/**
 * Admin endpoint to moderate messages (for future use)
 */
add_action(
	'rest_api_init',
	function () {
		register_rest_route(
			INIT_PLUGIN_SUITE_CHAT_ENGINE_NAMESPACE,
			'/admin/moderate',
			array(
				'methods'             => 'POST',
				'callback'            => 'init_plugin_suite_chat_engine_moderate_message',
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
				'args'                => array(
					'message_id' => array(
						'type'     => 'integer',
						'required' => true,
					),
					'action'     => array(
						'type'     => 'string',
						'required' => true,
						'enum'     => array( 'approve', 'delete', 'ban_user' ),
					),
				),
			)
		);
	}
);

/**
 * Moderate message (admin only)
 *
 * @param WP_REST_Request $request Request object.
 * @return WP_REST_Response|WP_Error
 */
function init_plugin_suite_chat_engine_moderate_message( WP_REST_Request $request ) {
	global $wpdb;

	$message_id = (int) $request->get_param( 'message_id' );
	$action     = $request->get_param( 'action' );

	$message = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}init_chatbox_msgs WHERE id = %d",
			$message_id
		)
	);

	if ( ! $message ) {
		return new WP_Error( 'message_not_found', __( 'Message not found.', 'init-chat-engine' ), array( 'status' => 404 ) );
	}

	switch ( $action ) {
		case 'delete':
			$wpdb->update(
				$wpdb->prefix . 'init_chatbox_msgs',
				array( 'is_deleted' => 1 ),
				array( 'id' => $message_id ),
				array( '%d' ),
				array( '%d' )
			);

			// Tin vừa bị ẩn khỏi danh sách hiển thị -> xóa cache liên quan (cascade
			// sang cả cache frontend, xem init_plugin_suite_chat_engine_clear_message_cache()).
			init_plugin_suite_chat_engine_clear_message_cache();
			init_plugin_suite_chat_engine_maybe_unpin_deleted( array( $message_id ) );
			break;

		case 'ban_user':
			if ( $message->user_id || $message->ip_address ) {
				init_plugin_suite_chat_engine_ban_user(
					$message->user_id ? $message->user_id : null,
					$message->ip_address ? $message->ip_address : null,
					$message->display_name,
					'Banned by moderator'
				);
			}
			break;

		case 'approve':
			// For future moderation system.
			break;
	}

	return rest_ensure_response(
		array(
			'success' => true,
			'action'  => $action,
		)
	);
}

// ----------------------------------------------------------------
// Permission: chỉ admin.
// ----------------------------------------------------------------
/**
 * Restrict a REST route to admins only (manage_options).
 *
 * @param WP_REST_Request $request Request object.
 * @return true|WP_Error
 */
function init_plugin_suite_chat_engine_admin_permission_check( WP_REST_Request $request ) {
	// Verify nonce.
	$valid = wp_verify_nonce(
		$request->get_header( 'X-WP-Nonce' ),
		'wp_rest'
	);
	if ( ! $valid ) {
		return new WP_Error( 'invalid_nonce', __( 'Invalid nonce.', 'init-chat-engine' ), array( 'status' => 403 ) );
	}

	if ( ! current_user_can( 'manage_options' ) ) {
		return new WP_Error( 'unauthorized', __( 'Permission denied.', 'init-chat-engine' ), array( 'status' => 403 ) );
	}

	return true;
}

// ----------------------------------------------------------------
// Callback: POST /pin.
// ----------------------------------------------------------------
/**
 * Pin a message.
 *
 * @param WP_REST_Request $request Request object.
 * @return WP_REST_Response|WP_Error
 */
function init_plugin_suite_chat_engine_rest_pin_message( WP_REST_Request $request ) {
	$message_id = (int) $request->get_param( 'message_id' );

	$result = init_plugin_suite_chat_engine_pin_message( $message_id );

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	// Trả về data đầy đủ để JS cập nhật UI ngay, không cần reload.
	$pinned = init_plugin_suite_chat_engine_get_pinned_message();

	return rest_ensure_response(
		array(
			'success'        => true,
			'pinned_message' => $pinned,
		)
	);
}

// ----------------------------------------------------------------
// Callback: DELETE /pin
// ----------------------------------------------------------------
/**
 * Unpin the currently pinned message.
 *
 * @param WP_REST_Request $request Request object (required by REST API callback signature, unused here).
 * @return WP_REST_Response
 */
function init_plugin_suite_chat_engine_rest_unpin_message( WP_REST_Request $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- $request required by WP_REST_Server callback signature.
	$result = init_plugin_suite_chat_engine_unpin_message();

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return rest_ensure_response(
		array(
			'success'        => true,
			'pinned_message' => null,
		)
	);
}
