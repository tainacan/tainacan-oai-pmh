<?php
/**
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
 * phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
 */
namespace Tainacan_OAI_PMH;

if (!defined('ABSPATH')) exit;

class Rate_Limiter {

    private string $table;

    public function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . 'tainacan_oai_rate_limits';
    }

    public function check() {
        if (!Settings::get('rate_limit_enabled', true)) return true;

        $ip = $this->get_client_ip();
        if ($ip === '0.0.0.0' || $this->is_whitelisted($ip)) return true;

        global $wpdb;
        $now_utc = gmdate('Y-m-d H:i:s');
        $window_start = gmdate('Y-m-d H:i:s', time() - 60);
        $threshold = max(1, (int) Settings::get('rate_limit_threshold', 60));

        // Atomic upsert: increments request_count if same window, resets if expired.
        // Single statement → no race condition possible.
        $wpdb->query($wpdb->prepare(
            "INSERT INTO {$this->table} (ip_address, request_count, window_start, blocked_until)
             VALUES (%s, 1, %s, NULL)
             ON DUPLICATE KEY UPDATE
                request_count = IF(window_start < %s, 1, request_count + 1),
                window_start = IF(window_start < %s, %s, window_start)",
            $ip, $now_utc, $window_start, $window_start, $now_utc
        ));

        $record = $wpdb->get_row($wpdb->prepare(
            "SELECT request_count, blocked_until FROM {$this->table} WHERE ip_address = %s",
            $ip
        ));

        if ($record && $record->blocked_until && $record->blocked_until > $now_utc) {
            return new \WP_Error('rate_limited', __('Too many requests. Please try again later.', 'tainacan-oai-pmh'));
        }

        if ($record && (int) $record->request_count > $threshold) {
            $wpdb->update($this->table, [
                'blocked_until' => gmdate('Y-m-d H:i:s', time() + 600),
            ], ['ip_address' => $ip]);
            return new \WP_Error('rate_limited', __('Too many requests. Blocked for 10 minutes.', 'tainacan-oai-pmh'));
        }

        return true;
    }

    private function is_whitelisted(string $ip): bool {
        $whitelist = Settings::get('rate_limit_whitelist', '');
        if (empty($whitelist)) return false;

        $lines = array_filter(array_map('trim', explode("\n", $whitelist)));
        foreach ($lines as $line) {
            if ($line === $ip) return true;
            if (strpos($line, '/') !== false && $this->ip_in_cidr($ip, $line)) return true;
        }
        return false;
    }

    private function ip_in_cidr(string $ip, string $cidr): bool {
        if (!str_contains($cidr, '/')) return false;
        [$subnet, $bits] = explode('/', $cidr, 2);
        $ip_long = ip2long($ip);
        $subnet_long = ip2long($subnet);
        if ($ip_long === false || $subnet_long === false) return false;
        $bits = (int) $bits;
        if ($bits < 0 || $bits > 32) return false;
        $mask = $bits === 0 ? 0 : (-1 << (32 - $bits));
        return ($ip_long & $mask) === ($subnet_long & $mask);
    }

    private function get_client_ip(): string {
        // REMOTE_ADDR first (untrusted forwarded headers can spoof IP)
        if (!empty($_SERVER['REMOTE_ADDR'])) {
            $ip = sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR']));
            if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
        }
        // Behind trusted proxy: only trust X-Forwarded-For if explicitly enabled
        if (Settings::get('trust_proxy_headers', false)) {
            foreach (['HTTP_X_FORWARDED_FOR', 'HTTP_CLIENT_IP'] as $h) {
                if (!empty($_SERVER[$h])) {
                    $forwarded = explode(',', sanitize_text_field(wp_unslash($_SERVER[$h])))[0];
                    $ip = trim($forwarded);
                    if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
                }
            }
        }
        return '0.0.0.0';
    }

    public function get_blocked() {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE blocked_until > %s ORDER BY blocked_until DESC",
            gmdate('Y-m-d H:i:s')
        ));
    }

    public function unblock(string $ip): void {
        global $wpdb;
        $wpdb->update($this->table, ['blocked_until' => null, 'request_count' => 0], ['ip_address' => $ip]);
    }

    public function cleanup(int $days = 7): int {
        global $wpdb;
        return (int) $wpdb->query($wpdb->prepare(
            "DELETE FROM {$this->table} WHERE window_start < %s AND blocked_until IS NULL",
            gmdate('Y-m-d H:i:s', time() - ($days * 86400))
        ));
    }
}
