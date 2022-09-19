<?php

use PHPMailer\PHPMailer\PHPMailer;

class MailController
{

  function __construct ()
  {
    $this->mail = new PHPMailer();
  }

  public function sendMail() : bool
  {
    $this->mail->isSMTP();
    $this->mail->Host = $_ENV["MAIL_HOST"];
    
    /**
     * Testing purposes, auth is deactivated 
     */
    //$this->mail->SMTPAuth = true;
    
    $this->mail->Username = $_ENV["MAIL_USER"];
    $this->mail->Password = $_ENV["MAIL_PASS"];
    $this->mail->SMTPSecure = $_ENV["MAIL_SEC"];
    $this->mail->Port = $_ENV["MAIL_PORT"];

    $this->mail->setFrom('from@example.com', 'First Last');
    $this->mail->addReplyTo('towho@example.com', 'John Doe');
    $this->mail->addAddress('recipient1@mailtrap.io', 'Tim');
    $this->mail->addCC('cc1@example.com', 'Elena');
    $this->mail->addBCC('bcc1@example.com', 'Alex');
    $this->mail->Subject = "PHPMailer SMTP test";
    $this->mail->Body = 'SimpleTextBody';
    $this->mail->AltBody = 'This is the plain text version of the email content';
    /* if (!$this->mail->send()) { */
    /*   echo 'Message could not be sent.'; */
    /*   echo 'Mailer Error: ' . $this->mail->ErrorInfo; */

    /*   return FALSE; */
    /* } else { */

    /*   echo 'Message has been sent'; */
    /*   return TRUE; */
    /* } */

    // add it to keep SMTP connection open after each email sent
    $this->mail->SMTPKeepAlive = FALSE; 
    $users = [
        ['email' => 'max@example.com', 'name' => 'Max'],
        ['email' => 'box@example.com', 'name' => 'Bob']
    ];
    foreach ($users as $user) {
      $this->mail->addAddress($user['email'], $user['name']);
        try {
          $this->mail->send();
            echo "Message sent to: ({$user['email']}) {$this->mail->ErrorInfo}\n";
        } catch (Exception $e) {
          
            echo "Mailer Error ({$user['email']}) {$this->mail->ErrorInfo}\n";
            return FALSE;
        }
      $this->mail->clearAddresses();
    }
    $this->mail->smtpClose();
    return TRUE;
  }

}
