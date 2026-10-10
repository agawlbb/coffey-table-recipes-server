<?php
declare(strict_types=1);

use PHPMailer\PHPMailer\PHPMailer;

final class InvitationMail
{
    public function __construct(private array $config) {}

    public static function text(string $inviter, string $household, bool $existingAccount, string $appUrl): string
    {
        $text = "$inviter has invited you to join the $household household on Coffey Table Recipes.\n\n";
        if ($existingAccount) {
            $text .= "Log in with this email address to accept or decline the invitation.\n";
        } else {
            $text .= "Coffey Table Recipes helps a household keep its recipes together, browse meals, and track each person's favorite recipes.\n\nCreate an account using this email address, verify your email, and log in to accept or decline the invitation.\n";
        }
        return $text . "\n$appUrl\n\nThis invitation expires in 14 days. No household access is granted unless you accept.\nThis address is used for outgoing invitations; replies are not monitored.\n";
    }

    public function send(string $email, string $text): void
    {
        $settings = $this->config['smtp'];
        if (empty($settings['host']) || empty($settings['username']) || empty($settings['password'])) {
            throw new HttpError(503, 'mail_not_configured', 'Invitation email delivery is not configured yet.');
        }
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = $settings['host'];
        $mail->Port = (int) $settings['port'];
        $mail->SMTPAuth = true;
        $mail->Username = $settings['username'];
        $mail->Password = $settings['password'];
        $mail->SMTPSecure = $settings['encryption'] === 'ssl' ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Timeout = 12;
        $mail->CharSet = 'UTF-8';
        $mail->setFrom($settings['from_email'], $settings['from_name']);
        $mail->addAddress($email);
        $mail->Subject = 'You are invited to a household on Coffey Table Recipes';
        $mail->Body = $text;
        // Certificate checks remain enabled. SMTP diagnostics must never expose credentials.
        try { $mail->send(); }
        catch (Throwable $error) {
            throw new HttpError(503, 'invitation_email_failed', 'The invitation email could not be sent. Please try again later.');
        }
    }
}
