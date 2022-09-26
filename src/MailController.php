<?php

use PHPMailer\PHPMailer\PHPMailer;

class MailController
{

  protected $setFrom;
  protected $replyTo;
  protected $recipients;
  protected $body;
  protected $signature;
  protected $subject;
  protected $receipt;
  protected $attachments;

  function __construct (
    string $setFrom,
    ?string $replyTo,
    array $recipients,
    string $body,
    string $signature,
    string $subject,
    bool $receipt      = NULL,
    array $attachments = NULL
  )
  {
    $this->mail = new PHPMailer();

    $this->setFrom     = $setFrom;
    $this->replyTo     = $replyTo;
    $this->recipients  = $recipients;
    $this->body        = $body;
    $this->signature   = $signature;
    $this->subject     = $subject;
    $this->receipt     = $receipt;
    $this->attachments = $attachments;

  }

  public function sendMail () : bool
  {
    $this->mail->isSMTP();
    $this->mail->Host = $_ENV["MAIL_HOST"];

    /**
     * Testing purposes, auth is deactivated
     */
    $this->mail->SMTPAuth = FALSE;

    $this->mail->Username   = $_ENV["MAIL_USER"];
    $this->mail->Password   = $_ENV["MAIL_PASS"];
    $this->mail->SMTPSecure = $_ENV["MAIL_SEC"];
    $this->mail->Port       = $_ENV["MAIL_PORT"];

    $this->mail->setFrom($this->setFrom);

    if (isset($this->replyTo) && !empty($this->replyTo)) {
      $this->mail->addReplyTo($this->replyTo);
    }

    $this->mail->Subject = $this->subject;
    $this->mail->Body    = $this->body;

    // add it to keep SMTP connection open after each email sent
    $this->mail->SMTPKeepAlive = FALSE;

    unset($this->recipients["count"]);
    $errors = [];
    foreach ($this->recipients as $mail) {
      $this->mail->addAddress($mail);
      try {
        $this->mail->send();

        $errors[] = json_encode("Mail sent to: ({$mail}) {$this->mail->ErrorInfo}");

        // You can retrieve  mail->ErrorInfo and return for debug.
        // Update tasks mail module here

      } catch (Exception $e) {

        // You can retrieve  mail->ErrorInfo and return for debug.
        // Update task mail module upon failure here.
        $errors[] = json_encode("Mailer Error ({$mail}) {$this->mail->ErrorInfo}");

        return FALSE;
      }
      $this->mail->clearAddresses();
    }

    echo json_encode($errors);

    $this->mail->smtpClose();
    return TRUE;
  }

}
