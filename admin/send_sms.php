<?php
session_start();
require_once '../vendor/autoload.php';  // For Composer-based packages like Twilio

use Twilio\Rest\Client;

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['phone_number'])) {
    $phone_number = $_POST['phone_number'];  // Phone number you want to send SMS to
    $message_body = "Hello, this is a message from the supplier!"; // Your message

    // Your Twilio credentials
    $sid = 'your_twilio_sid';
    $token = 'your_twilio_auth_token';
    $twilio = new Client($sid, $token);

    // Send SMS
    try {
        $message = $twilio->messages
                          ->create(
                              $phone_number, // The phone number to send to
                              [
                                  'from' => '+0987654321',  // Your Twilio phone number
                                  'body' => $message_body
                              ]
                          );
        echo "Message sent successfully!";
    } catch (Exception $e) {
        echo "Error: " . $e->getMessage();
    }
}
?>
