<?php
/* Google News Clean Extension for FreshRSS */

class GoogleNewsCleanExtension extends Minz_Extension {
    public function init() {
        $this->registerHook('entry_before_insert', [$this, 'cleanEntryLink']);
        
        if (is_null(FreshRSS_Context::$user_conf->GoogleNewsCleanFeeds)) {
            FreshRSS_Context::$user_conf->GoogleNewsCleanFeeds = [];
        }
        if (is_null(FreshRSS_Context::$user_conf->GoogleNewsCleanTTL)) {
            FreshRSS_Context::$user_conf->GoogleNewsCleanTTL = 604800;
        }
        if (is_null(FreshRSS_Context::$user_conf->GoogleNewsCleanMax)) {
            FreshRSS_Context::$user_conf->GoogleNewsCleanMax = 1000;
        }
        FreshRSS_Context::$user_conf->save();
    }

    public function install() {
        return true;
    }

    public function uninstall() {
        return true;
    }



    public function handleConfigureAction() {
        if (Minz_Request::isPost()) {
            // 清除快取動作
            if (Minz_Request::param('clear_cache') === '1') {
                $cacheFile = __DIR__ . '/data/cache.json';
                if (is_file($cacheFile)) {
                    @unlink($cacheFile);
                }
            }

            // Feeds 勾選
            $feeds = Minz_Request::param('feeds', []);
            if (!is_array($feeds)) {
                $feeds = [];
            }
            FreshRSS_Context::$user_conf->GoogleNewsCleanFeeds = $feeds;

            // 快取 TTL 與最大筆數
            $ttl = (int)Minz_Request::param('cache_ttl', 604800);
            $max = (int)Minz_Request::param('cache_max', 1000);
            if ($ttl < 60) { $ttl = 60; }
            if ($max < 10) { $max = 10; }
            FreshRSS_Context::$user_conf->GoogleNewsCleanTTL = $ttl;
            FreshRSS_Context::$user_conf->GoogleNewsCleanMax = $max;

            FreshRSS_Context::$user_conf->save();
        }
    }

    private function isTargetFeed($feedId) {
        $feeds = FreshRSS_Context::$user_conf->GoogleNewsCleanFeeds ?? [];
        return in_array((int)$feedId, $feeds, true);
    }

    public function cleanEntryLink($entry) {
        if (!($entry instanceof FreshRSS_Entry)) {
            return; // 防護
        }
        $feedId = $entry->idFeed();
        if (!$this->isTargetFeed($feedId)) {
            return; // 未勾選的 Feed 不處理
        }

        $url = $entry->link();
        if (strpos($url, 'news.google.com') === false) {
            return; // 不是 Google News 轉址
        }
        // 快取初始化
        require_once __DIR__ . '/lib/GoogleNewsCache.php';
        $cacheFile = __DIR__ . '/data/cache.json';
        $ttl = FreshRSS_Context::$user_conf->GoogleNewsCleanTTL ?? 604800;
        $max = FreshRSS_Context::$user_conf->GoogleNewsCleanMax ?? 1000;
        $cache = new GoogleNewsCache($cacheFile, $ttl, $max);
        $cached = $cache->get($url);
        if ($cached) {
            Minz_Log::notice('GoogleNewsClean: cache hit');
            $entry->_link($cached);
            return;
        }

        require_once __DIR__ . '/lib/GoogleNewsCleaner.php';
        $cleaner = new GoogleNewsCleaner();
        try {
            $clean = $cleaner->extractOriginalUrl($url);
            if ($clean && $clean !== $url) {
                $entry->_link($clean); // 更新 Entry 連結
                $cache->set($url, $clean);
                Minz_Log::notice('GoogleNewsClean: URL cleaned');
            } else {
                Minz_Log::notice('GoogleNewsClean: extraction failed or unchanged');
            }
        } catch (Throwable $e) {
            Minz_Log::error('GoogleNewsClean: exception ' . $e->getMessage());
        }
    }
}
