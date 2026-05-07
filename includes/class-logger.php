<?php
namespace Tainacan_OAI_PMH;

if (!defined('ABSPATH')) exit;

class Logger {
    
    private $table;
    private $harvesters_table;
    
    public function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . 'tainacan_oai_logs';
        $this->harvesters_table = $wpdb->prefix . 'tainacan_oai_harvesters';
    }
    
    public function log($message, $level = 'info', $context = []) {
        if (!Settings::get('log_enabled', true)) return;
        
        global $wpdb;
        
        $wpdb->insert($this->table, [
            'level' => $level,
            'message' => $message,
            'context' => maybe_serialize($context),
            'ip_address' => $this->get_client_ip(),
            'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? substr(sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])), 0, 500) : '',
            'verb' => $context['verb'] ?? null,
            'response_time' => $context['response_time'] ?? null,
            'created_at' => current_time('mysql'),
        ]);
        
        if (!empty($_SERVER['REMOTE_ADDR'])) {
            $this->track_harvester();
        }
    }
    
    private function track_harvester() {
        global $wpdb;
        
        $ip = $this->get_client_ip();
        $ua = isset($_SERVER['HTTP_USER_AGENT']) ? substr(sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])), 0, 500) : '';
        
        $exists = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->harvesters_table} WHERE ip_address = %s",
            $ip
        ));
        
        if ($exists) {
            $wpdb->update(
                $this->harvesters_table,
                [
                    'last_seen' => current_time('mysql'),
                    'total_requests' => $exists->total_requests + 1,
                    'user_agent' => $ua,
                ],
                ['ip_address' => $ip]
            );
        } else {
            $wpdb->insert($this->harvesters_table, [
                'ip_address' => $ip,
                'user_agent' => $ua,
                'hostname' => gethostbyaddr($ip) ?: null,
                'first_seen' => current_time('mysql'),
                'last_seen' => current_time('mysql'),
                'total_requests' => 1,
                'status' => 'active',
            ]);
        }
    }
    
    private function get_client_ip() {
        $headers = ['HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'];
        foreach ($headers as $h) {
            if (!empty($_SERVER[$h])) {
                $ip = explode(',', sanitize_text_field(wp_unslash($_SERVER[$h])))[0];
                return trim($ip);
            }
        }
        return '0.0.0.0';
    }
    
    public function get_logs($args = []) {
        global $wpdb;
        
        $defaults = ['limit' => 100, 'offset' => 0, 'level' => null, 'verb' => null];
        $args = wp_parse_args($args, $defaults);
        
        $where = ['1=1'];
        $params = [];
        
        if ($args['level']) {
            $where[] = 'level = %s';
            $params[] = $args['level'];
        }
        
        if ($args['verb']) {
            $where[] = 'verb = %s';
            $params[] = $args['verb'];
        }
        
        $params[] = $args['limit'];
        $params[] = $args['offset'];
        
        $sql = "SELECT * FROM {$this->table} WHERE " . implode(' AND ', $where) . 
               " ORDER BY created_at DESC LIMIT %d OFFSET %d";
        
        return $wpdb->get_results($wpdb->prepare($sql, $params));
    }
    
    public function get_stats($period = '24 hours') {
        global $wpdb;
        
        $since = gmdate('Y-m-d H:i:s', strtotime("-$period"));
        
        return [
            'total_requests' => (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->table} WHERE created_at >= %s", $since
            )),
            'errors' => (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->table} WHERE level = 'error' AND created_at >= %s", $since
            )),
            'avg_response_time' => round((float) $wpdb->get_var($wpdb->prepare(
                "SELECT AVG(response_time) FROM {$this->table} WHERE created_at >= %s AND response_time IS NOT NULL", $since
            )), 3),
            'error_rate' => 0,
        ];
    }
    
    public function get_daily_stats($days = 14) {
        global $wpdb;
        
        $since = gmdate('Y-m-d', strtotime("-$days days"));
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT DATE(created_at) as date, 
                    COUNT(*) as total,
                    SUM(CASE WHEN level = 'error' THEN 1 ELSE 0 END) as errors
             FROM {$this->table} 
             WHERE DATE(created_at) >= %s 
             GROUP BY DATE(created_at) 
             ORDER BY date ASC",
            $since
        ));
    }
    
    public function get_harvesters($limit = 50) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->harvesters_table} ORDER BY last_seen DESC LIMIT %d",
            $limit
        ));
    }
    
    public function get_harvester_stats() {
        global $wpdb;
        
        return [
            'total' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$this->harvesters_table}"),
            'active' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$this->harvesters_table} WHERE status = 'active'"),
            'last_24h' => (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->harvesters_table} WHERE last_seen >= %s",
                gmdate('Y-m-d H:i:s', strtotime('-24 hours'))
            )),
            'total_requests' => (int) $wpdb->get_var("SELECT SUM(total_requests) FROM {$this->harvesters_table}"),
        ];
    }
    
    public function cleanup($days = 30) {
        global $wpdb;
        $date = gmdate('Y-m-d H:i:s', strtotime("-$days days"));
        return $wpdb->query($wpdb->prepare("DELETE FROM {$this->table} WHERE created_at < %s", $date));
    }
    
    public function export_csv() {
        $logs = $this->get_logs(['limit' => 10000]);
        
        $output = fopen('php://temp', 'r+');
        fputcsv($output, ['ID', 'Date', 'Level', 'Verb', 'Message', 'IP', 'User Agent', 'Response Time']);
        
        foreach ($logs as $log) {
            fputcsv($output, [
                $log->id, $log->created_at, $log->level, $log->verb,
                $log->message, $log->ip_address, $log->user_agent, $log->response_time,
            ]);
        }
        
        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);
        
        return $csv;
    }
}
