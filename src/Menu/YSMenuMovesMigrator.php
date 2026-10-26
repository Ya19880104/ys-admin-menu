<?php
/**
 * YS CART 選單搬家配套（v1.5.0）
 *
 * YS CART V3 起後台選單重新分組：選單的 slug 一個都不改，只把幾個子選單換到新的上層選單，並拆掉
 * 「購物金」這個頂層選單。Core 提供唯讀的 YSAdminNavigationRegistry::moves()（Core 每一版的搬家都
 * 累積在裡面，加上附加外掛經 ys_ec_admin_menu_moves filter 宣告的）與 MOVES_VERSION；Core 不寫本外掛的設定。
 *
 * 本外掛的可見規則（隱藏、角色、僅限本人、白名單）都是「看 slug」，搬家後照樣有效。真正要靠上層選單
 * 才能運作的是下面這幾項，所以只搬這些：
 *   - 子選單的順序、自訂名稱、升頂層：ys_cart.items[] 的 parent_slug。
 *   - 複製到主選單：top_copies.items[] 的 parent_slug（複製項代稱有變才換 wp_native 的鍵）。
 *   - 被拆掉的頂層：頂層那一列改成新上層底下的子選單列，併入同 slug 的子選單列（名稱只取子選單列的）。
 *   - 上層選單隱藏的連帶效果：原本上層被隱藏，底下的子選單在側欄就看不到；搬到新上層後，把子選單
 *     「且」舊上層的規則寫到子選單的每一列（hide 取或、角色取交集、交集是空的改成隱藏；僅限本人：子選單
 *     自己沒有綁定才帶入上層側欄實際生效的那一個，子選單自己有綁定就不動，綁的不是同一個人再加隱藏）。
 *   - 1.4.0 的「僅限本人」會讓綁定的人略過那一頁的角色與白名單。所以搬家後誰綁著這一頁，就檢查他原本
 *     （舊分組）在側欄、直接開網址看不看得到：原本看不到、搬家後卻看得到的，再加隱藏（super admin 不受隱藏
 *     影響，照 1.4.0）。
 *   - 被拆掉的頂層：原本的隱藏要在子選單也生效（apply_hide_flags() 的頂層隱藏只對 $menu）。「全部選單」分頁沒有
 *     它的任何列、只在主選單分頁存過規則（隱藏、角色、僅限本人）時，補一列帶這一頁自己規則的子選單列。
 *   - 指定接在被拆掉頂層後面的複製／移到主選單項目，改接在舊側欄裡它前面的頂層。
 * 使用者覆寫的白名單只看 slug，不改，只在後台提醒管理員。
 *
 * 觸發：admin_menu 優先序 9000（本外掛 9999 套用設定之前），任何後台頁面請求都會檢查——這是資料遷移，
 * 跟目前是誰登入無關。admin-ajax.php、admin-post.php 不會觸發 admin_menu，等第一個完整的後台頁面請求。
 * 標記（Core 對照的版本＋moves 內容的 sha256＋本外掛的規則版本＋已套用的鍵 since:slug）相同就整批跳過；
 * 不同時照清單順序逐列處理，已套用的鍵跳過。寫入時搶鎖（每個請求自己的 token，只放自己的鎖），寫入前把
 * 整份舊設定備份一次（不覆蓋），主設定用「比對舊值再寫入」的一條 UPDATE 寫（YSMenuConfigBridge::
 * save_authoritative_if_unchanged()，寫完讀回確認，再補 Core 那份鏡像與指紋）；沒寫成就不寫標記與紀錄，
 * 交給下一個請求重跑。
 * 沒有 moves() 的舊版 YS CART：什麼都不做、不寫入。
 *
 * @package YangSheep\AdminMenu\Menu
 * @since   1.5.0
 */

namespace YangSheep\AdminMenu\Menu;

use YangSheep\AdminMenu\Access\YSAccess;

defined( 'ABSPATH' ) || exit;

final class YSMenuMovesMigrator {

	/** YS CART 提供搬家對照的類別。 */
	public const REGISTRY_CLASS = 'YangSheep\\Ecommerce\\Admin\\YSAdminNavigationRegistry';

	/**
	 * 遷移標記：{ version, sha256, rules, applied: [ 'since:slug', … ], notice: bool }。值很小，autoload yes（每個後台頁面都要讀，
	 * 放在 autoload 裡就不用另外查一次資料庫）。notice＝有還沒關掉的後台提醒（有才去讀遷移紀錄）。
	 */
	public const MARKER_OPTION = 'ys_admin_menu_moves_applied';

	/**
	 * 本外掛遷移規則的版本，跟 Core 的對照分開記。之後的版本看得懂新的 kind、或修了規則時往上加：Core 對照沒變也會
	 * 重跑一次（已套用的鍵照樣跳過，沒套用的補上）。
	 */
	public const RULES_VERSION = '1';

	/** 遷移紀錄與後台提醒（autoload no）。 */
	public const LOG_OPTION = 'ys_admin_menu_moves_log';

	/** 遷移鎖（值＝「取得的時間:亂數」，每個請求自己的 token）。 */
	public const LOCK_OPTION = 'ys_admin_menu_moves_lock';

	/** 遷移前備份：ys_admin_menu_config_pre_moves_<MOVES_VERSION>，只寫一次、不覆蓋。 */
	public const BACKUP_PREFIX = 'ys_admin_menu_config_pre_moves_';

	/** 鎖的逾時（秒）。 */
	public const LOCK_TIMEOUT = 300;

	/** 認得的搬家種類。 */
	public const KINDS = [ 'sub', 'top_to_sub' ];

	/** 關閉後台提醒的 admin-post 動作。 */
	public const DISMISS_ACTION = 'ys_admin_menu_dismiss_moves_notice';

	/** 紀錄最多留幾輪、每輪最多幾筆（防止異常資料塞爆 option）。 */
	private const MAX_RUNS    = 10;
	private const MAX_ENTRIES = 300;

	private static bool $running = false;

	/** 這個請求拿到的鎖（自己的 token；沒拿到是空字串）。 */
	private static string $lock_token = '';

	/** plan() 裡標記「這一列要拿掉」（不用 null，以免誤刪設定裡原本的值）。 */
	private static ?object $gone = null;

	public static function register(): void {
		add_action( 'admin_menu', [ self::class, 'maybe_migrate' ], 9000 );
		add_action( 'admin_notices', [ self::class, 'maybe_render_notice' ] );
		add_action( 'admin_post_' . self::DISMISS_ACTION, [ self::class, 'handle_dismiss' ] );
	}

	// ─────────────────────────────────────────────
	// 偵測 Core 的搬家對照
	// ─────────────────────────────────────────────

	/**
	 * 讀 Core 的搬家對照。沒有 moves()／MOVES_VERSION、呼叫失敗，或沒有任何可用的搬家時回 null。
	 *
	 * @return array{version:string,hash:string,moves:array<int,array<string,string>>,invalid:array<int,array<string,mixed>>}|null
	 */
	public static function detect(): ?array {
		$class = self::REGISTRY_CLASS;
		if ( ! class_exists( $class ) || ! method_exists( $class, 'moves' ) || ! is_callable( [ $class, 'moves' ] )
			|| ! defined( $class . '::MOVES_VERSION' ) ) {
			return null;
		}

		try {
			$version = constant( $class . '::MOVES_VERSION' );
			$raw     = call_user_func( [ $class, 'moves' ] );
		} catch ( \Throwable $e ) {
			return null;
		}

		if ( ! is_scalar( $version ) || '' === trim( (string) $version ) || ! is_array( $raw ) ) {
			return null;
		}

		[ $moves, $invalid ] = self::normalize_moves( $raw );
		if ( empty( $moves ) && empty( $invalid ) ) {
			return null; // 沒有任何搬家：不寫任何東西。
		}

		return [
			'version' => trim( (string) $version ),
			'hash'    => hash( 'sha256', (string) wp_json_encode( $raw ) ),
			'moves'   => $moves,
			'invalid' => $invalid,
		];
	}

	/**
	 * 把 moves() 的回傳整理成固定欄位（slug、from、to、kind、since）。缺 slug／to、sub 缺 from 的列丟掉並記下；
	 * 不認得的 kind 保留，套用時跳過並記錄。
	 *
	 * @param mixed $raw
	 * @return array{0:array<int,array<string,string>>,1:array<int,array<string,mixed>>}
	 */
	public static function normalize_moves( $raw ): array {
		$moves   = [];
		$invalid = [];
		if ( ! is_array( $raw ) ) {
			return [ $moves, $invalid ];
		}

		foreach ( $raw as $index => $m ) {
			if ( ! is_array( $m ) ) {
				$invalid[] = [ 'index' => $index ];
				continue;
			}
			$move = [];
			foreach ( [ 'slug', 'from', 'to', 'kind', 'since' ] as $field ) {
				$move[ $field ] = isset( $m[ $field ] ) && is_scalar( $m[ $field ] ) ? trim( (string) $m[ $field ] ) : '';
			}
			if ( '' === $move['slug'] || '' === $move['to'] || $move['slug'] === $move['to']
				|| ( 'sub' === $move['kind'] && ( '' === $move['from'] || $move['from'] === $move['to'] ) ) ) {
				$invalid[] = [ 'index' => $index ] + $move;
				continue;
			}
			$moves[] = $move;
		}

		return [ $moves, $invalid ];
	}

	/**
	 * 一筆搬家的唯一鍵（Core 保證 since:slug 不重複；since 是不透明字串，不比大小）。
	 *
	 * @param array<string,string> $move
	 */
	public static function move_key( array $move ): string {
		return $move['since'] . ':' . $move['slug'];
	}

	// ─────────────────────────────────────────────
	// 觸發與寫入
	// ─────────────────────────────────────────────

