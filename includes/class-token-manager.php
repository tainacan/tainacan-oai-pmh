<?php
namespace Tainacan_OAI_PMH;

if (!defined('ABSPATH')) exit;

class Token_Manager {
    
    private $table;
    
    public function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . 'tainacan_oai_tokens';
    }
    
    public function create($data) {
        global $wpdb;
        
        $token = bin2hex(random_bytes(32));
        $expiry_hours = (int) Settings::get('token_expiry', 24);
        
        $wpdb->insert($this->table, [
            'token' => $token,
            'data' => wp_json_encode($data),
            'created_at' => current_time('mysql'),
            'expires_at' => gmdate('Y-m-d H:i:s', time() + ($expiry_hours * 3600)),
        ]);
        
        return $token;
    }
    
    public function get($token) {
        global $wpdb;
        
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) return false;
        
        $record = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE token = %s AND expires_at > %s",
            $token, current_time('mysql')
        ));
        
        if (!$record) return false;
        
        return json_decode($record->data, true);
    }
    
    public function delete($token) {
        global $wpdb;
        $wpdb->delete($this->table, ['token' => $token]);
    }
    
    public function cleanup() {
        global $wpdb;
        return $wpdb->query($wpdb->prepare(
            "DELETE FROM {$this->table} WHERE expires_at < %s",
            current_time('mysql')
        ));
    }
}
