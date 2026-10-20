# YS Admin Menu

WordPress 後台選單管理工具 — wp-admin 原生選單的拖拉排序、隱藏、重新命名、改色、角色權限、個別使用者覆寫、直接網址防護，外加原生選單樣式美化與白牌。

> 從 YS CART 抽離的獨立工具，可單獨安裝，**不依賴** YS CART。

## 功能

| 模組 | 說明 |
|------|------|
| **選單編輯** | wp-admin 左側選單拖拉排序、隱藏／顯示、重新命名、改色、插入分隔列（頂層與子選單皆可管理）；子選單可「移到主選單」或「複製到主選單」（原位置保留，複製項可另外排序、改名、換圖示、隱藏、刪除） |
| **權限控管** | 依角色顯示／隱藏選單、針對個別使用者設定白名單覆寫；被隱藏的選單頁直接輸入網址也會回 404，工具列上的連結一併拿掉 |
| **原生選單樣式** | 美化側欄與 admin bar（背景／文字／圖示／hover 配色、字級、行高），含即時預覽，套用至全站 wp-admin。預設啟用：桌機寬版的側欄寬度為 300px，子選單改為點箭頭收放；側欄展開時子選單文字對齊上一層名稱 |
| **白牌** | 上傳 LOGO 作為頂層選單圖示、左側選單最上方的 LOGO 區塊、自訂 wp-admin 頁尾文字、隱藏 WordPress 標誌、後台背景色；管理 Admin Bar 節點（後台／前台各自隱藏）與前台 Admin Bar 載入方式（含角色限定） |
| **自訂登入頁** | wp-login.php 改成 YS CRM 登入頁的樣子：接近黑的深藍底、左側品牌區、右側白色大圓角登入卡片、膠囊形欄位與按鈕（標題／副標／標語／LOGO／背景圖／頁尾文字可設定，一個總開關控制；v1.6.0 起另可設定主色、背景、左上字標）。只改外觀與文字，不改登入流程；登入網址被其他外掛改掉（例如 WPS Hide Login）也照樣套用 |
| **登入防護** | 整合 Cloudflare Turnstile：在 wp-login.php 的登入（可另涵蓋註冊、忘記密碼）表單加入人機驗證，伺服器端向 Cloudflare 驗證；Cloudflare 判定失敗時擋下，連不到時可選寬鬆（放行並記錄）或嚴格（擋下）。不含 XML-RPC 與前台自訂登入表單。v1.6.0 起另有：登入次數限制（只鎖網路位址、涵蓋走 WordPress 登入流程的登入與 XML-RPC）、訪客 IP 偵測（六種方式）、「關閉 XML-RPC 登入」與「停用應用程式密碼」兩個開關，全部預設關閉 |

## 安裝

1. 將 `ys-admin-menu` 整個資料夾上傳至 `wp-content/plugins/`。
2. 於「外掛」頁面啟用「YS Admin Menu」。
3. 後台左側出現「YS 選單管理」頂層選單。

## 設定

- **選單與權限**：三個分頁 —「主選單（頂層）」、「全部選單（含子選單）」、「使用者覆寫」。可拖拉排序、限制角色可見、改色、勾選隱藏、插入分隔列；並可針對個別使用者設定可見選單白名單。
  - 「使用者覆寫」的「僅允許存取受保護的管理選單」（v1.5.1 起照字面）：這位使用者只看得到、進得去 YS CART 的頁面（ys-cart、ys-cart-*、ys-ec-*、ys-provider-*，以及 YS CART 自己登錄的頁面，含 YS CART 附加外掛登記給 YS CART 的頁面，例如分潤）、本外掛的頁面，與 `ys_admin_menu_extra_admin_slugs`、`ys_admin_menu_external_admin_pages` 這兩個 filter 指定的頁面；綁給他的「僅限本人」選單照樣看得到。控制台、個人資料，以及 Rank Math 這類跟 YS CART 無關的外掛頁面都進不去。沒裝 YS CART 的站請改用可見清單。
