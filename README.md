# Google News Clean (FreshRSS Extension)

將 Google News RSS 來源中的轉址連結還原為原始新聞站的 URL，類似 `FreshRSS-Translate` 的操作流程，但聚焦於連結清理。

#⚠️In heavy development⚠️fail for now
## 功能
- 後台可勾選要處理的 Feed。
- 自動攔截 `news.google.com` 轉址形式的文章連結並嘗試還原。
- 支援以下策略：
  1. 解析 `?url=` 參數。
  2. 跟隨 HTTP 轉址 (cURL Follow-Location)。
  3. 解析頁面內 `<meta http-equiv="refresh" content="...url=...">`。
  4. 嘗試解碼 `/articles/` 片段中可能的編碼資訊。
 - 具有檔案型快取（`data/cache.json`）減少重複外部請求。
 - 於各解析階段與例外狀況寫入 FreshRSS 日誌（Minz_Log）。

## 安裝
1. 將此資料夾放入 FreshRSS 安裝路徑的 `./extensions/googleNewsClean`。
2. 登入後台 → 擴充套件 → 啟用 "Google News Clean"。
3. 進入設定頁面勾選要處理的 Google News RSS Feed。

## 使用建議
- 僅對大量使用 Google News RSS 做聚合的情境啟用，避免不必要的額外請求延遲。
- 若來源伺服器回應較慢，可能稍微影響抓取速度；可視需要精簡勾選的 Feed。

## 注意事項
- 解析失敗時不會修改原連結。
- 部分特殊編碼或 Google News 未直接提供轉址資訊的情境可能無法還原。
- 請確保伺服器具備 cURL 支援。
 - 快取預設保留 7 天，最大 1000 筆，超過或過期會自動清除。
 - 若大量 Feed 產生頻繁寫入，可考慮將 flush 策略改為批次（修改 `GoogleNewsCache`）。

## 進一步優化想法
- 加入快取避免重複解析同一篇文章的 URL。
- 提供選項開關個別策略 (例如停用內容抓取以提升速度)。
- 增加錯誤記錄以利診斷。
 - 建立後台設定以調整 TTL / 容量 / 日誌層級。

## 授權
使用者可依自身需求修改；請留意 FreshRSS 專案本身的授權條款。
