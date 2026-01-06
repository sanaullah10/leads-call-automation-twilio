<?php
/**
 * Call Status Webhook
 * Receives call status updates from Twilio
 * Updates database when calls are answered, completed, failed, etc.
 */

require_once __DIR__ . '/../../bootstrap.php';

// Log incoming request
$logData = [
    'timestamp' => date('Y-m-d H:i:s'),
    'method' => $_SERVER['REQUEST_METHOD'],
    'post_data' => $_POST,
    'get_data' => $_GET,
];

file_put_contents(
    LOGS_PATH . '/twilio_webhook.log',
    json_encode($logData) . "\n",
    FILE_APPEND
);

// Get Twilio callback data
$callSessionId = $_GET['call_session_id'];
$leg = $_GET['leg'] ?? 'agent';

$callSid = $_POST['CallSid'] ?? null;
$callStatus = $_POST['CallStatus'] ?? null; // initiated, ringing, in-progress, completed, failed, etc.
$from = $_POST['From'] ?? null;
$to = $_POST['To'] ?? null;
$duration = $_POST['CallDuration'] ?? null;
$recordingUrl = $_POST['RecordingUrl'] ?? null;

if (!$callSid || !$callStatus) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing required parameters']);
    exit;
}

try {
    // Find the call session by Twilio call SID
    $sql = "SELECT cs.*, s.phonenumber as agent_phone, l.name as lead_name
            FROM tblcall_sessions cs
            JOIN tblstaff s ON s.staffid = cs.agent_id
            JOIN tblleads l ON l.id = cs.lead_id
            WHERE cs.id = ?
            LIMIT 1";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$callSessionId]);
    $callSession = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$callSession) {
        http_response_code(404);
        echo json_encode(['error' => 'Call session not found']);
        exit;
    }
    
    $orchestrator = new CallOrchestrator($pdo);
    // Determine if this is agent or client call
    // $isAgentCall = $callSession['agent_call_sid'] === $callSid;
    // $isClientCall = $callSession['client_call_sid'] === $callSid;

    if ($leg === 'agent') {
        if(in_array($callStatus, ['no-answer', 'busy', 'failed'])) {
            // Agent did not answer or call failed
            error_log("Agent call status: $callStatus. Initiating fallback.");
            
            // Pass actual call status (no-answer, busy, failed, etc)
            $fallbackResult = $orchestrator->handleAgentNoAnswer($callSessionId, $callStatus);
        }

        if ($callStatus === 'completed') {
            // Agent answered, call was completed normally
            // $orchestrator->onCallCompleted($callSessionId, $_POST);

            // Agent call completed - send SMS to agent for notes
            $leadId = $callSession['lead_id'];
            $agentPhone = $callSession['agent_phone'];
            $leadName = $callSession['lead_name'];

            $twilio = new \Twilio\Rest\Client(env('TWILIO_SID'), env('TWILIO_TOKEN'));
            $twilio->messages->create(
                $agentPhone,
                [
                    'from' => env('TWILIO_PHONE_NUMBER'),
                    'body' => "Call ended. Reply with your notes for $leadName (Lead ID: $leadId). Begin your message with \"$leadId:\"."
                ]
            );
        }
        // else {
        //     // Update call session status
        //     $orchestrator->updateCallSession($callSessionId, [
        //         'status' => $callStatus,
        //         'ended_at' => date('Y-m-d H:i:s'),
        //         'updated_at' => date('Y-m-d H:i:s'),
        //     ]);
        // }
    }

     

    if ($leg === 'client') {
        $orchestrator->updateCallSession($callSessionId, [
            'client_call_sid' => $callSid,
        ]);
        // Handling failures on the Client (Child) leg
        switch ($callStatus) {
            case 'busy':
                $orchestrator->updateLeadStatusBySession($callSessionId, env('LEAD_RETRY_STATUS', 'Call Busy'), 'client_busy');
                break;
            case 'no-answer':
                $orchestrator->updateLeadStatusBySession($callSessionId, env('LEAD_RETRY_STATUS', 'No Answer'), 'client_no_answer');
                break;
            case 'failed':
                $orchestrator->updateLeadStatusBySession($callSessionId, env('LEAD_RETRY_STATUS', 'Call Failed'), 'client_failed');
                break;
            case 'canceled':
                // Agent hung up before the client could answer
                $orchestrator->updateLeadStatusBySession($callSessionId, env('LEAD_RETRY_STATUS', 'Call Canceled'), 'client_canceled');
                break;
            case 'completed':
                // // 1. Update Lead Status to 'Completed'
                $orchestrator->onCallCompleted($callSessionId, $_POST);
                break;
        }
    }
    
    // Return 200 OK to acknowledge receipt
    http_response_code(200);
    echo json_encode(['status' => 'ok', 'message' => 'Webhook processed']);
    
} catch (Exception $e) {
    error_log("Error in call_status_webhook: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>
