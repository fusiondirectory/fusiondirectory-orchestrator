<?php

class MailUtils
{
  public function __construct ()
  {
  }

  public function sendMail ($setFrom, $setBCC, $recipients, $body, $signature, $subject, $receipt, $attachments)
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
  public function getMailObjectConfiguration (TaskGateway $gateway): array
  {
    return $gateway->getLdapTasks(
      "(objectClass=fdTasksConf)",
      ["fdTasksConfLastExecTime", "fdTasksConfIntervalEmails", "fdTasksConfMaxEmails"]
    );
  }

    /**
     * @param array $fdTasksConf
     * @return int
     * Note : Allows a safety check in case mail configuration backed within FD has been missed. (50).
     */
    public function returnMaximumMailToBeSend (array $fdTasksConf): int
    {
        // set the maximum mails to be sent to the configured value or 50 if not set.
        return $fdTasksConf[0]["fdtasksconfmaxemails"][0] ?? 50;
    }
}