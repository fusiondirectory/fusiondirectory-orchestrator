<?php

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

class MailController
{

  protected string  $setFrom;
  protected ?string $setBCC;
  protected array   $recipients;
  protected string  $body;
  protected ?string $signature;
  protected string  $subject;
  protected ?string $receipt;
  protected ?array  $attachments;
  private PHPMailer $mail;

  function __construct (
    string $setFrom,
    ?string $setBCC,
    array $recipients,
    string $body,
    ?string $signature,
    string $subject,
    string $receipt = NULL, array $attachments = NULL
  )
  {
    // The TRUE value passed it to enable the exception handling properly.
    $this->mail        = new PHPMailer(TRUE);
    $this->setFrom     = $setFrom;
    $this->setBCC      = $setBCC;
    $this->recipients  = $recipients;
    $this->body        = $body;
    $this->signature   = $signature;
    $this->subject     = $subject;
    $this->receipt     = $receipt;
    $this->attachments = $attachments;

  }

  public function sendMail (): array
  {
    // Our returned array
    $errors = [];

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

    if (!empty($this->attachments)) {
      foreach ($this->attachments as $attachment) {
        $this->mail->addStringAttachment($attachment['content'], $attachment['cn']);
      }
    }

    $this->mail->setFrom($this->setFrom);

    if (!empty($this->setBCC)) {
      $this->mail->addBCC($this->setBCC);
    }

    if ($this->receipt === 'TRUE') {
      $this->mail->addCustomHeader('Disposition-Notification-To', $this->setFrom);
    }
    $this->mail->Subject = $this->subject;
    $this->mail->Body    = $this->body;

    if (!empty($this->signature)) {
      $this->mail->Body .= "\n\n" . $this->signature;
    }

    // add it to keep SMTP connection open after each email sent
    $this->mail->SMTPKeepAlive = TRUE;

    if (!empty($this->recipients["count"])) {
      unset($this->recipients["count"]);
    }

    /* We have an anti-spam logic applied above the mail controller. In case of mail template, only one email is within
     the recipient address, in case of notifications (e.g), multiple address exists. Therefore, the counting of anti-spam
    increment is applied prior of this controller added by the numbers of recipients. See notifications logic in a send
    method.
    */
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