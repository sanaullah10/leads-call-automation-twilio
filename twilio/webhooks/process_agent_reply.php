<?php
require_once __DIR__ . '/../../bootstrap.php';

// Prevent any accidental whitespace from breaking the XML
ob_start();

$incomingMsg = trim($_POST['Body'] ?? ''); // e.g., "45: Client wants a follow-up on Friday"
$from = $_POST['From'];


// Logic to parse the Lead ID and the Note
// We expect format "ID: Note"
if (preg_match('/^(\d+):(.*)$/', $incomingMsg, $matches)) {
    $leadId = trim($matches[1]);
    $noteContent = trim($matches[2]);
    
    // echo "Processing incoming SMS from $noteContent\n";
    try {
        // Optional: Update last contact
        $stmt = $pdo->prepare("UPDATE tblleads SET lastcontact = NOW(), notes = ? WHERE id = ?");
        $stmt->execute([$noteContent, $leadId]);

        $responseMsg = "✓ Note saved for Lead #$leadId.";
            
    } catch (Exception $e) {
        error_log("SMS Save Error: " . $e->getMessage());
    }
} else {
    $responseMsg = "Invalid format. Use: LeadID: Note Text";
}

// 2. PROPER TWIML RESPONSE
ob_clean();
header("Content-Type: text/xml");
echo '<?xml version="1.0" encoding="UTF-8"?>';
?>
<Response>
    <Message><?php echo htmlspecialchars($responseMsg); ?></Message>
</Response>