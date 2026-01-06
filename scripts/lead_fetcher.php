<?php
/**
 * Lead Fetcher - Fetch pending leads for calling
 * Runs every 10 minutes via cron job
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../application/core/database_setup.php';

class LeadFetcher {
    private $pdo;
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
    }
    
    /**
     * Fetch leads that need to be called
     * Criteria:
     * - Status is "New" or "Pending"
     * - Has assigned agent (assigned > 0)
     * - Has client phone number
     * - Has not been called yet (lastcontact is NULL)
     */
    public function fetchPendingLeads($limit = 10) {
        try {
            // Get status IDs
            $newStatusId = LeadStatusSetup::getStatusId($this->pdo, 'New');
            $pendingStatusId = LeadStatusSetup::getStatusId($this->pdo, 'Pending');

            $callCompletedStatusId = LeadStatusSetup::getStatusId($this->pdo, 'Call Completed');

            // Status X from .env
            $retryStatusName = env('LEAD_RETRY_STATUS', 'Pending');
            $retryStatusId = LeadStatusSetup::getStatusId($this->pdo, $retryStatusName);
            echo "\n";

            // $dateadded today

            $dateadded = "AND l.dateadded >= NOW() - INTERVAL 5 MINUTE";
            // print_r($dateadded); die;// --- DEBUG ---

            // --- QUERY A: 10 Latest Fresh Leads ---
            // AND l.lastcontact IS NULL
            $sqlFresh = "SELECT 
                        l.id,
                        l.name,
                        l.email,
                        l.phonenumber,
                        l.company,
                        l.assigned,
                        l.status,
                        l.dateadded,
                        l.lastcontact,
                        a.staffid as agent_id,
                        a.phonenumber as agent_phone,
                        a.firstname as agent_name
                    FROM tblleads l
                    LEFT JOIN tblstaff a ON a.staffid = l.assigned
                    WHERE l.status NOT IN (?)
                    $dateadded
                    AND l.assigned > 0
                    AND l.lastcontact IS NULL
                    AND l.phonenumber IS NOT NULL
                    AND l.phonenumber != ''
                    AND NOT EXISTS (SELECT 1 FROM tblcall_sessions cs WHERE cs.lead_id = l.id)
                    ORDER BY l.dateadded DESC
                    LIMIT 10";
            
            $stmtFresh = $this->pdo->prepare($sqlFresh);
            $stmtFresh->execute([$callCompletedStatusId]);

            $freshLeads = $stmtFresh->fetchAll(PDO::FETCH_ASSOC);

            // Collect IDs to exclude from the next query
            $freshIds = array_column($freshLeads, 'id');
            

            // Build placeholders for NOT IN clause if we have fresh leads
            $notInClause = "";
            $params = [$retryStatusId];
            
            if (!empty($freshIds)) {
                $placeholders = implode(',', array_fill(0, count($freshIds), '?'));
                $notInClause = " AND l.id NOT IN ($placeholders) ";
                $params = array_merge($params, $freshIds);
            }

            // --- QUERY B: Leads with Status X (e.g., Pending) ---
            $limit = count($freshLeads) > 5 ? 5 : 10;

            $sqlRetry = "SELECT 
                        l.id,
                        l.name,
                        l.email,
                        l.phonenumber,
                        l.company,
                        l.assigned,
                        l.status,
                        l.dateadded,
                        l.lastcontact,
                        a.staffid as agent_id,
                        a.phonenumber as agent_phone,
                        a.firstname as agent_name
                    FROM tblleads l
                    LEFT JOIN tblstaff a ON a.staffid = l.assigned
                    WHERE l.status = ?
                    AND (l.lastcontact IS NULL OR l.lastcontact <= NOW() - INTERVAL 2 HOUR)
                    AND l.assigned > 0
                    AND l.phonenumber IS NOT NULL
                    AND l.phonenumber != ''
                    $notInClause
                    ORDER BY l.lastcontact ASC
                    LIMIT $limit";
                    
            $stmtRetry = $this->pdo->prepare($sqlRetry);
            $stmtRetry->execute($params);
            $retryLeads = $stmtRetry->fetchAll(PDO::FETCH_ASSOC);

            return array_merge($freshLeads, $retryLeads);


        } catch (Exception $e) {
            error_log("Error fetching pending leads: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Process leads - initiate calling
     */
    public function processLeads() {
        $limit = env('MAX_LEADS_PER_RUN', 10);
       
        $leads = $this->fetchPendingLeads($limit);
        print_r($leads); die;// --- DEBUG ---
        if (empty($leads)) {
            $this->log("No pending leads to process");
            return ['success' => true, 'processed' => 0];
        }
        
        $this->log("Found " . count($leads) . " leads to process");

        $orchestrator = new CallOrchestrator($this->pdo);
        $processed = 0;
        $failed = 0;
        
        foreach ($leads as $lead) {
            try {
                if ($orchestrator->initiateCall($lead)) {
                    $processed++;
                    $this->log("✓ Initiated call for lead {$lead['id']}: {$lead['name']}");
                } else {
                    $failed++;
                    $this->log("✗ Failed to initiate call for lead {$lead['id']}");
                }
            } catch (Exception $e) {
                $failed++;
                $this->log("✗ Error processing lead {$lead['id']}: " . $e->getMessage());
            }

            sleep(10); // Brief pause between calls
        }
        
        return [
            'success' => true,
            'total' => count($leads),
            'processed' => $processed,
            'failed' => $failed
        ];
    }
    
    /**
     * Log messages
     */
    private function log($message) {
        $logFile = LOGS_PATH . '/lead_fetcher.log';
        if (!is_dir(LOGS_PATH)) {
            mkdir(LOGS_PATH, 0755, true);
        }
        
        $timestamp = date('Y-m-d H:i:s');
        file_put_contents(
            $logFile,
            "[$timestamp] $message\n",
            FILE_APPEND
        );
        
        echo "$message\n";
    }
}

// Run if called from CLI or cron
if (php_sapi_name() === 'cli' || php_sapi_name() === 'cli-server') {
    echo "\n========================================\n";
    echo "Lead Fetcher - " . date('Y-m-d H:i:s') . "\n";
    echo "========================================\n\n";
    
    try {
        echo "\n";
        
        $fetcher = new LeadFetcher($pdo);
        $result = $fetcher->processLeads();
        
        echo "\n✓ Processing completed!\n";
        if (!empty($result)) {
            echo "  Total: " . ($result['total'] ?? 0) . "\n";
            echo "  Processed: " . ($result['processed'] ?? 0) . "\n";
            echo "  Failed: " . ($result['failed'] ?? 0) . "\n";
        }
        echo "\n";
        
    } catch (Exception $e) {
        echo "\n✗ Error: " . $e->getMessage() . "\n\n";
        exit(1);
    }
}
?>
