<?php
/**
 * Google News RSS Link Cleanup Plugin for FreshRSS
 * 
 * 此插件用於清理 Google News RSS 的連結，移除 Google News 的追蹤參數
 * 並還原原始連結
 * 
 * 方法：發送 HTTP 請求到 Google News 連結，Google 會重定向到原始連結，
 * 然後解析 HTML 響應中的連結
 * 
 * 例如：https://news.google.com/rss/articles/CBMi...?oc=5 -> 原始連結
 */

class GoogleNewsCleanupPlugin extends Plugin {
    /**
     * 獲取插件的操作
     */
    public function init() {
        $this->registerAction('entry_url', 'clean_google_news_url');
    }

    /**
     * 清理 Google News URL
     * 
     * @param string $url 原始 URL
     * @return string 清理後的 URL
     */
    public function clean_google_news_url($url) {
        // 檢查是否為 Google News RSS 連結
        if (strpos($url, 'news.google.com/rss/articles/') !== false) {
            // 嘗試從 Google News 重定向中提取原始 URL
            $cleaned_url = $this->extractOriginalUrlFromGoogleNews($url);
            if ($cleaned_url !== false && $cleaned_url !== $url) {
                return $cleaned_url;
            }
        }

        return $url;
    }

    /**
     * 從 Google News RSS 重定向中提取原始 URL
     * 
     * 根據 Stack Overflow 上的解決方案：
     * https://stackoverflow.com/questions/76063646/
     * 
     * Google News 在請求時會返回一個 HTML 頁面，其中包含一個連結到原始文章的 <a> 標籤
     * 
     * @param string $url Google News RSS URL
     * @return string|false 原始 URL，或 false 如果無法提取
     */
    private function extractOriginalUrlFromGoogleNews($url) {
        try {
            // 設置 HTTP 請求頭，模擬瀏覽器
            $options = array(
                'http' => array(
                    'method' => 'GET',
                    'header' => array(
                        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36'
                    ),
                    'timeout' => 5,
                    'ignore_errors' => true  // 獲取即使是重定向的內容
                ),
                'ssl' => array(
                    'verify_peer' => false,
                    'verify_peer_name' => false
                )
            );

            $context = stream_context_create($options);
            
            // 使用 get_headers 獲取重定向位置
            $headers = @get_headers($url, 1, $context);
            
            if ($headers === false) {
                return false;
            }

            // 檢查是否有 Location 重定向頭
            if (isset($headers['Location'])) {
                $location = $headers['Location'];
                
                // 如果 Location 是陣列（多個重定向），取最後一個
                if (is_array($location)) {
                    $location = end($location);
                }
                
                // 確保是有效的 URL
                if (!empty($location) && $this->isValidUrl($location)) {
                    return $location;
                }
            }

            // 備用方法：嘗試透過直接文件讀取和 HTML 解析
            $html = @file_get_contents($url, false, $context);
            
            if ($html !== false) {
                // 使用正則表達式尋找 <a> 標籤中的 href
                if (preg_match('/<a[^>]+href=["\'](https?:\/\/[^"\'<>]+)["\']/', $html, $matches)) {
                    $original_url = $matches[1];
                    
                    // 確保不是 Google 的連結
                    if (strpos($original_url, 'news.google.com') === false) {
                        return $original_url;
                    }
                }
            }

            return false;

        } catch (Exception $e) {
            // 如果發生錯誤，返回 false 讓原始 URL 通過
            return false;
        }
    }

    /**
     * 驗證 URL 是否有效
     * 
     * @param string $url 要驗證的 URL
     * @return bool 是否為有效的 URL
     */
    private function isValidUrl($url) {
        // 檢查是否以 http:// 或 https:// 開頭
        if (strpos($url, 'http://') === 0 || strpos($url, 'https://') === 0) {
            return true;
        }
        return false;
    }

    /**
     * 備用解碼方法：從 Base64 編碼中提取 URL
     * 這是備用方法，如果 HTTP 請求失敗
     * 
     * @param string $url Google News RSS URL
     * @return string|false 解碼後的 URL，或 false 如果解碼失敗
     */
    private function decodeFromBase64($url) {
        // 提取編碼部分
        // 格式: https://news.google.com/rss/articles/CBMi...?oc=5
        
        if (preg_match('/news\.google\.com\/rss\/articles\/([A-Za-z0-9_-]+)/', $url, $matches)) {
            $encoded_part = $matches[1];
            
            // Google 使用 URL-safe base64（- 和 _ 而不是 + 和 /）
            $padded = $encoded_part . str_repeat('=', (4 - strlen($encoded_part) % 4) % 4);
            $url_safe = strtr($padded, '-_', '+/');
            
            $decoded = @base64_decode($url_safe, true);
            
            if ($decoded !== false) {
                // 尋找以 http:// 或 https:// 開頭的字符串
                if (preg_match('/https?:\/\/[^\x00\s]+/', $decoded, $matches)) {
                    return $matches[0];
                }
            }
        }

        return false;
    }
}