- **子選單移到／複製到主選單**（v1.3.0 起；「全部選單（含子選單）」分頁，每個子選單列右側的按鈕）：
  - **移到主選單**：子選單變成頂層選單，原位置移除；等同勾選「升頂層」或把該列拖到「提升區」，再按一次「移回原位」還原。外部網址的子選單不提供「移到主選單」（v1.5.1 起，以前存下的也照原位顯示），要放上主選單請用「複製到主選單」。
  - **複製到主選單**：原位置保留，主選單另外多一個連到同一頁的項目（也可以把該列拖到「複製區」）。同一個子選單只能複製一次。儲存後到「主選單（頂層）」分頁，複製項標示「複製」，可排序、改名、換圖示、套色、限制角色、隱藏或刪除；刪除複製項不影響原子選單。複製項的角色、隱藏、僅限本人只管複製項，原子選單照它自己的規則（v1.5.1 起；外部網址的子選單以前會連帶套到原子選單）。「設定 → 一般」這類父選單預設頁也可以複製。
  - 兩者的連結都用原生父層組合的網址，頁面、權限、外掛的頁面掛鉤照舊。原子選單看得到、進得去的帳號才看得到複製項。
  - 高亮規則：同一頁只標一個目前所在。原位置優先（原父選單展開、原子選單標為目前頁）；原父選單對這個帳號隱藏時改標複製項。
- **原生選單樣式**：自訂 wp-admin 側欄與 admin bar 的顏色、字級、行高，支援即時預覽與一鍵重設。
- **白牌設定**（四個分頁）：
  - **後台品牌**：上傳 LOGO（取代頂層選單圖示、也可放在左側選單最上方）、後台頁尾文字、後台背景色、資料清除設定。
  - **Admin Bar**：隱藏左上角 WordPress 標誌；逐一設定各節點在後台／前台要不要隱藏；前台 Admin Bar 的載入方式（照預設／完全不載入／套用前台隱藏清單）與角色限定。「隱藏」只是工具列上看不到，不是停用功能。
  - **登入頁**：啟用自訂登入頁，設定標題、副標、標語、品牌名稱、LOGO（沒有 LOGO 時左上角放網站標題）、背景圖、頁尾文字、「返回首頁」連結與「顯示語言切換」（預設不顯示 WordPress 的語言選單）。v1.6.0 起的「樣式」卡片：主色、背景（預設深藍、淺色、自訂顏色）、左上字標；三項都留空時跟 1.5.2 一樣。
  - **登入防護**：v1.6.0 起依序是「登入次數限制」「登入入口」「Cloudflare Turnstile」與收合的「進階：訪客 IP」，表單下方是「鎖定與紀錄」（見下面幾節）。Turnstile：填入 Site Key 與 Secret Key，選擇套用範圍與「連不到 Cloudflare 時」的處理方式，並可當場「測試設定」。

### 配合 YS CART V3 的選單分組（v1.5.0 起）

