<?php
class GoogleNewsCache {
    private string $file;
    private array $data = [];
    private int $ttl; // 秒
    private int $maxEntries;
    private bool $dirty = false;

    public function __construct(string $file, int $ttl = 604800, int $maxEntries = 1000) { // 預設 7 天
        $this->file = $file;
        $this->ttl = $ttl;
        $this->maxEntries = $maxEntries;
        $this->load();
    }

    private function load(): void {
        if (is_file($this->file)) {
            $json = file_get_contents($this->file);
            $arr = json_decode($json, true);
            if (is_array($arr)) {
                $this->data = $arr;
            }
        }
        $this->purgeExpired();
    }

    private function purgeExpired(): void {
        $now = time();
        $changed = false;
        foreach ($this->data as $k => $v) {
            if (!isset($v['t']) || $now - (int)$v['t'] > $this->ttl) {
                unset($this->data[$k]);
                $changed = true;
            }
        }
        if ($changed) {
            $this->dirty = true;
        }
        // 若超過容量，依時間排序刪除最舊
        if (count($this->data) > $this->maxEntries) {
            uasort($this->data, function ($a, $b) {return $a['t'] <=> $b['t'];});
            $excess = count($this->data) - $this->maxEntries;
            $keys = array_keys($this->data);
            for ($i = 0; $i < $excess; $i++) {
                unset($this->data[$keys[$i]]);
            }
            $this->dirty = true;
        }
    }

    public function get(string $googleUrl): ?string {
        $item = $this->data[$googleUrl] ?? null;
        if (!$item) {
            return null;
        }
        if (time() - (int)$item['t'] > $this->ttl) {
            unset($this->data[$googleUrl]);
            $this->dirty = true;
            return null;
        }
        return $item['o'] ?? null;
    }

    public function set(string $googleUrl, string $originalUrl): void {
        $this->data[$googleUrl] = ['o' => $originalUrl, 't' => time()];
        $this->dirty = true;
        $this->flushIfNeeded();
    }

    private function flushIfNeeded(): void {
        // 即時寫入即可，量應不大
        if ($this->dirty) {
            $dir = dirname($this->file);
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
            file_put_contents($this->file, json_encode($this->data, JSON_UNESCAPED_SLASHES));
            $this->dirty = false;
        }
    }

    public function __destruct() {
        $this->flushIfNeeded();
    }
}
