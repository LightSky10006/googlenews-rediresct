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
            // 步驟 1: 獲取解碼參數 (signature 和 timestamp)
            $params = $this->getDecodingParams($articleId);
            if (!$params) {
                return null;
            }
            
            // 步驟 2: 使用參數調用 batchexecute API
            $url = $this->batchExecuteDecode($params);
            return $url;
            
        } catch (Throwable $e) {
            Minz_Log::error('GoogleNewsClean: decodeNewFormat exception: ' . $e->getMessage());
            return null;
        }
    }
    
    private function getDecodingParams(string $articleId): ?array {
        try {
            $url = "https://news.google.com/articles/$articleId";
            
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36'
            ]);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($httpCode !== 200 || !$response) {
                Minz_Log::warning('GoogleNewsClean: Failed to fetch article page, HTTP ' . $httpCode);
                return null;
            }
            
            // 解析 HTML 獲取 data-n-a-sg 和 data-n-a-ts
            $dom = new DOMDocument();
            @$dom->loadHTML($response);
            $xpath = new DOMXPath($dom);
            
            // 查找 c-wiz/div 元素
            $divs = $xpath->query("//c-wiz/div");
            if ($divs->length === 0) {
                Minz_Log::warning('GoogleNewsClean: Could not find c-wiz/div in article page');
                return null;
            }
            
            $div = $divs->item(0);
            $signature = $div->getAttribute('data-n-a-sg');
            $timestamp = $div->getAttribute('data-n-a-ts');
            
            if (empty($signature) || empty($timestamp)) {
                Minz_Log::warning('GoogleNewsClean: Missing signature or timestamp attributes');
                return null;
            }
            
            Minz_Log::debug("GoogleNewsClean: Got params - sig: $signature, ts: $timestamp");
            
            return [
                'signature' => $signature,
                'timestamp' => $timestamp,
                'gn_art_id' => $articleId
            ];
            
        } catch (Throwable $e) {
            Minz_Log::error('GoogleNewsClean: getDecodingParams exception: ' . $e->getMessage());
            return null;
        }
    }
    
    private function batchExecuteDecode(array $params): ?string {
        try {
            $articleReq = [
                "Fbv4je",
                json_encode([
                    ["garturlreq", [
                        ["X", "X", ["X", "X"], null, null, 1, 1, "US:en", null, 1, null, null, null, null, null, 0, 1],
                        "X", "X", 1, [1, 1, 1], 1, 1, null, 0, 0, null, 0
                    ]],
                    $params['gn_art_id'],
                    $params['timestamp'],
                    $params['signature']
                ])
            ];
            
            $payload = "f.req=" . urlencode(json_encode([[$articleReq]]));
            
            $ch = curl_init("https://news.google.com/_/DotsSplashUi/data/batchexecute");
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/x-www-form-urlencoded;charset=UTF-8',
                ],
                CURLOPT_TIMEOUT => 10,
                CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
            ]);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($httpCode !== 200 || !$response) {
                Minz_Log::warning('GoogleNewsClean: batchexecute returned HTTP ' . $httpCode);
                return null;
            }
            
            // 解析回應
            $responseParts = explode("\n\n", $response);
            if (count($responseParts) < 2) {
                Minz_Log::warning('GoogleNewsClean: Unexpected batchexecute response format');
                return null;
            }
            
            $decoded = json_decode($responseParts[1], true);
            if (!$decoded || !is_array($decoded) || count($decoded) < 1) {
                Minz_Log::warning('GoogleNewsClean: Failed to parse batchexecute JSON response');
                return null;
            }
            
            // 提取 URL
            $firstResult = $decoded[0];
            if (isset($firstResult[2])) {
                $innerData = json_decode($firstResult[2], true);
                if (isset($innerData[1])) {
                    $url = $innerData[1];
                    if (filter_var($url, FILTER_VALIDATE_URL)) {
                        Minz_Log::debug('GoogleNewsClean: Successfully decoded to ' . $url);
                        return $url;
                    }
                }
            }
            
            Minz_Log::warning('GoogleNewsClean: Could not extract URL from batchexecute response');
            return null;
            
        } catch (Throwable $e) {
            Minz_Log::error('GoogleNewsClean: batchExecuteDecode exception: ' . $e->getMessage());
            return null;
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
