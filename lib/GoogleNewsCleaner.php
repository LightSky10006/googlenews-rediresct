<?php
/**
 * Google News URL Decoder
 * Based on: https://gist.github.com/huksley/bc3cb046157a99cd9d1517b32f91a99e
 * Converts Google News RSS article URLs to original source URLs
 */
class GoogleNewsCleaner {
    
    public function extractOriginalUrl(string $sourceUrl): ?string {
        try {
            $parsedUrl = parse_url($sourceUrl);
            
            if (!isset($parsedUrl['host']) || !isset($parsedUrl['path'])) {
                return null;
            }
            
            // 只處理 news.google.com
            if ($parsedUrl['host'] !== 'news.google.com') {
                return null;
            }
            
            $pathParts = explode('/', trim($parsedUrl['path'], '/'));
            
            // 檢查是否為 articles/ 格式
            if (count($pathParts) < 2 || $pathParts[count($pathParts) - 2] !== 'articles') {
                return null;
            }
            
            $base64encoded = $pathParts[count($pathParts) - 1];
            
            // 解碼 Base64
            $decoded = base64_decode($base64encoded, true);
            if ($decoded === false) {
                Minz_Log::warning('GoogleNewsClean: Base64 decode failed for ' . $sourceUrl);
                return null;
            }
            
            // 移除前綴 0x08, 0x13, 0x22
            $prefix = pack('C*', 0x08, 0x13, 0x22);
            if (substr($decoded, 0, 3) === $prefix) {
                $decoded = substr($decoded, 3);
            }
            
            // 移除後綴 0xd2, 0x01, 0x00 (如果存在)
            $suffix = pack('C*', 0xd2, 0x01, 0x00);
            if (substr($decoded, -3) === $suffix) {
                $decoded = substr($decoded, 0, -3);
            }
            
            // 讀取長度並提取 URL
            $bytes = unpack('C*', $decoded);
            if (empty($bytes)) {
                Minz_Log::warning('GoogleNewsClean: Empty bytes after decode');
                return null;
            }
            
            $len = $bytes[1];
            $offset = 1;
            
            // 處理長度編碼（單字節或雙字節）
            if ($len >= 0x80) {
                $offset = 2;
            }
            
            // 提取 URL 字串
            $urlBytes = array_slice($bytes, $offset, $len);
            $extractedUrl = '';
            foreach ($urlBytes as $byte) {
                $extractedUrl .= chr($byte);
            }
            
            // 檢查是否為新式編碼 (AU_yqL 開頭)
            if (strpos($extractedUrl, 'AU_yqL') === 0) {
                Minz_Log::debug('GoogleNewsClean: New encoding format detected, fetching decode params');
                $decodedUrl = $this->decodeNewFormat($base64encoded);
                if ($decodedUrl) {
                    return $decodedUrl;
                }
                Minz_Log::warning('GoogleNewsClean: New format decode failed, trying redirect fallback');
                return $this->followRedirect($sourceUrl);
            }
            
            // 驗證提取的 URL
            if (filter_var($extractedUrl, FILTER_VALIDATE_URL)) {
                Minz_Log::debug('GoogleNewsClean: Successfully decoded to ' . $extractedUrl);
                return $extractedUrl;
            }
            
            Minz_Log::warning('GoogleNewsClean: Extracted string is not valid URL: ' . $extractedUrl);
            return null;
            
        } catch (Throwable $e) {
            Minz_Log::error('GoogleNewsClean: Exception during decode: ' . $e->getMessage());
            return null;
        }
    }
    
    private function decodeNewFormat(string $articleId): ?string {
        try {
            // 步驟 1: 獲取解碼參數
            $params = $this->getDecodingParams($articleId);
            if (!$params) {
                return null;
            }
            
            // 步驟 2: 使用單個文章調用 batchexecute
            $decodedUrls = $this->decodeUrlsBatch([$params]);
            
            if (!empty($decodedUrls) && isset($decodedUrls[0])) {
                return $decodedUrls[0];
            }
            
            return null;
            
        } catch (Throwable $e) {
            Minz_Log::error('GoogleNewsClean: decodeNewFormat exception: ' . $e->getMessage());
            return null;
        }
    }
    
