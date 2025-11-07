<?php
/**
 * Google News RSS Link Cleanup Extension for FreshRSS
 * 主要目的：在條目儲存前，將 Google News 路由型 RSS 文章連結還原成原始新聞來源 URL。
**/

// 類別名稱需與 metadata.json 內的 entrypoint 對應：entrypoint = googlenews_redirect
class GoogleNews_Redirect extends Minz_Extension {

    /**
     * 初始化：註冊在條目寫入資料庫前的 hook（entry_before_insert）。
     */
    public function init(): void {
        // 當 FreshRSS 正在匯入 / 更新 feed 時會呼叫此 hook
        $this->registerHook('entry_before_insert', [$this, 'cleanupEntry']);
    }

    /**
     * Hook：在條目插入之前清理連結
     * @param FreshRSS_Entry $entry
     */
    public function cleanupEntry($entry): void {
        // 防禦：僅在物件類型正確時處理
        if (!is_object($entry) || !method_exists($entry, 'link')) {
            return;
        }

        $original = $entry->link();
        if (!$this->isGoogleNewsArticleUrl($original)) {
            return; // 非目標 URL
        }

        $clean = $this->cleanGoogleNewsUrl($original);
        if ($clean && $clean !== $original) {
            // FreshRSS_Entry 沒有正式 setter；直接寫入公開屬性 (核心使用 _link 儲存)
            if (property_exists($entry, '_link')) {
                $entry->_link = $clean;
            } elseif (property_exists($entry, 'link')) { // 舊版相容
                $entry->link = $clean;
            }
            if (class_exists('Minz_Log')) {
                Minz_Log::notice('[googlenews_redirect] cleaned URL: ' . $original . ' => ' . $clean);
            }
        }
    }

    /**
     * 檢查是否為 Google News RSS 文章 URL
     */
    private function isGoogleNewsArticleUrl(string $url): bool {
        return strpos($url, 'news.google.com/rss/articles/') !== false;
    }

    /**
     * 對單一 URL 進行清理流程
     */
    private function cleanGoogleNewsUrl(string $url): ?string {
        // 1. 嘗試利用 HTTP 重定向
        $redirect = $this->extractOriginalUrlFromGoogleNews($url);
        if ($redirect && $redirect !== $url) {
            return $redirect;
        }
        // 2. 備用：Base64 解碼 (不一定成功)
        $decoded = $this->decodeFromBase64($url);
        if ($decoded && $decoded !== $url) {
            return $decoded;
        }
        return null;
    }

    /**
     * 從 Google News RSS 重定向中提取原始 URL
     * @param string $url Google News RSS URL
     * @return string|null
     */
    private function extractOriginalUrlFromGoogleNews(string $url): ?string {
        try {
            // 設置 HTTP 請求頭，模擬常見瀏覽器，降低被擋機率
            $options = [
                'http' => [
                    'method' => 'GET',
                    'header' => [
                        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36'
                    ],
                    'timeout' => 4,
                    'ignore_errors' => true,
                ],
                'ssl' => [
                    'verify_peer' => true,
                    'verify_peer_name' => true,
                ],
            ];
            $context = stream_context_create($options);

            // 先抓 header 以取得 Location
            $headers = @get_headers($url, 1, $context);
            if ($headers && isset($headers['Location'])) {
                $location = $headers['Location'];
                if (is_array($location)) {
                    $location = end($location); // 取最後一次跳轉
                }
                if ($this->isValidUrl($location) && !$this->isGoogleNewsArticleUrl($location)) {
                    return $location;
                }
            }

            // 無 header 跳轉，再嘗試取出 HTML 中的 <a href>
            $html = @file_get_contents($url, false, $context);
            if (is_string($html) && preg_match('/<a[^>]+href=["\'](https?:\/\/[^"\'<>]+)["\']/', $html, $matches)) {
                $candidate = $matches[1];
                if ($this->isValidUrl($candidate) && !$this->isGoogleNewsArticleUrl($candidate)) {
                    return $candidate;
                }
            }
        } catch (Throwable $e) {
            if (class_exists('Minz_Log')) {
                Minz_Log::warning('[googlenews_redirect] redirect extraction failed: ' . $e->getMessage());
            }
        }
        return null;
    }

    /**
     * 驗證 URL 是否有效 (簡單判斷開頭即可，避免耗時 DNS 請求)
     */
    private function isValidUrl(string $url): bool {
        return (strpos($url, 'http://') === 0 || strpos($url, 'https://') === 0);
    }

    /**
     * 備用解碼：嘗試 base64 (URL-safe) 解碼文章段落以尋找原始連結
     */
    private function decodeFromBase64(string $url): ?string {
        if (!preg_match('/news\.google\.com\/rss\/articles\/([A-Za-z0-9_-]+)/', $url, $m)) {
            return null;
        }
        $encoded = $m[1];
        $padded = $encoded . str_repeat('=', (4 - strlen($encoded) % 4) % 4);
        $standard = strtr($padded, '-_', '+/');
        $decoded = @base64_decode($standard, true);
        if ($decoded && preg_match('/https?:\/\/[^\x00\s]+/', $decoded, $mm)) {
            $found = $mm[0];
            if ($this->isValidUrl($found) && !$this->isGoogleNewsArticleUrl($found)) {
                return $found;
            }
        }
        return null;
    }
}
