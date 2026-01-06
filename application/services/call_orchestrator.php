<?php
/**
 * Call Orchestrator - Manage Agent → Client calling workflow
 * 1. Call agent first
 * 2. Once agent connects, call client
 * 3. Connect both on same call
 * 4. Update lead status throughout process
 */

require_once __DIR__ . '/../../application/core/database_setup.php';

// This file is loaded via bootstrap.php

use Twilio\Rest\Client as TwilioClient;
use Twilio\TwiML\VoiceResponse;

class CallOrchestrator {
    private $pdo;
    private $twilio;
    private $twilioPhoneNumber;
    private $availabilityChecker;
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
        
        // Initialize Twilio
        $accountSid = env('TWILIO_SID');
        $authToken = env('TWILIO_TOKEN');
        $this->twilioPhoneNumber = env('TWILIO_PHONE_NUMBER');
        
        if (!$accountSid || !$authToken) {
            throw new Exception("Twilio credentials not configured");
        }
        
        $this->twilio = new TwilioClient($accountSid, $authToken);
        $this->availabilityChecker = new AgentAvailabilityChecker($pdo);
    }
    
    /**
     * Initiate call workflow for a lead
     */
    public function initiateCall($lead) {
        try {
            // Check if agent is available
            if ($this->availabilityChecker->isAgentAvailable($lead['assigned'])) {
                $agentId = $lead['assigned']; 
            } else {
                // get next available agent
                $agentId = $this->availabilityChecker->getNextAvailableAgent();
                if (!$agentId) {
                    $this->updateLeadStatus($lead['id'], 'Agent Unavailable');
                    $this->log("No available agents for lead {$lead['id']}");
                    return false;
                }
            }

            
            // Get agent details
            $agent = $this->availabilityChecker->getAgentDetails($agentId);

            if (!$agent || !$agent['phonenumber']) {
                // $this->updateLeadStatus($lead['id'], 'Agent Unavailable');
                $this->log("✗ Agent details not found or no phone number for agent ID $agentId");
                return false;
            }
            
            // Update lead status: Calling Agent
            // $this->updateLeadStatus($lead['id'], 'Calling Agent');

            
            // print_r($agent);die; // --- DEBUG --
            
            // Create call session record
            $callSessionId = $this->createCallSession($lead['id'], $agent['staffid']);
            

            // Make call to agent
            // The agent will receive options via IVR (press 1 to accept, 2 to reject)
            $callUrl = env('APP_URL') . '/agent/handlers/agent_call_handler.php?' . http_build_query(['call_session_id' => $callSessionId]);
            $statusCallbackUrl = env('APP_URL') . '/twilio/webhooks/call_status_webhook.php?' . http_build_query(['call_session_id' => $callSessionId, 'leg' => 'agent']);
            
            try {
                $call = $this->twilio->calls->create(
                    $agent['phonenumber'],           // To: Agent's phone
                    $this->twilioPhoneNumber,        // From: Twilio number
                    [
                        'url' => $callUrl,
                        'timeout' => env('TWILIO_CALL_TIMEOUT', 30),
                        'method' => 'GET',
                        'statusCallback' => $statusCallbackUrl,
                        'statusCallbackEvent' => 'completed',
                        'statusCallbackMethod' => 'POST'
                        // 'record' => false,
                    ]
                );
                
                // Store Twilio call SID
                $this->updateCallSession($callSessionId, ['agent_call_sid' => $call->sid]);
                
                $this->log("✓ Called agent {$agent['firstname']} ({$agent['phonenumber']}) for lead {$lead['id']}");
                return true;
                
            } catch (Exception $e) {
                $this->updateLeadStatus($lead['id'], 'Call Failed');
                $this->log("✗ Failed to call agent: " . $e->getMessage());
                return false;
            }
            
        } catch (Exception $e) {
            $this->log("✗ Error initiating call: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Create call session record in database
     */
    private function createCallSession($leadId, $agentId) {
        try {
            // Create call log table if needed
            $sql = "INSERT INTO tblcall_sessions 
                    (lead_id, agent_id, started_at, status) 
                    VALUES (?, ?, NOW(), 'initiated')";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$leadId, $agentId]);
            
            return $this->pdo->lastInsertId();
        } catch (Exception $e) {
            // Table might not exist, log error
            error_log("Error creating call session: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Update call session
     */
    public function updateCallSession($sessionId, $data) {
        $this->log("Updating call session $sessionId with data: " . json_encode($data));
        if (!$sessionId) return false;
        
        try {
            $updates = [];
            $values = [];
            
            foreach ($data as $key => $value) {
                $updates[] = "$key = ?";
                $values[] = $value;
            }
            
            $values[] = $sessionId;
            
            $sql = "UPDATE tblcall_sessions SET " . implode(', ', $updates) . " WHERE id = ?";
            $stmt = $this->pdo->prepare($sql);
            
            return $stmt->execute($values);
        } catch (Exception $e) {
            error_log("Error updating call session: " . $e->getMessage());
            return false;
        }
    }

    public function updateLeadStatusBySession($sessionId, $leadStatusName, $callStatus = null) {
        $sql = "SELECT lead_id FROM tblcall_sessions WHERE id = ? LIMIT 1";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$sessionId]);
        $session = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($session) {
            if ($callStatus) {
                $this->updateCallSession($sessionId, [
                'status' => strtolower(str_replace(' ', '_', $callStatus)),
            ]);
            }

            // If busy, we update lastcontact but keep it 'Pending' for next cron run
            $this->updateLeadStatus($session['lead_id'], $leadStatusName);
            
            // Update the lastcontact to 'now' so lead_fetcher knows it was just tried
            $updateTime = "UPDATE tblleads SET lastcontact = NOW() WHERE id = ?";
            $this->pdo->prepare($updateTime)->execute([$session['lead_id']]);
        }
    }
    
    /**
     * Get call session
     */
    private function getCallSession($sessionId) {
        try {
            $sql = "SELECT * FROM tblcall_sessions WHERE id = ? LIMIT 1";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$sessionId]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            return null;
        }
    }
    
    /**
     * Update lead status
     */
    public function updateLeadStatus($leadId, $statusName, $sessionId = null) {
        try {
            $this->log("Updating lead $leadId status to '$statusName'");
            $statusId = LeadStatusSetup::getStatusId($this->pdo, $statusName);

            if (!$statusId) {
                // create new status if not found
                $statusId = (new LeadStatusSetup($this->pdo))->addStatus([
                    'name' => $statusName
                ]);

                // $this->log("✗ Status '$statusName' not found");
                // return false;
            }

            if($statusName == 'Call Completed'){
                if ($sessionId) {
                    // update call session to completed
                    $this->updateCallSession($sessionId, [
                        'status' => 'completed',
                        'ended_at' => date('Y-m-d H:i:s')
                    ]);
                }

                // For 'Call Completed', also update lastcontact to now
                $sql = "UPDATE tblleads 
                        SET status = ?, 
                            lastcontact = NOW(),
                            last_status_change = NOW()
                        WHERE id = ?";
                
                $stmt = $this->pdo->prepare($sql);
                return $stmt->execute([$statusId, $leadId]);
            }
            
            $sql = "UPDATE tblleads 
                    SET status = ?, 
                        last_status_change = NOW()
                    WHERE id = ?";
            
            $stmt = $this->pdo->prepare($sql);
            return $stmt->execute([$statusId, $leadId]);
        } catch (Exception $e) {
            $this->log("✗ Error updating status: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Handle call completion
     */
    public function onCallCompleted($callSessionId, $callData = []) {
        try {
            $session = $this->getCallSession($callSessionId);
            if (!$session) return false;
            
            // Update session with final data
            $this->updateCallSession($callSessionId, array_merge([
                'status' => 'completed',
                'ended_at' => date('Y-m-d H:i:s'),
                'duration' => $callData['duration'] ?? null
            ]));
            
            // Update lead status
            $this->updateLeadStatus($session['lead_id'], 'Call Completed');
            
            $this->log("✓ Call completed for lead {$session['lead_id']}");
            return true;
            
        } catch (Exception $e) {
            $this->log("✗ Error in onCallCompleted: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Handle agent no-answer - try next available agent
     * @param string $callSessionId - Current call session ID
     * @param string $callStatus - Call status from webhook (e.g., 'no_answer', 'failed', 'busy', 'completed')
     */
    public function handleAgentNoAnswer($callSessionId, $callStatus = 'no_answer') {
        try {
            $session = $this->getCallSession($callSessionId);
            if (!$session) {
                $this->log("✗ Call session $callSessionId not found for no-answer handling");
                return false;
            }

            $leadId = $session['lead_id'];
            
            // Mark current session with actual webhook status (previous agent didn't answer/failed/busy/etc)
            $this->updateCallSession($callSessionId, [
                'status' => $callStatus,
                'ended_at' => date('Y-m-d H:i:s')
            ]);
            
            // Count how many agents have been tried for this lead
            $sql = "SELECT id FROM tblcall_sessions WHERE lead_id = ?";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$leadId]);
            $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $attemptCount = count($result);
            
            // Get max attempts from config
            $maxAttempts = env('FALLBACK_MAX_ATTEMPTS', 3);
            
            $this->log("Agent $callStatus for lead $leadId. Attempt $attemptCount of $maxAttempts. Total unique agents tried so far: $attemptCount");
            
            // Check if we've exceeded max attempts
            if ($attemptCount >= $maxAttempts) {
                $this->log("✗ Max attempts ($maxAttempts) reached for lead $leadId. No new unique agents to try.");
                return false;
            }
            
            // Get next available agent (prefer new agents, fallback to previously tried if available)
            $nextAgent = $this->getNextAvailableAgentExcludingTried($leadId);
            
            if (!$nextAgent) {
                $this->log("✗ No agents available for lead $leadId");
                return false;
            }

            // CREATE NEW SESSION for next agent (preserve history of all attempts)
            $newSessionId = $this->createCallSession($leadId, $nextAgent['staffid']);
            if (!$newSessionId) {
                $this->log("✗ Failed to create new call session for fallback");
                return false;
            }
            
            // Call the next agent
            $this->log("✓ Calling next agent: {$nextAgent['firstname']} {$nextAgent['lastname']} ({$nextAgent['phonenumber']}) for lead $leadId (Attempt " . ($attemptCount + 1) . ")");
            
            // Make call to next agent
            $callUrl = env('APP_URL') . '/agent/handlers/agent_call_handler.php?' . http_build_query(['call_session_id' => $newSessionId]);
            $statusCallbackUrl = env('APP_URL') . '/twilio/webhooks/call_status_webhook.php?' . http_build_query(['call_session_id' => $newSessionId, 'leg' => 'agent']);
            
            try {
                $call = $this->twilio->calls->create(
                    $nextAgent['phonenumber'],       // To: Next agent's phone
                    $this->twilioPhoneNumber,        // From: Twilio number
                    [
                        'url' => $callUrl,
                        'timeout' => env('TWILIO_CALL_TIMEOUT', 30),
                        'method' => 'GET',
                        'statusCallback' => $statusCallbackUrl,
                        'statusCallbackEvent' => 'completed',
                        'statusCallbackMethod' => 'POST'
                    ]
                );
                
                // Update the new session with call SID
                $this->updateCallSession($newSessionId, [
                    'agent_call_sid' => $call->sid,
                ]);
                
                $this->log("✓ Successfully called next agent for lead $leadId. New session ID: $newSessionId");
                return true;
                
            } catch (Exception $e) {
                $this->log("✗ Failed to call next agent: " . $e->getMessage());
                return false;
            }
            
        } catch (Exception $e) {
            $this->log("✗ Error handling agent no-answer: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get next available agent excluding those already tried for this lead
     * Priority 1: Agents never tried for this lead
     * Priority 2: Previously tried agents (if no new agents available)
     */
    private function getNextAvailableAgentExcludingTried($leadId) {
        try {
            // PRIORITY 1: Get agents that have NOT been tried for this lead yet
            // and are not currently in an active call
            $sql = "SELECT s.staffid, s.firstname, s.lastname, s.phonenumber
                    FROM tblstaff s
                    WHERE s.phonenumber IS NOT NULL
                    AND s.phonenumber != ''
                    AND s.staffid NOT IN (
                        SELECT DISTINCT agent_id FROM tblcall_sessions WHERE lead_id = ?
                    )
                    AND s.staffid NOT IN (
                        SELECT agent_id FROM tblcall_sessions 
                        WHERE status IN ('initiated', 'agent_ringing', 'agent_connected', 'client_ringing', 'client_connected')
                    )
                    LIMIT 1";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$leadId]);
            $agent = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // If found a new agent, return it
            if ($agent) {
                $this->log("Found new agent (not previously attempted) for lead $leadId");
                return $agent;
            }
            
            // PRIORITY 2: No new agents available, try previously attempted agents
            // (agents who failed before but are now available)
            $this->log("No new agents available for lead $leadId. Checking previously attempted agents...");
            
            $sql = "SELECT s.staffid, s.firstname, s.lastname, s.phonenumber
                    FROM tblstaff s
                    WHERE s.phonenumber IS NOT NULL
                    AND s.phonenumber != ''
                    AND s.staffid IN (
                        SELECT DISTINCT agent_id FROM tblcall_sessions WHERE lead_id = ?
                    )
                    AND s.staffid NOT IN (
                        SELECT agent_id FROM tblcall_sessions 
                        WHERE status IN ('initiated', 'agent_ringing', 'agent_connected', 'client_ringing', 'client_connected')
                    )
                    ORDER BY s.staffid
                    LIMIT 1";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$leadId]);
            $agent = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($agent) {
                $this->log("Found previously attempted agent (now available) for lead $leadId: {$agent['firstname']} {$agent['lastname']}");
            }
            
            return $agent;
            
        } catch (Exception $e) {
            $this->log("✗ Error getting next available agent: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Logging
     */
    private function log($message) {
        $logFile = LOGS_PATH . '/call_orchestrator.log';
        if (!is_dir(LOGS_PATH)) {
            mkdir(LOGS_PATH, 0755, true);
        }
        
        $timestamp = date('Y-m-d H:i:s');
        file_put_contents($logFile, "[$timestamp] $message\n", FILE_APPEND);
    }
}
