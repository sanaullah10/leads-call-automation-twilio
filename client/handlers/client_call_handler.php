<?php
/**
 * Client Call Handler - Handle incoming client call
 * When client answers, they are connected with the agent
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
    // Get call session and agent info
    $sql = "SELECT cs.*, l.name as lead_name, a.firstname, a.lastname
            FROM tblcall_sessions cs
            JOIN tblleads l ON l.id = cs.lead_id
            JOIN tblstaff a ON a.staffid = cs.agent_id
            WHERE cs.id = ? LIMIT 1";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$callSessionId]);
    $session = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$session) {
        $response = new VoiceResponse();
        $response->say("Sorry, the call session was not found.", ['voice' => 'alice']);
        $response->hangup();
        
        header('Content-Type: application/xml');
        echo $response;
        exit;
    }
    
    // Generate TwiML to connect with agent
    $response = new VoiceResponse();
    
    // Welcome message
    $response->say(
        "Thank you for calling. You will now be connected with {$session['firstname']} {$session['lastname']}.",
        ['voice' => 'alice']
    );
    
    // Use Dial to connect with agent via conference
    // The agent is already on the call, so we need to use Dial to connect
    $dial = $response->dial();
    
    // Option 1: Use SIP to connect (if agent's number is registered as SIP)
    // $dial->sip('sip:agent@twilio.com');
    
    // Option 2: Call agent's phone number (simple approach)
    // This would create a new call to agent - not ideal
    // Better to use conference bridge
    
    // Option 3: Use Conference Bridge (RECOMMENDED)
    $conferenceId = 'lead_' . $session['lead_id'];
    $dial->conference($conferenceId, [
        'record' => 'record-from-start',
        'statusCallback' => env('APP_URL') . '/twilio/webhooks/call_status_webhook.php',
        'statusCallbackMethod' => 'POST'
    ]);
    
    header('Content-Type: application/xml');
    echo $response;
    
} catch (Exception $e) {
    error_log("Error in client_call_handler: " . $e->getMessage());
    $response = new VoiceResponse();
    $response->say("Sorry, there was an error connecting your call.", ['voice' => 'alice']);
    $response->hangup();
    
    header('Content-Type: application/xml');
    echo $response;
}
?>
