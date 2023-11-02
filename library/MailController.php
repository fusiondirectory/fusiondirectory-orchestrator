<?php

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

class MailController
{

  protected string $setFrom;
  protected ?string $replyTo;
  protected array $recipients;
  protected string $body;
  protected ?string $signature;
  protected string $subject;
  protected ?bool $receipt;
  protected ?array $attachments;
  private PHPMailer $mail;

  function __construct (
    string $setFrom,
    ?string $replyTo,
    array $recipients,
    string $body,
    ?string $signature,
    string $subject,
    bool $receipt      = NULL,
    array $attachments = NULL
  )
  {
    // The TRUE value passed it to enable the exception handling properly.
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

    /*
     * In case there are FQDN errors responses by the SMTP server, try below.
     * $this->mail->Helo = '['.$_SERVER['SERVER_ADDR'].']';
     */

    $this->mail->SMTPAuth   = TRUE;
    $this->mail->Username   = $_ENV["MAIL_USER"];
    $this->mail->Password   = $_ENV["MAIL_PASS"];
    $this->mail->SMTPSecure = $_ENV["MAIL_SEC"];
    $this->mail->Port       = $_ENV["MAIL_PORT"];
    $this->mail->AuthType   = 'LOGIN';

    foreach($this->attachments as $attachment) {
      $this->mail->addStringAttachment($attachment['content'], $attachment['cn']);
    }

    $this->mail->setFrom($this->setFrom);

    if (!empty($this->replyTo)) {
      $this->mail->addReplyTo($this->replyTo);
    }

    $this->mail->Subject = $this->subject;
    $this->mail->Body    = $this->body;

    if (!empty($this->signature)) {
      $this->mail->Body .= "\n\n". $this->signature;
    }

    // add it to keep SMTP connection open after each email sent
    $this->mail->SMTPKeepAlive = TRUE;

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