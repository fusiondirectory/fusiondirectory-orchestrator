<?php

class MailUtils
{
  private function __construct ()
  {
  }

  public static function sendMail ($setFrom, $setBCC, $recipients, $body, $signature, $subject, $receipt, $attachments)
  {
    $mail_controller = new \FusionDirectory\Mail\MailLib($setFrom,
      $setBCC,
      $recipients,
      $body,
      $signature,
      $subject,
      $receipt,
      $attachments);

    return $mail_controller->sendMail();
  }

  /**
   * @return array
   * Note : A simple retrieval methods of the mail backend configuration set in FusionDirectory
   */
  public static function getMailObjectConfiguration (TaskGateway $gateway): array
  {
    return $gateway->getLdapTasks(
      "(objectClass=fdTasksConf)",
      ["fdTasksConfLastExecTime", "fdTasksConfIntervalEmails", "fdTasksConfMaxEmails"]
    );
  }
}