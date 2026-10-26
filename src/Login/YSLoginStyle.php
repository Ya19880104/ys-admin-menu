<?php
/**
 * 自訂登入頁的樣式設定（v1.6.0）：主色、背景（預設深藍／淺色／自訂顏色）、左上字標。
 *
 * - 三項都沒設定時什麼都不輸出：不加內嵌樣式、body 不多 class，登入頁跟 1.5.2 一模一樣。
 * - 主色只收一個色碼，按鈕滑過、連結、焦點框、光暈都從它算出來；對比不夠時自動調整
 *   （按鈕文字改用深色、連結與焦點框調深），調了什麼寫在設定頁的說明裡。
 * - 輸出的 CSS 只用這裡算出來的數字組（#rrggbb、rgba(r, g, b, a)），不輸出存下的字串；
 *   背景只用白名單裡的 class。
 * - 淺色背景的變數寫在 CSS 檔（body.ys-am-login--light 底下）；自訂顏色的變數由這裡依顏色算好輸出。
 * - 卡片外面的字（大標、標語、頁尾、忘記密碼、返回首頁）不只對漸層兩端，也要對左下的青色光暈、
 *   以及有背景圖時圖透過遮色露出來最壞的樣子達標（1.5.x 預設深藍有背景圖時把淡色字換亮一階也是同一個道理）。
 *
 * @package YangSheep\AdminMenu\Login
 * @since   1.6.0
 */

namespace YangSheep\AdminMenu\Login;

use YangSheep\AdminMenu\Access\YSAccess;

defined( 'ABSPATH' ) || exit;

final class YSLoginStyle {

	/** wp_options key — 主色（#rrggbb；空白＝CRM 藍）。 */
	public const OPTION_PRIMARY_COLOR = 'ys_admin_menu_login_primary_color';

	/** wp_options key — 背景（''＝預設深藍、light＝淺色、custom＝自訂顏色）。 */
	public const OPTION_BACKGROUND = 'ys_admin_menu_login_background';

	/** wp_options key — 自訂背景色（#rrggbb；背景選「自訂顏色」時才用）。 */
	public const OPTION_BACKGROUND_COLOR = 'ys_admin_menu_login_background_color';

	/** wp_options key — 左上字標（純文字；空白＝沿用 1.5.2 的規則）。 */
	public const OPTION_WORDMARK = 'ys_admin_menu_login_wordmark';

	/** 背景的選項（白名單）。 */
	public const BACKGROUNDS = [ '', 'light', 'custom' ];

	/** 字標最多幾個字。 */
	public const WORDMARK_MAX = 40;

	/** 1.5.2 的主色（CRM 藍）：按鈕、連結。 */
	public const DEFAULT_PRIMARY = '#0072bd';

	/** 頁面底色（預設深藍）與淺色背景的兩端；白卡、次要按鈕滑過的底色。 */
	private const DARK_BG       = [ '#111c2d', '#0b1420' ];
	private const LIGHT_BG      = [ '#f5f8fb', '#e9eef3' ];
	private const CARD          = '#ffffff';
	private const SURFACE       = '#f5f8fb';
	private const TEXT_DARK     = '#111c2d';
	private const TEXT_WHITE    = '#ffffff';

	/** 淺色背景的標語與淡色字（跟 CSS 檔 body.ys-am-login--light 那一組一樣）。 */
	private const LIGHT_SOFT  = '#334155';
	private const LIGHT_MUTED = '#5f6870';

	/**
	 * 寬螢幕左下的青色光暈（CSS 的 --ys-login-glow-cyan，不透明度 0.1）。瀏覽器把不透明度存成 8 位元（26/255，
	 * 略高於 0.1），疊出來的顏色會再亮一點，所以這裡用 0.11 留一點餘裕。
	 */
	private const GLOW_CYAN       = [ 70, 202, 235 ];
	private const GLOW_CYAN_ALPHA = 0.11;

	/**
	 * 背景圖透過遮色露出來的比例：遮色在漸層起點的不透明度是 0.92（預設深藍是 0.9）、終點 0.94。
	 */
	private const IMAGE_SHOW_FROM         = 0.08;
	private const IMAGE_SHOW_FROM_DEFAULT = 0.1;
	private const IMAGE_SHOW_TO           = 0.06;

	/* ─────────────────────────── 設定讀取 ─────────────────────────── */

