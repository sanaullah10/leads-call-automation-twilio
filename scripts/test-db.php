<?php
/**
 * Database Connection Tester
 * Tests if the database connection is working
 */

// Load bootstrap
require_once __DIR__ . '/../bootstrap.php';

echo "\n" . str_repeat("=", 60) . "\n";
echo "DATABASE CONNECTION TESTER\n";
echo str_repeat("=", 60) . "\n\n";

// Display loaded configuration
echo "📋 Configuration Loaded:\n";
echo "  DB_DSN: " . env('DB_DSN', 'NOT SET') . "\n";
echo "  DB_USER: " . env('DB_USER', 'NOT SET') . "\n";
echo "  DB_PASS: " . (env('DB_PASS') ? '✓ SET' : 'EMPTY') . "\n";
echo "\n";

// Test connection
echo "🔗 Testing Database Connection...\n";
try {
    $stmt = $pdo->query("SELECT 1 as test");
    $result = $stmt->fetch();
    
    if ($result && $result['test'] == 1) {
        echo "✅ SUCCESS: Database connection is working!\n\n";
    } else {
        echo "❌ FAILED: Connection established but query failed\n\n";
        exit(1);
    }
} catch (Exception $e) {
    echo "❌ FAILED: " . $e->getMessage() . "\n\n";
    exit(1);
}

// Check tables
echo "📊 Checking Required Tables:\n";

$tables = [
    'tblleads' => 'Leads',
    'tblleads_status' => 'Lead Statuses',
    'tblstaff' => 'Staff/Agents',
    'tblcall_sessions' => 'Call Sessions (created by system)'
];

foreach ($tables as $table => $description) {
    try {
        $stmt = $pdo->query("SELECT COUNT(*) as count FROM `$table` LIMIT 1");
        $result = $stmt->fetch();
        echo "  ✅ $table ($description): " . $result['count'] . " records\n";
    } catch (Exception $e) {
        if (strpos($e->getMessage(), 'exist') !== false) {
            echo "  ⚠️  $table ($description): Table not found\n";
        } else {
            echo "  ❌ $table ($description): Error - {$e->getMessage()}\n";
        }
    }
}

echo "\n";

// Test lead query
echo "🎯 Testing Lead Query:\n";
try {
    $stmt = $pdo->query("
        SELECT l.id, l.name, l.phonenumber, l.assigned, s.firstname 
        FROM tblleads l 
        LEFT JOIN tblstaff s ON s.staffid = l.assigned 
        LIMIT 5
    ");
    $leads = $stmt->fetchAll();
    
    if (count($leads) > 0) {
        echo "  ✅ Found " . count($leads) . " leads\n";
        foreach ($leads as $lead) {
            echo "    - ID: {$lead['id']}, Name: {$lead['name']}, Agent: {$lead['firstname']}\n";
        }
    } else {
        echo "  ⚠️  No leads found in database\n";
    }
} catch (Exception $e) {
    echo "  ❌ Error: " . $e->getMessage() . "\n";
}

echo "\n" . str_repeat("=", 60) . "\n";
echo "✅ All tests completed!\n";
echo str_repeat("=", 60) . "\n\n";
?>
