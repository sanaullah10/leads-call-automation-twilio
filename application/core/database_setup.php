<?php
/**
 * Database Setup - Initialize Required Lead Statuses
 * This script ensures all necessary statuses exist in tblleads_status table
 */

// Load config if not already loaded
if (!defined('BASE_PATH')) {
    require_once __DIR__ . '/../../config/config.php';
}

class LeadStatusSetup {
    private $pdo;
    
    // Define required statuses for the calling workflow
    private $requiredStatuses = [
        [
            'name' => 'New',
            'statusorder' => 1,
            'color' => '#28B8DA',
            'description' => 'New lead'
        ],
        [
            'name' => 'Calling Agent',
            'statusorder' => 2,
            'color' => '#FF9800',
            'description' => 'System is calling the assigned agent'
        ],
        [
            'name' => 'Agent Connected',
            'statusorder' => 3,
            'color' => '#4CAF50',
            'description' => 'Agent has accepted the call'
        ],
        [
            'name' => 'Calling Client',
            'statusorder' => 4,
            'color' => '#2196F3',
            'description' => 'System is calling the client'
        ],
        [
            'name' => 'Client Connected',
            'statusorder' => 5,
            'color' => '#4CAF50',
            'description' => 'Client is connected with agent'
        ],
        [
            'name' => 'Call Completed',
            'statusorder' => 6,
            'color' => '#8BC34A',
            'description' => 'Call completed successfully'
        ],
        [
            'name' => 'Agent Unavailable',
            'statusorder' => 7,
            'color' => '#F44336',
            'description' => 'Agent did not answer or is unavailable'
        ],
        [
            'name' => 'Client Unavailable',
            'statusorder' => 8,
            'color' => '#F44336',
            'description' => 'Client did not answer or is unavailable'
        ],
        [
            'name' => 'Call Failed',
            'statusorder' => 9,
            'color' => '#D32F2F',
            'description' => 'Call failed to connect'
        ],
        [
            'name' => 'Pending',
            'statusorder' => 10,
            'color' => '#FFC107',
            'description' => 'Waiting to be called'
        ]
    ];
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
    }
    
    /**
     * Setup all required statuses
     */
    public function setupStatuses() {
        try {
            foreach ($this->requiredStatuses as $status) {
                $this->addStatus($status);
            }
            echo "✓ All statuses configured successfully\n";
            return true;
        } catch (Exception $e) {
            echo "✗ Error setting up statuses: " . $e->getMessage() . "\n";
            return false;
        }
    }
    
    /**
     * Add status if it doesn't exist
     */
    private function addStatus($statusData) {
        $sql = "SELECT id FROM tblleads_status WHERE name = ?";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$statusData['name']]);
        
        if ($stmt->rowCount() > 0) {
            echo "  • Status '{$statusData['name']}' already exists\n";
            return;
        }
        
        $sql = "INSERT INTO tblleads_status (name, statusorder, color) 
                VALUES (?, ?, ?)";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            $statusData['name'],
            $statusData['statusorder'],
            $statusData['color']
        ]);
        
        echo "  ✓ Created status: {$statusData['name']}\n";
    }
    
    /**
     * Get status ID by name
     */
    public static function getStatusId($pdo, $statusName) {
        $sql = "SELECT id FROM tblleads_status WHERE name = ? LIMIT 1";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$statusName]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ? $result['id'] : null;
    }
}

// Initialize and run setup if called directly
if (php_sapi_name() === 'cli' || php_sapi_name() === 'cli-server') {
    // Only run if not already being auto-loaded by bootstrap
    if (basename(__FILE__) === basename($_SERVER['PHP_SELF'] ?? '')) {
        echo "\n========================================\n";
        echo "Lead Status Database Setup\n";
        echo "========================================\n\n";
        
        try {
            // PDO should be available from config.php via bootstrap
            if (!isset($pdo)) {
                require_once __DIR__ . '/../../config/config.php';
            }
            
            $setup = new LeadStatusSetup($pdo);
            $setup->setupStatuses();
            echo "\n✓ Setup completed!\n\n";
        } catch (Exception $e) {
            echo "\n✗ Setup failed: " . $e->getMessage() . "\n\n";
            exit(1);
        }
    }
}
?>
