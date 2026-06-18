<?php
/**
 * Admin REST 權限驗證。
 *
 * 三道 gate（移植自 YS CART YSAdminRestAuth::permission_admin 行為）：
 *   1. Capability：manage_options（不可降低）
 *   2. Nonce：X-WP-Nonce / _wpnonce → wp_verify_nonce(…, 'wp_rest')
 *   3. Request body size cap（避免超大 payload）
 *
 * @package YangSheep\AdminMenu\Rest
 * @since   1.0.0
 */

namespace YangSheep\AdminMenu\Rest;

defined( 'ABSPATH' ) || exit;

final class YSRestAuth {

	/** 請求 body 大小上限（選單設定 JSON 足夠；防止超大 payload）。 */
	private const MAX_BODY_BYTES = 262144; // 256 KB

	/**
	 * Permission callback：cap floor + nonce + body size cap。
	 *
	 * @param \WP_REST_Request $request
	 * @return bool|\WP_Error
	 */
	public static function permission_admin( \WP_REST_Request $request ) {
		// 1. Capability floor — 可用 filter 收緊，但不可低於 manage_options。
		$capability = (string) apply_filters( 'ys_admin_menu_rest_capability', 'manage_options' );
		if ( '' === $capability ) {
			$capability = 'manage_options';
		}
		if ( ! current_user_can( $capability ) ) {
			return new \WP_Error(
				'ys_admin_menu_forbidden',
				__( '權限不足。', 'ys-admin-menu' ),
				[ 'status' => 403 ]
			);
		}

		// 2. Nonce（wp_rest）。
		$nonce = self::extract_nonce( $request );
		if ( '' === $nonce || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new \WP_Error(
				'ys_admin_menu_bad_nonce',
				__( '安全驗證失敗，請重新整理頁面後再試。', 'ys-admin-menu' ),
				[ 'status' => 403 ]
			);
		}

		// 3. Body size cap。
		$body = $request->get_body();
		if ( is_string( $body ) && strlen( $body ) > self::MAX_BODY_BYTES ) {
			return new \WP_Error(
				'ys_admin_menu_payload_too_large',
				__( '請求內容過大。', 'ys-admin-menu' ),
				[ 'status' => 413 ]
			);
		}

		return true;
	}

	private static function extract_nonce( \WP_REST_Request $request ): string {
		$nonce = $request->get_header( 'X-WP-Nonce' );
		if ( ! $nonce ) {
			$nonce = $request->get_header( 'x_wp_nonce' );
		}
		if ( ! $nonce ) {
			$param = $request->get_param( '_wpnonce' );
			$nonce = is_string( $param ) ? $param : '';
		}

		return is_string( $nonce ) ? sanitize_text_field( $nonce ) : '';
	}
}
