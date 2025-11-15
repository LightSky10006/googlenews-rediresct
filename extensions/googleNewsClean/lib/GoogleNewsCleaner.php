<?php
class GoogleNewsCleaner {
    public function extractOriginalUrl(string $url): ?string {
        Minz_Log::notice('GoogleNewsClean: start extract');
        // 1. 直接解析 ?url= 參數 (部分情況會出現)
        if (preg_match('/[?&]url=([^&]+)/', $url, $m)) {
            $candidate = urldecode($m[1]);
            if ($this->isNonGoogleHost($candidate)) {
                Minz_Log::notice('GoogleNewsClean: found via query param');
                return $candidate;
            }
        }

        // 2. 嘗試以 cURL 跟隨 HTTP 轉址
        try {
            $resolved = $this->resolveRedirects($url);
            if ($resolved && $this->isNonGoogleHost($resolved)) {
                Minz_Log::notice('GoogleNewsClean: found via redirects');
                return $resolved;
            }
        } catch (Throwable $e) {
            Minz_Log::warning('GoogleNewsClean: redirect resolve error ' . $e->getMessage());
        }

        // 3. 讀取內容，解析 meta refresh 中的 URL
        try {
            $final = $this->extractFromMetaRefresh($url);
            if ($final && $this->isNonGoogleHost($final)) {
                Minz_Log::notice('GoogleNewsClean: found via meta refresh');
                return $final;
            }
        } catch (Throwable $e) {
            Minz_Log::warning('GoogleNewsClean: meta refresh error ' . $e->getMessage());
        }

        // 4. 有些 Google News RSS 文章 URL 編碼在 /articles/ 後，嘗試 Base64 片段判斷
        //    範例: https://news.google.com/rss/articles/CBMiXGh0dHBzOi8vZXhhbXBsZS5jb20vYWJjP2Q9MTIz0... => 抓出可能的 https 起頭片段
        if (preg_match('/articles\/([A-Za-z0-9+\-_]+)/', $url, $m)) {
            try {
                $decoded = $this->robustDecode($m[1]);
                if ($decoded && preg_match('#https?://[^\s\"<>]+#', $decoded, $mm)) {
                    $candidate = $mm[0];
                    if ($this->isNonGoogleHost($candidate)) {
                        Minz_Log::notice('GoogleNewsClean: found via articles fragment decode');
                        return $candidate;
                    }
                }
            } catch (Throwable $e) {
                Minz_Log::warning('GoogleNewsClean: articles fragment decode error ' . $e->getMessage());
            }
        }

        Minz_Log::notice('GoogleNewsClean: extraction ended without result');
        return null; // 無法抽取
    }

    private function resolveRedirects(string $url): ?string {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_USERAGENT => 'FreshRSS GoogleNewsClean/1.0',
            CURLOPT_HEADER => false,
        ]);
        curl_exec($ch);
        $finalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        curl_close($ch);
        return $finalUrl ?: null;
    }

    private function extractFromMetaRefresh(string $url): ?string {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_USERAGENT => 'FreshRSS GoogleNewsClean/1.0',
        ]);
        $html = curl_exec($ch);
        curl_close($ch);
        if (!is_string($html) || $html === '') {
            return null;
        }
        if (preg_match('/<meta[^>]+http-equiv=["\']refresh["\'][^>]+content=["\']\d+;\s*url=([^"\']+)["\']/i', $html, $m)) {
            return trim($m[1]);
        }
        return null;
    }

    private function robustDecode(string $fragment): ?string {
        // 嘗試 Base64 (加入缺失的 =)
        $pad = strlen($fragment) % 4;
        if ($pad !== 0) {
            $fragment .= str_repeat('=', 4 - $pad);
        }
        $decoded = base64_decode($fragment, true);
        if ($decoded !== false && $decoded !== '') {
            return $decoded;
        }
        // 嘗試 URL decode
        $urlDecoded = urldecode($fragment);
        if ($urlDecoded !== $fragment) {
            return $urlDecoded;
        }
        return null;
    }

    private function isNonGoogleHost(string $candidate): bool {
        $host = parse_url($candidate, PHP_URL_HOST);
        if (!$host) {
            return false;
        }
        return stripos($host, 'news.google.com') === false && stripos($host, 'google.com') === false;
    }
}
