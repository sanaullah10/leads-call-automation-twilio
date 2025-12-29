<?php
/**
 * Agent Response Handler - Process agent accept/reject
 */

require_once __DIR__ . '/../../bootstrap.php';

use Twilio\TwiML\VoiceResponse;

$callSessionId = $_POST['call_session_id'] ?? null;
$digit = $_POST['Digits'] ?? null;

if (!$callSessionId) {
    http_response_code(400);
    echo "Missing call_session_id";
    exit;
}

try {
    $orchestrator = new CallOrchestrator($pdo);
    $response = new VoiceResponse();
    
    if ($digit === '1') {
        // Agent accepted
        $orchestrator->onAgentAccepted($callSessionId);
        $response->say("Thank you. Connecting you with the client now.", ['voice' => 'alice']);
        $response->play(); // Will be played when client connects
        
    } else if ($digit === '2') {
        // Agent rejected
        // Update session and lead status
        $sql = "SELECT lead_id FROM tblcall_sessions WHERE id = ? LIMIT 1";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$callSessionId]);
        $session = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($session) {
            $orchestrator->updateLeadStatus($session['lead_id'], 'Agent Unavailable');
        }
        
        $response->say("Your call has been rejected.", ['voice' => 'alice']);
        $response->hangup();
        
    } else {
        $response->say("Invalid input. Please try again.", ['voice' => 'alice']);
        $response->redirect(
            env('APP_URL') . '/agent/handlers/agent_call_handler.php?call_session_id=' . $callSessionId
        );
    }
    
    header('Content-Type: application/xml');
    echo $response;
    
} catch (Exception $e) {
    error_log("Error in agent_response: " . $e->getMessage());
    $response = new VoiceResponse();
    $response->say("Sorry, there was an error processing your response.", ['voice' => 'alice']);
    $response->hangup();
    
    header('Content-Type: application/xml');
    echo $response;
}
?>
