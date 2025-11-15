<?php
/* Google News Clean Extension for FreshRSS */

class GoogleNewsCleanExtension extends Minz_Extension {
    public function init() {
        // Hook 在文章寫入資料庫前，清理連結
        $this->registerHook('entry_before_insert', [$this, 'cleanEntryLink']);
        // 提供設定頁面 Action
        $this->registerHook('menu_admin', [$this, 'adminMenu']);
    }

    public function install() {
        $config = $this->getConfig();
        if (!isset($config['feeds'])) {
            $config['feeds'] = [];
        }
        if (!isset($config['cache_ttl'])) {
            $config['cache_ttl'] = 604800; // 7 天
        }
        if (!isset($config['cache_max'])) {
            $config['cache_max'] = 1000;
        }
        $this->saveConfig($config);
        return true;
    }

    public function uninstall() {
        return true;
    }

    public function adminMenu() {
        // 在後台擴充套件設定列表顯示一個連結
        $url = Minz_Url::display(['c' => 'extension', 'a' => 'configure', 'params' => ['ext' => $this->name]]);
        echo '<li><a href="' . htmlspecialchars($url) . '">Google News Clean</a></li>';
    }

    public function handleConfigureAction() {
        if (Minz_Request::isPost()) {
            $token = Minz_Request::param('_csrf');
            if (!FreshRSS_Auth::hasValidCsrf($token)) {
                Minz_Session::warning(_t('gen.csrf.failed'));
                Minz_Url::redirect(['c' => 'extension', 'a' => 'configure', 'params' => ['ext' => $this->name]]);
                return;
            }

            $config = $this->getConfig();

            // 清除快取動作
            if (Minz_Request::param('clear_cache') === '1') {
                $cacheFile = __DIR__ . '/data/cache.json';
                if (is_file($cacheFile)) {
                    @unlink($cacheFile);
                }
                Minz_Session::param('info', '快取已清除');
                Minz_Url::redirect(['c' => 'extension', 'a' => 'configure', 'params' => ['ext' => $this->name]]);
                return;
            }

            // Feeds 勾選
            $feeds = Minz_Request::param('feeds', []);
            $feeds = array_map('intval', is_array($feeds) ? $feeds : []);
            $config['feeds'] = $feeds;

            // 快取 TTL 與最大筆數
            $ttl = (int)Minz_Request::param('cache_ttl', $config['cache_ttl'] ?? 604800);
            $max = (int)Minz_Request::param('cache_max', $config['cache_max'] ?? 1000);
            if ($ttl < 60) { $ttl = 60; }
            if ($max < 10) { $max = 10; }
            $config['cache_ttl'] = $ttl;
            $config['cache_max'] = $max;

            $this->saveConfig($config);
            Minz_Session::param('info', _t('gen.form.prefs.updated'));
        }
        require __DIR__ . '/configure.phtml';
    }

    private function isTargetFeed($feedId) {
        $config = $this->getConfig();
        return in_array((int)$feedId, $config['feeds'] ?? [], true);
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
        $config = $this->getConfig();
        $ttl = isset($config['cache_ttl']) ? (int)$config['cache_ttl'] : 604800;
        $max = isset($config['cache_max']) ? (int)$config['cache_max'] : 1000;
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