- YS CART V3 把幾個選單換到新的分組（會員等級、購物金設定／總覽／規則搬到「顧客」，購物金活動搬到「優惠促銷」，運輸標籤搬到「訂單」），並拆掉「購物金」頂層。升級後第一次打開後台，本外掛會把這幾個選單已存的順序、自訂名稱、移到主選單、複製到主選單搬到新位置。附加外掛（物流、分潤、內容存取等）的頁面不在 YS CART 的搬家清單裡，WordPress 選單上的位置不變，本外掛也不動它們的設定。
- 原本因為看不到上層選單而看不到這幾頁的角色或帳號，搬家後照樣看不到（只加不減）：看不到「購物金」的不會在「顧客」「優惠促銷」底下看到購物金的頁面，看不到「促銷活動」的不會在「顧客」底下看到「會員等級」，看不到「金物流管理」的不會在「訂單」底下看到「運輸標籤」，看不到「商店設定」的不會在「顧客」底下看到「會員生日」的連結；這些角色或帳號直接開網址或從頁面裡的連結也會被擋。上層選單的「僅限本人」會帶到搬走的子選單；綁定的帳號原本就看不到那一頁的，再加上隱藏（到「全部選單」調整之前，沒有任何帳號看得到或打得開那一頁）。「購物金」頂層自己的隱藏、角色、「僅限本人」，在「購物金設定」變成子選單後照樣有效。
- 搬家的對照由 YS CART 提供（`YSAdminNavigationRegistry::moves()`），本外掛只讀不改 YS CART 的資料。
- 「顧客」底下的「會員生日」照它連到的「商店設定」判斷誰看得到；「使用者覆寫」的可見清單不另外列它，勾「商店設定」就看得到。「商店設定」自己底下的分頁連結照 1.4.0。
- 搬完後台會提醒一次（只給管理員，可關掉），列出要你確認的帳號與頁面。使用者覆寫的可見清單不會自動改。
- 搬家之前就打開的「選單與權限」設定頁，按「儲存設定」會請你重新整理再存，不會把搬好的設定寫回舊分組。
- 遷移前的設定備份在 `ys_admin_menu_config_pre_moves_b1`（最後一段是 YS CART 的搬家版本）。沒有自動還原；要還原時把備份的值寫回 `ys_admin_menu_config`。
- 本外掛停用期間 YS CART 已經升到 V3、自己搬過一次選單設定的站：啟用本外掛時，只要本外掛自己還沒搬過這一批，帳號設定（使用者覆寫、舊版只允許受保護選單的帳號清單）照 YS CART 現在那份帶過來；選單每一列的設定（順序、名稱、顏色、上層與層級、移到主選單、複製到主選單、隱藏與角色等規則，連同哪些列存在）改用 YS CART 搬家前存下的那份（`ys_ec_menu_config_pre_moves_b1`，只讀），照本外掛的規則重新搬一次再同步回去。升級 V3 之後在 YS CART 改的選單設定（包括 V3 的「全部隱藏」等新規則）不會帶過來；換掉之前 YS CART 原本那份會原樣另存，查看：`wp option get ys_admin_menu_core_copy_pre_activation`。本外掛已經搬過、或讀不到 YS CART 的搬家標記與備份時照 1.4.0 沿用 YS CART 那份；裡面有 V3 的新可見規則（全部隱藏、隱藏指定角色、僅限操作本人）時，本外掛看不懂這些規則，後台會提醒一次並列出選單。
- 多站網路「網路啟用」時，只有主站走上面這段啟用時的處理，其他站照各自留著的本外掛設定遷移。
- V3 上把「商店設定」底下的分頁連結移到主選單時，「使用者覆寫」的可見清單仍把它列在商店設定底下，勾了沒作用：它跟著「商店設定」判斷。
- WP-CLI（只讀）：`wp ys-admin-menu moves status` 看遷移狀態、`wp ys-admin-menu moves dry-run` 預覽會改哪些設定列。
- 搭配舊版 YS CART 或沒有 YS CART 時不會做任何事，側欄與直接開網址都跟 1.4.0 一樣。

### 登入防護（Cloudflare Turnstile）

- 啟用開關、Site Key、Secret Key 都填好才會生效；少一個就不顯示、也不擋登入。
- 後端驗證掛在 `authenticate`、早於帳號密碼檢查。**Cloudflare 有回應且判定失敗時擋下**（沒完成驗證、token 無效或用過、Secret Key 錯誤，以及重試後仍是 `internal-error`——寬鬆模式也擋）；**連不到 Cloudflare 時依模式處理**：寬鬆（預設）放行並記錄、嚴格擋下。擋下時不因帳號是否存在而給出不同回應。
- **只涵蓋 wp-login.php 的表單送出**（含送到 wp-login.php 的前台 `wp_login_form()`）；不含 XML-RPC、REST API、WooCommerce「我的帳號」等前台自訂登入表單，也不含社群登入／SSO 在 wp-login.php 的 GET 回呼。
- **站台設定擋住了對 Cloudflare 的連線**（`WP_HTTP_BLOCK_EXTERNAL` 沒放行 challenges.cloudflare.com、主機防火牆、攔截 HTTP 請求的外掛）時，Turnstile 實際上沒有作用：設定頁顯示「目前實際沒有作用」，後台對管理員常駐提醒；設定頁也會顯示最後一次驗證成功的時間。
- **一個客戶站一個 widget**：在 Cloudflare 為每個站建立自己的 Turnstile widget、設好網域白名單，不要多站共用同一組金鑰。需要額外比對網域時，用 `ys_admin_menu_turnstile_expected_hostnames` filter 回傳網域清單（預設不比對）。
- **隱私提醒**：啟用後，登入頁與前台使用 `wp_login_form()` 的頁面都會載入 Cloudflare 的腳本（challenges.cloudflare.com）。
- Secret Key 不會回填、不顯示、不寫進紀錄或匯出檔；**刪除外掛時一律刪除 Secret Key**（不論有沒有勾「刪除外掛時一併清除所有設定」，多站網路逐站刪除；其他設定照勾選保留或清除）。送往 Cloudflare 的 IP 預設只用 `REMOTE_ADDR`（反向代理可用 `ys_admin_menu_turnstile_remote_ip` filter 覆寫）；v1.6.0 起勾了「Turnstile 也使用偵測到的 IP」才改用「進階：訪客 IP」偵測到的位址，沒勾的站不管存了哪個分頁都照舊。
- **被鎖在外面時**：在 wp-config.php 加 `define( 'YS_ADMIN_MENU_TURNSTILE_DISABLED', true );`，或執行 `wp option update ys_admin_menu_turnstile_enabled no`。

