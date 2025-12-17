<?php

class CacheService {
    private $redis = null;
    private $baseUrl;
    private $token;

    public function __construct() {
        if (!getenv('KV_REST_API_URL') || !getenv('KV_REST_API_TOKEN')) {
            $this->redis = new Redis();
            $this->redis->connect('127.0.0.1', 6379); 
        } else {
            $this->baseUrl = getenv('KV_REST_API_URL');
            $this->token = getenv('KV_REST_API_TOKEN');
        }
    }

    public function get($key) {
        if ($this->redis) {
            try {
                $value = $this->redis->get($key);
                return $value === false ? null : $value;
            } catch (Exception $e) {
                error_log("Local Redis Get Error: " . $e->getMessage());
                return null;
            }
        }

        return $this->get_via_curl($key);
    }

    public function put($key, $value, $seconds = 3600) {
        if ($this->redis) {
            try {
                return $this->redis->setex($key, $seconds, $value);
            } catch (Exception $e) {
                error_log("Local Redis Put Error: " . $e->getMessage());
                return false;
            }
        }

        return $this->put_via_curl($key, $value, $seconds);
    }

    public function clear_cache() {
        if ($this->redis) {
            return $this->redis->flushDB();
        }

        return $this->clear_via_curl();
    }

    // --- Private cURL methods ---
    
    private function put_via_curl($key, $value, $seconds) {
        try {
            $url = $this->baseUrl . "/setex/$key/$seconds/" . urlencode($value);
            
            $ch = curl_init($url);
            if ($ch === false) {
                throw new Exception("Failed to initialize cURL.");
            }

            curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer ".$this->token]);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 3); 

            $response = curl_exec($ch);

            if ($response === false) {
                throw new Exception("cURL Error on $url: " . curl_error($ch));
            }

            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if ($httpCode >= 400) {
                throw new Exception("HTTP Error $httpCode. Response: " . substr($response, 0, 200));
            }

            curl_close($ch);

        } catch (Exception $e) {
            error_log("KV Put Error for key '{$key}': " . $e->getMessage());
        }
    }

    private function get_via_curl($key) {
        try {
            $url = $this->baseUrl . "/get/$key";
            
            $ch = curl_init($url);
            if ($ch === false) {
                throw new Exception("Failed to initialize cURL.");
            }

            curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer ".$this->token]);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 3); 

            $response = curl_exec($ch);

            if ($response === false) {
                throw new Exception("cURL Error on $url: " . curl_error($ch));
            }

            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if ($httpCode >= 400) {
                throw new Exception("HTTP Error $httpCode. Response: " . substr($response, 0, 200));
            }

            curl_close($ch);
            
            $data = json_decode($response, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception("JSON Decode Error: " . json_last_error_msg());
            }

            return $data['result'] ?? null;

        } catch (Exception $e) {
            error_log("KV Get Error for key '{$key}': " . $e->getMessage());
            return null;
        }
    }

    private function clear_via_curl() {
        try {
            $endpoint = $this->baseUrl . '/flushdb';

            $ch = curl_init($endpoint);
            if ($ch === false) {
                throw new Exception("Failed to initialize cURL.");
            }

            curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer ".$this->token]);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 5); 

            $response = curl_exec($ch);

            if ($response === false) {
                throw new Exception("cURL Error on $endpoint: " . curl_error($ch));
            }

            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if ($httpCode >= 400) {
                throw new Exception("HTTP Error $httpCode. Response: " . substr($response, 0, 200));
            }

            curl_close($ch);

            error_log("KV Cache Flushed Successfully.");            
            return "Cache cleared successfully! Response: " . $response;

        } catch (Exception $e) {
            error_log("KV Flush Error: " . $e->getMessage());
            return "Failed to clear cache: " . $e->getMessage();
        }
    }   
}