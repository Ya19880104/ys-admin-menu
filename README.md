# YS Admin Menu

WordPress 後台選單管理工具 — wp-admin 原生選單的拖拉排序、隱藏、重新命名、改色、角色權限、個別使用者覆寫、直接網址防護，外加原生選單樣式美化與白牌。

> 從 YS CART 抽離的獨立工具，可單獨安裝，**不依賴** YS CART。

## 功能

| 模組 | 說明 |
|------|------|
| **選單編輯** | wp-admin 左側選單拖拉排序、隱藏／顯示、重新命名、改色、插入分隔列（頂層與子選單皆可管理） |
| **權限控管** | 依角色顯示／隱藏選單、針對個別使用者設定白名單覆寫；被隱藏的選單頁直接輸入網址也會回 404，工具列上的連結一併拿掉 |
| **原生選單樣式** | 美化側欄與 admin bar（背景／文字／圖示／hover 配色、字級、行高），含即時預覽，套用至全站 wp-admin。預設啟用：桌機寬版的側欄寬度為 300px，子選單改為點箭頭收放 |
| **白牌** | 上傳 LOGO 作為頂層選單圖示、自訂 wp-admin 頁尾文字 |

## 安裝

1. 將 `ys-admin-menu` 整個資料夾上傳至 `wp-content/plugins/`。
2. 於「外掛」頁面啟用「YS Admin Menu」。
3. 後台左側出現「YS 選單管理」頂層選單。

## 設定

- **選單與權限**：三個分頁 —「主選單（頂層）」、「全部選單（含子選單）」、「使用者覆寫」。可拖拉排序、限制角色可見、改色、勾選隱藏、插入分隔列；並可針對個別使用者設定可見選單白名單。
- **原生選單樣式**：自訂 wp-admin 側欄與 admin bar 的顏色、字級、行高，支援即時預覽與一鍵重設。
- **白牌設定**：上傳 LOGO（取代頂層選單圖示）、設定後台頁尾文字。

## 技術資訊

- **命名空間**：`YangSheep\AdminMenu`
- **設定儲存**（wp_options；本外掛自己沒有資料表）：
  - `ys_admin_menu_config` — 選單與權限設定（JSON）
  - `ys_admin_menu_theme_config` — 原生選單樣式設定
  - `ys_admin_menu_logo_url` / `ys_admin_menu_footer_text` — 白牌
- **REST**：`ys-admin-menu/v1`（權限：`manage_options` + `wp_rest` nonce + body size cap）
- **自動更新**：透過內嵌的 YS Plugin Hub Client。它會定期向 yangsheep.com.tw 查詢更新（送出站台網址與站上 YS 外掛的版本），並建立兩張自己的資料表（`{prefix}ys_hub_client_logs`、`{prefix}ys_hub_client_settings`）
- **防自我鎖死**：super admin（單站 administrator）不受隱藏、角色限制與使用者覆寫影響，避免把自己鎖在後台外。例外是「僅限本人」：綁定後連其他管理員也看不到該選單
- **隱藏不是權限**：隱藏與角色限制是整理介面用的。被隱藏的頂層選單底下的子頁要各自設定；真正要禁止的功能請調整角色的 capability

## 注意事項

- 本外掛與 YS CART 內建的選單管理功能為同一套機制（兩者皆在 `admin_menu` priority 9999 套用設定）。**同一網站建議擇一啟用**，避免兩套選單路由疊加衝突。
- 設定以 `ys_admin_menu_*` option 為準；每次儲存會同步一份到 `ys_ec_menu_config`，供 YS CART 核心在本外掛停用時接手。

## 作者

YANGSHEEP DESIGN — <https://yangsheep.com.tw>
