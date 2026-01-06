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
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
    }
    
    /**
     * Add status if it doesn't exist
     */
    public function addStatus($statusData) {
        $sql = "SELECT id FROM tblleads_status WHERE name = ?";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$statusData['name']]);
        
        if ($stmt->rowCount() > 0) {
            // echo "  • Status '{$statusData['name']}' already exists\n";
            return;
        }
        
        $sql = "INSERT INTO tblleads_status (name, statusorder, color) 
                VALUES (?, ?, ?)";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            $statusData['name'],
            $statusData['statusorder'] ?? null,
            $statusData['color'] ?? null
        ]);

        // echo "  ✓ Created status: {$statusData['name']}\n";
        
        return $this->pdo->lastInsertId();
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

// // Initialize and run setup if called directly
// if (php_sapi_name() === 'cli' || php_sapi_name() === 'cli-server') {
//     // Only run if not already being auto-loaded by bootstrap
//     if (basename(__FILE__) === basename($_SERVER['PHP_SELF'] ?? '')) {
//         echo "\n========================================\n";
//         echo "Lead Status Database Setup\n";
//         echo "========================================\n\n";
        
//         try {
//             // PDO should be available from config.php via bootstrap
//             if (!isset($pdo)) {
//                 require_once __DIR__ . '/../../config/config.php';
//             }
            
//             $setup = new LeadStatusSetup($pdo);
//             // $setup->setupStatuses();
//             echo "\n✓ Setup completed!\n\n";
//         } catch (Exception $e) {
//             echo "\n✗ Setup failed: " . $e->getMessage() . "\n\n";
//             exit(1);
//         }
//     }
// }
?>
