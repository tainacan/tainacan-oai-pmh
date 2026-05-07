<?php
namespace Tainacan_OAI_PMH;

if (!defined('ABSPATH')) exit;

class Rate_Limiter {
    
    private $table;
    
    public function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . 'tainacan_oai_rate_limits';
    }
    
    public function check() {
        if (!Settings::get('rate_limit_enabled', true)) return true;
        
        $ip = $this->get_client_ip();
        if ($this->is_whitelisted($ip)) return true;
        
        global $wpdb;
        $now = current_time('mysql');
        $threshold = (int) Settings::get('rate_limit_threshold', 60);
        
        // Check if blocked
        $blocked = $wpdb->get_var($wpdb->prepare(
            "SELECT blocked_until FROM {$this->table} WHERE ip_address = %s AND blocked_until > %s",
            $ip, $now
        ));
        
        if ($blocked) {
            return new \WP_Error('rate_limited', __('Too many requests. Please try again later.', 'tainacan-oai-pmh'));
        }
        
        // Get or create record
        $record = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE ip_address = %s", $ip
        ));
        
        $window_start = gmdate('Y-m-d H:i:s', strtotime('-1 minute'));
        
        if ($record) {
            if ($record->window_start < $window_start) {
                // Reset window
                $wpdb->update($this->table, [
                    'request_count' => 1,
                    'window_start' => $now,
                    'blocked_until' => null,
                ], ['ip_address' => $ip]);
            } else {
                $new_count = $record->request_count + 1;
                
                if ($new_count > $threshold) {
                    // Block for 10 minutes
                    $wpdb->update($this->table, [
                        'blocked_until' => gmdate('Y-m-d H:i:s', strtotime('+10 minutes')),
                    ], ['ip_address' => $ip]);
                    return new \WP_Error('rate_limited', __('Too many requests. Blocked for 10 minutes.', 'tainacan-oai-pmh'));
                }
                
                $wpdb->update($this->table, ['request_count' => $new_count], ['ip_address' => $ip]);
            }
        } else {
            $wpdb->insert($this->table, [
                'ip_address' => $ip,
                'request_count' => 1,
                'window_start' => $now,
            ]);
        }
        
        return true;
    }
    
    private function is_whitelisted($ip) {
        $whitelist = Settings::get('rate_limit_whitelist', '');
        if (empty($whitelist)) return false;
        
        $lines = array_filter(array_map('trim', explode("\n", $whitelist)));
        foreach ($lines as $line) {
            if ($line === $ip) return true;
            if (strpos($line, '/') !== false && $this->ip_in_cidr($ip, $line)) return true;
        }
        return false;
    }
    
    private function ip_in_cidr($ip, $cidr) {
        list($subnet, $bits) = explode('/', $cidr);
        $ip_long = ip2long($ip);
        $subnet_long = ip2long($subnet);
        $mask = -1 << (32 - (int)$bits);
        return ($ip_long & $mask) === ($subnet_long & $mask);
    }
    
    private function get_client_ip() {
        foreach (['HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $h) {
            if (!empty($_SERVER[$h])) {
                return trim(explode(',', sanitize_text_field(wp_unslash($_SERVER[$h])))[0]);
            }
        }
        return '0.0.0.0';
    }
    
    public function get_blocked() {
        global $wpdb;
        return $wpdb->get_results(
            "SELECT * FROM {$this->table} WHERE blocked_until > NOW() ORDER BY blocked_until DESC"
        );
    }
    
    public function unblock($ip) {
        global $wpdb;
        $wpdb->update($this->table, ['blocked_until' => null, 'request_count' => 0], ['ip_address' => $ip]);
    }
    
    public function cleanup($days = 7) {
        global $wpdb;
        return $wpdb->query($wpdb->prepare(
            "DELETE FROM {$this->table} WHERE window_start < %s AND blocked_until IS NULL",
            gmdate('Y-m-d H:i:s', strtotime("-$days days"))
        ));
    }
}
