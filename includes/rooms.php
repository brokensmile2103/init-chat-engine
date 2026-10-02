<?php
/**
 * Multi-room support: room naming, signed room tokens, per-room config registry.
 *
 * Mỗi shortcode [init_chatbox room="..."] là 1 phòng chat riêng. Phòng mặc định
 * (không truyền room) có room = '' - chính là khung chat chung của các bản trước,
 * nên toàn bộ tin nhắn cũ vẫn nằm nguyên ở phòng mặc định sau khi nâng cấp.
 *
 * @package Init_Chat_Engine
 */

defined( 'ABSPATH' ) || exit;

/**
 * Option lưu cấu hình riêng của từng phòng (ghi đè allow_guests / max_messages).
 */
const INIT_PLUGIN_SUITE_CHAT_ENGINE_ROOMS_OPTION = 'init_chat_rooms';

/**
 * Sanitize a room name: chữ thường, số, "-" và "_", tối đa 64 ký tự.
 *
 * @param mixed $room Raw room name.
 * @return string Sanitized room name ('' = default room).
 */
function init_plugin_suite_chat_engine_sanitize_room( $room ) {
	if ( ! is_scalar( $room ) ) {
		return '';
	}

	$room = preg_replace( '/[^a-z0-9_-]/', '', strtolower( (string) $room ) );

	return substr( $room, 0, 64 );
}

/**
 * Chữ ký (HMAC) của 1 phòng.
 *
 * Chỉ trang có shortcode mới nhận được chữ ký này (in sẵn vào dữ liệu JS), nên
 * không ai có thể gọi thẳng REST API để tự "đẻ" ra phòng ảo rồi spam vào đó.
 * Chữ ký không phụ thuộc người dùng nên vẫn hoạt động với trang được page cache.
 *
 * @param string $room Room name.
 * @return string Token ('' for the default room).
 */
function init_plugin_suite_chat_engine_get_room_token( $room ) {
	$room = init_plugin_suite_chat_engine_sanitize_room( $room );

	if ( '' === $room ) {
		return '';
	}

	return substr( hash_hmac( 'sha256', 'init-chat-engine|room|' . $room, wp_salt( 'auth' ) ), 0, 32 );
}

/**
 * Verify a room token.
 *
 * @param string $room  Sanitized room name.
 * @param mixed  $token Token sent by the client.
 * @return bool
 */
function init_plugin_suite_chat_engine_verify_room_token( $room, $token ) {
	if ( '' === $room ) {
		return true;
	}

	return is_string( $token ) && '' !== $token && hash_equals( init_plugin_suite_chat_engine_get_room_token( $room ), $token );
}

/**
 * Lấy + kiểm tra phòng từ REST request (tham số room + room_token).
 *
 * @param WP_REST_Request $request Request object.
 * @return string|WP_Error Room name, or error when the room/token is invalid.
 */
function init_plugin_suite_chat_engine_get_request_room( $request ) {
	$raw = $request->get_param( 'room' );

	if ( null === $raw || '' === $raw ) {
		return '';
	}

	$room = init_plugin_suite_chat_engine_sanitize_room( $raw );

	if ( ! is_string( $raw ) || $room !== $raw || ! init_plugin_suite_chat_engine_verify_room_token( $room, $request->get_param( 'room_token' ) ) ) {
		return new WP_Error( 'invalid_room', __( 'Invalid chat room.', 'init-chat-engine' ), array( 'status' => 403 ) );
	}

	return $room;
}

/**
 * REST args dùng chung cho tham số room / room_token.
 *
 * @return array
 */
function init_plugin_suite_chat_engine_room_rest_args() {
	return array(
		'room'       => array(
			'type'     => 'string',
			'required' => false,
			'default'  => '',
		),
		'room_token' => array(
			'type'     => 'string',
			'required' => false,
			'default'  => '',
		),
	);
}

/**
 * Get the per-room config registry.
 *
 * @return array room => array( 'allow_guests' => ''|'0'|'1', 'max_messages' => int ).
 */
function init_plugin_suite_chat_engine_get_rooms_registry() {
	$registry = get_option( INIT_PLUGIN_SUITE_CHAT_ENGINE_ROOMS_OPTION, array() );

	return is_array( $registry ) ? $registry : array();
}

/**
 * Lưu cấu hình riêng của 1 phòng (chỉ ghi DB khi cấu hình thực sự thay đổi).
 *
 * @param string $room   Sanitized room name.
 * @param array  $config array( 'allow_guests' => ''|'0'|'1', 'max_messages' => int ).
 * @return void
 */
function init_plugin_suite_chat_engine_register_room( $room, $config ) {
	$entry = array(
		'allow_guests' => in_array( $config['allow_guests'], array( '0', '1' ), true ) ? $config['allow_guests'] : '',
		'max_messages' => max( 0, (int) $config['max_messages'] ),
	);

	$registry = init_plugin_suite_chat_engine_get_rooms_registry();

	if ( isset( $registry[ $room ] ) && $registry[ $room ] === $entry ) {
		return;
	}

	$registry[ $room ] = $entry;
	ksort( $registry );

	// autoload = false: chỉ cần khi gửi tin / render shortcode / cron, không cần nạp ở mọi request.
	update_option( INIT_PLUGIN_SUITE_CHAT_ENGINE_ROOMS_OPTION, $registry, false );
	wp_cache_delete( 'known_rooms', 'init_chat_engine' );
}

