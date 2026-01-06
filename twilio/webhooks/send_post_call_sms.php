<?php
require_once __DIR__ . '/../../bootstrap.php';
use Twilio\Rest\Client;

$leadId = $_GET['lead_id'];
$agentPhone = $_POST['To']; // Twilio sends the number that was called

if ($_POST['CallStatus'] === 'completed') {
    $twilio = new Client(env('TWILIO_SID'), env('TWILIO_TOKEN'));
    $twilio->messages->create(
        $agentPhone,
        [
            'from' => env('TWILIO_PHONE_NUMBER'),
            'body' => "Call ended. Please reply to this SMS with your notes for Lead #$leadId. Start your reply with '$leadId:' followed by your notes."
        ]
    );
}