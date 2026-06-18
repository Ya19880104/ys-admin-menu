# Changelog

本外掛所有重要變更皆記錄於此。

格式遵循 [Keep a Changelog](https://keepachangelog.com/zh-TW/1.0.0/)，版本號遵循 [語意化版本](https://semver.org/lang/zh-TW/)。

## [1.0.0] - 2026-06-17

### 新增
- **選單編輯**：wp-admin 左側選單拖拉排序、隱藏／顯示、重新命名、改色、插入分隔列（頂層與子選單）。
- **權限控管**：依角色顯示／隱藏選單、個別使用者白名單覆寫、直接網址存取 404 防護。
- **原生選單樣式**：側欄與 admin bar 配色、字級、行高自訂，含即時預覽與一鍵重設。
- **白牌**：上傳 LOGO 作為頂層選單圖示、自訂 wp-admin 頁尾文字。
- 整合 YS Plugin Hub Client 支援自動更新。

### 說明
- 本版自 YS CART 抽離既有後台選單管理子系統，重構為可獨立運作的外掛；視覺與互動沿用 YS CART 既有設計（ADR-049／050 design system）。
- 設定改用獨立的 `ys_admin_menu_*` option 儲存，與 YS CART 完全隔離。
