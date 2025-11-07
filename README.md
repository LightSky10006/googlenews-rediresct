# FreshRSS Google News Link Cleanup Plugin

[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)
[![GitHub last commit](https://img.shields.io/github/last-commit/Lightsky10006/xExtension-google_news_redirect)](https://github.com/your-username/xExtension-google_news_redirect)

一個強大的 FreshRSS 插件，自動清理和還原 Google News RSS 中隱藏的原始連結。

[English](#-english) | [中文](#-中文)

## 🎯 功能特性

✅ **自動偵測** Google News RSS 連結  
✅ **智能重定向** 跟蹤並提取原始 URL  
✅ **備用解碼** Base64 編碼的 URL  
✅ **無縫集成** 直接在 FreshRSS 中使用  
✅ **零配置** 安裝後自動運作  

## 📸 使用示例

| 來源連結 | 清理後 |
|---------|--------|
| `https://news.google.com/rss/articles/CBMiK2h0dHBzOi8vemRuZXQuY28ua3Ivdmlldy8_bm89MjAyMzA0MTYxMTA1NDnSAQA?oc=5` | `https://zdnet.co.kr/view/?no=20230416110549` |

## 🚀 快速開始

### 安裝步驟

1. **下載插件**
   ```bash
   cd /path/to/FreshRSS/plugins
   git clone https://github.com/your-username/xExtension-google_news_redirect.git GoogleNewsCleanup
   ```

2. **啟用插件**
   - 進入 FreshRSS 管理後台
   - 前往 "設定" → "插件"
   - 尋找 "Google News RSS Link Cleanup" 並啟用

3. **重新加載 RSS**
   - 更新 Google News RSS 源
   - 連結將自動被清理

## 🔧 工作原理

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

### 技術細節

該插件使用以下技術：

- **HTTP 重定向跟蹤** - 捕獲 Location HTTP 頭
- **Base64 解碼** - 支持 Google 的 URL-safe base64 格式
- **正則表達式** - 從二進制數據中提取 URL
- **錯誤處理** - 自動回退和驗證

## 📋 系統要求

- FreshRSS 1.20.0 或更新版本
- PHP 7.4 或更新版本
- 允許出站 HTTP 連接

## 📚 文檔

更多詳細信息請查看 [GoogleNewsCleanup/README.md](./GoogleNewsCleanup/README.md)

### 常見問題

**Q: 為什麼某些連結沒有被清理？**  
A: 某些連結可能已經是原始形式，或網路連接問題導致請求失敗。插件會保持原始 URL 以確保安全性。

**Q: 這會影響性能嗎？**  
A: HTTP 請求可能需要 100-500ms，但這僅在加載 RSS 時運行。建議在低峰時段更新源。

**Q: 支持哪些語言？**  
A: 支持所有語言的新聞源。

## 🛠️ 開發

### 專案結構

```
xExtension-google_news_redirect/
├── GoogleNewsCleanup/
│   ├── index.php           # 主要邏輯
│   ├── metadata.json       # 插件配置
│   └── README.md           # 詳細文檔
├── LICENSE                 # MIT 許可證
├── CHANGELOG.md            # 更新日誌
└── README.md              # 本文件
```

### 如何貢獻

1. Fork 本倉庫
2. 建立特性分支 (`git checkout -b feature/AmazingFeature`)
3. 提交更改 (`git commit -m 'Add some AmazingFeature'`)
4. 推送到分支 (`git push origin feature/AmazingFeature`)
5. 開啟 Pull Request

## 🔗 相關資源

- [FreshRSS 官網](https://www.freshrss.org/)
- [FreshRSS 插件開發文檔](https://www.freshrss.org/plugins.html)
- [Stack Overflow 原始解決方案](https://stackoverflow.com/questions/76063646/how-can-i-have-redirection-link-from-google-news-link-using-requests)

## 📄 許可證

本項目採用 MIT 許可證 - 詳見 [LICENSE](./LICENSE) 文件

## 👤 作者

- **Kalle** - *初始工作* - [GitHub](https://github.com/your-username)

## 🙏 致謝

感謝 Stack Overflow 社區提供的 HTTP 重定向解決方案。

---

## 🌍 English

### Features

✅ **Auto Detection** of Google News RSS links  
✅ **Smart Redirection** tracking to extract original URLs  
✅ **Fallback Decoding** for Base64 encoded URLs  
✅ **Seamless Integration** with FreshRSS  
✅ **Zero Configuration** - Works out of the box  

### Quick Start

```bash
cd /path/to/FreshRSS/plugins
git clone https://github.com/your-username/xExtension-google_news_redirect.git GoogleNewsCleanup
```

Then enable it in FreshRSS settings.

### How It Works

The plugin uses HTTP redirection tracking:
1. Send request to Google News URL
2. Capture Location header (redirect)
3. Extract and return original URL

### License

MIT License - See [LICENSE](./LICENSE) file

### Contributing

Contributions are welcome! Please fork the repository and submit a pull request.

---

**Made with ❤️ for FreshRSS users**