	/**
	 * admin_menu 9000：有需要才遷移。失敗不影響後台（照舊用目前的設定）。
	 */
	public static function maybe_migrate(): void {
		if ( self::$running ) {
			return;
		}
		self::$running = true;
		try {
			self::migrate();
		} catch ( \Throwable $e ) {
			error_log( '[ys-admin-menu] 選單搬家遷移失敗：' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- 失敗時留紀錄，後台照常。
		} finally {
			self::$running = false;
		}
	}

	/**
	 * 跑一次遷移。
	 *
	 * 回傳：no_core（舊版 YS CART／沒有搬家）、current（標記相同，零寫入）、locked（別的請求正在遷移）、
	 * conflict（讀到設定之後、寫入之前設定被改過，這一輪放棄，交給下一個請求）、failed（資料庫寫入失敗或讀回不符，
	 * 不寫標記與紀錄，交給下一個請求重試）、marker（設定不用改，只寫標記與紀錄）、migrated（已寫入設定）。
	 */
	public static function migrate(): string {
		$core = self::detect();
		if ( null === $core ) {
			return 'no_core';
		}

		if ( self::marker_is_current( self::read_marker( get_option( self::MARKER_OPTION, null ) ), $core ) ) {
			return 'current';
		}

		if ( ! self::acquire_lock() ) {
			return 'locked';
		}

		try {
			// 拿到鎖之後從資料庫重讀（不經快取）：別的請求可能剛做完，或設定剛被存過。
			$marker = self::read_marker( self::db_value( self::MARKER_OPTION ) );
			if ( self::marker_is_current( $marker, $core ) ) {
				self::refresh_option_cache();
				return 'current';
			}

			$raw    = self::db_get( YSMenuRouter::OPTION_KEY );
			$config = null === $raw ? [] : self::decode_config( maybe_unserialize( $raw ) );
			$plan   = self::plan( $config, $core['moves'], $marker['applied'] );

			if ( $plan['changed'] && null !== $raw ) {
				self::write_backup_once( $core['version'], $raw );

				// 比對舊值與寫入是同一條 UPDATE：讀到設定之後有人剛好按了儲存，就對不到（conflict），交給下一個請求重跑；
				// 資料庫寫入失敗或讀回不符（failed）也一樣。這兩種都不寫標記與紀錄。
				$saved = YSMenuConfigBridge::save_authoritative_if_unchanged( $plan['config'], $raw );
				self::refresh_option_cache();
				if ( 'saved' !== $saved['result'] ) {
					return $saved['result'];
				}
				if ( ! $saved['mirror'] ) {
					$plan['log'][] = [ 'type' => 'mirror_failed' ];
				}
			}

			$pending = self::save_log( $core, $plan );
			self::save_marker( $core, $marker, $plan, $pending );
			return $plan['changed'] ? 'migrated' : 'marker';
		} finally {
			self::release_lock();
		}
	}

	/**
	 * @param array{version:string,sha256:string,rules:string,applied:array<int,string>} $marker
	 * @param array<string,mixed>                                                        $core
	 */
	public static function marker_is_current( array $marker, array $core ): bool {
		return '' !== $marker['version'] && $marker['version'] === $core['version'] && $marker['sha256'] === $core['hash']
			&& self::RULES_VERSION === $marker['rules'];
	}

	/**
	 * @param mixed $raw
	 * @return array{version:string,sha256:string,rules:string,applied:array<int,string>}
	 */
	public static function read_marker( $raw ): array {
		$raw = is_array( $raw ) ? $raw : [];
		return [
			'version' => is_scalar( $raw['version'] ?? null ) ? (string) $raw['version'] : '',
			'sha256'  => is_scalar( $raw['sha256'] ?? null ) ? (string) $raw['sha256'] : '',
			'rules'   => is_scalar( $raw['rules'] ?? null ) ? (string) $raw['rules'] : '',
			'applied' => array_values( array_filter( (array) ( $raw['applied'] ?? [] ), 'is_string' ) ),
		];
	}

	/**
	 * @param array<string,mixed> $core
	 * @param array<string,mixed> $marker
	 * @param array<string,mixed> $plan
	 * @param bool                $notice 遷移紀錄裡有還沒關掉的提醒（save_log() 的結果）。
	 */
	private static function save_marker( array $core, array $marker, array $plan, bool $notice ): void {
		update_option(
			self::MARKER_OPTION,
			[
				'version' => $core['version'],
				'sha256'  => $core['hash'],
				'rules'   => self::RULES_VERSION,
				'applied' => array_values( array_unique( array_merge( $marker['applied'], $plan['applied'] ) ) ),
				'notice'  => $notice,
			],
			true
		);
	}

	/**
	 * 遷移標記上的 notice（有還沒關掉的後台提醒）。遷移以外的地方（關掉提醒、啟用時加提醒、提醒其實沒內容）用：
	 * 比對原值再寫，遷移（拿著鎖）剛好同時寫標記時不會被這裡蓋掉，對不到就先放著，下次顯示提醒時再對齊。
	 * 還沒有標記（還沒遷移過）又要提醒時，建一個只有 notice 的標記（版本空白＝還沒遷移；遷移時整個寫過）；用「沒有才新增」，
	 * 同時有遷移剛寫好標記也不會蓋掉。
	 */
	private static function set_notice_flag( bool $on ): void {
		global $wpdb;
		$raw = self::db_get( self::MARKER_OPTION );
		if ( null === $raw ) {
			if ( $on ) {
				self::db_insert_ignore( self::MARKER_OPTION, (string) maybe_serialize( [ 'notice' => true ] ), 'yes' );
			}
			return;
		}
		$marker = maybe_unserialize( $raw );
		$marker = is_array( $marker ) ? $marker : [];
		if ( ! empty( $marker['notice'] ) === $on ) {
			return;
		}
		$marker['notice'] = $on;
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND BINARY option_value = %s", maybe_serialize( $marker ), self::MARKER_OPTION, $raw ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- 比對舊值再寫，不蓋掉同時進行的遷移。
		self::refresh_option_cache();
	}

	/**
	 * 這個請求載入的自動載入 option 裡，遷移標記有沒有 notice（不另外查資料庫：沒有標記的站不在 alloptions 裡，直接是 false）。
	 */
	private static function notice_flag(): bool {
		$all = wp_load_alloptions();
		$raw = $all[ self::MARKER_OPTION ] ?? null;
		if ( null === $raw ) {
			return false;
		}
		$marker = maybe_unserialize( $raw );
		return is_array( $marker ) && ! empty( $marker['notice'] );
	}

	/**
	 * 「選單與權限」設定頁開頁當下的遷移標記（頁面上的隱藏欄位，存檔時一起送回）。存檔時跟目前的標記比，不同就是這一頁
	 * 在遷移之前打開的：照舊分組畫的表格存回去，會把搬好的設定寫回舊分組，所以不存。沒有標記（沒有 Core moves 的站）
	 * 回空字串，兩邊都是空字串就照常存。
	 *
	 * @param bool $fresh true＝先清掉這個請求的 option 快取再讀（存檔時比對用：這個請求開始之後才完成的遷移也看得到）。
	 */
	public static function page_token( bool $fresh = false ): string {
		if ( $fresh ) {
			wp_cache_delete( self::MARKER_OPTION, 'options' );
			wp_cache_delete( 'notoptions', 'options' );
			wp_cache_delete( 'alloptions', 'options' );
		}
		$raw = get_option( self::MARKER_OPTION, null );
		$m   = self::read_marker( $raw );
		if ( ! is_array( $raw ) || '' === $m['version'] ) {
			return ''; // 還沒遷移過（只有 notice 的標記也算）
		}
		return hash( 'sha256', implode( "\n", [ $m['version'], $m['sha256'], $m['rules'], implode( ',', $m['applied'] ) ] ) );
	}

	/**
	 * 遷移紀錄（最近幾輪）與後台提醒的內容。提醒有新內容時重新顯示，管理員關掉之前一直在。
	 *
	 * @param array<string,mixed> $core
	 * @param array<string,mixed> $plan
	 * @return bool 存完之後有沒有還沒關掉的提醒（寫進遷移標記的 notice）。
	 */
	private static function save_log( array $core, array $plan ): bool {
		$log     = self::read_log( self::db_value( self::LOG_OPTION ) );
		$entries = $plan['log'];
		foreach ( $core['invalid'] as $bad ) {
			$entries[] = [ 'type' => 'invalid' ] + $bad;
		}

		$runs   = $log['runs'];
		$runs[] = [
			'at'      => time(),
			'version' => $core['version'],
			'sha256'  => $core['hash'],
			'plugin'  => defined( 'YS_ADMIN_MENU_VERSION' ) ? YS_ADMIN_MENU_VERSION : '',
			'changed' => (bool) $plan['changed'],
			'applied' => $plan['applied'],
			'entries' => array_slice( $entries, 0, self::MAX_ENTRIES ),
		];

		$notice    = self::merge_notice( $log['notice'], $plan['notice'] );
		$fresh     = $notice !== $log['notice'];
		$notice    = self::with_titles( $notice );
		$dismissed = $fresh ? false : $log['dismissed'];

		update_option(
			self::LOG_OPTION,
			[
				'runs'      => array_slice( $runs, -self::MAX_RUNS ),
				'notice'    => $notice,
				'dismissed' => $dismissed,
				'events'    => $log['events'],
			],
			false
		);
		return ! $dismissed && self::notice_has_content( $notice );
	}

	/**
	 * 提醒裡有沒有要給管理員看的內容（WP-CLI 的 status 也用）。
	 *
	 * @param array<string,mixed> $notice
	 */
	public static function notice_has_content( array $notice ): bool {
		foreach ( [ 'moved', 'whitelist', 'parent_hidden', 'conflicts', 'bound', 'gated', 'group_titles', 'unknown', 'anchors', 'v2_rules', 'core_backup' ] as $k ) {
			if ( ! empty( $notice[ $k ] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * 啟用本外掛時（YSMenuConfigBridge::synchronize( true )）做了什麼，記進遷移紀錄的 events（最近幾筆），並放進後台提醒：
	 *   - core_backup：YS CART V3 已經自己搬過一次選單設定，改用它的搬家前備份交給本外掛遷移（備份的 option、Core 那份另存的
	 *     option、備份是不是就是上次鏡像）；提醒 core_backup。
	 *   - core_copy_v2：照 1.4.0 沿用 YS CART 那份，裡面有本外掛看不懂的 v2 規則；提醒 v2_rules（列出選單）。
	 *
	 * @param array<string,mixed> $data
	 * @param array<string,mixed> $notice 要併進後台提醒的內容（empty_notice() 的鍵）。
	 */
	public static function record_activation( string $type, array $data, array $notice = [] ): void {
		$log      = self::read_log( self::db_value( self::LOG_OPTION ) );
		$events   = $log['events'];
		$events[] = [ 'at' => time(), 'type' => $type ] + $data;
		$notice    = self::merge_notice( $log['notice'], $notice );
		$fresh     = $notice !== $log['notice'];
		$dismissed = $fresh ? false : $log['dismissed'];

		update_option(
			self::LOG_OPTION,
			[
				'runs'      => $log['runs'],
				'notice'    => $notice,
				'dismissed' => $dismissed,
				'events'    => array_slice( $events, -self::MAX_RUNS ),
			],
			false
		);
		self::set_notice_flag( ! $dismissed && self::notice_has_content( $notice ) );
	}

	/**
	 * @param mixed $raw
	 * @return array{runs:array<int,array<string,mixed>>,notice:array<string,mixed>,dismissed:bool,events:array<int,array<string,mixed>>}
	 */
	public static function read_log( $raw ): array {
		$raw = is_array( $raw ) ? $raw : [];
		return [
			'runs'      => array_values( array_filter( (array) ( $raw['runs'] ?? [] ), 'is_array' ) ),
			'notice'    => self::merge_notice( self::empty_notice(), is_array( $raw['notice'] ?? null ) ? $raw['notice'] : [] ),
			'dismissed' => ! empty( $raw['dismissed'] ),
			'events'    => array_values( array_filter( (array) ( $raw['events'] ?? [] ), 'is_array' ) ),
		];
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function empty_notice(): array {
		return [
			'moved'         => [],
			'rules'         => false,
			'whitelist'     => [],
			'parent_hidden' => [],
			'conflicts'     => [],
			'bound'         => [],
			'gated'         => [],
			'group_titles'  => [],
			'unknown'       => [],
			'anchors'       => [],
			'v2_rules'      => [],
			'core_backup'   => false,
			'titles'        => [],
		];
	}

	/**
	 * 合併提醒內容（每一輪的結果累加；同樣的項目不重複）。
	 *
	 * @param array<string,mixed> $a
	 * @param array<string,mixed> $b
	 * @return array<string,mixed>
	 */
	private static function merge_notice( array $a, array $b ): array {
		$out = self::empty_notice();

		foreach ( [ 'moved', 'conflicts', 'v2_rules' ] as $k ) {
			$list = array_merge( (array) ( $a[ $k ] ?? [] ), (array) ( $b[ $k ] ?? [] ) );
			$out[ $k ] = array_values( array_unique( array_filter( $list, static fn( $v ) => is_string( $v ) && '' !== $v ) ) );
		}

		$ids = array_merge( (array) ( $a['whitelist'] ?? [] ), (array) ( $b['whitelist'] ?? [] ) );
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ), static fn( $v ) => $v > 0 ) ) );
		sort( $ids );
		$out['whitelist'] = $ids;

		$out['rules']       = ! empty( $a['rules'] ) || ! empty( $b['rules'] );
		$out['core_backup'] = ! empty( $a['core_backup'] ) || ! empty( $b['core_backup'] );

		foreach ( [ 'parent_hidden', 'bound', 'gated', 'group_titles', 'unknown', 'anchors' ] as $k ) {
			$seen = [];
			foreach ( array_merge( (array) ( $a[ $k ] ?? [] ), (array) ( $b[ $k ] ?? [] ) ) as $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}
				$id = serialize( $item );
				if ( ! isset( $seen[ $id ] ) ) {
					$seen[ $id ]  = true;
					$out[ $k ][] = $item;
				}
			}
		}

		foreach ( [ (array) ( $a['titles'] ?? [] ), (array) ( $b['titles'] ?? [] ) ] as $titles ) {
			foreach ( $titles as $slug => $title ) {
				if ( is_string( $slug ) && is_string( $title ) && '' !== $title ) {
					$out['titles'][ $slug ] = $title;
				}
			}
		}

		return $out;
	}

	/**
	 * 遷移當下記下提醒裡選單的名稱（側欄現在的名稱），之後找不到時用。
	 *
	 * @param array<string,mixed> $notice
	 * @return array<string,mixed>
	 */
	private static function with_titles( array $notice ): array {
		$pages = array_merge( $notice['moved'], $notice['conflicts'], $notice['v2_rules'] );
		$tops  = [];
		foreach ( [ 'parent_hidden', 'bound', 'gated', 'unknown', 'anchors' ] as $k ) {
			foreach ( $notice[ $k ] as $item ) {
				if ( isset( $item['slug'] ) && is_string( $item['slug'] ) ) {
					$pages[] = $item['slug'];
				}
				if ( isset( $item['to'] ) && is_string( $item['to'] ) ) {
					$tops[] = $item['to'];
				}
			}
		}
		foreach ( [ 'page' => array_unique( $pages ), 'top' => array_unique( $tops ) ] as $kind => $slugs ) {
			foreach ( $slugs as $slug ) {
				$key = 'top' === $kind ? 'top:' . $slug : $slug;
				if ( ! isset( $notice['titles'][ $key ] ) ) {
					$title = self::live_menu_title( $slug, 'top' === $kind );
					if ( '' !== $title ) {
						$notice['titles'][ $key ] = $title;
					}
				}
			}
		}
		return $notice;
	}

	/**
	 * @param mixed $raw
	 * @return array<string,mixed>
	 */
	private static function decode_config( $raw ): array {
		if ( is_string( $raw ) ) {
			$decoded = json_decode( $raw, true );
			$raw     = is_array( $decoded ) ? $decoded : [];
		}
		return is_array( $raw ) ? $raw : [];
	}

	// ─── 資料庫：鎖、備份、讀原值（都在目前站台的 options 表） ───

	/**
	 * 搶鎖。add_option() 遇到同名資料列會改成更新（ON DUPLICATE KEY UPDATE），兩個請求同時搶會都「成功」，
	 * 所以和 WordPress 核心的 WP_Upgrader::create_lock() 一樣用 INSERT IGNORE；超過逾時的舊鎖以比對舊值接手。
	 * 鎖的值是這個請求自己的 token（取得的時間:亂數），釋放時只刪自己的值。
	 */
	private static function acquire_lock(): bool {
		global $wpdb;
		$now   = time();
		$token = $now . ':' . self::random_hex();
		if ( self::db_insert_ignore( self::LOCK_OPTION, $token ) ) {
			self::$lock_token = $token;
			return true;
		}
		$held = self::db_get( self::LOCK_OPTION );
		if ( null === $held || self::lock_time( $held ) > $now - self::LOCK_TIMEOUT ) {
			return false;
		}
		$result = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND BINARY option_value = %s", $token, self::LOCK_OPTION, $held ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- 比對舊值接手逾時的鎖。
		self::forget_lock_cache();
		if ( is_numeric( $result ) && 1 === (int) $result ) {
			self::$lock_token = $token;
			return true;
		}
		return false;
	}

	/**
	 * 只刪自己寫進去的鎖：這個請求卡太久、鎖已經被別的請求接手時，不能刪掉對方的鎖。
	 */
	private static function release_lock(): void {
		if ( '' === self::$lock_token ) {
			return;
		}
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND BINARY option_value = %s", self::LOCK_OPTION, self::$lock_token ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- 只刪自己的 token。
		self::$lock_token = '';
		self::forget_lock_cache();
	}

	/**
	 * 鎖的值裡的取得時間（只存時間的舊值也讀得懂）。
	 */
	public static function lock_time( string $value ): int {
		return (int) explode( ':', $value, 2 )[0];
	}

	private static function random_hex(): string {
		try {
			return bin2hex( random_bytes( 16 ) );
		} catch ( \Throwable $e ) {
			return md5( uniqid( (string) wp_rand(), true ) );
		}
	}

	private static function forget_lock_cache(): void {
		wp_cache_delete( self::LOCK_OPTION, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
	}

	/**
	 * 遷移前備份（原樣保存資料庫裡的值）。同一個 MOVES_VERSION 只寫一次，已經有就不覆蓋。
	 */
	private static function write_backup_once( string $version, string $raw ): void {
		self::db_insert_ignore( self::backup_option_name( $version ), $raw );
	}

	public static function backup_option_name( string $version ): string {
		$key = strtolower( (string) preg_replace( '/[^A-Za-z0-9_\-]/', '', $version ) );
		if ( '' === $key ) {
			$key = substr( hash( 'sha256', $version ), 0, 12 );
		}
		return self::BACKUP_PREFIX . substr( $key, 0, 64 );
	}

	private static function db_get( string $name ): ?string {
		global $wpdb;
		$value = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- 要繞過快取讀資料庫裡的原值。
		return null === $value ? null : (string) $value;
	}

	/**
	 * @return mixed 資料庫裡的值（反序列化後）；沒有回 null。
	 */
	private static function db_value( string $name ) {
		$raw = self::db_get( $name );
		return null === $raw ? null : maybe_unserialize( $raw );
	}

	private static function db_insert_ignore( string $name, string $value, string $autoload = 'no' ): bool {
		global $wpdb;
		$result = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, %s)", $name, $value, $autoload ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- 原子的「沒有才新增」。
		wp_cache_delete( $name, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		if ( 'yes' === $autoload ) {
			wp_cache_delete( 'alloptions', 'options' );
		}
		return is_numeric( $result ) && (int) $result > 0;
	}

	/**
	 * 讓這個請求（與物件快取）重新讀到資料庫裡的設定與標記。
	 */
	private static function refresh_option_cache(): void {
		foreach ( [ YSMenuRouter::OPTION_KEY, YSMenuConfigBridge::CORE_OPTION_KEY, self::MARKER_OPTION, self::LOG_OPTION, self::LOCK_OPTION ] as $key ) {
			wp_cache_delete( $key, 'options' );
		}
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );
	}

	// ─────────────────────────────────────────────
	// 遷移計畫（不寫資料庫：只看設定與搬家對照；判斷綁定的帳號原本看不看得到時會讀帳號的角色）
	// ─────────────────────────────────────────────

	/**
	 * 算出遷移後的設定。
	 *
	 * @param array<string,mixed>             $config  ys_admin_menu_config（這一輪開始時的快照）。
	 * @param array<int,array<string,string>> $moves   normalize_moves() 的結果（依序套用）。
	 * @param array<int,string>               $applied 標記裡已套用的鍵（since:slug）。
	 * @return array{config:array<string,mixed>,changed:bool,applied:array<int,string>,log:array<int,array<string,mixed>>,rows:array<int,array<string,mixed>>,notice:array<string,mixed>}
	 */
	public static function plan( array $config, array $moves, array $applied = [] ): array {
		if ( null === self::$gone ) {
			self::$gone = new \stdClass();
		}
		$gone     = self::$gone;
		$snapshot = $config; // 規則一律從快照讀，寫在工作副本上。

		$items  = self::list_section( $config, 'ys_cart' );
		$native = self::list_section( $config, 'wp_native' );
		$copies = self::list_section( $config, 'top_copies' );
		$orig   = [ $items, $native, $copies ];

		$applied_set = array_fill_keys( $applied, true );
		$old_layout  = self::old_layout_keys( $snapshot, $moves, $applied_set );

		$removed = []; // 被拆掉的頂層 slug（含之前套用過的）
		foreach ( $moves as $m ) {
			if ( 'top_to_sub' === $m['kind'] ) {
				$removed[ $m['slug'] ] = true;
			}
		}

		$log          = [];
		$notice       = self::empty_notice();
		$new_keys     = [];
		$handled      = []; // 這一輪已處理過的 slug（規則只合併一次）
		$placement    = []; // 新上層 => [ [ idx, old, seq ], … ]
		$renames      = []; // 舊代稱 => 新代稱（wp_native 的複製項／移到主選單列）
		$anchor_tops  = []; // 這一輪拆掉的頂層（after 錨點要改）
		$copies_moved = false;
		$inherited    = []; // slug => 從舊上層帶入的「僅限本人」帳號
		$gates        = []; // slug => 搬家資訊；最後檢查「綁著這一頁的人原本看不看得到」

		foreach ( $moves as $seq => $move ) {
			$kind = $move['kind'];
			if ( ! in_array( $kind, self::KINDS, true ) ) {
				$log[]               = [ 'type' => 'unknown_kind', 'slug' => $move['slug'], 'kind' => $kind, 'since' => $move['since'] ];
				$notice['unknown'][] = [ 'slug' => $move['slug'], 'kind' => $kind, 'since' => $move['since'] ];
				continue;
			}
			$key = self::move_key( $move );
			if ( isset( $applied_set[ $key ] ) ) {
				continue; // 已套用過的鍵不再套用（搬回原位之後重跑也不會再搬一次）。
			}
			$applied_set[ $key ] = true;
			$new_keys[]          = $key;

			$slug             = $move['slug'];
			$to               = $move['to'];
			if ( isset( $gates[ $slug ] ) ) {
				// 同一頁這一輪又搬一次：閘門看最後的上層（from 保留第一次的舊上層）。
				$gates[ $slug ]['to'] = $to;
			}
			$first            = ! isset( $handled[ $slug ] );
			$handled[ $slug ] = true;
			$old              = ! empty( $old_layout[ $key ] );
			$n_rules          = self::effective_rules( $snapshot, $to );

			if ( 'top_to_sub' === $kind ) {
				$from = $slug; // 「父選單預設頁」的複製項（slug＝上層自己）跟著搬。
				$r    = self::convert_top( $items, $slug, $to, $seq, $gone );
				$log  = array_merge( $log, $r['log'] );
				if ( $r['touched'] ) {
					$notice['moved'][] = $slug;
					if ( null !== $r['place'] ) {
						$placement[ $to ][] = $r['place'];
					}
					if ( $r['conflict'] ) {
						$notice['conflicts'][] = $slug;
					}
					if ( null !== $r['group_title'] ) {
						$notice['group_titles'][] = [ 'slug' => $slug, 'title' => $r['group_title'] ];
					}
					// 這一頁原本設了隱藏（例如只在主選單分頁勾了隱藏）：apply_hide_flags() 的頂層隱藏只對 $menu，
					// 變成子選單之後要有一列子選單列帶著隱藏才對得到。
					if ( self::effective_rules( $snapshot, $slug )['hide'] && self::ensure_hidden_sub( $items, $slug, $to, $gone ) ) {
						$notice['rules'] = true;
						$log[]           = [ 'type' => 'hide_sub_row', 'slug' => $slug, 'to' => $to ];
					}
				} elseif ( $old && $first && ! self::slug_has_rows( $items, [], $slug, $gone ) ) {
					// 「全部選單」分頁沒有這一頁的任何列、只在主選單分頁存過舊頂層列：那一列的規則（隱藏、角色、僅限本人）照 slug
					// 仍然有效，但隱藏只拿掉頂層，那一列也不在新分組的列表裡。補一列子選單列，帶這一頁自己的規則（不是舊上層的），
					// 不寫順序；主選單分頁那一列照舊保留。綁定取側欄實際生效的那一個（後面的列蓋掉前面）；綁了不同帳號就再加隱藏
					// （跟頂層列併成子選單列時一樣）。
					$own = self::effective_rules( $snapshot, $slug );
					if ( self::rules_restrict( $own ) ) {
						$split   = count( $own['uids'] ) > 1;
						[ $row ] = self::apply_merged(
							self::rules_only_row( $slug, $to, false ),
							[
								'hide'     => $own['hide'] || $split || ( is_array( $own['roles'] ) && [] === $own['roles'] ),
								'roles'    => $own['roles'],
								'inherit'  => $own['last'],
								'conflict' => $split,
							]
						);
						$items[]           = $row;
						$notice['moved'][] = $slug;
						$notice['rules']   = true;
						$log[]             = [ 'type' => 'top_rules_row', 'slug' => $slug, 'to' => $to ];
						if ( $split ) {
							$notice['conflicts'][] = $slug;
						}
					}
				}
				$anchor_tops[ $slug ] = true;
				$in_old               = $r['touched'] || $old; // 這個站在這筆搬家上確實是舊分組
				if ( $in_old ) {
					// 新分組下存的設定（YS CART 已經是 V3 時存的）側欄本來就是新分組，搬家不改變誰看得到，不用檢查。
					$gates[ $slug ] = [ 'from' => '', 'to' => $to, 'sub' => false ];
				}
				if ( $in_old && self::newly_restricted( $n_rules, self::no_rules() ) ) {
					$notice['parent_hidden'][] = [ 'slug' => $slug, 'to' => $to ];
				}
			} else {
				$from      = $move['from'];
				$p_rules   = self::effective_rules( $snapshot, $from );
				$from_rows = self::find_rows( $items, $slug, $from );
				// 搬之前新上層底下就有的同一頁的列（例如搭配 1.4.0 時在新選單存過一次）。在搬這一筆的列之前先找好：
				// 這一筆搬過去的列不算進來（同一頁兩列各自原樣搬過去，不互相合併）。
				$pre_to = self::find_rows( $items, $slug, $to );

				if ( ! empty( $from_rows ) ) {
					// 有舊位置的列（直接證據）：換上層、重給順序，名稱、升頂層等其他欄位原樣帶過去。
					$promoted = false;
					foreach ( $from_rows as $i ) {
						$row                = $items[ $i ];
						$prev_order         = isset( $row['order'] ) && is_numeric( $row['order'] ) ? (int) $row['order'] : null;
						$row['parent_slug'] = $to;
						$log[]              = [ 'type' => 'move', 'slug' => $slug, 'from' => $from, 'to' => $to ];

						if ( ! empty( $row['promote_to_top'] ) ) {
							$promoted = true;
							$old_key  = YSMenuRouter::promoted_menu_url( $slug, $from );
							$new_key  = YSMenuRouter::promoted_menu_url( $slug, $to );
							if ( $old_key !== $new_key ) {
								$renames[ $old_key ] = $new_key;
							}
						}

						if ( ! empty( $pre_to ) ) {
							// 併進新上層那一列（名稱、順序以它為準）。這一輪才搬過來的列不互相合併：同一頁兩列各自的綁定原樣保留。
							$t           = $pre_to[0];
							$items[ $t ] = self::merge_rows( $items[ $t ], $row, $i < $t );
							$items[ $i ] = $gone;
							$log[]       = [ 'type' => 'merge', 'slug' => $slug, 'to' => $to ];
						} else {
							$items[ $i ] = $row;
							if ( empty( $row['promote_to_top'] ) ) {
								$placement[ $to ][] = [ 'idx' => $i, 'old' => $prev_order, 'seq' => $seq ];
							}
						}
					}
					$notice['moved'][] = $slug;

					// 升頂層的項目原本就不在上層底下顯示，沒有「跟著上層一起隱藏」的效果，不必合併規則。
					if ( $first && ! $promoted ) {
						$s_rules                     = self::effective_rules( $snapshot, $slug );
						$merged                      = self::combine_rules( $s_rules, $p_rules );
						[ $items, $native, $fields ] = self::write_rules_to_slug( $items, $native, $slug, $merged, $gone );
						if ( ! empty( $fields ) ) {
							$notice['rules'] = true;
							$log[]           = [ 'type' => 'rules', 'slug' => $slug, 'from' => $from, 'fields' => $fields ];
						}
						if ( $merged['conflict'] ) {
							$notice['conflicts'][] = $slug;
							$log[]                 = [ 'type' => 'self_only_conflict', 'slug' => $slug, 'own_uids' => $s_rules['uids'], 'parent_uid' => $p_rules['last'] ];
						}
						if ( $merged['inherit'] > 0 ) {
							$inherited[ $slug ] = $merged['inherit'];
							$log[]              = [ 'type' => 'bind', 'slug' => $slug, 'from' => $from, 'user_id' => $merged['inherit'] ];
						}
						$gates[ $slug ] = [ 'from' => $from, 'to' => $to, 'sub' => true ];
					}
				} elseif ( $old && $first && self::rules_restrict( $p_rules ) && ! self::slug_has_rows( $items, $native, $slug, $gone ) ) {
					// 子選單沒有任何已存的列，舊上層有規則，而且這一筆是舊分組時存下的：新增一列只帶規則、不寫順序。
					$merged  = self::combine_rules( self::no_rules(), $p_rules );
					[ $row ] = self::apply_merged( self::rules_only_row( $slug, $to, false ), $merged );
					$items[]           = $row;
					$notice['moved'][] = $slug;
					$notice['rules']   = true;
					$log[]             = [ 'type' => 'rules_row', 'slug' => $slug, 'from' => $from, 'to' => $to ];
					if ( $merged['inherit'] > 0 ) {
						$inherited[ $slug ] = $merged['inherit'];
						$log[]              = [ 'type' => 'bind', 'slug' => $slug, 'from' => $from, 'user_id' => $merged['inherit'] ];
					}
					$gates[ $slug ] = [ 'from' => $from, 'to' => $to, 'sub' => true ];
				}

				$in_old = ! empty( $from_rows ) || $old;
				if ( $in_old && self::newly_restricted( $n_rules, $p_rules ) ) {
					$notice['parent_hidden'][] = [ 'slug' => $slug, 'to' => $to ];
				}
			}

			// 複製到主選單：來源的上層換成新上層；代稱有變時，主選單分頁的那一列換鍵。
			foreach ( $copies as $k => $copy ) {
				if ( ! is_array( $copy ) || (string) ( $copy['slug'] ?? '' ) !== $slug || (string) ( $copy['parent_slug'] ?? '' ) !== $from ) {
					continue;
				}
				$copies[ $k ]['parent_slug'] = $to;
				$copies_moved                = true;
				$in_old                      = true;
				$notice['moved'][]           = $slug;
				$log[]                       = [ 'type' => 'copy', 'slug' => $slug, 'from' => $from, 'to' => $to ];
				$old_key                     = YSMenuRouter::copy_menu_key( $slug, $from );
				$new_key                     = YSMenuRouter::copy_menu_key( $slug, $to );
				if ( '' !== $old_key && '' !== $new_key && $old_key !== $new_key ) {
					$renames[ $old_key ] = $new_key;
				}
			}

			// 白名單：只提醒、不改（只看這個站在這筆搬家上確實是舊分組的情況）。
			if ( $in_old ) {
				foreach ( self::whitelist_mismatches( $snapshot, $move ) as $uid ) {
					$notice['whitelist'][] = $uid;
					$log[]                 = [ 'type' => 'whitelist', 'user_id' => $uid, 'slug' => $slug ];
				}
			}
		}

		// 同一個子選單只能有一個複製項（代稱相同＝同一個）：搬家後撞在一起的留第一筆。
		if ( $copies_moved ) {
			$seen = [];
			foreach ( $copies as $k => $copy ) {
				if ( ! is_array( $copy ) ) {
					continue;
				}
				$ck = YSMenuRouter::copy_menu_key( (string) ( $copy['slug'] ?? '' ), (string) ( $copy['parent_slug'] ?? '' ) );
				if ( '' === $ck ) {
					continue;
				}
				if ( isset( $seen[ $ck ] ) ) {
					unset( $copies[ $k ] );
					$log[] = [ 'type' => 'copy_duplicate', 'slug' => (string) ( $copy['slug'] ?? '' ) ];
					continue;
				}
				$seen[ $ck ] = true;
			}
			$copies = array_values( $copies );
		}

		// 主選單分頁：代稱換鍵；接在這一輪拆掉的頂層後面的項目改接在它前面的頂層。
		$native = self::rename_native_keys( $native, $renames, $log );
		if ( ! empty( $anchor_tops ) ) {
			$native = self::repoint_anchors( $native, $snapshot, $anchor_tops, $removed, $log, $notice );
		}

		// 順序：接在新上層最後一個已存子選單之後、下一列之前；新上層沒有已存子選單時不寫順序。
		$items = self::place_orders( $items, $placement, $gone, $log );
		$items = array_values( array_filter( $items, static fn( $row ) => $row !== $gone ) );

		// 「僅限本人」會讓綁定的人略過那一頁的角色與白名單：搬家後綁著這一頁的人，原本（舊分組）看不到、搬家後卻看得到
		// （側欄或直接開網址）的，再加隱藏。其他人本來就被「僅限本人」擋著，super admin 不受隱藏影響，所以沒有人會少看到。
		$gated = [];
		if ( ! empty( $gates ) ) {
			$planned = self::with_sections( $config, $items, $native, $copies, $orig );
			foreach ( $gates as $slug => $g ) {
				$slug = (string) $slug;
				$uid  = self::binding_needs_hide( $snapshot, $planned, $slug, $g );
				if ( $uid > 0 ) {
					$items   = self::hide_rows_of( $items, $slug, $g['to'] );
					$gated[] = [ 'slug' => $slug, 'user_id' => $uid ];
					$log[]   = [ 'type' => 'gate', 'slug' => $slug, 'user_id' => $uid ];
				}
			}
		}

		$new     = self::with_sections( $config, $items, $native, $copies, $orig );
		$changed = $new !== $config;

		foreach ( [ 'moved', 'conflicts' ] as $k ) {
			$notice[ $k ] = array_values( array_unique( $notice[ $k ] ) );
		}
		$gated_slugs = array_column( $gated, 'slug' );
		foreach ( $inherited as $slug => $uid ) {
			if ( ! in_array( (string) $slug, $gated_slugs, true ) ) {
				$notice['bound'][] = [ 'slug' => (string) $slug, 'user_id' => (int) $uid ];
			}
		}
		$notice['gated'] = $gated;
		if ( ! empty( $gated ) ) {
			$notice['rules'] = true;
		}
		if ( ! $changed ) {
			$notice['moved'] = [];
			$notice['rules'] = false;
			$notice['bound'] = [];
			$notice['gated'] = [];
		}

		return [
			'config'  => $new,
			'changed' => $changed,
			'applied' => $new_keys,
			'log'     => $log,
			'rows'    => $changed ? self::row_diff( $orig[0], $items, $orig[1], $native, $orig[2], $copies ) : [],
			'notice'  => self::merge_notice( self::empty_notice(), $notice ),
		];
	}

	/**
	 * 每一筆還沒套用的搬家（逐鍵判斷）是不是在舊分組時存下的設定：同一個 since 裡還沒套用的那幾筆，沒有任何一列已經
	 * 掛在它的 to 底下（在新選單用「全部選單」分頁存過一次，這幾頁就會各有一列掛在新上層）。已套用過的鍵不算進來
	 * （那是本外掛搬過去的，不是新分組的證據），所以附加外掛之後才沿用同一個 since 加的列也判斷得出來。
	 * ys_cart 一列都沒有、判斷不出來時當成舊分組（結果只會更嚴，提醒會列出來）。
	 *
	 * @param array<string,mixed>             $snapshot
	 * @param array<int,array<string,string>> $moves
	 * @param array<string,bool>              $applied_set
	 * @return array<string,bool> 鍵（since:slug）=> 是不是舊分組
	 */
	private static function old_layout_keys( array $snapshot, array $moves, array $applied_set ): array {
		$items   = self::list_section( $snapshot, 'ys_cart' );
		$no_rows = true;
		foreach ( $items as $row ) {
			if ( is_array( $row ) && empty( $row['separator'] ) && '' !== (string) ( $row['slug'] ?? '' ) ) {
				$no_rows = false;
				break;
			}
		}

		$batches = [];
		foreach ( $moves as $m ) {
			if ( in_array( $m['kind'], self::KINDS, true ) && ! isset( $applied_set[ self::move_key( $m ) ] ) ) {
				$batches[ $m['since'] ][] = $m;
			}
		}

		$out = [];
		foreach ( $batches as $list ) {
			$under = false;
			foreach ( $list as $m ) {
				if ( ! empty( self::find_rows( $items, $m['slug'], $m['to'] ) ) ) {
					$under = true;
					break;
				}
			}
			foreach ( $list as $m ) {
				$out[ self::move_key( $m ) ] = $no_rows || ! $under;
			}
		}
		return $out;
	}

	/**
	 * 三個分頁換成工作副本（沒變的分頁原樣保留，不新增空的段落）。
	 *
	 * @param array<string,mixed> $config
	 * @param array<int,mixed>    $items
	 * @param array<int,mixed>    $native
	 * @param array<int,mixed>    $copies
	 * @param array<int,mixed>    $orig
	 * @return array<string,mixed>
	 */
	private static function with_sections( array $config, array $items, array $native, array $copies, array $orig ): array {
		if ( $items !== $orig[0] ) {
			$config['ys_cart']['items'] = $items;
		}
		if ( $native !== $orig[1] ) {
			$config['wp_native']['items'] = $native;
		}
		if ( $copies !== $orig[2] ) {
			$config['top_copies']['items'] = $copies;
		}
		return $config;
	}

	/**
	 * 搬家後綁著這一頁的人（存取判斷看的第一列綁定）原本看不看得到它。原本看不到、搬家後卻看得到（側欄或直接開網址）
	 * 就回那個帳號（要加隱藏），否則回 0。
	 *   - 原本（舊分組，快照）：舊上層在側欄看得到、這一頁在它底下也看得到；被拆掉的頂層是這一頁當頂層看得到。白名單
	 *     「變多」是規格事先宣告的例外：白名單裡明確勾了這一頁，就當舊上層也有勾。
	 *   - 搬家後：新上層看得到、這一頁在它底下也看得到。
	 *   - 兩邊都算「複製到主選單」的那一項：只要原子選單過得了子選單的過濾就會出現，跟上層看不看得到無關。
	 *   - super admin 不受隱藏影響，不用判斷。
	 *
	 * @param array<string,mixed>                   $snapshot
	 * @param array<string,mixed>                   $planned
	 * @param array{from:string,to:string,sub:bool} $g
	 */
	private static function binding_needs_hide( array $snapshot, array $planned, string $slug, array $g ): int {
		$uid = self::effective_rules( $planned, $slug )['first'];
		if ( $uid <= 0 || is_super_admin( $uid ) ) {
			return 0;
		}

		if ( $g['sub'] ) {
			$also = [];
			$list = YSMenuRouter::visible_slugs_for_user( $snapshot, $uid );
			if ( is_array( $list ) && in_array( $slug, $list, true ) ) {
				$also = [ $g['from'] ];
			}
			$before = ( YSMenuRouter::sidebar_allows( $g['from'], true, $snapshot, $uid, $also ) && YSMenuRouter::sidebar_allows( $slug, false, $snapshot, $uid, [], $g['from'] ) )
				|| YSMenuRouter::copy_allows( $slug, $g['from'], $snapshot, $uid );
		} else {
			$before = YSMenuRouter::sidebar_allows( $slug, true, $snapshot, $uid ) || YSMenuRouter::copy_allows( $slug, $slug, $snapshot, $uid );
		}
		$after = ( YSMenuRouter::sidebar_allows( $g['to'], true, $planned, $uid ) && YSMenuRouter::sidebar_allows( $slug, false, $planned, $uid, [], $g['to'] ) )
			|| YSMenuRouter::copy_allows( $slug, $g['to'], $planned, $uid );
		if ( $after && ! $before ) {
			return $uid;
		}

		return YSMenuRouter::url_allows( $slug, $planned, $uid ) && ! YSMenuRouter::url_allows( $slug, $snapshot, $uid ) ? $uid : 0;
	}

	/**
	 * 這一頁的每一列「全部選單」列都加隱藏；沒有子選單列就補一列只帶隱藏的（apply_hide_flags() 要靠子選單列才藏得掉子選單）。
	 *
	 * @param array<int,mixed> $items
	 * @return array<int,mixed>
	 */
	private static function hide_rows_of( array $items, string $slug, string $to ): array {
		$sub = false;
		foreach ( $items as $i => $row ) {
			if ( ! is_array( $row ) || ! empty( $row['separator'] ) || (string) ( $row['slug'] ?? '' ) !== $slug ) {
				continue;
			}
			$items[ $i ]['hide'] = true;
			if ( 'sub' === (string) ( $row['level'] ?? 'top' ) ) {
				$sub = true;
			}
		}
		if ( ! $sub ) {
			$items[] = self::rules_only_row( $slug, $to, true );
		}
		return $items;
	}

	/**
	 * 被拆掉的頂層原本有隱藏：確定有一列「全部選單」子選單列帶著隱藏，沒有就給現有的子選單列加上，連一列都沒有就補一列
	 * 只帶隱藏的（掛在新上層、不寫順序）。有改才回 true。
	 *
	 * @param array<int,mixed> $items 直接修改。
	 */
	private static function ensure_hidden_sub( array &$items, string $slug, string $to, object $gone ): bool {
		$subs = [];
		foreach ( $items as $i => $row ) {
			if ( $row === $gone || ! is_array( $row ) || ! empty( $row['separator'] ) || (string) ( $row['slug'] ?? '' ) !== $slug
				|| 'sub' !== (string) ( $row['level'] ?? 'top' ) ) {
				continue;
			}
			if ( ! empty( $row['hide'] ) ) {
				return false;
			}
			$subs[] = $i;
		}
		if ( empty( $subs ) ) {
			$items[] = self::rules_only_row( $slug, $to, true );
			return true;
		}
		foreach ( $subs as $i ) {
			$items[ $i ]['hide'] = true;
		}
		return true;
	}

	/**
	 * 只帶規則的子選單列（沒有順序值、名稱照預設）。
	 *
	 * @return array<string,mixed>
	 */
	private static function rules_only_row( string $slug, string $to, bool $hide ): array {
		return [
			'slug'           => $slug,
			'roles'          => [],
			'level'          => 'sub',
			'parent_slug'    => $to,
			'hide'           => $hide,
			'self_only_uid'  => 0,
			'promote_to_top' => false,
			'title_override' => null,
		];
	}

	/**
	 * @param array<string,mixed> $config
	 * @return array<int,mixed>
	 */
	private static function list_section( array $config, string $section ): array {
		$items = $config[ $section ]['items'] ?? [];
		return is_array( $items ) ? $items : [];
	}

	/**
	 * ys_cart.items 裡 slug、上層都相同的子選單列（索引）。
	 *
	 * @param array<int,mixed> $items
	 * @return array<int,int|string>
	 */
	private static function find_rows( array $items, string $slug, string $parent ): array {
		$out = [];
		foreach ( $items as $i => $row ) {
			if ( is_array( $row ) && empty( $row['separator'] ) && 'sub' === (string) ( $row['level'] ?? 'top' )
				&& (string) ( $row['slug'] ?? '' ) === $slug && (string) ( $row['parent_slug'] ?? '' ) === $parent ) {
				$out[] = $i;
			}
		}
		return $out;
	}

	/**
	 * 兩個分頁裡有沒有任何 slug 相同的列。
	 *
	 * @param array<int,mixed> $items
	 * @param array<int,mixed> $native
	 */
	private static function slug_has_rows( array $items, array $native, string $slug, object $gone ): bool {
		foreach ( array_merge( $items, $native ) as $row ) {
			if ( $row !== $gone && is_array( $row ) && empty( $row['separator'] ) && (string) ( $row['slug'] ?? '' ) === $slug ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @return array{hide:bool,roles:?array<int,string>,first:int,last:int,uids:array<int,int>}
	 */
	private static function no_rules(): array {
		return [ 'hide' => false, 'roles' => null, 'first' => 0, 'last' => 0, 'uids' => [] ];
	}

	/**
	 * 某個 slug 的有效規則：兩個分頁、各層級裡 slug 相同的列合起來（1.4.0 判斷看不看得到就是這樣看的）。
	 *   - hide：任一列 hide。
	 *   - roles：每一列非空的 roles 都要滿足＝取交集（[] 表示交集是空的；null 表示沒有限制）。
	 *   - 僅限本人：1.4.0 兩個地方看的不一樣——存取判斷與直接開網址（self_only_uid_for_slug）只看第一列（first，
	 *     0＝第一列沒綁＝沒綁）；側欄（apply_self_only_filter）是後面的列蓋掉前面（last）。uids＝所有綁定的帳號。
	 *
	 * @param array<string,mixed> $config
	 * @return array{hide:bool,roles:?array<int,string>,first:int,last:int,uids:array<int,int>}
	 */
	private static function effective_rules( array $config, string $slug ): array {
		$rules = self::no_rules();
		$seen  = false;
		foreach ( [ 'wp_native', 'ys_cart' ] as $tab ) {
			foreach ( self::list_section( $config, $tab ) as $row ) {
				if ( ! is_array( $row ) || ! empty( $row['separator'] ) || (string) ( $row['slug'] ?? '' ) !== $slug ) {
					continue;
				}
				if ( ! empty( $row['hide'] ) ) {
					$rules['hide'] = true;
				}
				$list = self::clean_roles( $row['roles'] ?? [] );
				if ( ! empty( $list ) ) {
					$rules['roles'] = null === $rules['roles'] ? $list : array_values( array_intersect( $rules['roles'], $list ) );
				}
				$u = max( 0, (int) ( $row['self_only_uid'] ?? 0 ) );
				if ( ! $seen ) {
					$rules['first'] = $u;
					$seen           = true;
				}
				if ( $u > 0 ) {
					$rules['last']       = $u;
					$rules['uids'][ $u ] = $u;
				}
			}
		}
		$rules['uids'] = array_values( $rules['uids'] );
		return $rules;
	}

	/**
	 * @param array{hide:bool,roles:?array<int,string>,first:int,last:int,uids:array<int,int>} $rules
	 */
	private static function rules_restrict( array $rules ): bool {
		return $rules['hide'] || null !== $rules['roles'] || $rules['last'] > 0;
	}

	/**
	 * 子選單 S「且」舊上層 P。
	 *   - hide：取或。
	 *   - roles：兩邊都有取交集、一邊空用另一邊；交集是空的改成 hide（1.4.0 的空陣列＝所有人都看得到，不能這樣存）。
	 *   - 僅限本人：P 的綁定看側欄實際生效的那一個（後面的列蓋掉前面）。S 自己一列都沒綁 → 帶入 P 的（inherit，寫到
	 *     S 的每一列）；S 自己有綁 → S 的各列原樣不動（1.4.0 只看第一列，統一寫到每一列會讓沒生效的綁定生效），
	 *     跟 P 綁的不是同一個帳號就是衝突，加 hide。
	 *
	 * @param array{hide:bool,roles:?array<int,string>,first:int,last:int,uids:array<int,int>} $s
	 * @param array{hide:bool,roles:?array<int,string>,first:int,last:int,uids:array<int,int>} $p
	 * @return array{hide:bool,roles:?array<int,string>,inherit:int,conflict:bool}
	 */
	private static function combine_rules( array $s, array $p ): array {
		$hide = $s['hide'] || $p['hide'];

		if ( null === $s['roles'] ) {
			$roles = $p['roles'];
		} elseif ( null === $p['roles'] ) {
			$roles = $s['roles'];
		} else {
			$roles = array_values( array_intersect( $s['roles'], $p['roles'] ) );
		}
		if ( is_array( $roles ) && [] === $roles ) {
			$hide = true;
		}

		$inherit  = 0;
		$conflict = false;
		if ( $p['last'] > 0 ) {
			if ( empty( $s['uids'] ) ) {
				$inherit = $p['last'];
			} elseif ( [ $p['last'] ] !== $s['uids'] ) {
				$conflict = true;
				$hide     = true;
			}
		}

		return [ 'hide' => $hide, 'roles' => $roles, 'inherit' => $inherit, 'conflict' => $conflict ];
	}

	/**
	 * 把合併後的規則寫進一列。交集是空的（改成 hide）時，roles 保留這一列原本的值，管理員在設定頁看得出原本怎麼設。
	 * 「僅限本人」只在從舊上層帶入時才寫。
	 *
	 * @param array<string,mixed>                                               $row
	 * @param array{hide:bool,roles:?array<int,string>,inherit:int,conflict:bool} $merged
	 * @return array{0:array<string,mixed>,1:array<int,string>}
	 */
	private static function apply_merged( array $row, array $merged ): array {
		$fields = [];
		if ( $merged['hide'] && empty( $row['hide'] ) ) {
			$row['hide'] = true;
			$fields[]    = 'hide';
		}
		if ( is_array( $merged['roles'] ) && [] !== $merged['roles'] ) {
			$own = self::clean_roles( $row['roles'] ?? [] );
			$a   = $own;
			$b   = $merged['roles'];
			sort( $a );
			sort( $b );
			if ( $a !== $b ) {
				$row['roles'] = $merged['roles'];
				$fields[]     = 'roles';
			}
		}
		if ( $merged['inherit'] > 0 && (int) ( $row['self_only_uid'] ?? 0 ) !== $merged['inherit'] ) {
			$row['self_only_uid'] = $merged['inherit'];
			$fields[]             = 'self_only_uid';
		}
		return [ $row, $fields ];
	}

	/**
	 * 合併後的規則寫到 S 的每一列（wp_native、ys_cart 都寫）：1.4.0 的 self_only_uid_for_slug() 只看第一列，
	 * 只寫其中一列可能沒有效果。從舊上層帶入的「僅限本人」也寫到每一列；S 自己原本的綁定不動。
	 *
	 * @param array<int,mixed>                                                    $items
	 * @param array<int,mixed>                                                    $native
	 * @param array{hide:bool,roles:?array<int,string>,inherit:int,conflict:bool} $merged
	 * @return array{0:array<int,mixed>,1:array<int,mixed>,2:array<int,string>}
	 */
	private static function write_rules_to_slug( array $items, array $native, string $slug, array $merged, object $gone ): array {
		$fields = [];
		foreach ( [ 'items', 'native' ] as $which ) {
			$list = 'items' === $which ? $items : $native;
			foreach ( $list as $i => $row ) {
				if ( $row === $gone || ! is_array( $row ) || ! empty( $row['separator'] ) || (string) ( $row['slug'] ?? '' ) !== $slug ) {
					continue;
				}
				[ $list[ $i ], $f ] = self::apply_merged( $row, $merged );
				$fields             = array_merge( $fields, $f );
			}
			if ( 'items' === $which ) {
				$items = $list;
			} else {
				$native = $list;
			}
		}
		return [ $items, $native, array_values( array_unique( $fields ) ) ];
	}

	/**
	 * @param mixed $roles
	 * @return array<int,string>
	 */
	private static function clean_roles( $roles ): array {
		return array_values( array_unique( array_filter( array_map( 'sanitize_key', array_map( 'strval', array_filter( (array) $roles, 'is_scalar' ) ) ) ) ) );
	}

	/**
	 * 新上層比舊上層多藏了某些角色（只是告知：那些角色在側欄會看不到搬家子項）。舊上層本來就藏起來的不算。
	 *
	 * @param array{hide:bool,roles:?array<int,string>,first:int,last:int,uids:array<int,int>} $n
	 * @param array{hide:bool,roles:?array<int,string>,first:int,last:int,uids:array<int,int>} $f
	 */
	private static function newly_restricted( array $n, array $f ): bool {
		if ( $f['hide'] || ( is_array( $f['roles'] ) && [] === $f['roles'] ) ) {
			return false;
		}
		if ( $n['hide'] || ( is_array( $n['roles'] ) && [] === $n['roles'] ) ) {
			return true;
		}
		if ( null !== $n['roles'] && ( null === $f['roles'] || [] !== array_diff( $f['roles'], $n['roles'] ) ) ) {
			return true;
		}
		return $n['last'] > 0 && $n['last'] !== $f['last'];
	}

	/**
	 * 同一頁的兩列併成一列：$base 的名稱、順序與其他欄位優先（沒有名稱才用 $other 的），升頂層取或，規則從嚴
	 * （之後還會把合併後的規則寫到這一頁的每一列）。「僅限本人」取兩列照原本順序裡後面那個有綁的（側欄實際生效的），
	 * 兩列綁不同帳號就加 hide。
	 *
	 * @param array<string,mixed> $base
	 * @param array<string,mixed> $other
	 * @param bool                $other_first $other 在清單裡排在 $base 前面。
	 * @return array<string,mixed>
	 */
	private static function merge_rows( array $base, array $other, bool $other_first ): array {
		$row = $base;
		if ( ! self::has_title( $row ) && self::has_title( $other ) ) {
			$row['title_override'] = $other['title_override'];
		}
		if ( ! empty( $other['promote_to_top'] ) ) {
			$row['promote_to_top'] = true;
		}
		$rules = self::rules_of_rows( $other_first ? [ $other, $base ] : [ $base, $other ] );
		if ( $rules['hide'] || count( $rules['uids'] ) > 1 || ( is_array( $rules['roles'] ) && [] === $rules['roles'] ) ) {
			$row['hide'] = true;
		}
		if ( is_array( $rules['roles'] ) && [] !== $rules['roles'] ) {
			$row['roles'] = $rules['roles'];
		}
		$row['self_only_uid'] = $rules['last'];
		return $row;
	}

	/**
	 * @param array<int,array<string,mixed>> $rows
	 * @return array{hide:bool,roles:?array<int,string>,first:int,last:int,uids:array<int,int>}
	 */
	private static function rules_of_rows( array $rows ): array {
		return self::effective_rules( [ 'ys_cart' => [ 'items' => array_map( static fn( $r ) => [ 'slug' => '_' ] + $r, $rows ) ] ], '_' );
	}

	/**
	 * @param array<string,mixed> $row
	 */
	private static function has_title( array $row ): bool {
		return isset( $row['title_override'] ) && is_string( $row['title_override'] ) && '' !== trim( $row['title_override'] );
	}

	/**
	 * 被拆掉的頂層（top_to_sub）。前提：ys_cart 有一列 level=top、slug 相同。
	 * 頂層列、同 slug 的子選單列（上層＝自己，1.4.0 通常不會存，當防禦），以及新上層底下已經有的這一頁的列，
	 * 併成一列 level=sub、parent_slug=新上層。名稱只取子選單列的；頂層列的自訂名稱是群組名，不帶過去、寫進紀錄。
	 *
	 * @param array<int,mixed> $items 直接修改（併掉的列換成 $gone）。
	 * @return array{touched:bool,log:array<int,array<string,mixed>>,conflict:bool,group_title:?string,place:?array<string,mixed>}
	 */
	private static function convert_top( array &$items, string $slug, string $to, int $seq, object $gone ): array {
		$tops  = [];
		$selfs = [];
		$exist = [];
		foreach ( $items as $i => $row ) {
			if ( $row === $gone || ! is_array( $row ) || ! empty( $row['separator'] ) || (string) ( $row['slug'] ?? '' ) !== $slug ) {
				continue;
			}
			$level  = (string) ( $row['level'] ?? 'top' );
			$parent = (string) ( $row['parent_slug'] ?? '' );
			if ( 'sub' !== $level ) {
				$tops[] = $i;
			} elseif ( $parent === $slug ) {
				$selfs[] = $i;
			} elseif ( $parent === $to ) {
				$exist[] = $i;
			}
		}

		$out = [ 'touched' => false, 'log' => [], 'conflict' => false, 'group_title' => null, 'place' => null ];
		if ( empty( $tops ) ) {
			return $out;
		}
		$out['touched'] = true;

		$title = null;
		foreach ( array_merge( $exist, $selfs ) as $i ) {
			if ( self::has_title( $items[ $i ] ) ) {
				$title = (string) $items[ $i ]['title_override'];
				break;
			}
		}
		foreach ( $tops as $i ) {
			if ( self::has_title( $items[ $i ] ) ) {
				$out['group_title'] = (string) $items[ $i ]['title_override'];
				$out['log'][]       = [ 'type' => 'drop_group_title', 'slug' => $slug, 'title' => $out['group_title'] ];
				break;
			}
		}

		$all = array_merge( $tops, $selfs, $exist );
		sort( $all );
		$rows = array_map( static fn( $i ) => $items[ $i ], $all );

		// 規則：這一頁這幾列合起來（頂層列「且」子選單列）。「僅限本人」取側欄實際生效的那一個（後面的列蓋掉前面）；
		// 綁了不同帳號＝衝突，再加 hide。
		$rules           = self::rules_of_rows( $rows );
		$out['conflict'] = count( $rules['uids'] ) > 1;
		$merged          = [
			'hide'     => $rules['hide'] || $out['conflict'] || ( is_array( $rules['roles'] ) && [] === $rules['roles'] ),
			'roles'    => $rules['roles'],
			'inherit'  => 0,
			'conflict' => $out['conflict'],
		];

		$base = ! empty( $exist ) ? $exist[0] : $tops[0];
		$row  = $items[ $base ];
		$row['level']          = 'sub';
		$row['parent_slug']    = $to;
		$row['title_override'] = $title;
		$promote               = false;
		foreach ( array_merge( $selfs, $exist ) as $i ) {
			if ( ! empty( $items[ $i ]['promote_to_top'] ) ) {
				$promote = true;
			}
		}
		$row['promote_to_top'] = $promote;
		[ $row ]               = self::apply_merged( $row, $merged );
		$row['self_only_uid']  = $rules['last'];

		$prev_order = null;
		foreach ( array_merge( $tops, $selfs ) as $i ) {
			if ( isset( $items[ $i ]['order'] ) && is_numeric( $items[ $i ]['order'] ) ) {
				$prev_order = (int) $items[ $i ]['order'];
				break;
			}
		}
		if ( empty( $exist ) ) {
			unset( $row['order'] ); // 順序照「順序」的規則重給。
		}

		foreach ( $all as $i ) {
			$items[ $i ] = $i === $base ? $row : $gone;
		}

		if ( ! empty( $selfs ) ) {
			$out['log'][] = [ 'type' => 'merge_self', 'slug' => $slug ];
		}
		if ( ! empty( $exist ) ) {
			$out['log'][] = [ 'type' => 'merge', 'slug' => $slug, 'to' => $to ];
			return $out;
		}
		$out['log'][] = [ 'type' => 'convert', 'slug' => $slug, 'to' => $to ];
		if ( empty( $row['promote_to_top'] ) ) {
			$out['place'] = [ 'idx' => $base, 'old' => $prev_order, 'seq' => $seq ];
		}
		return $out;
	}

	/**
	 * 主選單分頁（wp_native.items）的複製項／移到主選單列換成新的代稱；after 指向舊代稱的也跟著換。
	 *
	 * @param array<int,mixed>               $native
	 * @param array<string,string>           $renames 舊代稱 => 新代稱
	 * @param array<int,array<string,mixed>> $log
	 * @return array<int,mixed>
	 */
	private static function rename_native_keys( array $native, array $renames, array &$log ): array {
		if ( empty( $renames ) ) {
			return $native;
		}
		$present = [];
		foreach ( $native as $row ) {
			if ( is_array( $row ) && empty( $row['separator'] ) && isset( $row['slug'] ) ) {
				$present[ (string) $row['slug'] ] = true;
			}
		}
		foreach ( $native as $k => $row ) {
			if ( ! is_array( $row ) || ! empty( $row['separator'] ) ) {
				continue;
			}
			$slug = (string) ( $row['slug'] ?? '' );
			if ( isset( $renames[ $slug ] ) ) {
				if ( isset( $present[ $renames[ $slug ] ] ) ) {
					$log[] = [ 'type' => 'key_conflict', 'old' => $slug, 'new' => $renames[ $slug ] ];
				} else {
					$native[ $k ]['slug'] = $renames[ $slug ];
					$log[]                = [ 'type' => 'key', 'old' => $slug, 'new' => $renames[ $slug ] ];
				}
			}
			if ( isset( $row['after'] ) && is_string( $row['after'] ) && isset( $renames[ wp_specialchars_decode( $row['after'] ) ] ) ) {
				$native[ $k ]['after'] = $renames[ wp_specialchars_decode( $row['after'] ) ];
			}
		}
		return $native;
	}

	/**
	 * 接在被拆掉頂層後面（after）的複製／移到主選單項目，改接在舊側欄裡排在那個頂層前面的項目；
	 * 找不到就不動（側欄會留在原位，不會壞），寫進紀錄與提醒。
	 *
	 * @param array<int,mixed>               $native
	 * @param array<string,mixed>            $snapshot
	 * @param array<string,bool>             $tops    這一輪拆掉的頂層
	 * @param array<string,bool>             $removed 所有拆掉的頂層（往前找時跳過）
	 * @param array<int,array<string,mixed>> $log
	 * @param array<string,mixed>            $notice
	 * @return array<int,mixed>
	 */
	private static function repoint_anchors( array $native, array $snapshot, array $tops, array $removed, array &$log, array &$notice ): array {
		$sequence = null;
		$fallback = null;
		foreach ( $native as $k => $row ) {
			if ( ! is_array( $row ) || ! empty( $row['separator'] ) || ! isset( $row['after'] ) || ! is_string( $row['after'] ) ) {
				continue;
			}
			$anchor = wp_specialchars_decode( $row['after'] );
			if ( ! isset( $tops[ $anchor ] ) ) {
				continue;
			}
			if ( null === $sequence ) {
				$sequence = self::old_top_sequence( $snapshot );
				$fallback = self::old_top_sequence_from_ys_cart( $snapshot );
			}
			$new = self::predecessor( $sequence, $anchor, $removed );
			if ( null === $new ) {
				$new = self::predecessor( $fallback, $anchor, $removed );
			}
			$who = (string) ( $row['slug'] ?? '' );
			if ( null === $new ) {
				$log[]               = [ 'type' => 'anchor_unresolved', 'slug' => $who, 'old' => $anchor ];
				$notice['anchors'][] = [ 'slug' => $who, 'old' => $anchor ];
				continue;
			}
			$native[ $k ]['after'] = $new;
			$log[]                 = [ 'type' => 'anchor', 'slug' => $who, 'old' => $anchor, 'new' => $new ];
		}
		return $native;
	}

	/**
	 * 舊側欄的頂層順序（照 YSMenuRouter：主選單分頁依 order 排，指定位置的項目接在錨點後面）。
	 *
	 * @param array<string,mixed> $config
	 * @return array<int,string>
	 */
	private static function old_top_sequence( array $config ): array {
		$ordered  = [];
		$anchored = [];
		foreach ( self::list_section( $config, 'wp_native' ) as $i => $row ) {
			if ( ! is_array( $row ) || ! empty( $row['separator'] ) || empty( $row['slug'] ) ) {
				continue;
			}
			if ( isset( $row['after'] ) && is_string( $row['after'] ) ) {
				$anchored[] = $row;
				continue;
			}
			$ordered[] = [ 'slug' => wp_specialchars_decode( (string) $row['slug'] ), 'order' => (int) ( $row['order'] ?? 0 ), 'i' => $i ];
		}
		usort(
			$ordered,
			static function ( $a, $b ) {
				return ( $a['order'] <=> $b['order'] ) ?: ( $a['i'] <=> $b['i'] );
			}
		);

		$keys = [];
		$pos  = 1;
		foreach ( $ordered as $o ) {
			if ( ! isset( $keys[ $o['slug'] ] ) ) {
				$keys[ $o['slug'] ] = (string) $pos;
				$pos               += 2;
			}
		}

		$pending = [];
		foreach ( $anchored as $row ) {
			$id    = wp_specialchars_decode( (string) $row['slug'] );
			$after = wp_specialchars_decode( (string) $row['after'] );
			if ( $after !== $id && ! isset( $pending[ $id ] ) ) {
				$pending[ $id ] = $after;
			}
		}
		$used = [];
		do {
			$progress = false;
			foreach ( $pending as $id => $after ) {
				if ( '' === $after ) {
					$base = '-1';
				} elseif ( isset( $keys[ $after ] ) && ! isset( $pending[ $after ] ) ) {
					$base = $keys[ $after ];
				} else {
					continue;
				}
				$n             = ( $used[ $base ] ?? 0 ) + 1;
				$used[ $base ] = $n;
				$keys[ $id ]   = $base . '.' . str_pad( (string) $n, 4, '0', STR_PAD_LEFT );
				unset( $pending[ $id ] );
				$progress = true;
			}
		} while ( $progress && ! empty( $pending ) );

		uasort( $keys, 'strnatcasecmp' );
		return array_map( 'strval', array_keys( $keys ) );
	}

	/**
	 * 主選單分頁沒有存過被拆掉的頂層時，退回「全部選單」分頁的頂層列順序（存檔當時的側欄順序）。
	 *
	 * @param array<string,mixed> $config
	 * @return array<int,string>
	 */
	private static function old_top_sequence_from_ys_cart( array $config ): array {
		$tops = [];
		foreach ( self::list_section( $config, 'ys_cart' ) as $i => $row ) {
			if ( is_array( $row ) && empty( $row['separator'] ) && ! empty( $row['slug'] ) && 'sub' !== (string) ( $row['level'] ?? 'top' ) ) {
				$tops[] = [ 'slug' => (string) $row['slug'], 'order' => (int) ( $row['order'] ?? 0 ), 'i' => $i ];
			}
		}
		usort(
			$tops,
			static function ( $a, $b ) {
				return ( $a['order'] <=> $b['order'] ) ?: ( $a['i'] <=> $b['i'] );
			}
		);
		return array_values( array_unique( array_map( static fn( $t ) => $t['slug'], $tops ) ) );
	}

	/**
	 * 序列裡排在 $slug 前面、不是被拆掉頂層的項目；$slug 是第一個回空字串（排在最前面）；不在序列裡回 null。
	 *
	 * @param array<int,string>  $sequence
	 * @param array<string,bool> $removed
	 */
	private static function predecessor( array $sequence, string $slug, array $removed ): ?string {
		$at = array_search( $slug, $sequence, true );
		if ( false === $at ) {
			return null;
		}
		for ( $j = (int) $at - 1; $j >= 0; $j-- ) {
			if ( ! isset( $removed[ $sequence[ $j ] ] ) ) {
				return $sequence[ $j ];
			}
		}
		return '';
	}

	/**
	 * 搬家項的順序。「全部選單」分頁的順序是整份清單共用的一串數字，所以搬家項要接在新上層最後一個
	 * 已存子選單之後、下一列之前；同一個上層一次搬進好幾項時，照它們在舊設定裡的順序，舊設定沒有順序的照
	 * moves 的列表順序。中間沒有空位才把後面的列整批往後挪（相對順序不變）。新上層完全沒有已存子選單時
	 * 不寫順序，照 YS CART 的預設順序。
	 *
	 * @param array<int,mixed>                             $items
	 * @param array<string,array<int,array<string,mixed>>> $placement
	 * @param array<int,array<string,mixed>>               $log
	 * @return array<int,mixed>
	 */
	private static function place_orders( array $items, array $placement, object $gone, array &$log ): array {
		$pending = [];
		foreach ( $placement as $group ) {
			foreach ( $group as $p ) {
				$pending[ $p['idx'] ] = true;
			}
		}

		foreach ( $placement as $to => $group ) {
			$group = array_values( array_filter( $group, static fn( $p ) => isset( $items[ $p['idx'] ] ) && is_array( $items[ $p['idx'] ] ) ) );
			if ( empty( $group ) ) {
				continue;
			}
			usort(
				$group,
				static function ( $a, $b ) {
					$ao = null === $a['old'] ? PHP_INT_MAX : $a['old'];
					$bo = null === $b['old'] ? PHP_INT_MAX : $b['old'];
					return ( $ao <=> $bo ) ?: ( ( $a['seq'] <=> $b['seq'] ) ?: ( $a['idx'] <=> $b['idx'] ) );
				}
			);

			$numbered = static function ( $row ) use ( $gone ): bool {
				return $row !== $gone && is_array( $row ) && isset( $row['order'] ) && is_numeric( $row['order'] );
			};

			$siblings = [];
			foreach ( $items as $i => $row ) {
				if ( isset( $pending[ $i ] ) || ! $numbered( $row ) || ! empty( $row['separator'] ) ) {
					continue;
				}
				if ( 'sub' === (string) ( $row['level'] ?? 'top' ) && (string) ( $row['parent_slug'] ?? '' ) === (string) $to ) {
					$siblings[] = (int) $row['order'];
				}
			}

			if ( empty( $siblings ) ) {
				foreach ( $group as $p ) {
					if ( array_key_exists( 'order', $items[ $p['idx'] ] ) ) {
						unset( $items[ $p['idx'] ]['order'] );
					}
					$log[] = [ 'type' => 'order_default', 'slug' => (string) ( $items[ $p['idx'] ]['slug'] ?? '' ), 'to' => (string) $to ];
					unset( $pending[ $p['idx'] ] );
				}
				continue;
			}

			$last = max( $siblings );
			$next = null;
			foreach ( $items as $i => $row ) {
				if ( isset( $pending[ $i ] ) || ! $numbered( $row ) ) {
					continue;
				}
				$o = (int) $row['order'];
				if ( $o > $last && ( null === $next || $o < $next ) ) {
					$next = $o;
				}
			}

			$need = count( $group );
			if ( null !== $next && $next - $last - 1 < $need ) {
				$shift = $need - ( $next - $last - 1 );
				foreach ( $items as $i => $row ) {
					if ( isset( $pending[ $i ] ) || ! $numbered( $row ) ) {
						continue;
					}
					if ( (int) $row['order'] > $last ) {
						$items[ $i ]['order'] = (int) $row['order'] + $shift;
					}
				}
				$log[] = [ 'type' => 'order_shift', 'to' => (string) $to, 'by' => $shift ];
			}

			$o = $last;
			foreach ( $group as $p ) {
				++$o;
				$items[ $p['idx'] ]['order'] = $o;
				unset( $pending[ $p['idx'] ] );
				$log[] = [ 'type' => 'order', 'slug' => (string) ( $items[ $p['idx'] ]['slug'] ?? '' ), 'to' => (string) $to, 'order' => $o ];
			}
		}
		return $items;
	}

	/**
	 * 使用者覆寫的白名單（只看 slug）跟新分組對不上的帳號。只提醒、不改：
	 *   - 子選單在白名單裡、舊上層與新上層只有一邊在白名單裡（新上層不在＝側欄看不到；只有新上層在＝側欄會多看到）。
	 *   - 被拆掉的頂層在白名單裡、新上層不在。
	 *
	 * @param array<string,mixed>  $config
	 * @param array<string,string> $move
	 * @return array<int,int>
	 */
	private static function whitelist_mismatches( array $config, array $move ): array {
		$lists = [];
		foreach ( (array) ( $config['user_overrides']['items'] ?? [] ) as $uo ) {
			$uid = is_array( $uo ) ? (int) ( $uo['user_id'] ?? 0 ) : 0;
			if ( $uid > 0 && ! isset( $lists[ $uid ] ) && ! empty( $uo['visible_slugs'] ) ) {
				$lists[ $uid ] = (array) $uo['visible_slugs'];
			}
		}
		foreach ( (array) ( $config['wp_native']['user_overrides'] ?? [] ) as $uid => $row ) {
			$uid = (int) $uid;
			if ( $uid > 0 && ! isset( $lists[ $uid ] ) && is_array( $row ) && ! empty( $row['visible_slugs'] ) ) {
				$lists[ $uid ] = (array) $row['visible_slugs'];
			}
		}

		$out = [];
		foreach ( $lists as $uid => $slugs ) {
			$has = [];
			foreach ( $slugs as $s ) {
				if ( is_scalar( $s ) ) {
					$has[ trim( (string) $s ) ] = true;
				}
			}
			if ( ! isset( $has[ $move['slug'] ] ) ) {
				continue;
			}
			$hit = 'top_to_sub' === $move['kind']
				? ! isset( $has[ $move['to'] ] )
				: ( isset( $has[ $move['from'] ] ) xor isset( $has[ $move['to'] ] ) );
			if ( $hit ) {
				$out[] = (int) $uid;
			}
		}
		return $out;
	}

	/**
	 * dry-run 用：列出改了哪些設定列（改之前／改之後）。
	 *
	 * @param array<int,mixed> $items_before
	 * @param array<int,mixed> $items_after
	 * @param array<int,mixed> $nat_before
	 * @param array<int,mixed> $nat_after
	 * @param array<int,mixed> $cop_before
	 * @param array<int,mixed> $cop_after
	 * @return array<int,array<string,mixed>>
	 */
	private static function row_diff( array $items_before, array $items_after, array $nat_before, array $nat_after, array $cop_before, array $cop_after ): array {
		$rows = [];

		$left = $items_after;
		foreach ( $items_before as $before ) {
			$same = array_search( $before, $left, true );
			if ( false !== $same ) {
				unset( $left[ $same ] );
				continue;
			}
			$match = null;
			if ( is_array( $before ) && empty( $before['separator'] ) ) {
				foreach ( $left as $j => $after ) {
					if ( is_array( $after ) && (string) ( $after['slug'] ?? '' ) === (string) ( $before['slug'] ?? '' ) ) {
						$match = $j;
						break;
					}
				}
			}
			if ( null === $match ) {
				$rows[] = [ 'section' => 'ys_cart', 'action' => 'removed', 'before' => $before, 'after' => null ];
				continue;
			}
			$rows[] = [ 'section' => 'ys_cart', 'action' => 'changed', 'before' => $before, 'after' => $left[ $match ] ];
			unset( $left[ $match ] );
		}
		foreach ( $left as $after ) {
			$rows[] = [ 'section' => 'ys_cart', 'action' => 'added', 'before' => null, 'after' => $after ];
		}

		foreach ( $nat_before as $i => $before ) {
			if ( ( $nat_after[ $i ] ?? null ) !== $before ) {
				$rows[] = [ 'section' => 'wp_native', 'action' => 'changed', 'before' => $before, 'after' => $nat_after[ $i ] ?? null ];
			}
		}

		$left = $cop_after;
		foreach ( $cop_before as $before ) {
			$same = array_search( $before, $left, true );
			if ( false !== $same ) {
				unset( $left[ $same ] );
				continue;
			}
			$rows[] = [ 'section' => 'top_copies', 'action' => 'removed', 'before' => $before, 'after' => null ];
		}
		foreach ( $left as $after ) {
			$rows[] = [ 'section' => 'top_copies', 'action' => 'added', 'before' => null, 'after' => $after ];
		}
		return $rows;
	}

	// ─────────────────────────────────────────────
	// 後台提醒（只給 manage_options、可以關掉）
	// ─────────────────────────────────────────────

	/**
	 * admin_notices：遷移標記上有 notice 才讀遷移紀錄、畫提醒（標記是自動載入的，沒有標記的站每個後台頁都不多查資料庫）。
	 * 提醒其實沒有內容可畫（已經關掉、列的帳號都刪了）時，把標記上的 notice 拿掉，之後的頁面就不再讀紀錄。
	 */
	public static function maybe_render_notice(): void {
		if ( ! YSAccess::can_manage() || ! self::notice_flag() ) {
			return;
		}
		if ( ! self::render_notice() ) {
			self::set_notice_flag( false );
		}
	}

	/**
	 * @return bool 有沒有畫出提醒。
	 */
	public static function render_notice(): bool {
		if ( ! YSAccess::can_manage() ) {
			return false;
		}
		$log = self::read_log( get_option( self::LOG_OPTION, null ) );
		if ( $log['dismissed'] ) {
			return false;
		}
		$n        = $log['notice'];
		$titles   = $n['titles'];
		$name     = static fn( string $slug ): string => self::notice_menu_name( $slug, $titles, false );
		$top_name = static fn( string $slug ): string => self::notice_menu_name( $slug, $titles, true );
		$sep      = '、';
		$admin    = is_multisite() ? '超級管理員' : '管理員'; // 不受隱藏影響的是 super admin（單站就是管理員）
		$lines    = [];

		if ( ! empty( $n['moved'] ) ) {
			$lines[] = sprintf( '這些選單換到新的位置，原本的順序、名稱與顯示設定已經跟著搬過去：%s。', implode( $sep, array_map( $name, $n['moved'] ) ) );
			if ( $n['rules'] ) {
				$lines[] = '原本因為看不到上層選單而看不到這些頁面的角色或帳號，搬到新位置後照樣看不到，直接開網址或從頁面裡的連結也會被擋。';
			}
		}
		foreach ( $n['group_titles'] as $g ) {
			$lines[] = sprintf( '選單群組「%s」已經拆掉，底下的頁面都搬到新位置，這個自訂的群組名稱不再使用。', (string) ( $g['title'] ?? '' ) );
		}
		if ( ! empty( $n['parent_hidden'] ) ) {
			$pairs = array_map(
				static fn( $p ) => sprintf( '%s（在「%s」底下）', $name( (string) ( $p['slug'] ?? '' ) ), $top_name( (string) ( $p['to'] ?? '' ) ) ),
				$n['parent_hidden']
			);
			$lines[] = sprintf( '這些頁面的新位置有部分角色看不到，那些角色在側欄也看不到這些頁面：%s。', implode( $sep, $pairs ) );
		}

		$people = [];
		foreach ( $n['whitelist'] as $uid ) {
			$user = get_userdata( (int) $uid );
			if ( ! $user || is_super_admin( (int) $uid ) ) {
				continue; // 帳號已刪除，或是管理員（白名單本來就不限制管理員）。
			}
			$people[] = '' !== (string) $user->display_name ? (string) $user->display_name : (string) $user->user_login;
		}
		if ( ! empty( $people ) ) {
			$lines[] = sprintf( '這些帳號有自訂可見的選單，請到「使用者覆寫」照新的分組重新勾選：%s。', implode( $sep, $people ) );
		}
		if ( ! empty( $n['conflicts'] ) ) {
			$lines[] = sprintf( '這些頁面設了「僅限本人」，但和原本的上層選單綁的不是同一個帳號，已經先改成隱藏：%1$s。現在除了綁定的%2$s帳號，沒有任何帳號（包括其他%2$s）看得到或打得開，要到「全部選單」調整後才會恢復。', implode( $sep, array_map( $name, $n['conflicts'] ) ), $admin );
		}
		$who = static function ( array $entries ) use ( $name ): array {
			return array_map(
				static function ( $e ) use ( $name ): string {
					$user = get_userdata( (int) ( $e['user_id'] ?? 0 ) );
					$acct = $user ? ( '' !== (string) $user->display_name ? (string) $user->display_name : (string) $user->user_login ) : '帳號 #' . (int) ( $e['user_id'] ?? 0 );
					return sprintf( '%s（%s）', $name( (string) ( $e['slug'] ?? '' ) ), $acct );
				},
				$entries
			);
		};
		if ( ! empty( $n['bound'] ) ) {
			$lines[] = sprintf( '這些頁面沿用了原本上層選單的「僅限本人」，現在只有括號裡的帳號看得到：%s。', implode( $sep, $who( $n['bound'] ) ) );
		}
		if ( ! empty( $n['gated'] ) ) {
			$lines[] = sprintf( '這些頁面綁定的帳號原本看不到它們，搬家後已經先改成隱藏：%1$s。現在沒有任何帳號（包括%2$s）看得到或打得開，要到「全部選單」調整後才會恢復。', implode( $sep, $who( $n['gated'] ) ), $admin );
		}
		if ( ! empty( $n['anchors'] ) ) {
			$lines[] = sprintf( '這些放在主選單的項目原本接在拆掉的選單群組後面，找不到新的位置，請到「全部選單」重新拖拉：%s。', implode( $sep, array_map( static fn( $a ) => $top_name( (string) ( $a['slug'] ?? '' ) ), $n['anchors'] ) ) );
		}
		if ( ! empty( $n['unknown'] ) ) {
			$lines[] = sprintf( '新版 YS CART 有 %d 項選單調整，這個版本的 YS 選單管理還看不懂，已經先略過，請更新 YS 選單管理。', count( $n['unknown'] ) );
		}
		if ( ! empty( $n['core_backup'] ) ) {
			$copy    = YSMenuConfigBridge::CORE_COPY_OPTION;
			$lines[] = false !== get_option( $copy, false )
				? sprintf( '啟用時，帳號設定（「使用者覆寫」的可見清單、「僅允許存取受保護的管理選單」）照 YS CART 那份帶過來；選單的順序、名稱和規則改用升級 YS CART V3 之前的那份，照本外掛的規則重新搬家，停用本外掛期間、升級 V3 之後在 YS CART 改的選單設定（包括 V3 的「全部隱藏」等規則）沒有帶過來。YS CART 那份已另存，可用 WP-CLI 查看：wp option get %s。', $copy )
				: '啟用時改用升級 YS CART V3 之前的那份選單設定（含帳號設定），照本外掛的規則重新搬家；YS CART 那份當時不在資料庫裡，沒有另存。';
		}
		if ( ! empty( $n['v2_rules'] ) ) {
			$lines[] = sprintf( '啟用本外掛時沿用了 YS CART 那份選單設定，其中這些選單用了 YS CART V3 的新可見規則（全部隱藏、隱藏指定角色、僅限操作本人），本外掛看不懂這種規則，現在只照原本的隱藏、角色與「僅限本人」判斷，請到「選單與權限」確認誰看得到：%s。', implode( $sep, array_map( $name, $n['v2_rules'] ) ) );
		}

		if ( empty( $lines ) ) {
			return false;
		}

		$settings = admin_url( 'admin.php?page=' . \YangSheep\AdminMenu\Admin\YSMenuPage::SLUG_PERMISSIONS . '&tab=ys_cart' );
		$dismiss  = wp_nonce_url( admin_url( 'admin-post.php?action=' . self::DISMISS_ACTION ), self::DISMISS_ACTION );

		echo '<div class="notice notice-info ys-admin-menu-moves-notice">';
		echo '<p><strong>' . esc_html__( 'YS 選單管理已配合新版 YS CART 調整選單設定', 'ys-admin-menu' ) . '</strong></p>';
		foreach ( $lines as $line ) {
			echo '<p>' . esc_html( $line ) . '</p>';
		}
		echo '<p><a class="button button-primary" href="' . esc_url( $settings ) . '">' . esc_html__( '前往選單與權限', 'ys-admin-menu' ) . '</a> ';
		echo '<a class="button" href="' . esc_url( $dismiss ) . '">' . esc_html__( '我知道了', 'ys-admin-menu' ) . '</a></p>';
		echo '</div>';
		return true;
	}

	public static function handle_dismiss(): void {
		if ( ! YSAccess::can_manage() ) {
			wp_die( esc_html__( '權限不足。', 'ys-admin-menu' ), '', [ 'response' => 403 ] );
		}
		check_admin_referer( self::DISMISS_ACTION );

		$raw = get_option( self::LOG_OPTION, null );
		if ( is_array( $raw ) && empty( $raw['dismissed'] ) ) {
			$raw['dismissed'] = true;
			update_option( self::LOG_OPTION, $raw, false );
		}
		self::set_notice_flag( false );

		$back = wp_get_referer();
		wp_safe_redirect( $back ? $back : admin_url() );
		exit;
	}

	/**
	 * @param array<string,string> $titles 遷移當下記下的名稱（上層選單以 top: 開頭）。
	 */
	private static function notice_menu_name( string $slug, array $titles, bool $top ): string {
		$live = self::live_menu_title( $slug, $top );
		if ( '' !== $live ) {
			return $live;
		}
		$key = $top ? 'top:' . $slug : $slug;
		return isset( $titles[ $key ] ) && '' !== $titles[ $key ] ? $titles[ $key ] : $slug;
	}

	/**
	 * 側欄現在的名稱（拿掉數字徽章）。頁面以子選單的名稱優先；上層選單以頂層的名稱優先。
	 */
	private static function live_menu_title( string $slug, bool $top = false ): string {
		global $submenu, $menu;
		if ( '' === $slug ) {
			return '';
		}
		$clean = static function ( $title ): string {
			$title = preg_replace( '#<span[^>]*>.*?</span>#s', '', (string) $title );
			return trim( wp_strip_all_tags( (string) $title ) );
		};
		$from_sub = static function () use ( $submenu, $slug, $clean ): string {
			foreach ( (array) $submenu as $rows ) {
				foreach ( (array) $rows as $row ) {
					if ( is_array( $row ) && (string) ( $row[2] ?? '' ) === $slug ) {
						$t = $clean( $row[0] ?? '' );
						if ( '' !== $t ) {
							return $t;
						}
					}
				}
			}
			return '';
		};
		$from_top = static function () use ( $menu, $slug, $clean ): string {
			foreach ( (array) $menu as $row ) {
				if ( is_array( $row ) && (string) ( $row[2] ?? '' ) === $slug ) {
					$t = $clean( $row[0] ?? '' );
					if ( '' !== $t ) {
						return $t;
					}
				}
			}
			return '';
		};
		$first = $top ? $from_top() : $from_sub();
		return '' !== $first ? $first : ( $top ? $from_sub() : $from_top() );
	}
}
