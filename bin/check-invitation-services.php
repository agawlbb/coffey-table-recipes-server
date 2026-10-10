<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/src/bootstrap.php';

// Read-only checks: no invitation rows or outbound email are created.
$config = configuration();
$settings = $config['smtp'];
$mail = new PHPMailer\PHPMailer\PHPMailer(true);
$mail->isSMTP();
$mail->Host = $settings['host'];
$mail->Port = (int) $settings['port'];
$mail->SMTPAuth = true;
$mail->Username = $settings['username'];
$mail->Password = $settings['password'];
$mail->SMTPSecure = $settings['encryption'] === 'ssl'
    ? PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS
    : PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
$mail->Timeout = 12;
try {
    if (!$mail->smtpConnect()) throw new RuntimeException('SMTP connection failed');
    $mail->smtpClose();
    echo "SMTP TLS authentication passed. No email sent.\n";
    $options = getopt('', ['email:']);
    if (isset($options['email'])) {
        $email = strtolower(trim($options['email']));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Invalid email');
        $account = (new FirebaseAccountDirectory($config))->lookup($email);
        echo 'Firebase account lookup passed: ' . ($account === null ? 'not found' : 'found') . ".\n";
    }
} catch (Throwable $error) {
    // Raw provider errors may contain authentication records or credentials.
    fwrite(STDERR, "Invitation service check failed. Check server configuration.\n");
    exit(1);
}
