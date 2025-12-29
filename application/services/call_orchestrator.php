<?php
/**
 * Call Orchestrator - Manage Agent → Client calling workflow
 * 1. Call agent first
 * 2. Once agent connects, call client
 * 3. Connect both on same call
 * 4. Update lead status throughout process
 */

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
            if (!$this->availabilityChecker->isAgentAvailable($lead['assigned'])) {
                $this->updateLeadStatus($lead['id'], 'Agent Unavailable');
                $this->log("Agent {$lead['assigned']} is unavailable for lead {$lead['id']}");
                return false;
            }
            
            // Get agent details
            $agent = $this->availabilityChecker->getAgentDetails($lead['assigned']);
            
            if (!$agent || !$agent['phonenumber']) {
                $this->updateLeadStatus($lead['id'], 'Agent Unavailable');
                $this->log("No valid phone number for agent {$lead['assigned']}");
                return false;
            }
            
            // Update lead status: Calling Agent
            $this->updateLeadStatus($lead['id'], 'Calling Agent');

            
            
            // Create call session record
            $callSessionId = $this->createCallSession($lead['id'], $agent['staffid']);
            
            // Make call to agent
            // The agent will receive options via IVR (press 1 to accept, 2 to reject)
            $callUrl = env('APP_URL') . '/agent/handlers/agent_call_handler.php?call_session_id=' . $callSessionId;
            
            try {
                $call = $this->twilio->calls->create(
                    $agent['phonenumber'],           // To: Agent's phone
                    $this->twilioPhoneNumber,        // From: Twilio number
                    [
                        'url' => $callUrl,
                        'method' => 'GET',
                        'record' => true,
                        'timeout' => 30,
                        'statusCallback' => env('APP_URL') . '/twilio/webhooks/call_status_webhook.php',
                        'statusCallbackMethod' => 'POST'
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
    private function updateCallSession($sessionId, $data) {
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
    
    /**
     * Handle agent acceptance of call
     * This is called from agent_call_handler.php when agent presses 1
     */
    public function onAgentAccepted($callSessionId) {
        try {
            $session = $this->getCallSession($callSessionId);
            if (!$session) return false;
            
            // Update session status
            $this->updateCallSession($callSessionId, ['status' => 'agent_connected']);
            
            // Update lead status
            $this->updateLeadStatus($session['lead_id'], 'Agent Connected');
            
            // Call the client
            $this->callClient($session['lead_id'], $session['agent_id'], $callSessionId);
            
            $this->log("✓ Agent accepted call for lead {$session['lead_id']}");
            return true;
            
        } catch (Exception $e) {
            $this->log("✗ Error in onAgentAccepted: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Call the client
     */
    private function callClient($leadId, $agentId, $callSessionId) {
        try {
            // Get lead info
            $sql = "SELECT * FROM tblleads WHERE id = ? LIMIT 1";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$leadId]);
            $lead = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$lead || !$lead['phonenumber']) {
                $this->updateLeadStatus($leadId, 'Client Unavailable');
                return false;
            }
            
            // Update lead status
            $this->updateLeadStatus($leadId, 'Calling Client');
            
            $callUrl = env('APP_URL') . '/client/handlers/client_call_handler.php?call_session_id=' . $callSessionId;
            
            $call = $this->twilio->calls->create(
                $lead['phonenumber'],              // To: Client's phone
                $this->twilioPhoneNumber,          // From: Twilio number
                [
                    'url' => $callUrl,
                    'method' => 'GET',
                    'record' => true,
                    'timeout' => 30,
                    'statusCallback' => env('APP_URL') . '/twilio/webhooks/call_status_webhook.php',
                    'statusCallbackMethod' => 'POST'
                ]
            );
            
            // Store client call SID
            $this->updateCallSession($callSessionId, ['client_call_sid' => $call->sid]);
            
            $this->log("✓ Calling client {$lead['name']} ({$lead['phonenumber']}) for lead {$leadId}");
            return true;
            
        } catch (Exception $e) {
            $this->updateLeadStatus($leadId, 'Call Failed');
            $this->log("✗ Error calling client: " . $e->getMessage());
            return false;
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
    public function updateLeadStatus($leadId, $statusName) {
        try {
            $statusId = LeadStatusSetup::getStatusId($this->pdo, $statusName);

            if (!$statusId) {
                // TODO create new status if not found

                $this->log("✗ Status '$statusName' not found");
                return false;
            }
            
            $sql = "UPDATE tblleads 
                    SET status = ?, 
                        last_status_change = NOW(),
                        lastcontact = COALESCE(lastcontact, NOW())
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
?>
