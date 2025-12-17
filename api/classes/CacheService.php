<?php

class CacheService {
    private $redis = null;
    private $local = false;
    private $token;

    public function __construct() {
        if (CACHE_ENABLED) {
            if (!getenv('REDIS')) {
                $this->redis = new Redis();
                $this->redis->connect('127.0.0.1', 6379); 
                $this->local = true;
            } else {
                $parsed = parse_url(getenv('REDIS'));

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
            $value = $this->redis->get($key);
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
            return $this->redis->setex($key, $seconds, $value);
        } catch (Exception $e) {
            error_log("Local Redis Put Error: " . $e->getMessage());
            return false;
        }
    }

    public function clear_cache() {
        if (!CACHE_ENABLED) {
            return null;
        }
        if (!$this->local) {
            return $this->redis->flushDB();
        }
    }

}