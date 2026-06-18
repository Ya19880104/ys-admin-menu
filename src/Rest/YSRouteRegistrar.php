<?php
/**
 * REST 路由註冊器 — 掛在 rest_api_init。
 *
 * 所有路由都在 ys-admin-menu/v1 namespace 下。
 *
 * @package YangSheep\AdminMenu\Rest
 * @since   1.0.0
 */

namespace YangSheep\AdminMenu\Rest;

defined( 'ABSPATH' ) || exit;

final class YSRouteRegistrar {

	/** REST namespace（本外掛專屬）。 */
	public const NAMESPACE = 'ys-admin-menu/v1';

	public static function register_routes(): void {
		YSPermissionController::register_routes();
	}
}