### 登入次數限制（v1.6.0 起，預設關閉）

- 在「登入防護」分頁的「登入次數限制」卡片打開。打開後的預設：同一個網路位址 15 分鐘內失敗 10 次，鎖 30 分鐘；24 小時內第 3 次被鎖起，改鎖 24 小時（「逐次加長」）。次數與時間都可以調。
- **只鎖網路位址，不鎖帳號**：別人故意輸錯，也不會把管理員的帳號鎖住。IPv6 以 /64 為一個單位；「IPv6 也以 /48 彙總計算」（預設關）另外合計同一個 /48 的失敗，門檻是 5 倍、到了就鎖整個 /48。
- **涵蓋的登入**：走 WordPress 登入流程（`wp_authenticate()`）的登入——wp-login.php（含被其他外掛改過的登入網址）、前台呼叫 `wp_signon()` 的登入表單、XML-RPC。用應用程式密碼的 REST 請求在鎖定期間也擋，失敗不計數。
- 被鎖住時，密碼對不對、帳號存不存在，看到的訊息都一樣；**已經登入的人不受影響**。同一個人打錯又登入成功，只原諒他自己帳號的那幾次，猜別人帳號的照算。
- **分不出訪客位址的登入併成一桶**：站在代理或 Cloudflare 後面、「進階：訪客 IP」還沒設好時，失敗會記到同一個共用的桶（門檻 5 倍、不逐次加長），設定頁與後台會提醒。純內網、前面沒有代理的站可以打開「內網位址逐一計數」。
- **計數有上限**：計數空間滿了、或同一個網段的位址太多時，新來的位址改用它的網段（IPv4 /24、IPv6 /48）一起計算；網段多到連這個也放不下時，超出的部分只計數、不擋人，後台紅字提醒。
- **拒絕從其他網站送來的登入（建議）**：登入次數限制底下的子開關，打開總開關時預設勾選。別的網站可以讓訪客的瀏覽器替它送出錯誤的登入、把訪客的 IP 鎖住；打開後這種登入直接拒絕、不計數。
  - 剛打開的頭 24 小時只記錄、不拒絕；要提早開始按「現在就開始拒絕」。
  - YS CART headless 前台設定的來源與 WordPress 的 `allowed_http_origins` 自動允許。其他前台請加進「允許跨站登入的來源」（一行一個 `https://主機`，可帶埠；本機開發可以用 `http://localhost`），或在「鎖定與紀錄」被拒的來源旁按「允許這個來源」。
  - **前台在另一個網域、用 JWT 或 WPGraphQL 這類回傳 token 的方式登入**時，觀察期過後這些從瀏覽器送來的登入會被擋，要把前台的網址加進允許清單。
  - YS CART 的前台登入會把被擋的原因一律顯示成「帳號或密碼錯誤」，請看「鎖定與紀錄」與後台提醒。
