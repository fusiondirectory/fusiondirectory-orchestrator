<?php

use PHPMailer\PHPMailer\PHPMailer;

class MailController
{

  function __construct () 
  {
    echo "inside construc of PhpMailer" .PHP_EOL; 
    $this->mail = new PHPMailer();
  }

  public function sendMail() : bool
  {
    echo "inside sendMail" .PHP_EOL;
    $this->mail->isSMTP();
    $this->mail->Host = 'localhost';
    $this->mail->SMTPAuth = FALSE;
    $this->mail->Username = 'thibault';
    $this->mail->Password = 'thibault';
    //$this->mail->SMTPSecure = 'tls';
    $this->mail->Port = 1025;
    $this->mail->setFrom('from@example.com', 'First Last');
    $this->mail->addReplyTo('towho@example.com', 'John Doe');
    $this->mail->addAddress('recipient1@mailtrap.io', 'Tim');
    $this->mail->addCC('cc1@example.com', 'Elena');
    $this->mail->addBCC('bcc1@example.com', 'Alex');
    $this->mail->Subject = "PHPMailer SMTP test";
    $this->mail->Body = 'SimpleTextBody';
    $this->mail->AltBody = 'This is the plain text version of the email content';

    echo "before send" .PHP_EOL;

    if (!$this->mail->send()) {
      echo 'Message could not be sent.';
      echo 'Mailer Error: ' . $this->mail->ErrorInfo;

      return FALSE;
    } else {

      echo 'Message has been sent';
      return TRUE;
    }
  }

}
