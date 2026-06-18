# YS Admin Menu

WordPress 後台選單管理工具 — wp-admin 原生選單的拖拉排序、隱藏、重新命名、改色、角色權限、個別使用者覆寫、直接網址防護，外加原生選單樣式美化與白牌。

> 從 YS CART 抽離的獨立工具，可單獨安裝，**不依賴** YS CART。

## 功能

| 模組 | 說明 |
|------|------|
| **選單編輯** | wp-admin 左側選單拖拉排序、隱藏／顯示、重新命名、改色、插入分隔列（頂層與子選單皆可管理） |
| **權限控管** | 依角色顯示／隱藏選單、針對個別使用者設定白名單覆寫、直接網址存取 404 防護 |
| **原生選單樣式** | 美化側欄與 admin bar（背景／文字／圖示／hover 配色、字級、行高），含即時預覽，套用至全站 wp-admin |
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
- **設定儲存**（wp_options，無自訂資料表）：
  - `ys_admin_menu_config` — 選單與權限設定（JSON）
  - `ys_admin_menu_theme_config` — 原生選單樣式設定
  - `ys_admin_menu_logo_url` / `ys_admin_menu_footer_text` — 白牌
- **REST**：`ys-admin-menu/v1`（權限：`manage_options` + `wp_rest` nonce + body size cap）
- **自動更新**：透過內嵌的 YS Plugin Hub Client
- **防自我鎖死**：super admin（單站 administrator）永不受自身的隱藏／白名單規則影響，避免把自己鎖在後台外

## 注意事項

- 本外掛與 YS CART 內建的選單管理功能為同一套機制（兩者皆在 `admin_menu` priority 9999 套用設定）。**同一網站建議擇一啟用**，避免兩套選單路由疊加衝突。
- 設定獨立儲存於 `ys_admin_menu_*` option，與 YS CART 的 `ys_ec_menu_config` 完全分開。

## 作者

YANGSHEEP DESIGN — <https://yangsheep.com.tw>
