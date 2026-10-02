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
			) + init_plugin_suite_chat_engine_room_rest_args(),
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
			) + init_plugin_suite_chat_engine_room_rest_args(),
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
			'args'                => init_plugin_suite_chat_engine_room_rest_args(),
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
			'args'                => init_plugin_suite_chat_engine_room_rest_args(),
		)
	);
}

/**
 * Permission check for messages endpoint
 *
 * @param WP_REST_Request $request Request object (required by REST API callback signature, unused here).
 * @return bool
 */
function init_plugin_suite_chat_engine_messages_permission_check( $request ) {
	// Phòng chat phải có chữ ký hợp lệ (chỉ phòng được tạo từ shortcode).
	$room = init_plugin_suite_chat_engine_get_request_room( $request );
	if ( is_wp_error( $room ) ) {
		return $room;
	}

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

	// Phòng chat phải có chữ ký hợp lệ.
	$room = init_plugin_suite_chat_engine_get_request_room( $request );
	if ( is_wp_error( $room ) ) {
		return $room;
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

	// Phòng đã được kiểm tra chữ ký ở permission_callback.
	$room = init_plugin_suite_chat_engine_get_request_room( $request );
	if ( is_wp_error( $room ) ) {
		return $room;
	}

	// Limit an toàn.
	if ( $limit <= 0 ) {
		$limit = 20;
	} elseif ( $limit > 100 ) {
		$limit = 100;
	}

	// Generation token PHẢI được đọc TRƯỚC mọi query DB bên dưới - xem
	// init_plugin_suite_chat_engine_clear_frontend_message_cache().
	$cache_gen = init_plugin_suite_chat_engine_get_frontend_cache_gen( $room );

    // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	$messages = array();

	// Build query dựa theo tham số.
	if ( $after_id > 0 ) {
		// Polling realtime: đại đa số các lần poll KHÔNG có tin nhắn mới. Cache "ID
		// tin mới nhất của phòng" kèm generation token: client đã có ID này rồi
		// (after_id >= latest) thì trả rỗng ngay, không chạm DB.
		//
		// Fix 1.3.9: bản cũ cache con số này KHÔNG kèm token, nên 1 request poll đọc
		// DB trước khi có tin mới nhưng ghi cache sau khi /send đã xóa cache sẽ ghi
		// đè lại ID cũ -> mọi client đang ở đúng ID đó bị trả rỗng mãi (kẹt ở
		// after_id cũ, phải reload trang). Giờ giá trị ghi trễ mang token cũ nên tự
		// bị loại ở lần đọc kế tiếp.
		$latest_key = init_plugin_suite_chat_engine_room_key( 'frontend_latest_id', $room );
		$latest_id  = init_plugin_suite_chat_engine_get_frontend_cache( $latest_key, $cache_gen );

		if ( null === $latest_id ) {
			// MAX(id) dùng index (room, is_deleted, id) - chỉ là 1 lần dò index.
			$latest_id = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT MAX(id) FROM {$table} WHERE room = %s AND is_deleted = 0",
					$room
				)
			);
			init_plugin_suite_chat_engine_set_frontend_cache( $latest_key, $latest_id, $cache_gen, 10 * MINUTE_IN_SECONDS );
		}

		if ( $after_id < (int) $latest_id ) {
			// Lấy message mới hơn (realtime updates).
			$messages = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, user_id, display_name, message, created_at 
                     FROM {$table} 
                     WHERE room = %s AND id > %d AND is_deleted = 0
                     ORDER BY id ASC 
                     LIMIT %d",
					$room,
					$after_id,
					$limit
				),
				ARRAY_A
			);
		}

		// Lưu ý: KHÔNG query thêm 50 tin gần nhất để "refresh timestamp" ở đây nữa.
		// Hiển thị dạng "x phút trước" được tính trực tiếp ở client (chat.js) dựa vào
		// created_at_iso đã trả sẵn, không cần round-trip lên server.

	} elseif ( $before_id > 0 ) {
		// Phân trang lùi (older messages) - mỗi client dừng cuộn ở vị trí khác nhau
		// nên tỉ lệ cache hit sẽ thấp, cố tình không cache nhánh này.
		$messages = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, user_id, display_name, message, created_at 
                 FROM {$table} 
                 WHERE room = %s AND id < %d AND is_deleted = 0
                 ORDER BY id DESC 
                 LIMIT %d",
				$room,
				$before_id,
				$limit
			),
			ARRAY_A
		);

	} else {
		// Lần đầu tải trang: MỌI client mới vào phòng đều gọi đúng 1 dạng query giống
		// hệt nhau, chỉ khác $limit. Cache chung 50 dòng mới nhất của phòng (mức trần
		// limit cho phép ở REST arg), rồi cắt theo $limit bằng array_slice.
		$latest_messages_key = init_plugin_suite_chat_engine_room_key( 'frontend_latest_messages', $room );
		$cached_latest       = init_plugin_suite_chat_engine_get_frontend_cache( $latest_messages_key, $cache_gen );

		if ( ! is_array( $cached_latest ) ) {
			$cached_latest = (array) $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, user_id, display_name, message, created_at 
                     FROM {$table} 
                     WHERE room = %s AND is_deleted = 0
                     ORDER BY id DESC 
                     LIMIT %d",
					$room,
					50
				),
				ARRAY_A
			);
			// TTL chỉ làm lưới an toàn - tính đúng đắn dựa vào generation token.
			init_plugin_suite_chat_engine_set_frontend_cache( $latest_messages_key, $cached_latest, $cache_gen, 5 * MINUTE_IN_SECONDS );
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

	$response = rest_ensure_response(
		array(
			'success'        => true,
			'messages'       => $messages,
			'count'          => count( $messages ),
			'has_more'       => count( $messages ) === $limit,
			'pinned_message' => init_plugin_suite_chat_engine_get_pinned_message( $room ),
			'room'           => $room,
		)
	);

	return init_plugin_suite_chat_engine_add_nocache_headers( $response );
}

