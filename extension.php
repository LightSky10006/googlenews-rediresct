<?php
require_once(__DIR__ . '/lib/GoogleNewsCleaner.php');

class GoogleNewsCleanExtension extends Minz_Extension {
    public function init() {
        $this->registerHook('entry_before_insert', array($this, 'cleanEntry'));

        if (is_null(FreshRSS_Context::$user_conf->GoogleNewsCleanFeeds)) {
            FreshRSS_Context::$user_conf->GoogleNewsCleanFeeds = array();
        }
        if (is_null(FreshRSS_Context::$user_conf->GoogleNewsCleanTTL)) {
            FreshRSS_Context::$user_conf->GoogleNewsCleanTTL = 604800;
        }
        if (is_null(FreshRSS_Context::$user_conf->GoogleNewsCleanMax)) {
            FreshRSS_Context::$user_conf->GoogleNewsCleanMax = 1000;
        }
        FreshRSS_Context::$user_conf->save();
    }

    public function handleConfigureAction() {
        if (Minz_Request::isPost()) {
            $cleanFeeds = Minz_Request::param('GoogleNewsCleanFeeds', array());
            if (!is_array($cleanFeeds)) {
                $cleanFeeds = array();
            }
            FreshRSS_Context::$user_conf->GoogleNewsCleanFeeds = $cleanFeeds;

            $ttl = (int)Minz_Request::param('cache_ttl', 604800);
            $max = (int)Minz_Request::param('cache_max', 1000);
            if ($ttl < 60) { $ttl = 60; }
            if ($max < 10) { $max = 10; }
            FreshRSS_Context::$user_conf->GoogleNewsCleanTTL = $ttl;
            FreshRSS_Context::$user_conf->GoogleNewsCleanMax = $max;

            if (Minz_Request::param('clear_cache') === '1') {
                $cacheFile = __DIR__ . '/data/cache.json';
                if (is_file($cacheFile)) {
                    @unlink($cacheFile);
                }
            }

            FreshRSS_Context::$user_conf->save();
        }
    }

    public function handleUninstallAction() {
        if (isset(FreshRSS_Context::$user_conf->GoogleNewsCleanFeeds)) {
            unset(FreshRSS_Context::$user_conf->GoogleNewsCleanFeeds);
        }
        if (isset(FreshRSS_Context::$user_conf->GoogleNewsCleanTTL)) {
            unset(FreshRSS_Context::$user_conf->GoogleNewsCleanTTL);
        }
        if (isset(FreshRSS_Context::$user_conf->GoogleNewsCleanMax)) {
            unset(FreshRSS_Context::$user_conf->GoogleNewsCleanMax);
        }
        FreshRSS_Context::$user_conf->save();
    }

    public function cleanEntry($entry) {
        $feedId = $entry->feed()->id();
        
        if (isset(FreshRSS_Context::$user_conf->GoogleNewsCleanFeeds[$feedId]) && 
            FreshRSS_Context::$user_conf->GoogleNewsCleanFeeds[$feedId] == '1') {
            
            $url = $entry->link();
            
            if (strpos($url, 'news.google.com') !== false) {
                require_once __DIR__ . '/lib/GoogleNewsCache.php';
                $cacheFile = __DIR__ . '/data/cache.json';
                $ttl = FreshRSS_Context::$user_conf->GoogleNewsCleanTTL ?? 604800;
                $max = FreshRSS_Context::$user_conf->GoogleNewsCleanMax ?? 1000;
                $cache = new GoogleNewsCache($cacheFile, $ttl, $max);
                
                $cached = $cache->get($url);
                if ($cached) {
                    $entry->_link($cached);
                    return $entry;
                }

                $cleaner = new GoogleNewsCleaner();
                $cleanUrl = $cleaner->extractOriginalUrl($url);
                
                if (!empty($cleanUrl) && $cleanUrl !== $url) {
                    $entry->_link($cleanUrl);
                    $cache->set($url, $cleanUrl);
                }
            }
        }
        
        return $entry;
    }
}
