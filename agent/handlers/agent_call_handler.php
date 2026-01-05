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
    
    // Get call session and lead info including client phone number
    $sql = "SELECT cs.*, l.name as lead_name, l.phonenumber as client_phone, s.firstname, s.lastname 
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
    
    // Greeting message and automatic connection
    $response->say(
        "Connecting you with the client now. Please wait.",
        ['voice' => 'alice']
    );
    
    // Use Dial with Number to bridge agent directly to client (outbound bridging)
    
    $dial = $response->dial('', ['hangupOnStar' => true]);

    // $dial = $response->dial('', [
    //     'action' => env('APP_URL') . '/twilio/webhooks/call_status_webhook.php',
    //     'method' => 'POST',
    // ]);
    
    // Dial the client's number directly - creates a bridge between agent and client
    $dial->number($session['client_phone'], [
        'statusCallback' => env('APP_URL') . '/twilio/webhooks/call_status_webhook.php?call_session_id=' . $callSessionId . '&leg=client',
        'statusCallbackEvent' => 'completed',
        'statusCallbackMethod' => 'POST'
    ]);
    
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
