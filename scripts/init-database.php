<?php
/**
 * Initialize Database Setup
 * Run this once to set up the database structure and statuses
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../application/core/database_setup.php';

echo "\n========================================\n";
echo "Lead Status Database Setup\n";
echo "========================================\n\n";

try {
    $setup = new LeadStatusSetup($pdo);
    $setup->setupStatuses();
    echo "\n✓ Setup completed!\n\n";
} catch (Exception $e) {
    echo "\n✗ Setup failed: " . $e->getMessage() . "\n\n";
    exit(1);
}
?>
