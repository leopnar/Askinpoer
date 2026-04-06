<?php
class NetflixTokenChecker {
    private $session;
    private $headers;
    private $api_url;
    
    public function __construct() {
        $this->headers = [
            'User-Agent' => 'com.netflix.mediaclient/63884 (Linux; U; Android 13; ro; M2007J3SG; Build/TQ1A.230205.001.A2; Cronet/143.0.7445.0)',
            'Accept' => 'multipart/mixed;deferSpec=20220824, application/graphql-response+json, application/json',
            'Content-Type' => 'application/json',
            'Origin' => 'https://www.netflix.com',
            'Referer' => 'https://www.netflix.com/'
        ];
        $this->api_url = 'https://android13.prod.ftl.netflix.com/graphql';
    }
    
    public function parseNetscapeCookieLine($line) {
        $parts = explode("\t", trim($line));
        if (count($parts) >= 7) {
            $name = $parts[5];
            $value = $parts[6];
            return [$name => $value];
        }
        return [];
    }
    
    public function parseNetscapeCookies($content) {
        $cookies_list = [];
        $current_cookie_set = [];
        $lines = explode("\n", $content);
        
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line) || strpos($line, '#') === 0) continue;
            
            $cookie = $this->parseNetscapeCookieLine($line);
            if ($cookie) {
                $current_cookie_set = array_merge($current_cookie_set, $cookie);
                
                if (isset($current_cookie_set['NetflixId']) && 
                    isset($current_cookie_set['SecureNetflixId']) && 
                    isset($current_cookie_set['nfvdid'])) {
                    $cookies_list[] = $current_cookie_set;
                    $current_cookie_set = [];
                }
            }
        }
        return $cookies_list;
    }
    
    public function extractCookiesFromText($text) {
        $cookies_list = [];
        
        // Try Netscape format first
        if (strpos($text, "\t") !== false && (strpos($text, 'NetflixId') !== false || strpos($text, 'nfvdid') !== false)) {
            $netscape_cookies = $this->parseNetscapeCookies($text);
            if (!empty($netscape_cookies)) return $netscape_cookies;
        }
        
        // Try JSON format
        $data = json_decode($text, true);
        if ($data) {
            if (is_array($data)) {
                foreach ((array)$data as $item) {
                    if (is_array($item)) {
                        $cookie_dict = [];
                        $required = ['NetflixId', 'SecureNetflixId', 'nfvdid', 'OptanonConsent'];
                        foreach ($required as $key) {
                            if (isset($item[$key])) $cookie_dict[$key] = $item[$key];
                        }
                        if (!empty($cookie_dict)) $cookies_list[] = $cookie_dict;
                    }
                }
            }
        }
        
        // Try raw cookie string format
        if (empty($cookies_list)) {
            $cookie_dict = [];
            $patterns = [
                '/(NetflixId=[^;\s]+)/',
                '/(SecureNetflixId=[^;\s]+)/',
                '/(nfvdid=[^;\s]+)/',
                '/(OptanonConsent=[^;\s]+)/'
            ];
            
            foreach ($patterns as $pattern) {
                if (preg_match_all($pattern, $text, $matches)) {
                    foreach ($matches[1] as $match) {
                        [$key, $value] = explode('=', $match, 2);
                        $cookie_dict[$key] = $value;
                    }
                }
            }
            if (!empty($cookie_dict)) $cookies_list[] = $cookie_dict;
        }
        
        return $cookies_list;
    }
    
    public function buildCookieString($cookie_dict) {
        $cookie_parts = [];
        foreach ($cookie_dict as $key => $value) {
            $cookie_parts[] = "$key=$value";
        }
        return implode('; ', $cookie_parts);
    }
    
    public function checkCookie($cookie_dict) {
        $required_cookies = ['NetflixId', 'SecureNetflixId', 'nfvdid'];
        $missing = [];
        foreach ($required_cookies as $c) {
            if (!isset($cookie_dict[$c])) $missing[] = $c;
        }
        
        if (!empty($missing)) {
            return [false, null, "Missing required cookies: " . implode(', ', $missing)];
        }
        
        $cookie_str = $this->buildCookieString($cookie_dict);
        
        $payload = [
            "operationName" => "CreateAutoLoginToken",
            "variables" => ["scope" => "WEBVIEW_MOBILE_STREAMING"],
            "extensions" => [
                "persistedQuery" => [
                    "version" => 102,
                    "id" => "76e97129-f4b5-41a0-a73c-12e674896849"
                ]
            ]
        ];
        
        $headers = $this->headers;
        $headers['Cookie'] = $cookie_str;
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $this->api_url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => array_map(function($k, $v) { return "$k: $v"; }, array_keys($headers), $headers),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => false
        ]);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($http_code === 200) {
            $data = json_decode($response, true);
            if (isset($data['data']['createAutoLoginToken'])) {
                $token = $data['data']['createAutoLoginToken'];
                return [true, $token, null];
            } elseif (isset($data['errors'])) {
                return [false, null, "API Error: " . json_encode($data['errors'], JSON_PRETTY_PRINT)];
            } else {
                return [false, null, "Unexpected response: " . substr($response, 0, 200)];
            }
        }
        return [false, null, "HTTP $http_code: " . substr($response, 0, 200)];
    }
    
    public function formatNftokenLink($token) {
        return "https://netflix.com/?nftoken=$token";
    }
}
?>