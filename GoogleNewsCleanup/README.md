# Google News RSS Link Cleanup Plugin

這是一個 FreshRSS 的插件，用於自動清理 Google News RSS 源中的連結。

## 功能

- 自動偵測 Google News RSS 連結（`news.google.com/rss/articles/`）
- 發送 HTTP 請求並跟蹤重定向到原始連結
- 備用方法：Base64 解碼 Google 的編碼 URL 格式
- 移除 `?oc=5` 等追蹤參數

## 工作原理

### 主要方法（基於 Stack Overflow 解決方案）

Google News 會在你訪問 RSS 連結時重定向到原始文章：

```
GET https://news.google.com/rss/articles/CBMiXXXXX...?oc=5
    ↓ (HTTP 重定向)
GET https://original-news-site.com/article/...
    ↓
原始連結被提取
```

#### 工作流程

1. **發送請求** 到 Google News 連結
2. **捕獲重定向** （Location HTTP 頭）
3. **提取原始連結** 從響應中
4. **驗證** URL 的有效性

### 備用方法（Base64 解碼）

如果 HTTP 請求失敗，插件會嘗試：

1. **提取** 編碼部分（`CBMiXXXXX...`）
2. **Base64 解碼**（支持 Google 的 URL-safe base64）
3. **解析**二進制數據以提取原始 URL

## 安裝方式

### 方式 1: 直接複製文件

1. 將 `GoogleNewsCleanup` 文件夾複製到 FreshRSS 的 `plugins/` 目錄
   ```
   FreshRSS/plugins/GoogleNewsCleanup/
   ├── metadata.json
   ├── index.php
   └── README.md
   ```

2. 在 FreshRSS 設定中啟用此插件

### 方式 2: 使用 FreshRSS 插件管理器

1. 進入 FreshRSS 設定 → 插件
2. 搜尋並安裝 "Google News RSS Link Cleanup"

## 使用範例

### 原始連結（來自 Google News RSS）
```
https://news.google.com/rss/articles/CBMiK2h0dHBzOi8vemRuZXQuY28ua3Ivdmlldy8_bm89MjAyMzA0MTYxMTA1NDnSAQA?oc=5
```

### 清理後的連結
```
https://zdnet.co.kr/view/?no=20230416110549
```

## 技術細節

### HTTP 重定向方法

```php
// 發送請求並獲取 Location 頭
$headers = get_headers($url, 1);
$location = $headers['Location'];  // 原始連結
```

### Base64 編碼格式

Google 使用 URL-safe base64：
- 使用 `-` 代替 `+`
- 使用 `_` 代替 `/`

### 二進制數據結構

編碼的二進制數據包含：
- 文章元數據
- **原始 URL**（通過正則表達式提取）
- 時間戳
- 其他追蹤信息

## 配置

編輯 `metadata.json` 以自訂插件行為：

```json
{
  "name": "Google News RSS Link Cleanup",
  "description": "清理 Google News RSS 的連結，移除追蹤碼並還原原始連結",
  "version": "1.0.0",
  "author": "Your Name",
  "entrypoint": "index.php",
  "permissions": ["entry_url"],
  "type": "item"
}
```

## 常見問題

### Q: 插件無法正常工作怎麼辦？

A: 
1. 確保 FreshRSS 版本支持插件系統
2. 檢查 `metadata.json` 格式是否正確
3. 確認服務器允許出站 HTTP 請求（用於重定向跟蹤）
4. 查看 FreshRSS 日誌以獲取更多信息

### Q: 為什麼有些連結仍然沒被清理？

A: 
- 某些連結可能已經是原始形式
- Google News 可能在不同時間使用不同的編碼方式
- 網路連接問題導致 HTTP 請求失敗
- 插件會自動跳過無法解碼的連結，保留原始 URL

### Q: 插件如何影響性能？

A: 
- 插件只在 RSS 項目加載時運行
- HTTP 請求可能需要數百毫秒（可選擇禁用此功能）
- Base64 解碼非常快速
- 建議在低流量時段運行

### Q: 能否禁用 HTTP 請求以提高速度？

A: 
當前版本優先使用 HTTP 重定向方法。如需禁用，可編輯 `index.php` 並刪除 `extractOriginalUrlFromGoogleNews` 方法的前半部分。

## 參考資料

- [Stack Overflow - How can I have redirection link from google news link using requests?](https://stackoverflow.com/questions/76063646/how-can-i-have-redirection-link-from-google-news-link-using-requests)
- FreshRSS 插件文檔

## 更新日誌

### v1.0.1 (2025-11-07)
- 更新為使用 HTTP 重定向方法（基於 Stack Overflow 解決方案）
- 新增備用 Base64 解碼方法
- 改進錯誤處理

### v1.0.0 (2024-11-07)
- 初始版本發布
- 支持基本的 Google News 連結解碼
- 支持多種編碼格式

## 許可證

MIT License

## 貢獻

歡迎提交 Issue 和 Pull Request！

## 支持

如有問題，請在 GitHub 上提交 Issue，或直接聯繫作者。
