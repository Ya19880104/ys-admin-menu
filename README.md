# YS Admin Menu

WordPress 後台選單管理工具 — wp-admin 原生選單的拖拉排序、隱藏、重新命名、改色、角色權限、個別使用者覆寫、直接網址防護，外加原生選單樣式美化與白牌。

> 從 YS CART 抽離的獨立工具，可單獨安裝，**不依賴** YS CART。

## 功能

| 模組 | 說明 |
|------|------|
| **選單編輯** | wp-admin 左側選單拖拉排序、隱藏／顯示、重新命名、改色、插入分隔列（頂層與子選單皆可管理）；子選單可「移到主選單」或「複製到主選單」（原位置保留，複製項可另外排序、改名、換圖示、隱藏、刪除） |
| **權限控管** | 依角色顯示／隱藏選單、針對個別使用者設定白名單覆寫；被隱藏的選單頁直接輸入網址也會回 404，工具列上的連結一併拿掉 |
| **原生選單樣式** | 美化側欄與 admin bar（背景／文字／圖示／hover 配色、字級、行高），含即時預覽，套用至全站 wp-admin。預設啟用：桌機寬版的側欄寬度為 300px，子選單改為點箭頭收放 |
| **白牌** | 上傳 LOGO 作為頂層選單圖示、左側選單最上方的 LOGO 區塊、自訂 wp-admin 頁尾文字、隱藏 WordPress 標誌、後台背景色；管理 Admin Bar 節點（後台／前台各自隱藏）與前台 Admin Bar 載入方式（含角色限定） |
| **自訂登入頁** | wp-login.php 改成 YS CRM 的深藍品牌版面（標題／副標／標語／LOGO／背景圖／頁尾文字可設定，一個總開關控制）。只改外觀與文字，不改登入流程 |
| **登入防護** | 整合 Cloudflare Turnstile：在登入（可另涵蓋註冊、忘記密碼）表單加入人機驗證，伺服器端向 Cloudflare 驗證後才放行；連不到 Cloudflare 時可選寬鬆（放行並記錄）或嚴格（擋下） |

## 安裝

1. 將 `ys-admin-menu` 整個資料夾上傳至 `wp-content/plugins/`。
2. 於「外掛」頁面啟用「YS Admin Menu」。
3. 後台左側出現「YS 選單管理」頂層選單。

## 設定

- **選單與權限**：三個分頁 —「主選單（頂層）」、「全部選單（含子選單）」、「使用者覆寫」。可拖拉排序、限制角色可見、改色、勾選隱藏、插入分隔列；並可針對個別使用者設定可見選單白名單。
- **子選單移到／複製到主選單**（v1.3.0 起；「全部選單（含子選單）」分頁，每個子選單列右側的按鈕）：
  - **移到主選單**：子選單變成頂層選單，原位置移除；等同勾選「升頂層」或把該列拖到「提升區」，再按一次「移回原位」還原。
  - **複製到主選單**：原位置保留，主選單另外多一個連到同一頁的項目（也可以把該列拖到「複製區」）。同一個子選單只能複製一次。儲存後到「主選單（頂層）」分頁，複製項標示「複製」，可排序、改名、換圖示、套色、限制角色、隱藏或刪除；刪除複製項不影響原子選單。「設定 → 一般」這類父選單預設頁也可以複製。
  - 兩者的連結都用原生父層組合的網址，頁面、權限、外掛的頁面掛鉤照舊。原子選單看得到、進得去的帳號才看得到複製項。
  - 高亮規則：同一頁只標一個目前所在。原位置優先（原父選單展開、原子選單標為目前頁）；原父選單對這個帳號隱藏時改標複製項。
- **原生選單樣式**：自訂 wp-admin 側欄與 admin bar 的顏色、字級、行高，支援即時預覽與一鍵重設。
- **白牌設定**（四個分頁）：
  - **後台品牌**：上傳 LOGO（取代頂層選單圖示、也可放在左側選單最上方）、後台頁尾文字、後台背景色、資料清除設定。
  - **Admin Bar**：隱藏左上角 WordPress 標誌；逐一設定各節點在後台／前台要不要隱藏；前台 Admin Bar 的載入方式（照預設／完全不載入／套用前台隱藏清單）與角色限定。「隱藏」只是工具列上看不到，不是停用功能。
  - **登入頁**：啟用自訂登入頁，設定標題、副標、標語、品牌名稱、LOGO、背景圖、頁尾文字與「返回首頁」連結。
  - **登入防護**：啟用 Cloudflare Turnstile，填入 Site Key 與 Secret Key，選擇套用範圍與「連不到 Cloudflare 時」的處理方式，並可當場「測試設定」。

### 登入防護（Cloudflare Turnstile）

- 啟用開關、Site Key、Secret Key 都填好才會生效；少一個就不顯示、也不擋登入。
- 後端驗證掛在 `authenticate`、早於帳號密碼檢查；驗證沒過一定擋下，且不因帳號是否存在而給出不同回應。
- Secret Key 不會回填、不顯示、不寫進紀錄或匯出檔；送往 Cloudflare 的 IP 只用 `REMOTE_ADDR`（反向代理可用 `ys_admin_menu_turnstile_remote_ip` filter 覆寫）。
- **被鎖在外面時**：在 wp-config.php 加 `define( 'YS_ADMIN_MENU_TURNSTILE_DISABLED', true );`，或執行 `wp option update ys_admin_menu_turnstile_enabled no`。

## 技術資訊

- **命名空間**：`YangSheep\AdminMenu`
- **設定儲存**（wp_options；本外掛自己沒有資料表）：
  - `ys_admin_menu_config` — 選單與權限設定（JSON）。子選單複製到主選單存在 `top_copies.items[]`（每筆只記來源 `slug`、`parent_slug`）；複製項在主選單的排序、名稱、圖示（`icon`）等存在 `wp_native.items[]`，`slug` 是 `./` 開頭的選單代稱（例：`./options-writing.php`）
  - `ys_admin_menu_theme_config` — 原生選單樣式設定
  - `ys_admin_menu_logo_url` / `ys_admin_menu_footer_text` — 白牌
- **REST**：`ys-admin-menu/v1`（權限：`manage_options` + `wp_rest` nonce + body size cap）
- **自動更新**：透過內嵌的 YS Plugin Hub Client。它會定期向 yangsheep.com.tw 查詢更新（送出站台網址與站上 YS 外掛的版本），並建立兩張自己的資料表（`{prefix}ys_hub_client_logs`、`{prefix}ys_hub_client_settings`）
- **防自我鎖死**：super admin（單站 administrator）不受隱藏、角色限制與使用者覆寫影響，避免把自己鎖在後台外。例外是「僅限本人」：綁定後連其他管理員也看不到該選單
- **隱藏不是權限**：隱藏與角色限制是整理介面用的。被隱藏的頂層選單底下的子頁要各自設定；真正要禁止的功能請調整角色的 capability

## 注意事項

- 本外掛與 YS CART 內建的選單管理功能為同一套機制（兩者皆在 `admin_menu` priority 9999 套用設定）。**同一網站建議擇一啟用**，避免兩套選單路由疊加衝突。
- 設定以 `ys_admin_menu_*` option 為準；每次儲存會同步一份到 `ys_ec_menu_config`，供 YS CART 核心在本外掛停用時接手。YS CART 核心接手時不會顯示「複製到主選單」的項目（其他設定照常）。

## 作者

YANGSHEEP DESIGN — <https://yangsheep.com.tw>
