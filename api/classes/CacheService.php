<?php

class CacheService {
    private $redis = null;
    private $local = false;
    private $token;

    public function __construct() {
        if (CACHE_ENABLED) {
            if (!getenv('REDIS_URL')) {
                $this->redis = new Redis();
                $this->redis->connect('127.0.0.1', 6379); 
                $this->local = true;
            } else {
                $parsed = parse_url(getenv('REDIS_URL'));

                $host = $parsed['host'];
                $port = $parsed['port'];
                $user = $parsed['user'] ?? null; // 'default'
                $pass = $parsed['pass'];

                $redis = new Redis();

                try {
                    $this->redis->connect($host, $port);
                    if ($user && $pass) {
                        $this->redis->auth(['user' => $user, 'pass' => $pass]);
                    } else {
                        $this->redis->auth($pass);
                    }

                    echo "Connected successfully! Ping: " . $this->redis->ping();

                } catch (Exception $e) {
                    echo "Connection failed: " . $e->getMessage();
                }            
            }
        }
    }

    public function get($key) {
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

    public function put($key, $value, $seconds = 3600) {
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

    public function clear_cache() {
        $prefix = "API-VIEWER:*";
        error_log("DEBUG: deleting $$prefix keys from Redis");

        $it = null;
        $deleted = 0;

        do {
            $keys = $this->redis->scan($it, $prefix, 1000);
            if ($keys !== false && !empty($keys)) {
                $deleted += $this->redis->del($keys);
                error_log("DEBUG: deleting $key from Redis");
            }
        } while ($it > 0);

        return $deleted; // number of keys deleted

        
                
    }

}