	/**
	 * 色碼正規化：sanitize_hex_color() 之後，3 位數展開成 6 位數、一律小寫；不合法回空字串。
	 *
	 * @param mixed $value
	 */
	public static function normalize_hex( $value ): string {
		if ( ! is_string( $value ) ) {
			return '';
		}
		$value = trim( $value );
		if ( '' === $value ) {
			return '';
		}
		$hex = sanitize_hex_color( $value );
		if ( ! is_string( $hex ) || '' === $hex ) {
			return '';
		}
		$hex = strtolower( ltrim( $hex, '#' ) );
		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		return 1 === preg_match( '/^[0-9a-f]{6}$/', $hex ) ? '#' . $hex : '';
	}

	/** 已存的主色（輸出前再驗證一次；不合法當空白）。 */
	public static function get_primary(): string {
		return self::normalize_hex( get_option( self::OPTION_PRIMARY_COLOR, '' ) );
	}

	/** 已存的背景設定（不在白名單裡當預設）。 */
	public static function get_background_setting(): string {
		$value = get_option( self::OPTION_BACKGROUND, '' );
		return is_string( $value ) && in_array( $value, self::BACKGROUNDS, true ) ? $value : '';
	}

	public static function get_background_color(): string {
		return self::normalize_hex( get_option( self::OPTION_BACKGROUND_COLOR, '' ) );
	}

	/** 實際用的背景：選了「自訂顏色」卻沒有合法的顏色時，照預設。 */
	public static function get_background(): string {
		$mode = self::get_background_setting();
		if ( 'custom' === $mode && '' === self::get_background_color() ) {
			return '';
		}
		return $mode;
	}

	/** 已存的左上字標（純文字，最多 40 字）。 */
	public static function get_wordmark_setting(): string {
		return self::clean_wordmark( get_option( self::OPTION_WORDMARK, '' ) );
	}