- **鎖定與紀錄**（設定表單下方）：目前鎖定中的位址（解鎖、全部解鎖）、最近 7 天被拒的跨站登入來源、最近的紀錄（可以只看某一個 IP）、清空紀錄、把你目前的 IP 加進白名單（IPv6 加它的 /64）。每個動作都要有管理網站的權限。
- **通知**（預設關）：建立一次鎖定就寄一封純文字信給網站管理員（或你指定的信箱）。同一個位址 24 小時內一封，全站每 24 小時最多十封；信裡只列站上存在的帳號，不存在的只寫個數。
- **後台提醒**（只給管理員）：資料表建不起來、IP 偵測可能沒設好、計數空間用完、通知信寄不出去、清理出錯。
- **不會把自己鎖在外面**：
  - WP-CLI：`wp ys-admin-menu login-guard unlock 你的IP`、`unlock --all`、`disable`。
  - 在 wp-config.php 加 `define( 'YS_ADMIN_MENU_LOGIN_GUARD_DISABLED', true );`，整個登入次數限制不作用（「登入入口」的兩個開關不受影響）。
  - 白名單（一行一個 IP 或 CIDR，最多 200 行）：白名單裡的位址不計數、不鎖，從那裡來的登入沒有這一層保護。
- **資料**：三張自己的資料表——計數（`{prefix}ys_admin_menu_login_guard`）、「鍵＋帳號」（`{prefix}ys_admin_menu_login_guard_acct`，帳號只存使用者 ID 的雜湊）、紀錄（`{prefix}ys_admin_menu_login_log`）。紀錄裡有 IP 與嘗試的帳號名稱，只有管理員看得到，保留天數可設（預設 30 天，紀錄最多 50,000 筆）。建表只在管理員打開後台、外掛啟用、WP-CLI 時做，建不起來時照常可以登入。
- 2FA 外掛「需要第二步驗證」的錯誤會被算成一次失敗時，可以用 `ys_admin_menu_login_guard_should_count` filter 排除。

### 訪客 IP 偵測（v1.6.0 起）

- 「登入防護」分頁的「進階：訪客 IP」（預設收合）。登入次數限制用它判斷訪客是誰；Turnstile 只有勾了「Turnstile 也使用偵測到的 IP」才用它。
- 六種方式：自動（預設）、只用 REMOTE_ADDR、Cloudflare、X-Forwarded-For、X-Real-IP、自訂標頭。
  - **自動就是 REMOTE_ADDR**，不猜標頭；打開「信任 Cloudflare」時，從 Cloudflare 範圍來的請求才改用 CF-Connecting-IP。
  - 標頭模式只在 REMOTE_ADDR 是你加入的「信任的代理」時才看標頭；X-Forwarded-For 從右往左讀、跳過信任的代理，遇到不合法的段落就停。私有位址不會自動被信任。
- **「信任 Cloudflare」預設關**。只有原站只收自己這個 Cloudflare zone 的流量時才安全（例如 Authenticated Origin Pulls 或 Cloudflare Tunnel）；只把防火牆開給 Cloudflare 的 IP 不夠，其他 Cloudflare 客戶也從那些 IP 連進來。其他網站的 Cloudflare Worker 送來的請求不採用 CF-Connecting-IP。
- 診斷表列出這一次請求的各個標頭、每種選項會得到什麼 IP，並可以一鍵「信任這個代理」。改設定之前也可以用 `wp ys-admin-menu ip check --remote-addr=10.0.0.2 --header="X-Forwarded-For: 9.9.9.9, 10.0.0.5"` 試算。
- 內建的 Cloudflare 範圍隨外掛版本更新，不會自動下載；`wp ys-admin-menu ip cloudflare-check` 手動比對官方清單、只印差異，需要時用 `ys_admin_menu_ip_cloudflare_ranges` filter 調整。

### 登入入口（v1.6.0 起，預設都關）

- 「登入防護」分頁的「登入入口」卡片，兩個獨立開關，跟登入次數限制的總開關無關：
  - **關閉 XML-RPC 登入**：需要帳密的 XML-RPC 方法一律失敗，其他照常。
  - **停用應用程式密碼**：已建立的應用程式密碼保留，關掉就恢復。
- WP-CLI：`wp ys-admin-menu login-guard xmlrpc on|off`、`wp ys-admin-menu login-guard app-passwords on|off`。

### WP-CLI（v1.6.0 起）

