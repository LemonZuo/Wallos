<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

require_once 'validate.php';
require_once __DIR__ . '/../../includes/connect_endpoint_crontabs.php';

require 'settimezone.php';
require_once __DIR__ . '/../../includes/instance_config.php';
require_once __DIR__ . '/../../includes/email_template.php';

// The admin row with anything the deployment owns applied over it, so this job
// uses the same mail server the admin page shows.
$admin = wallos_get_admin_settings($db);

if ($admin['require_email_verification'] == 0) {
    if (php_sapi_name() !== 'cli') {
        echo "Email verification is not required.";
    }
    die();
}

$query = "SELECT * FROM email_verification WHERE email_sent = 0";
$stmt = $db->prepare($query);
$result = $stmt->execute();

$rows = [];
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $rows[] = $row;
}

if ($rows) {
    if ($admin['smtp_address'] && $admin['smtp_port'] && $admin['smtp_username'] && $admin['smtp_password'] && $admin['encryption']) {
        // There are SMTP settings
        $smtpAddress = $admin['smtp_address'];
        $smtpPort = $admin['smtp_port'];
        $smtpUsername = $admin['smtp_username'];
        $smtpPassword = $admin['smtp_password'];
        $fromEmail = empty($admin['from_email']) ? 'wallos@wallosapp.com' : $admin['from_email'];
        $encryption = $admin['encryption'];
        $server_url = $admin['server_url'];
        $smtpAuth = (isset($admin["smtp_username"]) && $admin["smtp_username"] != "") || (isset($admin["smtp_password"]) && $admin["smtp_password"] != "");

        require __DIR__ . '/../../libs/PHPMailer/PHPMailer.php';
        require __DIR__ . '/../../libs/PHPMailer/SMTP.php';
        require __DIR__ . '/../../libs/PHPMailer/Exception.php';

        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Timeout = 15;
        $mail->Host = $smtpAddress;
        $mail->SMTPAuth = $smtpAuth;
        if ($smtpAuth) {
          $mail->Username = $smtpUsername;
          $mail->Password = $smtpPassword;
        }
        if ($encryption != "none") {
          $mail->SMTPSecure = $encryption;
        }
        $mail->Port = $smtpPort;
        $mail->setFrom($fromEmail);

        try {
            foreach ($rows as $user) {
                $mail->addAddress($user['email']);
                $mail->Subject = 'Wallos - Email Verification';
                $verifyUrl = $server_url . '/verifyemail.php?email=' . $user['email'] . '&token=' . $user['token'];
                $html = wallos_email_layout([
                    'title' => $mail->Subject,
                    'preheader' => 'Confirm your email address to finish setting up your Wallos account.',
                    'heading' => 'Verify your email',
                    'intro' => "Registration on Wallos was successful.\nPlease confirm your email address by clicking the button below.",
                    'button' => ['label' => 'Verify Email', 'url' => $verifyUrl],
                    'link_hint' => "If the button doesn't work, copy and paste this link into your browser:",
                    'note' => "If you didn't create a Wallos account, you can safely ignore this email.",
                    'server_url' => $server_url,
                ]);
                $text = "Registration on Wallos was successful.\n\nPlease open the following link to verify your email:\n" . $verifyUrl;
                wallos_email_apply($mail, $html, $text);

                $mail->send();

                $query = "UPDATE email_verification SET email_sent = 1 WHERE id = :id";
                $stmt = $db->prepare($query);
                $stmt->bindParam(':id', $user['id'], SQLITE3_INTEGER);
                $stmt->execute();

                $mail->clearAddresses();

                echo "Verification email sent to " . $user['email'] . "<br>";
            }
        } catch (Exception $e) {
            echo "Message could not be sent. Mailer Error: {$mail->ErrorInfo}";
        }
    } else {
        // There are no SMTP settings
        if (php_sapi_name() !== 'cli') {
            echo "SMTP settings are not configured. Please configure SMTP settings in the admin page.";
        }
        exit();
    }
} else {
    // There are no verification emails to be sent
    if (php_sapi_name() !== 'cli') {
        echo "No verification emails to be sent.";
    }
    exit();
}

?>
