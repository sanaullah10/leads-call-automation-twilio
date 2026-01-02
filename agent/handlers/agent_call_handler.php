<?php
/**
 * Agent Call Handler - IVR for agent to accept/reject call
 * Generates TwiML response for the agent's incoming call
 */

require_once __DIR__ . '/../../bootstrap.php';

use Twilio\TwiML\VoiceResponse;

$callSessionId = $_GET['call_session_id'] ?? null;

if (!$callSessionId) {
    http_response_code(400);
    echo "Missing call_session_id";
    exit;
}

try {
    $orchestrator = new CallOrchestrator($pdo);
    
    // Get call session and lead info
    $sql = "SELECT cs.*, l.name as lead_name, s.firstname, s.lastname 
            FROM tblcall_sessions cs
            JOIN tblleads l ON l.id = cs.lead_id
            JOIN tblstaff s ON s.staffid = cs.agent_id
            WHERE cs.id = ? LIMIT 1";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$callSessionId]);
    $session = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$session) {
        http_response_code(404);
        echo "Call session not found";
        exit;
    }
    
    // Generate TwiML response
    $response = new VoiceResponse();
    
    // Greeting message
    $response->say(
        "You have an incoming call from {$session['lead_name']} regarding {$session['lead_name']}.",
    );
    
    // Gather user input (press 1 to accept, 2 to reject)
    $gather = $response->gather(
        [
            'numDigits' => 1,
            'action' => env('APP_URL') . '/agent/handlers/agent_response.php?call_session_id=' . $callSessionId,
            'method' => 'POST',
            'timeout' => 30
        ]
    );
    
    $gather->say("Press 1 to accept this call, or press 2 to reject.");
    
    // Fallback if no input
    $response->redirect(
        env('APP_URL') . '/agent/handlers/agent_call_handler.php?call_session_id=' . $callSessionId
    );
    
    // Set Twilio response content type
    header('Content-Type: application/xml');
    echo $response;
    
} catch (Exception $e) {
    error_log("Error in agent_call_handler: " . $e->getMessage());
    $response = new VoiceResponse();
    $response->say("Sorry, there was an error processing your call.");
    $response->hangup();
    
    header('Content-Type: application/xml');
    echo $response;
}
?>
