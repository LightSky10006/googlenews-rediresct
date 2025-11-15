<?php
class GoogleNewsCleaner {
    public function extractOriginalUrl(string $url): ?string {
        // 參考: https://stackoverflow.com/questions/78790420/extract-url-from-google-news-rss-feed
        // Google News RSS 的 URL 格式通常是: https://news.google.com/rss/articles/[encoded_string]
        
        // 方法 1: 直接從 URL 參數提取
        if (preg_match('/[?&]url=([^&]+)/', $url, $m)) {
            $candidate = urldecode($m[1]);
            if ($this->isValidUrl($candidate)) {
                return $candidate;
            }
        }
        
        // 方法 2: 從 articles/ 路徑解碼 (主要方法)
        if (preg_match('#/articles/(.+?)(?:\?|$)#', $url, $m)) {
            $encoded = $m[1];
            $decoded = $this->decodeGoogleNewsUrl($encoded);
            if ($decoded) {
                return $decoded;
            }
        }
        
        // 方法 3: 跟隨 HTTP 重定向
        $redirected = $this->followRedirect($url);
        if ($redirected && $redirected !== $url && $this->isValidUrl($redirected)) {
            return $redirected;
        }
        
        return null;
    }
    
    private function decodeGoogleNewsUrl(string $encoded): ?string {
        // Google News 使用特殊的編碼格式
        // 通常包含 Base64 編碼的 URL，前綴為 "CBMi" 或類似標記
        
        // 移除可能的 URL 參數
        $encoded = preg_replace('/\?.*$/', '', $encoded);
        
        // 嘗試多種解碼方式
        $patterns = [
            // 直接 base64 解碼
            function($str) {
                // 補齊 padding
                $str = str_pad($str, strlen($str) + (4 - strlen($str) % 4) % 4, '=');
                $decoded = @base64_decode($str, true);
                if ($decoded && preg_match('#https?://[^\s\x00-\x1f"<>]+#', $decoded, $m)) {
                    return $m[0];
                }
                return null;
            },
            // URL-safe base64
            function($str) {
                $str = str_replace(['-', '_'], ['+', '/'], $str);
                $str = str_pad($str, strlen($str) + (4 - strlen($str) % 4) % 4, '=');
                $decoded = @base64_decode($str, true);
                if ($decoded && preg_match('#https?://[^\s\x00-\x1f"<>]+#', $decoded, $m)) {
                    return $m[0];
                }
                return null;
            },
            // 移除前綴後再解碼 (如 CBMi, CBIi 等)
            function($str) {
                if (preg_match('/^[A-Z]{2,4}[a-z](.+)/', $str, $m)) {
                    $str = $m[1];
                    $str = str_replace(['-', '_'], ['+', '/'], $str);
                    $str = str_pad($str, strlen($str) + (4 - strlen($str) % 4) % 4, '=');
                    $decoded = @base64_decode($str, true);
                    if ($decoded && preg_match('#https?://[^\s\x00-\x1f"<>]+#', $decoded, $m)) {
                        return $m[0];
                    }
                }
                return null;
            }
        ];
        
        foreach ($patterns as $decoder) {
            $result = $decoder($encoded);
            if ($result && $this->isValidUrl($result)) {
                return $result;
            }
        }
        
        return null;
    }
    
    private function followRedirect(string $url): ?string {
        if (!function_exists('curl_init')) {
            return null;
        }
        
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; FreshRSS)',
            CURLOPT_HEADER => false,
            CURLOPT_NOBODY => true, // 只取 header
        ]);
        
        @curl_exec($ch);
        $finalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        curl_close($ch);
        
        return $finalUrl ?: null;
    }
    
    private function isValidUrl(string $url): bool {
        // 檢查是否為有效的 URL 且不是 Google News
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }
        
        $host = parse_url($url, PHP_URL_HOST);
        if (!$host) {
            return false;
        }
        
        // 排除 Google 網域
        $googleDomains = ['google.com', 'google.', 'goo.gl', 'g.co'];
        foreach ($googleDomains as $domain) {
            if (stripos($host, $domain) !== false) {
                return false;
            }
        }
        
        return true;
    }
}