| 指令 | 做什麼 |
|------|--------|
| `wp ys-admin-menu login-guard status [--repair]` | 開關、停用常數、資料表、IP 偵測、有沒有正常作用與原因、鎖定中幾個、最近的失敗與提醒；`--repair` 重新建表 |
| `wp ys-admin-menu login-guard list [--locked] [--ip=9.9.9.9]` | 列出計數或鎖定中的位址 |
| `wp ys-admin-menu login-guard unlock 9.9.9.9`、`unlock 2001:db8::1`、`unlock via:internal`、`unlock --all` | 解除鎖定；IPv6 位址解它的 /64，打開 /48 彙總時一併解它的 /48；共用的桶用 `via:` 加名稱 |
| `wp ys-admin-menu login-guard allowlist list`、`allowlist add 203.0.113.0/24`、`allowlist remove 203.0.113.0/24` | 白名單（跟設定頁同一套驗證） |
| `wp ys-admin-menu login-guard enable`、`disable` | 總開關；`enable` 會建表 |
| `wp ys-admin-menu login-guard cleanup` | 立刻清理 |
| `wp ys-admin-menu login-guard log [--since=24h] [--ip=9.9.9.9]` | 最近的紀錄 |
| `wp ys-admin-menu login-guard purge --drop-tables [--yes]` | 刪掉三張資料表與內部用的鍵（降版前用）；沒帶 `--yes` 只印會刪什麼 |
| `wp ys-admin-menu ip check`、`wp ys-admin-menu ip cloudflare-check` | 試算 IP 偵測、比對 Cloudflare 清單（都不改設定） |

輸出裡從資料庫來的帳號名稱、來源會先清掉控制字元。

### 已知限制（v1.6.0 的登入防護）

- IPv6 位址很容易大量取得。攻擊分散在大量 IPv6 網段時，登入次數限制只能把每個網段的猜測速度壓低，沒辦法單獨擋住。請搭配 Cloudflare Turnstile 或 WAF。
- 本外掛的 Turnstile 只保護 wp-login.php，不保護 XML-RPC、REST 與前台表單；不需要 XML-RPC 時，建議打開「關閉 XML-RPC 登入」。
- 不經過 `wp_authenticate()` 的密碼驗證不在範圍內，例如外掛自己直接比對密碼的登入（YS CART headless 的 token 端點）；社群登入、單一登入、忘記密碼、註冊不計數。
- 站在代理或 Cloudflare 後面、「進階：訪客 IP」沒設好時，所有人併成同一桶：有人在那條路上猜到門檻時，從那條路登入的人會一起暫停（已經登入的不受影響）。
- 打開「信任 Cloudflare」、而原站沒有限制只收自己這個 zone 的流量時，訪客的 IP 可以被偽造。
- 計數空間滿了之後，同一個網段裡在表裡沒有自己紀錄的人，會跟同網段的人一起算。
- 拒絕跨站登入時，同一個註冊網域底下的其他子網域照常放行；既不送 Sec-Fetch-Site 也不送 Origin 的很舊瀏覽器照常處理。
- 多站網路是各站分開計算。

### 解除安裝、停用與降版（v1.6.0）

- **刪除外掛時一律刪掉登入次數限制的三張資料表**、內部用的鍵與清理排程（裡面有 IP 與嘗試的帳號名稱），不論有沒有勾「刪除外掛時一併清除所有設定」；多站網路逐站刪。其他設定照勾選保留或清除。
- 停用外掛：取消每天的清理排程，資料表與設定都留著。
- **降版回 1.5.x 之前**，請先在 1.6.0 執行 `wp ys-admin-menu login-guard purge --drop-tables --yes`：1.5.x 的解除安裝不知道這三張表，之後在 1.5.x 刪外掛，紀錄會留在資料庫裡。不能用 WP-CLI 時，至少在「鎖定與紀錄」按「清空紀錄」（計數表裡每個位址最後一次的 IP 與帳號名稱還會留著）。

## 技術資訊

