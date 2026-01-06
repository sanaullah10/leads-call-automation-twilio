<?php
/**
 * Agent Availability Checker
 * Determines if agent is available to receive calls
 */

// This file is loaded via bootstrap.php

class AgentAvailabilityChecker {
    private $pdo;
    
    // Define availability statuses (adjust based on your system)
    private $availableStatusIds = [1, 2]; // Status IDs that mean agent is available
    
    public function __construct($pdo, $availableStatusIds = null) {
        $this->pdo = $pdo;
        if ($availableStatusIds) {
            $this->availableStatusIds = $availableStatusIds;
        }
    }
    
    /**
     * Check if agent is available
     * Agent is available if:
     * 1. Agent exists in database
     * 2. Agent doesn't have an active call currently
     * 3. Agent has a valid phone number
     */
    public function isAgentAvailable($agentId) {
        try {
            // Check if agent exists and has phone number
            $sql = "SELECT staffid FROM tblstaff 
                    WHERE staffid = ? 
                    AND phonenumber IS NOT NULL
                    AND phonenumber != ''
                    LIMIT 1";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$agentId]);
            
            if ($stmt->rowCount() === 0) {
                return false;
            }

            // Check if agent is currently in an active call
            $sql = "SELECT id FROM tblcall_sessions 
                    WHERE agent_id = ? 
                    AND status IN ('initiated', 'agent_ringing', 'agent_connected', 'client_ringing', 'client_connected')
                    LIMIT 1";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$agentId]);

            // If no active call, agent is available
            return $stmt->rowCount() === 0;
        } catch (Exception $e) {
            error_log("Error checking agent availability: " . $e->getMessage());
            return false;
        }
    }

    public function getNextAvailableAgent() {
        try {
            // get any staff has phone number and call_session status is completed
            $sql = "SELECT s.staffid FROM tblstaff s
                    WHERE s.phonenumber IS NOT NULL
                    AND s.phonenumber != ''
                    AND s.staffid NOT IN (
                        SELECT agent_id FROM tblcall_sessions 
                        WHERE status IN ('initiated', 'agent_ringing', 'agent_connected', 'client_ringing', 'client_connected')
                    )
                    LIMIT 1"; 
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute();
            
            $agent = $stmt->fetch(PDO::FETCH_ASSOC);
            return $agent ? $agent['staffid'] : null;
        } catch (Exception $e) {
            error_log("Error checking agent availability by status: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get agent details including phone number
     */
    public function getAgentDetails($agentId) {
        try {
            $sql = "SELECT 
                        staffid,
                        firstname,
                        lastname,
                        email,
                        phonenumber
                    FROM tblstaff
                    WHERE staffid = ?
                    LIMIT 1";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$agentId]);
            
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Error fetching agent details: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Get all available agents (for broadcast calling)
     * Returns agents who are not currently in an active call
     */
    public function getAvailableAgents($limit = 10) {
        try {
            $sql = "SELECT 
                        s.staffid,
                        s.firstname,
                        s.lastname,
                        s.email,
                        s.phonenumber
                    FROM tblstaff s
                    WHERE s.phonenumber IS NOT NULL
                    AND s.phonenumber != ''
                    AND s.staffid NOT IN (
                        SELECT agent_id FROM tblcall_sessions 
                        WHERE status IN ('initiated', 'agent_ringing', 'agent_connected', 'client_ringing', 'client_connected')
                    )
                    LIMIT ?";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$limit]);
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Error fetching available agents: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Check if agent is currently in a call
     */
    public function isAgentInCall($agentId) {
        try {
            $sql = "SELECT id FROM tblcall_sessions 
                    WHERE agent_id = ? 
                    AND status IN ('initiated', 'agent_ringing', 'agent_connected', 'client_ringing', 'client_connected')
                    LIMIT 1";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$agentId]);
            
            return $stmt->rowCount() > 0;
        } catch (Exception $e) {
            error_log("Error checking if agent in call: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get current call session for agent
     */
    public function getAgentCurrentCall($agentId) {
        try {
            $sql = "SELECT cs.*, l.name as lead_name, l.phonenumber as lead_phone
                    FROM tblcall_sessions cs
                    JOIN tblleads l ON l.id = cs.lead_id
                    WHERE cs.agent_id = ? 
                    AND cs.status IN ('initiated', 'agent_ringing', 'agent_connected', 'client_ringing', 'client_connected')
                    LIMIT 1";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$agentId]);
            
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Error getting agent current call: " . $e->getMessage());
            return null;
        }
    }
}
