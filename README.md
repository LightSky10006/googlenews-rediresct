# FreshRSS Google News Link Cleanup

[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)
[![GitHub last commit](https://img.shields.io/github/last-commit/Lightsky10006/xExtension-google_news_redirect)](https://github.com/your-username/xExtension-google_news_redirect)

一個 FreshRSS 擴充套件，自動清理和還原 Google News RSS 中隱藏的原始連結。

## 功能

**自動偵測** Google News RSS 連結  
**重定向** 跟蹤並提取原始 URL  
**備用解碼** Base64 編碼的 URL  

## 示例

| 來源連結 | 
|---------|
| `https://news.google.com/rss/articles/xxxx` |

## 快速開始

### 安裝步驟

1. **下載插件**
   ```bash
   cd /path/to/FreshRSS/extensions
   git clone https://github.com/Lightsky10006/googlenews-redirect.git
   ```

2. **啟用插件**
   - 進入 FreshRSS 管理後台
   - 前往 "設定" → "插件"
   - 尋找 "Google News RSS Link Cleanup" 並啟用

3. **重新加載 RSS**
   - 更新 Google News RSS 源
   - 連結將自動被清理

### 主要方法：HTTP 重定向

```
Google News URL
    ↓ (HTTP GET 請求)
Google 伺服器返回重定向
    ↓ (提取 Location 頭)
原始新聞網站 URL
    ↓
插件返回清理後的 URL
```