/**
 * Cấu hình hiệu lực của 1 phòng: ghi đè riêng của phòng (nếu có), không thì dùng Settings chung.
 *
 * @param string $room Sanitized room name.
 * @return array array( 'room' => string, 'allow_guests' => bool, 'max_messages' => int ).
 */
function init_plugin_suite_chat_engine_get_room_config( $room ) {
	$registry = init_plugin_suite_chat_engine_get_rooms_registry();
	$entry    = isset( $registry[ $room ] ) && is_array( $registry[ $room ] ) ? $registry[ $room ] : array();

	if ( isset( $entry['allow_guests'] ) && in_array( $entry['allow_guests'], array( '0', '1' ), true ) ) {
		$allow_guests = '1' === $entry['allow_guests'];
	} else {
		$allow_guests = ! empty( init_plugin_suite_chat_engine_get_setting( 'allow_guests', 0 ) );
	}

	$max_messages = ! empty( $entry['max_messages'] )
		? (int) $entry['max_messages']
		: (int) init_plugin_suite_chat_engine_get_setting( 'max_messages', 1000 );

	return array(
		'room'         => $room,
		'allow_guests' => $allow_guests,
		'max_messages' => max( 10, min( 10000, $max_messages ) ),
	);
}

/**
 * Danh sách phòng đang có (từ registry + từ dữ liệu tin nhắn), phòng mặc định đứng đầu.
 *
 * @return string[]
 */
function init_plugin_suite_chat_engine_get_known_rooms() {
	global $wpdb;

	$rooms = wp_cache_get( 'known_rooms', 'init_chat_engine' );

	if ( ! is_array( $rooms ) ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rooms = (array) $wpdb->get_col( "SELECT DISTINCT room FROM `{$wpdb->prefix}init_chatbox_msgs`" );
		$rooms = array_merge( $rooms, array_keys( init_plugin_suite_chat_engine_get_rooms_registry() ) );
		$rooms = array_values( array_unique( array_map( 'strval', $rooms ) ) );
		sort( $rooms );

		wp_cache_set( 'known_rooms', $rooms, 'init_chat_engine', 5 * MINUTE_IN_SECONDS );
	}

	if ( ! in_array( '', $rooms, true ) ) {
		array_unshift( $rooms, '' );
	}

	return $rooms;
}

/**
 * Human readable room label.
 *
 * @param string $room Room name.
 * @return string
 */
function init_plugin_suite_chat_engine_room_label( $room ) {
	return '' === $room ? __( 'Default room', 'init-chat-engine' ) : $room;
}

/**
 * Cache / stat key riêng cho từng phòng. Phòng mặc định giữ nguyên key cũ để
 * tương thích dữ liệu (tin ghim, cache) từ các bản trước.
 *
 * @param string $base Base key.
 * @param string $room Room name.
 * @return string
 */
function init_plugin_suite_chat_engine_room_key( $base, $room ) {
	return '' === $room ? $base : $base . '@' . $room;
}

/**
 * Bộ lọc phòng đang chọn ở trang Management.
 *
 * Tham số room_filter: rỗng = tất cả phòng, "(default)" = phòng mặc định, còn lại
 * là tên phòng.
 *
 * @return string|null Room name, or null for "all rooms".
 */
function init_plugin_suite_chat_engine_get_admin_room_filter() {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Chỉ đọc để lọc dữ liệu hiển thị, không thay đổi dữ liệu.
	$raw = isset( $_GET['room_filter'] ) ? sanitize_text_field( wp_unslash( $_GET['room_filter'] ) ) : '';

	if ( '' === $raw ) {
		return null;
	}

	if ( '(default)' === $raw ) {
		return '';
	}

	return init_plugin_suite_chat_engine_sanitize_room( $raw );
}

/**
 * Giá trị query string tương ứng với 1 phòng (dùng cho link / dropdown bộ lọc).
 *
 * @param string|null $room Room name, or null for "all rooms".
 * @return string
 */
function init_plugin_suite_chat_engine_room_filter_value( $room ) {
	if ( null === $room ) {
		return '';
	}

	return '' === $room ? '(default)' : $room;
}

/**
 * In dropdown chọn phòng cho các form lọc ở trang Management.
 *
 * @param string|null $current Phòng đang chọn (null = tất cả).
 * @return void
 */
function init_plugin_suite_chat_engine_render_room_filter_select( $current ) {
	$current_value = init_plugin_suite_chat_engine_room_filter_value( $current );
	?>
	<label for="init-chat-room-filter" class="screen-reader-text"><?php esc_html_e( 'Filter by room', 'init-chat-engine' ); ?></label>
	<select name="room_filter" id="init-chat-room-filter">
		<option value="" <?php selected( $current_value, '' ); ?>><?php esc_html_e( 'All rooms', 'init-chat-engine' ); ?></option>
		<?php foreach ( init_plugin_suite_chat_engine_get_known_rooms() as $room ) : ?>
			<?php $value = init_plugin_suite_chat_engine_room_filter_value( $room ); ?>
			<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $current_value, $value ); ?>><?php echo esc_html( init_plugin_suite_chat_engine_room_label( $room ) ); ?></option>
		<?php endforeach; ?>
	</select>
	<?php
}
