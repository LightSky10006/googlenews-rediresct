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
        if (!isset($this->config['feeds'])) {
            $this->config['feeds'] = [];
        }
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
            $feeds = Minz_Request::param('feeds', []);
            $this->config['feeds'] = array_map('intval', $feeds);
            $this->saveConfig();
            Minz_Session::_param('info', '設定已儲存');
        }
        require __DIR__ . '/configure.php';
    }

    private function isTargetFeed($feedId) {
        return in_array((int)$feedId, $this->config['feeds'] ?? [], true);
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
        $cache = new GoogleNewsCache($cacheFile);
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
