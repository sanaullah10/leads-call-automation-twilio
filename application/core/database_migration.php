<?php
/**
 * Database Migration - Create required tables
 */

// Load config if not already loaded
if (!defined('BASE_PATH')) {
    require_once __DIR__ . '/../../config/config.php';
}

class DatabaseMigration {
    private $pdo;
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
    }
    
    /**
     * Create call_sessions table
     */
    public function createCallSessionsTable() {
        try {
            $sql = "CREATE TABLE IF NOT EXISTS `tblcall_sessions` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `lead_id` int(11) NOT NULL,
                `agent_id` int(11) NOT NULL,
                `agent_call_sid` varchar(100) COLLATE utf8mb4_unicode_ci,
                `client_call_sid` varchar(100) COLLATE utf8mb4_unicode_ci,
                `status` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT 'initiated',
                `started_at` datetime,
                `ended_at` datetime,
                `duration` int(11),
                `recording_url` text COLLATE utf8mb4_unicode_ci,
                `created_at` timestamp DEFAULT CURRENT_TIMESTAMP,
                `updated_at` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_lead_id` (`lead_id`),
                KEY `idx_agent_id` (`agent_id`),
                KEY `idx_status` (`status`),
                KEY `idx_created_at` (`created_at`),
                FOREIGN KEY (`lead_id`) REFERENCES `tblleads` (`id`) ON DELETE CASCADE,
                FOREIGN KEY (`agent_id`) REFERENCES `tblstaff` (`staffid`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
            
            $this->pdo->exec($sql);
            echo "✓ tblcall_sessions table created\n";
            return true;
            
        } catch (Exception $e) {
            if (strpos($e->getMessage(), 'already exists') !== false) {
                echo "  • tblcall_sessions table already exists\n";
                return true;
            }
            echo "✗ Error creating tblcall_sessions: " . $e->getMessage() . "\n";
            return false;
        }
    }
    
    /**
     * Run all migrations
     */
    public function migrate() {
        echo "\n========================================\n";
        echo "Database Migration\n";
        echo "========================================\n\n";
        
        $this->createCallSessionsTable();
        
        echo "\n✓ Migration completed!\n";
    }
}

// Run if called from CLI
if (php_sapi_name() === 'cli' || php_sapi_name() === 'cli-server') {
    try {
        $migration = new DatabaseMigration($pdo);
        $migration->migrate();
    } catch (Exception $e) {
        echo "\n✗ Migration failed: " . $e->getMessage() . "\n";
        exit(1);
    }
}
?>
