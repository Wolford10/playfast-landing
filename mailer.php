<?php
/**
 * mailer.php — Play Fast
 * Standalone email helper for waitlist and future notifications.
 *
 * Usage:
 *   require_once __DIR__ . '/mailer.php';
 *   Mailer::init([
 *     'from'       => 'no-reply@YOURDOMAIN.TLD',
 *     'from_name'  => 'Play Fast',
 *     'reply_to'   => 'support@YOURDOMAIN.TLD',
 *     'admin_to'   => 'you@YOURDOMAIN.TLD',
 *     // Choose transport: 'mail' (PHP mail()) or 'smtp'
 *     'transport'  => 'mail',
 *     // SMTP settings (only used when transport==='smtp')
 *     'smtp_host'  => 'smtp.YOURDOMAIN.TLD',
 *     'smtp_user'  => 'no-reply@YOURDOMAIN.TLD',
 *     'smtp_pass'  => 'YOUR_SMTP_PASSWORD',
 *     'smtp_port'  => 587, // 465 for SMTPS
 *     'smtp_secure'=> 'tls' // 'ssl' or 'tls'
 *   ]);
 *
 *   [$sentUser, $sentAdmin] = Mailer::sendWaitlist($email, $fname, $lname, $phone, $meta);
 */

class Mailer {
  private static array $cfg = [
    'from'       => 'support@playfastlax.com',
    'from_name'  => 'Play Fast',
    'reply_to'   => 'support@playfastlax.com',
    'admin_to'   => 'support@playfastlax.com',
    'transport'  => 'mail', // 'mail' | 'smtp'
    'smtp_host'  => '',
    'smtp_user'  => '',
    'smtp_pass'  => '',
    'smtp_port'  => 587,
    'smtp_secure'=> 'tls',
  ];

  public static function init(array $overrides = []): void {
    self::$cfg = array_merge(self::$cfg, $overrides);
  }

  /** Send user confirmation + admin notification. */
  public static function sendWaitlist(string $email, string $fname, string $lname = '', ?string $phone = null, array $meta = []): array {
    $userSubj = "You're on the Play Fast waitlist";
    [$userHtml, $userTxt] = self::tplUser($fname);

    $adminSubj = '[Play Fast] New waitlist signup';
    [$adminHtml, $adminTxt] = self::tplAdmin($fname, $lname, $email, $phone, $meta);

    $sentUser  = self::send($email, trim("$fname $lname"), $userSubj, $userHtml, $userTxt);
    $sentAdmin = self::send(self::$cfg['admin_to'], 'Play Fast Admin', $adminSubj, $adminHtml, $adminTxt);

    return [$sentUser, $sentAdmin];
  }

  /** Core send: chooses transport. */
  private static function send(string $to, string $toName, string $subject, string $html, string $text): bool {
    return (self::$cfg['transport'] === 'smtp') ? self::sendSMTP($to, $toName, $subject, $html, $text)
                                                : self::sendMail($to, $toName, $subject, $html, $text);
  }

  /** PHP mail() transport. */
  private static function sendMail(string $to, string $toName, string $subject, string $html, string $text): bool {
    $from = self::$cfg['from'];
    $fromName = self::$cfg['from_name'];
    $reply = self::$cfg['reply_to'];

    $headers  = "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    $headers .= "From: {$fromName} <{$from}>\r\n";
    if ($reply) { $headers .= "Reply-To: {$reply}\r\n"; }

    // Some hosts prefer plain text. If deliverability is poor, switch to SMTP.
    return @mail($to, $subject, $html, $headers);
  }

  /** SMTP transport via PHPMailer (optional). */
  private static function sendSMTP(string $to, string $toName, string $subject, string $html, string $text): bool {
    // Requires PHPMailer installed via Composer.
    // If unavailable, gracefully fall back to mail().
    if (!class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
      return self::sendMail($to, $toName, $subject, $html, $text);
    }

    try {
      $mail = new PHPMailer\PHPMailer\PHPMailer(true);
      $mail->isSMTP();
      $mail->Host       = self::$cfg['smtp_host'];
      $mail->SMTPAuth   = true;
      $mail->Username   = self::$cfg['smtp_user'];
      $mail->Password   = self::$cfg['smtp_pass'];
      $mail->Port       = (int) self::$cfg['smtp_port'];
      $secure = strtolower((string) self::$cfg['smtp_secure']);
      if ($secure === 'ssl') { $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS; }
      else { $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS; }

      $mail->setFrom(self::$cfg['from'], self::$cfg['from_name']);
      if (self::$cfg['reply_to']) { $mail->addReplyTo(self::$cfg['reply_to']); }
      $mail->addAddress($to, $toName);

      $mail->isHTML(true);
      $mail->Subject = $subject;
      $mail->Body    = $html;
      $mail->AltBody = $text;

      $mail->send();
      return true;
    } catch (\Throwable $e) {
      error_log('SMTP send failed: ' . $e->getMessage());
      return false;
    }
  }

  /** Simple user-facing email template. */
  private static function tplUser(string $fname): array {
    $safe = htmlspecialchars($fname ?: 'there');
    $html = "<div style='font-family:Inter,system-ui,Segoe UI,Arial,sans-serif;font-size:16px;color:#0b3d0b;'>"
          . "<p>Hi {$safe},</p>"
          . "<p>Thanks for joining the <strong>PlayFast</strong> waitlist! We'll email you about early access and launch updates.</p>"
          . "<p style='margin-top:16px;color:#437a43'>— The Play Fast Team</p>"
          . "</div>";
    $txt  = "Hi {$fname},\n\n"
          . "Thanks for joining the Play Fast waitlist! We'll email you about early access and launch updates.\n\n"
          . "— The Play Fast Team\n";
    return [$html, $txt];
  }

  /** Admin alert template. */
  private static function tplAdmin(string $fname, string $lname, string $email, ?string $phone, array $meta): array {
    $n = htmlspecialchars(trim("$fname $lname"));
    $e = htmlspecialchars($email);
    $p = htmlspecialchars($phone ?: '—');
    $ip = htmlspecialchars($meta['ip'] ?? ($_SERVER['REMOTE_ADDR'] ?? ''));
    $ua = htmlspecialchars($meta['ua'] ?? ($_SERVER['HTTP_USER_AGENT'] ?? ''));
    $features = htmlspecialchars($meta['features'] ?? '—');
    $when = date('Y-m-d H:i:s');

    $html = "<div style='font-family:Inter,system-ui,Segoe UI,Arial,sans-serif;font-size:15px;color:#0b3d0b;'>"
          . "<p><strong>New waitlist signup</strong></p>"
          . "<ul>"
          . "<li>Name: {$n}</li>"
          . "<li>Email: {$e}</li>"
          . "<li>Phone: {$p}</li>"
          . "<li>Features: {$features}</li>"
          . "<li>IP: {$ip}</li>"
          . "<li>UA: {$ua}</li>"
          . "<li>When: {$when}</li>"
          . "</ul></div>";

    $txt  = "New waitlist signup\n"
          . "Name: {$fname} {$lname}\n"
          . "Email: {$email}\n"
          . "Phone: " . ($phone ?: '—') . "\n"
          . "Features: " . ($meta['features'] ?? '—') . "\n"
          . "IP: {$ip}\n"
          . "UA: {$ua}\n"
          . "When: {$when}\n";

    return [$html, $txt];
  }
}
