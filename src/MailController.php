<?php

use PHPMailer\PHPMailer\PHPMailer;

class MailController
{

  protected $setFrom;
  protected $replyTo;
  protected $recipients;
  protected $body;
  protected $subject;
  protected $receipt;
  protected $attachments;

  function __construct (
    string $setFrom,
    string $replyTo,
    array $recipients,
    string $body,
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
    $this->mail->addReplyTo($this->replyTo);
    $this->mail->Subject = $this->subject;
    $this->mail->Body    = "test";

    // add it to keep SMTP connection open after each email sent
    $this->mail->SMTPKeepAlive = FALSE;

    unset($this->recipients["count"]);
    foreach ($this->recipients as $mail) {
      $this->mail->addAddress($mail, "tim");
      try {
        $this->mail->send();
        echo "Message sent to: ({$mail}) {$this->mail->ErrorInfo}\n";
      } catch (Exception $e) {

        echo "Mailer Error ({$mail}) {$this->mail->ErrorInfo}\n";
        return FALSE;
      }
      $this->mail->clearAddresses();
    }
    $this->mail->smtpClose();
    return TRUE;
  }

}