/**
 * Gắn header chống cache cho response REST của chat.
 *
 * Request poll của khách (và cả user đăng nhập, vì JS không gửi nonce khi GET)
 * được WordPress coi là chưa đăng nhập nên KHÔNG tự thêm header no-cache. Khi đó
 * page cache / CDN (LiteSpeed Cache "Cache REST API", Cloudflare, Varnish, Nginx
 * FastCGI cache...) có thể cache nguyên URL ?after_id=X và trả về kết quả rỗng
 * cũ mãi cho mọi client đang ở đúng ID đó.
 *
 * @param WP_REST_Response $response Response object.
 * @return WP_REST_Response
 */
function init_plugin_suite_chat_engine_add_nocache_headers( $response ) {
	foreach ( wp_get_nocache_headers() as $name => $value ) {
		if ( $value ) {
			$response->header( $name, $value );
		}
	}

	// LiteSpeed Cache tôn trọng header riêng này cho cả REST API.
	$response->header( 'X-LiteSpeed-Cache-Control', 'no-cache' );

	return $response;
}

/**
 * POST /send – Send a message
 *
 * @param WP_REST_Request $request Request object.
 * @return WP_REST_Response|WP_Error
 */
function init_plugin_suite_chat_engine_send_message( WP_REST_Request $request ) {
	global $wpdb;

	$room = init_plugin_suite_chat_engine_get_request_room( $request );
	if ( is_wp_error( $room ) ) {
		return $room;
	}

	// Cấu hình hiệu lực của phòng (ghi đè allow_guests / max_messages nếu có).
	$room_config  = init_plugin_suite_chat_engine_get_room_config( $room );
	$allow_guests = $room_config['allow_guests'];

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
			'room'         => $room,
		),
		array(
			'%d',
			'%s',
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
	init_plugin_suite_chat_engine_clear_frontend_message_cache( $room );

	// Danh sách tin ở trang quản trị cũng cần thấy tin mới ngay (không đợi TTL).
	init_plugin_suite_chat_engine_bump_admin_cache();

	do_action( 'init_plugin_suite_chat_engine_message_saved', $message_id, $message, $user_id, $display_name, $room );

	// Update statistics (atomic +1, 1 query mỗi stat thay vì đọc rồi ghi).
	init_plugin_suite_chat_engine_increment_stat( 'total_messages' );
	init_plugin_suite_chat_engine_increment_stat( 'messages_today' );

	// Cleanup if over limit (soft delete) - giới hạn tính riêng cho từng phòng.
	if ( init_plugin_suite_chat_engine_trim_room( $room, $room_config['max_messages'] ) ) {
		// Trim tin cũ nhất làm đổi danh sách hiển thị của phòng -> đổi generation
		// của cache phòng + cache trang quản trị.
		init_plugin_suite_chat_engine_clear_frontend_message_cache( $room );
		init_plugin_suite_chat_engine_bump_admin_cache();
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
			'room'       => $room,
		)
	);
}

