<?php

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

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
    $this->mail = new PHPMailer(TRUE);

    $this->setFrom     = $setFrom;
    $this->replyTo     = $replyTo;
    $this->recipients  = $recipients;
    $this->body        = $body;
    $this->signature   = $signature;
    $this->subject     = $subject;
    $this->receipt     = $receipt;
    $this->attachments = $attachments;

  }

  public function sendMail () : array
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

    // Our returned array
    $errors = [];

    foreach ($this->recipients as $mail) {
      $this->mail->addAddress($mail);

      try {
        $this->mail->send();

      } catch (Exception $e) {
        $errors[] = $this->mail->ErrorInfo;

      }
      $this->mail->clearAddresses();

      if (empty($errors)) {
        $errors[] = "SUCCESS";
      }
    }

    $this->mail->smtpClose();
    return $errors;
  }

}