    private function getDecodingParams(string $gn_art_id): ?array {
        try {
            $url = "https://news.google.com/articles/$gn_art_id";
            
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 5,
                CURLOPT_TIMEOUT => 15,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_ENCODING => '',
                CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                CURLOPT_HTTPHEADER => [
                    'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
                    'Accept-Language: en-US,en;q=0.9',
                    'Accept-Encoding: gzip, deflate, br',
                    'Connection: keep-alive',
                    'Upgrade-Insecure-Requests: 1',
                    'Sec-Fetch-Dest: document',
                    'Sec-Fetch-Mode: navigate',
                    'Sec-Fetch-Site: none',
                ],
            ]);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);
            
            if ($error) {
                Minz_Log::error('GoogleNewsClean: Curl error: ' . $error);
                return null;
            }
            
            if ($httpCode !== 200) {
                Minz_Log::warning("GoogleNewsClean: Failed to fetch article page, HTTP $httpCode");
                return null;
            }
            
            if (empty($response)) {
                Minz_Log::warning('GoogleNewsClean: Empty response from Google News');
                return null;
            }
            
            // Load the response into DOMDocument
            $dom = new DOMDocument();
            libxml_use_internal_errors(true); // 抑制 HTML 解析警告
            $loaded = @$dom->loadHTML($response);
            libxml_clear_errors();
            
            if (!$loaded) {
                Minz_Log::warning('GoogleNewsClean: Failed to parse HTML response');
                return null;
            }
            
            $xpath = new DOMXPath($dom);
            
            // 嘗試多種可能的選擇器
            $div = null;
            $queries = [
                "//c-wiz/div",
                "//c-wiz//div[@data-n-a-sg]",
                "//div[@data-n-a-sg]",
                "//*[@data-n-a-sg]"
            ];
            
            foreach ($queries as $query) {
                $result = $xpath->query($query);
                if ($result && $result->length > 0) {
                    $div = $result->item(0);
                    Minz_Log::debug("GoogleNewsClean: Found element using query: $query");
                    break;
                }
            }
            
            if (!$div) {
                // 記錄部分 HTML 以便調試
                $preview = substr(strip_tags($response), 0, 300);
                Minz_Log::warning('GoogleNewsClean: Could not find element with data-n-a-sg. Response preview: ' . $preview);
                return null;
            }
            
            $signature = $div->getAttribute("data-n-a-sg");
            $timestamp = $div->getAttribute("data-n-a-ts");
            
            if (empty($signature) || empty($timestamp)) {
                Minz_Log::warning("GoogleNewsClean: Missing signature or timestamp (sig: '$signature', ts: '$timestamp')");
                return null;
            }
            
            Minz_Log::debug("GoogleNewsClean: Successfully extracted params - signature: $signature, timestamp: $timestamp");
            
            return [
                "signature" => $signature,
                "timestamp" => $timestamp,
                "gn_art_id" => $gn_art_id,
            ];
            
        } catch (Throwable $e) {
            Minz_Log::error('GoogleNewsClean: getDecodingParams exception: ' . $e->getMessage());
            return null;
        }
    }
    
    private function decodeUrlsBatch(array $articles): array {
        try {
            $articles_reqs = [];

            foreach ($articles as $art) {
                $articles_reqs[] = [
                    "Fbv4je",
                    json_encode([
                        ["garturlreq", [
                            ["X", "X", ["X", "X"], null, null, 1, 1, "US:en", null, 1, null, null, null, null, null, 0, 1],
                            "X", "X", 1, [1, 1, 1], 1, 1, null, 0, 0, null, 0
                        ]],
                        $art["gn_art_id"],
                        $art["timestamp"],
                        $art["signature"]
                    ])
                ];
            }

            $payload = "f.req=" . urlencode(json_encode([$articles_reqs]));
            $headers = [
                "Content-Type: application/x-www-form-urlencoded;charset=UTF-8",
            ];

            $ch = curl_init("https://news.google.com/_/DotsSplashUi/data/batchexecute");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

            $response = curl_exec($ch);
            
            if (curl_errno($ch)) {
                Minz_Log::error('GoogleNewsClean: Curl error: ' . curl_error($ch));
                curl_close($ch);
                return [];
            }
            
            curl_close($ch);
            
            $responseParts = explode("\n\n", $response);
            
            if (count($responseParts) < 2) {
                Minz_Log::warning('GoogleNewsClean: Invalid response format');
                return [];
            }
            
            $decoded = json_decode($responseParts[1], true);
            
            if (!$decoded || !is_array($decoded)) {
                Minz_Log::warning('GoogleNewsClean: Failed to decode JSON response');
                return [];
            }
            
            return array_map(function($res) {
                $innerData = json_decode($res[2], true);
                return $innerData[1];
            }, array_slice($decoded, 0, -2));
            
        } catch (Throwable $e) {
            Minz_Log::error('GoogleNewsClean: decodeUrlsBatch exception: ' . $e->getMessage());
            return [];
        }
    }
    
    private function fetchDecodedBatchExecute(string $articleId): ?string {
        try {
            // 構建請求 payload，格式需要與 Google News 期望的一致
            $payload = sprintf(
                '[[["Fbv4je","[\\\"garturlreq\\\",[[\\\"%s\\\",\\\"%s\\\",[\\\"FINANCE_TOP_INDICES\\\",\\\"WEB_TEST_1_0_0\\\"],null,null,1,1,\\\"%s\\\",null,180,null,null,null,null,null,0,null,null,[1608992183,723341000]],\\\"%s\\\",\\\"%s\\\",1,[2,3,4,8],1,0,\\\"%s\\\",0,0,null,0],\\\"%s\\\"]",null,"generic"]]]',
                'en-US', 'US', 'US:en', 'en-US', 'US', '655000234', $articleId
            );
            
            $ch = curl_init('https://news.google.com/_/DotsSplashUi/data/batchexecute?rpcids=Fbv4je');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => 'f.req=' . urlencode($payload),
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/x-www-form-urlencoded;charset=UTF-8',
                    'Referer: https://news.google.com/',
                    'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36'
                ],
                CURLOPT_TIMEOUT => 10,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_SSL_VERIFYPEER => true
            ]);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($httpCode !== 200) {
                Minz_Log::warning('GoogleNewsClean: batchexecute API returned ' . $httpCode);
                return null;
            }
            
            if (!$response) {
                Minz_Log::warning('GoogleNewsClean: batchexecute empty response');
                return null;
            }
            
            // 解析回應 - Google 返回的格式可能包含轉義字符
            // 尋找 garturlres 標記
            if (preg_match('/\["garturlres","([^"]+)"/', $response, $matches)) {
                $url = $matches[1];
                // 處理可能的轉義
                $url = stripcslashes($url);
                
                if (filter_var($url, FILTER_VALIDATE_URL)) {
                    Minz_Log::debug('GoogleNewsClean: batchexecute decoded to ' . $url);
                    return $url;
                }
            }
            
            Minz_Log::warning('GoogleNewsClean: batchexecute could not parse response');
            return null;
            
        } catch (Throwable $e) {
            Minz_Log::error('GoogleNewsClean: batchexecute exception: ' . $e->getMessage());
            return null;
        }
    }
    
    private function followRedirect(string $url): ?string {
        if (!function_exists('curl_init')) {
            return null;
        }
        
        try {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 3,
                CURLOPT_TIMEOUT => 5,
                CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; FreshRSS)',
                CURLOPT_HEADER => false,
                CURLOPT_NOBODY => true,
            ]);
            
            @curl_exec($ch);
            $finalUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
            curl_close($ch);
            
            if ($finalUrl && $finalUrl !== $url) {
                return $finalUrl;
            }
        } catch (Throwable $e) {
            Minz_Log::error('GoogleNewsClean: Redirect failed: ' . $e->getMessage());
        }
        
        return null;
    }
}
