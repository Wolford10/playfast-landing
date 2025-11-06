<?php
/**
 * test_email.php — Play Fast
 * A simple script to test the Mailer class.
 */

// Enable error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

echo "<h1>Email Test Page</h1>";

// IMPORTANT: Replace with a real email address you can check for testing.
$testRecipient = 'test-recipient@example.com';
$adminRecipient = 'admin-recipient@example.com';

echo "<p>Attempting to send a test email to <strong>" . htmlspecialchars($testRecipient) . "</strong> and an admin notification to <strong>" . htmlspecialchars($adminRecipient) . "</strong>...</p>";

require_once __DIR__ . '/mailer.php';

// Initialize the mailer, overriding the admin email for this test
Mailer::init([
    'admin_to' => $adminRecipient,
]);

// Use the existing sendWaitlist function for a comprehensive test
try {
    [$sentUser, $sentAdmin] = Mailer::sendWaitlist(
        $testRecipient,
        'Test',
        'User',
        '555-123-4567',
        ['source' => 'Email Test Page']
    );

    echo $sentUser ? "<p style='color:green;'>User confirmation email sent successfully.</p>" : "<p style='color:red;'>User confirmation email failed to send.</p>";
    echo $sentAdmin ? "<p style='color:green;'>Admin notification email sent successfully.</p>" : "<p style='color:red;'>Admin notification email failed to send.</p>";

} catch (Throwable $e) {
    echo "<p style='color:red;'>An error occurred: " . htmlspecialchars($e->getMessage()) . "</p>";
}

echo "<p>Test complete.</p>";