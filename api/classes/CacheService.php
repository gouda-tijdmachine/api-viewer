<?php

declare(strict_types=1);

class CacheService
{
    private $redis = null;

    public function __construct()
    {
        if (!CACHE_ENABLED) {
            return;
        }

        $this->redis = new Redis();

        try {
            $this->redis->connect('127.0.0.1', 6379, 2.5);
            $this->redis->setOption(Redis::OPT_READ_TIMEOUT, 2.5);
            $this->redis->select(CACHE_REDIS_DATABASE);
        } catch (Throwable $e) {
            echo "Connection failed: " . $e->getMessage();
        }
    }

    public function get($key)
    {
        if (isset($_GET['no_cache']) || !CACHE_ENABLED) {
            return null;
        }
        try {
            $value = $this->redis->get("API-VIEWER:$key");

            return $value === false ? null : $value;
        } catch (Exception $e) {
            error_log("Local Redis Get Error: " . $e->getMessage());

            return null;
        }
    }

    public function put($key, $value, $seconds = 3600)
    {
        if (!CACHE_ENABLED) {
            return null;
        }

        try {
            return $this->redis->setex("API-VIEWER:$key", $seconds, $value);
        } catch (Exception $e) {
            error_log("Local Redis Put Error: " . $e->getMessage());

            return false;
        }
    }

    public function clear_cache()
    {
        $prefix = "API-VIEWER:*";

        $it = null;
        $deleted = 0;

        do {
            $keys = $this->redis->scan($it, $prefix, 1000);
            if ($keys !== false && !empty($keys)) {
                $deleted += $this->redis->del($keys);
            }
        } while ($it > 0);

        return $deleted; // number of keys deleted
    }
}