	/**
	 * @param mixed $value
	 */
	public static function clean_wordmark( $value ): string {
		$value = sanitize_text_field( is_string( $value ) ? $value : '' );
		return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, self::WORDMARK_MAX ) : substr( $value, 0, self::WORDMARK_MAX );
	}

	/* ─────────────────────────── 輸出 ─────────────────────────── */

	/**
	 * body 要加的 class：只有背景是淺色或自訂顏色時才有。
	 *
	 * @return string[]
	 */
	public static function body_classes(): array {
		$mode = self::get_background();
		if ( 'light' === $mode ) {
			return [ 'ys-am-login--light' ];
		}
		if ( 'custom' === $mode ) {
			return [ 'ys-am-login--custom-bg' ];
		}
		return [];
	}

	/**
	 * 要加在登入頁 CSS 後面的內嵌樣式：只覆寫顏色變數；主色與背景都是預設時回空字串（不輸出）。
	 *
	 * 選擇器帶著背景的 class（只會是白名單裡的），跟 CSS 檔裡淺色背景那一組一樣具體；
	 * 內嵌樣式排在 CSS 檔後面，所以主色算出來的光暈、焦點框會蓋過淺色背景的預設值，
	 * 自訂顏色的淡色字也會蓋過有背景圖時的那一條。
	 */
	public static function inline_css(): string {
		$vars = self::variables();
		if ( [] === $vars ) {
			return '';
		}
		$decl = '';
		foreach ( $vars as $name => $value ) {
			$decl .= $name . ':' . $value . ';';
		}
		$selector = 'body.ys-am-login.login';
		foreach ( self::body_classes() as $class ) {
			$selector .= '.' . $class;
		}
		return $selector . '{' . $decl . '}';
	}

	/**
	 * 這個站的設定要覆寫的 CSS 變數（名稱 => 值）。主色與背景都是預設時是空陣列。
	 *
	 * @return array<string, string>
	 */
	public static function variables(): array {
		return self::derive( self::get_primary(), self::get_background(), self::get_background_color(), self::has_image() )['vars'];
	}

	/** 有沒有背景圖（跟 body 的 ys-am-login--has-bg 用同一個判斷）。 */
	private static function has_image(): bool {
		return '' !== YSLoginPage::get_bg_url();
	}

	/**
	 * 依主色、背景算出變數與說明。
	 *
	 * @param bool $has_image 有背景圖時，卡片外面的字還要對「圖透過遮色露出來」最壞的樣子達標。
	 * @return array{vars: array<string, string>, notes: string[], colors: array<string, string>}
	 */
	public static function derive( string $primary, string $background, string $background_color, bool $has_image = false ): array {
		$vars   = [];
		$notes  = [];
		$colors = [];

		$page = self::page_colors( $background, $background_color, $has_image );
		if ( null !== $page ) {
			$vars = array_merge( $vars, $page['vars'] );
			foreach ( $page['notes'] as $note ) {
				$notes[] = $note;
			}
		}

		if ( '' !== $primary ) {
			$p      = self::rgb( $primary );
			$white  = self::rgb( self::TEXT_WHITE );
			$dark   = self::rgb( self::TEXT_DARK );
			$card   = self::rgb( self::CARD );
			$surf   = self::rgb( self::SURFACE );
			$bg_ref = null !== $page
				? $page['against']
				: self::surroundings( self::rgb( self::DARK_BG[0] ), self::rgb( self::DARK_BG[1] ), false, $has_image, self::IMAGE_SHOW_FROM_DEFAULT );
			$light  = null !== $page && $page['light'];

			// 按鈕文字：白字達 4.5:1 用白字，否則用深色字。
			$white_ok    = self::contrast( $white, $p ) >= 4.5;
			$button_text = $white_ok ? $white : $dark;
			if ( ! $white_ok ) {
				$notes[] = sprintf(
					/* translators: 1: 主色, 2: 對比 */
					__( '按鈕文字改用深色：白字對 %1$s 只有 %2$s:1。', 'ys-admin-menu' ),
					strtoupper( $primary ),
					self::ratio_text( self::contrast( $white, $p ) )
				);
			}
			// 滑過：白字時往黑色混（越來越深），深色字時往白色混；兩種都要維持文字 4.5:1。
			$hover = $white_ok ? self::mix( $p, [ 0, 0, 0 ], 0.15 ) : self::mix( $p, [ 255, 255, 255 ], 0.15 );
			if ( self::contrast( $button_text, $hover ) < 4.5 ) {
				$hover = $p;
			}

			// 表單裡的連結、勾選框、次要按鈕滑過的字：對 #f5f8fb 也要 4.5:1（白卡自然也達標）。
			$accent = self::darken_until( $p, [ $surf ], 4.5 );
			if ( $accent !== $p ) {
				$notes[] = sprintf(
					/* translators: 1: 主色, 2: 實際用色 */
					__( '連結改用 %2$s：%1$s 在白卡上對比不夠。', 'ys-admin-menu' ),
					strtoupper( $primary ),
					strtoupper( self::hex( $accent ) )
				);
			}
			$accent_hover = self::darken_until( self::mix( $accent, [ 0, 0, 0 ], 0.15 ), [ $surf ], 4.5 );

			// 白卡上的焦點框 3:1；頁面底色上的焦點框往底色的反方向調到 3:1。
			$focus         = self::darken_until( $p, [ $card ], 3.0 );
			$focus_on_page = $light
				? self::darken_until( $p, $bg_ref, 3.0 )
				: self::lighten_until( $p, $bg_ref, 3.0 );

			$colors = [
				'button'      => self::hex( $p ),
				'button_text' => self::hex( $button_text ),
				'link'        => self::hex( $accent ),
				'focus'       => self::hex( $focus ),
			];

			$vars['--ys-login-button']                  = self::hex( $p );
			$vars['--ys-login-button-text']             = self::hex( $button_text );
			$vars['--ys-login-button-hover']            = self::hex( $hover );
			$vars['--ys-login-accent-text']             = self::hex( $accent );
			$vars['--ys-login-accent-hover']            = self::hex( $accent_hover );
			$vars['--ys-login-focus']                   = self::hex( $focus );
			$vars['--ys-login-focus-on-dark']           = self::hex( $focus_on_page );
			$vars['--ys-login-glow-blue']               = self::rgba( $p, $light ? 0.12 : 0.2 );
			$vars['--wp-admin-theme-color']             = self::hex( $p );
			$vars['--wp-admin-theme-color-darker-10']   = self::hex( self::mix( $p, [ 0, 0, 0 ], 0.15 ) );
			$vars['--wp-admin-theme-color-darker-20']   = self::hex( self::mix( $p, [ 0, 0, 0 ], 0.3 ) );
		}

		return [
			'vars'   => $vars,
			'notes'  => $notes,
			'colors' => $colors,
		];
	}

	/**
	 * 頁面底色那一組：預設深藍回 null；淺色背景的字色寫在 CSS 檔，只有背景圖讓字不夠清楚時才覆寫；
	 * 自訂顏色依顏色算出字色與遮色。
	 *
	 * 字要對 surroundings() 列的每一種底色都達標（標語、淡色字 4.5:1，焦點框 3:1）。
	 *
	 * @return null|array{against: array<int, int[]>, light: bool, vars: array<string, string>, notes: string[]}
	 */
	private static function page_colors( string $background, string $background_color, bool $has_image ): ?array {
		if ( 'light' === $background ) {
			$from    = self::rgb( self::LIGHT_BG[0] );
			$to      = self::rgb( self::LIGHT_BG[1] );
			$against = self::surroundings( $from, $to, true, $has_image, self::IMAGE_SHOW_FROM );
			$text    = self::fit_text( self::rgb( self::TEXT_DARK ), self::rgb( self::LIGHT_SOFT ), self::rgb( self::LIGHT_MUTED ), $against );
			$focus   = self::darken_until( self::rgb( self::DEFAULT_PRIMARY ), $against, 3.0 );
			$vars    = [];
			// 沒有背景圖時，CSS 檔那一組本來就達標，這裡什麼都不覆寫（淺色背景不輸出內嵌樣式）。
			if ( self::LIGHT_SOFT !== self::hex( $text['soft'] ) ) {
				$vars['--ys-login-on-dark-soft'] = self::hex( $text['soft'] );
			}
			if ( self::LIGHT_MUTED !== self::hex( $text['muted'] ) ) {
				$vars['--ys-login-on-dark-muted'] = self::hex( $text['muted'] );
			}
			if ( self::DEFAULT_PRIMARY !== self::hex( $focus ) ) {
				$vars['--ys-login-focus-on-dark'] = self::hex( $focus );
			}
			return [
				'against' => $against,
				'light'   => true,
				'vars'    => $vars,
				'notes'   => $text['notes'],
			];
		}
		if ( 'custom' !== $background || '' === $background_color ) {
			return null;
		}

		$from = self::rgb( $background_color );
		$to   = self::mix( $from, [ 0, 0, 0 ], 0.08 );
		// 白字或深色字：哪一種對底色（漸層兩端與光暈）的最低對比比較高就用哪一種（交界大約在相對亮度 0.2）。
		// 用固定的亮度門檻（例如 0.4）會讓 #60A5FA 這類中間偏亮的顏色配上白字，只有 2.5:1。
		$plain = self::surroundings( $from, $to, false, false, 0.0 );
		$light = self::min_contrast( self::rgb( self::TEXT_DARK ), $plain ) > self::min_contrast( self::rgb( self::TEXT_WHITE ), $plain );

		$against = self::surroundings( $from, $to, $light, $has_image, self::IMAGE_SHOW_FROM );
		$text    = $light
			? self::fit_text( self::rgb( self::TEXT_DARK ), self::rgb( self::LIGHT_SOFT ), self::rgb( self::LIGHT_MUTED ), $against )
			: self::fit_text( self::rgb( self::TEXT_WHITE ), self::rgb( '#e6ecf1' ), self::rgb( '#94a3b8' ), $against );

		$vars = [
			'--ys-login-bg-from'       => self::hex( $from ),
			'--ys-login-bg-to'         => self::hex( $to ),
			'--ys-login-on-dark'       => self::hex( $text['strong'] ),
			'--ys-login-on-dark-soft'  => self::hex( $text['soft'] ),
			'--ys-login-on-dark-muted' => self::hex( $text['muted'] ),
			'--ys-login-focus-on-dark' => self::hex(
				$light
					? self::darken_until( self::rgb( self::DEFAULT_PRIMARY ), $against, 3.0 )
					: self::lighten_until( self::rgb( '#5cbcee' ), $against, 3.0 )
			),
			'--ys-login-glow-blue'     => self::rgba( self::rgb( '#0085db' ), $light ? 0.12 : 0.2 ),
			'--ys-login-overlay-from'  => self::rgba( $from, 0.92 ),
			'--ys-login-overlay-to'    => self::rgba( $to, 0.94 ),
		];
		if ( $light ) {
			$vars['--ys-login-card-border'] = '#dfe6ee';
			$vars['--ys-login-card-shadow'] = '0 20px 40px -16px rgba(17, 28, 45, 0.16)';
		}

		return [
			'against' => $against,
			'light'   => $light,
			'vars'    => $vars,
			'notes'   => $text['notes'],
		];
	}

	/**
	 * 標語（soft）與淡色字（muted：頁尾、卡片下方的連結）對每一種底色都要 4.5:1，不夠就往大標的顏色靠。
	 * 中間色連大標的顏色都到不了 4.5:1，這時在設定頁提醒換顏色。
	 *
	 * @param int[]             $strong
	 * @param int[]             $soft
	 * @param int[]             $muted
	 * @param array<int, int[]> $against
	 * @return array{strong: int[], soft: int[], muted: int[], notes: string[]}
	 */
	private static function fit_text( array $strong, array $soft, array $muted, array $against ): array {
		$soft_ok  = self::toward_until( $soft, $strong, $against, 4.5 );
		$muted_ok = self::toward_until( $muted, $strong, $against, 4.5 );
		$notes    = [];
		if ( self::min_contrast( $soft_ok, $against ) < 4.5 || self::min_contrast( $muted_ok, $against ) < 4.5 ) {
			$notes[] = __( '這個背景色上的字（標語、頁尾、返回首頁）對比不到 4.5:1，建議換一個更深或更淺的顏色。', 'ys-admin-menu' );
		}
		return [
			'strong' => $strong,
			'soft'   => $soft_ok,
			'muted'  => $muted_ok,
			'notes'  => $notes,
		];
	}

	/**
	 * 卡片外面的字背後可能出現的底色：
	 * - 漸層的兩端；
	 * - 寬螢幕左下的青色光暈疊上去（頁尾就在它上面）；
	 * - 有背景圖時，圖上最亮（白字時）或最暗（深色字時）的地方透過遮色露出來（起點最多透出 $image_from，終點 6%）。
	 * 右上的品牌藍光暈底下沒有字（字標、大標、標語都靠左），不算。
	 *
	 * @param int[] $from
	 * @param int[] $to
	 * @param bool  $light 字是深色（底是淺的）。
	 * @return array<int, int[]>
	 */
	private static function surroundings( array $from, array $to, bool $light, bool $has_image, float $image_from ): array {
		$worst = $light ? [ 0, 0, 0 ] : [ 255, 255, 255 ];
		$out   = [];
		foreach ( [ [ $from, $image_from ], [ $to, self::IMAGE_SHOW_TO ] ] as [ $end, $show ] ) {
			$bases = [ $end ];
			if ( $has_image ) {
				$bases[] = self::mix( $end, $worst, $show );
			}
			foreach ( $bases as $base ) {
				$out[] = $base;
				$out[] = self::mix( $base, self::GLOW_CYAN, self::GLOW_CYAN_ALPHA );
			}
		}
		return $out;
	}

	/* ─────────────────────────── 顏色計算（WCAG 2） ─────────────────────────── */

	/**
	 * @return int[] [r, g, b]
	 */
	public static function rgb( string $hex ): array {
		$hex = ltrim( $hex, '#' );
		return [ (int) hexdec( substr( $hex, 0, 2 ) ), (int) hexdec( substr( $hex, 2, 2 ) ), (int) hexdec( substr( $hex, 4, 2 ) ) ];
	}

	/**
	 * @param int[] $rgb
	 */
	public static function hex( array $rgb ): string {
		return sprintf( '#%02x%02x%02x', self::channel( $rgb[0] ), self::channel( $rgb[1] ), self::channel( $rgb[2] ) );
	}

	/**
	 * @param int[] $rgb
	 */
	private static function rgba( array $rgb, float $alpha ): string {
		return sprintf( 'rgba(%d, %d, %d, %s)', self::channel( $rgb[0] ), self::channel( $rgb[1] ), self::channel( $rgb[2] ), rtrim( rtrim( number_format( $alpha, 2, '.', '' ), '0' ), '.' ) );
	}

	private static function channel( $value ): int {
		return max( 0, min( 255, (int) round( (float) $value ) ) );
	}

	/**
	 * 相對亮度（sRGB）。
	 *
	 * @param int[] $rgb
	 */
	public static function luminance( array $rgb ): float {
		$lin = static function ( int $c ): float {
			$c = $c / 255;
			return $c <= 0.04045 ? $c / 12.92 : ( ( $c + 0.055 ) / 1.055 ) ** 2.4;
		};
		return 0.2126 * $lin( $rgb[0] ) + 0.7152 * $lin( $rgb[1] ) + 0.0722 * $lin( $rgb[2] );
	}

	/**
	 * @param int[] $a
	 * @param int[] $b
	 */
	public static function contrast( array $a, array $b ): float {
		$la = self::luminance( $a );
		$lb = self::luminance( $b );
		return ( max( $la, $lb ) + 0.05 ) / ( min( $la, $lb ) + 0.05 );
	}

	/**
	 * @param int[]             $color
	 * @param array<int, int[]> $against
	 */
	private static function min_contrast( array $color, array $against ): float {
		$min = INF;
		foreach ( $against as $bg ) {
			$min = min( $min, self::contrast( $color, $bg ) );
		}
		return $min;
	}

	/**
	 * a 往 b 混 t（0～1）。
	 *
	 * @param int[] $a
	 * @param int[] $b
	 * @return int[]
	 */
	public static function mix( array $a, array $b, float $t ): array {
		return [
			self::channel( $a[0] + ( $b[0] - $a[0] ) * $t ),
			self::channel( $a[1] + ( $b[1] - $a[1] ) * $t ),
			self::channel( $a[2] + ( $b[2] - $a[2] ) * $t ),
		];
	}

	/**
	 * 往 target 一次混 5%，直到對 against 裡每個顏色都達到 ratio（最多 20 步）。
	 *
	 * @param int[]             $color
	 * @param int[]             $target
	 * @param array<int, int[]> $against
	 * @return int[]
	 */
	private static function toward_until( array $color, array $target, array $against, float $ratio ): array {
		$out = $color;
		for ( $i = 0; $i <= 20 && self::min_contrast( $out, $against ) < $ratio; $i++ ) {
			$out = self::mix( $color, $target, min( 1.0, ( $i + 1 ) * 0.05 ) );
		}
		return $out;
	}

	/**
	 * @param int[]             $color
	 * @param array<int, int[]> $against
	 * @return int[]
	 */
	private static function darken_until( array $color, array $against, float $ratio ): array {
		return self::toward_until( $color, [ 0, 0, 0 ], $against, $ratio );
	}

	/**
	 * @param int[]             $color
	 * @param array<int, int[]> $against
	 * @return int[]
	 */
	private static function lighten_until( array $color, array $against, float $ratio ): array {
		return self::toward_until( $color, [ 255, 255, 255 ], $against, $ratio );
	}

	private static function ratio_text( float $ratio ): string {
		return number_format( floor( $ratio * 10 ) / 10, 1, '.', '' );
	}

	/* ─────────────────────────── 設定頁 ─────────────────────────── */

	/**
	 * 儲存（由 YSLoginPage::save_settings 呼叫，權限與 nonce 已經驗過）。
	 *
	 * @param array<string, mixed> $post 已 wp_unslash 的 $_POST。
	 */
	public static function save_settings( array $post ): void {
		if ( ! YSAccess::can_manage() ) {
			return;
		}
		$background = isset( $post['login_background'] ) && is_string( $post['login_background'] ) && in_array( $post['login_background'], self::BACKGROUNDS, true )
			? $post['login_background']
			: '';

		update_option( self::OPTION_PRIMARY_COLOR, self::normalize_hex( $post['login_primary_color'] ?? '' ), false );
		update_option( self::OPTION_BACKGROUND, $background, false );
		update_option( self::OPTION_BACKGROUND_COLOR, self::normalize_hex( $post['login_background_color'] ?? '' ), false );
		update_option( self::OPTION_WORDMARK, self::clean_wordmark( $post['login_wordmark'] ?? '' ), false );
	}

	/**
	 * 設定頁需要的值與說明（實際用色、自動調整了什麼）。
	 *
	 * @return array<string, mixed>
	 */
	public static function settings_view(): array {
		$derived = self::derive( self::get_primary(), self::get_background(), self::get_background_color(), self::has_image() );
		return [
			'primary'          => self::get_primary(),
			'primary_default'  => self::DEFAULT_PRIMARY,
			'background'       => self::get_background_setting(),
			'background_color' => self::get_background_color(),
			'wordmark'         => self::get_wordmark_setting(),
			'colors'           => $derived['colors'],
			'notes'            => $derived['notes'],
		];
	}
}