/**
 * GET /user-status – Get current user status and chat info
 *
 * @param WP_REST_Request $request Request object (required by REST API callback signature, unused here).
 * @return WP_REST_Response
 */
function init_plugin_suite_chat_engine_get_user_status( WP_REST_Request $request ) {
	$settings     = init_plugin_suite_chat_engine_get_all_settings();
	$room         = init_plugin_suite_chat_engine_get_request_room( $request );
	$room         = is_wp_error( $room ) ? '' : $room;
	$room_config  = init_plugin_suite_chat_engine_get_room_config( $room );
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
		'allow_guests' => $room_config['allow_guests'],
		'room'         => $room,
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

	return init_plugin_suite_chat_engine_add_nocache_headers( rest_ensure_response( $status ) );
}

// REMOVED: Online users function - XÓA LUÔN VÌ VÔ DỤNG!

/**
 * Check if there are any messages
 *
 * @param string $room Room name ('' = default room).
 * @return bool
 */
function init_plugin_suite_chat_engine_has_messages( $room = '' ) {
	global $wpdb;

	$room      = (string) $room;
	$cache_key = init_plugin_suite_chat_engine_room_key( 'has_messages', $room );
	$cache_gen = init_plugin_suite_chat_engine_get_frontend_cache_gen( $room );

	// Try cache first.
	$cached = init_plugin_suite_chat_engine_get_frontend_cache( $cache_key, $cache_gen );
	if ( null !== $cached ) {
		return (bool) $cached;
	}

	// Query DB.
	$exists = $wpdb->get_var(
		$wpdb->prepare(
			"SELECT 1 FROM {$wpdb->prefix}init_chatbox_msgs WHERE room = %s AND is_deleted = 0 LIMIT 1",
			$room
		)
	);

	$result = (bool) $exists;

	// Cache for 1 day (86400 seconds) - tự hết hiệu lực khi phòng có thay đổi.
	init_plugin_suite_chat_engine_set_frontend_cache( $cache_key, $result, $cache_gen, DAY_IN_SECONDS );

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

	global $wpdb;

	// Tin được ghim vào phòng chứa nó -> trả về tin ghim của đúng phòng đó.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$room = (string) $wpdb->get_var(
		$wpdb->prepare( "SELECT room FROM {$wpdb->prefix}init_chatbox_msgs WHERE id = %d", $message_id )
	);

	// Trả về data đầy đủ để JS cập nhật UI ngay, không cần reload.
	$pinned = init_plugin_suite_chat_engine_get_pinned_message( $room );

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
 * Unpin the currently pinned message of a room.
 *
 * @param WP_REST_Request $request Request object.
 * @return WP_REST_Response|WP_Error
 */
function init_plugin_suite_chat_engine_rest_unpin_message( WP_REST_Request $request ) {
	$room = init_plugin_suite_chat_engine_get_request_room( $request );
	if ( is_wp_error( $room ) ) {
		return $room;
	}

	$result = init_plugin_suite_chat_engine_unpin_message( $room );

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