- **命名空間**：`YangSheep\AdminMenu`
- **設定儲存**（wp_options；v1.6.0 的登入次數限制另有三張資料表，見「登入次數限制」一節）：
  - `ys_admin_menu_config` — 選單與權限設定（JSON）。子選單複製到主選單存在 `top_copies.items[]`（每筆只記來源 `slug`、`parent_slug`）；複製項在主選單的排序、名稱、圖示（`icon`）等存在 `wp_native.items[]`，`slug` 是 `./` 開頭的選單代稱（例：`./options-writing.php`）
  - `ys_admin_menu_theme_config` — 原生選單樣式設定
  - `ys_admin_menu_logo_url` / `ys_admin_menu_footer_text` — 白牌
  - v1.5.0 YS CART 選單搬家：`ys_admin_menu_moves_applied`（遷移標記：版本、對照的 sha256、本外掛的規則版本、已套用的 `since:slug`、有沒有還沒關掉的提醒；自動載入。後台提醒只在它記著有提醒時才去讀遷移紀錄；沒有 YS CART V3 的站不會遷移，平常後台頁不會多查資料庫）、`ys_admin_menu_moves_log`（遷移紀錄與提醒）、`ys_admin_menu_moves_lock`（遷移時的暫時鎖，值是拿到鎖的請求自己的 token）、`ys_admin_menu_config_pre_moves_b1`（遷移前備份，最後一段是 YS CART 的搬家版本）、`ys_admin_menu_core_copy_pre_activation`（啟用時改用 YS CART 搬家前備份之前，另存的 YS CART 那份原字串），後四種不自動載入
- **YS CART 的搬家版本**：本外掛只認得 b1（`YSMenuConfigBridge::KNOWN_CORE_MOVES_VERSIONS`）。YS CART 的搬家版本換成 b1 以外的版本時，本外掛要同時更新，否則啟用時改回 1.4.0 的做法（沿用 YS CART 那份）。
- **REST**：`ys-admin-menu/v1`（權限：`manage_options` + `wp_rest` nonce + body size cap）
- **自動更新**：透過內嵌的 YS Plugin Hub Client（2.0.7；外掛市集走 WordPress REST，更新與下載只接受 yangsheep.com.tw 的網址；同站有其他版本時先載入的那份負責連線，「電商工具箱」選單的名稱與位置由 2.0.4 起的共用整理統一，本外掛有設定時先整理再套用設定）。它會定期向 yangsheep.com.tw 查詢更新（送出站台網址與站上 YS 外掛的版本），並建立兩張自己的資料表（`{prefix}ys_hub_client_logs`、`{prefix}ys_hub_client_settings`）
- **slug 比對**（v1.5.1）：存檔時 slug 經過 `sanitize_text_field()`，網址裡的 %xx 編碼會被拿掉。比對存下的設定與側欄上實際的 slug 時，兩邊都換成同一個鍵（`YSMenuRouter::slug_key()`：帶 %xx 的網址與 customize.php 的連結先還原 HTML 實體，再用同一個函式清理；customize.php 的 `return` 參數只留名稱、不比值）。實際 slug 存檔時不會被改掉的，照原字串比對；會被改掉的（帶 %xx、customize.php 帶 return）原字串對不上時才用鍵。存法不變。
- **防自我鎖死**：super admin（單站 administrator）不受隱藏、角色限制與使用者覆寫影響，避免把自己鎖在後台外。例外是「僅限本人」：綁定後連其他管理員也看不到該選單
- **隱藏不是權限**：隱藏與角色限制是整理介面用的。被隱藏的頂層選單底下的子頁要各自設定；真正要禁止的功能請調整角色的 capability

## 注意事項

- 本外掛與 YS CART 內建的選單管理功能為同一套機制（兩者皆在 `admin_menu` priority 9999 套用設定）。**同一網站建議擇一啟用**，避免兩套選單路由疊加衝突。
- 設定以 `ys_admin_menu_*` option 為準；每次儲存會同步一份到 `ys_ec_menu_config`，供 YS CART 核心在本外掛停用時接手。YS CART 核心接手時不會顯示「複製到主選單」的項目（其他設定照常）。本外掛停用、由 YS CART V3 接手時：副本裡只要有任何一列 YS 頂層選單（包含「YS CART 平台」、「電商工具箱」等平台列；在本外掛的「主選單（頂層）」分頁存過就會有），YS CART 就照這份設定的順序排側欄；從未存過的套用 YS CART 的預設順序：商家功能接在「控制台」之後，平台列接在「設定」之後。

## 作者

YANGSHEEP DESIGN — <https://yangsheep.com.tw>
