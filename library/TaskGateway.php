<?php

// Class to Get / Create FD Tasks
class TaskGateway
{
  private $ds;

  // Variable type can be LDAP : enhancement
  public function __construct ($ldap_connect)
  {
    $this->ds = $ldap_connect->getConnection();
  }

  // Return the task specified by object type.
  public function getTask (?string $object_type): array
  {
    switch ($object_type) {
      case "mail":
        $list_tasks = $this->getLdapTasks("(&(objectClass=fdTasksGranular)(fdtasksgranulartype=Mail Object))");
        unset($list_tasks["count"]);
        break;

      case "lifeCycle":
        $list_tasks = $this->getLdapTasks("(&(objectClass=fdTasksGranular)(fdtasksgranulartype=Life Cycle))");
        unset($list_tasks["count"]);
        break;

      // If no tasks object type declared , return all tasks
      case NULL:
        $list_tasks = $this->getLdapTasks("(objectClass=fdTasks)", ["cn", "objectClass"]);
        break;

      //Will match any object type passed not found.
      default:
        // return empty array which will be interpreted as FALSE by parent.
        $list_tasks = [];
        break;
    }

    return $list_tasks;
  }

  public function processMailTasks (array $list_tasks): array
  {
    $result = [];

    $fdTasksConf = $this->getLdapTasks(
      "(objectClass=fdTasksConf)",
      ["fdTasksConfLastExecTime", "fdTasksConfIntervalEmails", "fdTasksConfMaxEmails"]
    );

    // set the maximum mails to be sent to the configured value or 50 if not set.
    $maxMailsConfig = $fdTasksConf[0]["fdtasksconfmaxemails"][0] ?? 50;

    if ($this->verifySpamProtection($fdTasksConf)) {
      foreach ($list_tasks as $mail) {

        $maxMailsIncrement = 0;

        // verify status before processing (to be checked with schedule as well).
        if ($mail["fdtasksgranularstatus"][0] == 1 && $this->verifySchedule($mail["fdtasksgranularschedule"][0])) {

          // Search for the related attached mail object.
          $cn          = $mail["fdtasksgranularref"][0];
          $mailInfos   = $this->getLdapTasks("(|(objectClass=fdMailTemplate)(objectClass=fdMailAttachments))", [], $cn);
          $mailContent = $mailInfos[0];

          // Only takes arrays related to files attachments for the mail template selected
          unset($mailInfos[0]);
          // Re-order keys
          unset($mailInfos['count']);
          $mailAttachments = array_values($mailInfos);

          $setFrom    = $mail["fdtasksgranularmailfrom"][0];
          $setBCC     = $mail["fdtasksgranularmailbcc"][0] ?? NULL;
          $recipients = $mail["fdtasksgranularmail"];
          $body       = $mailContent["fdmailtemplatebody"][0];
          $signature  = $mailContent["fdmailtemplatesignature"][0] ?? NULL;
          $subject    = $mailContent["fdmailtemplatesubject"][0];
          $receipt    = $mailContent["fdmailtemplatereadreceipt"][0];

          foreach ($mailAttachments as $file) {
            $fileInfo['cn']      = $file['cn'][0];
            $fileInfo['content'] = $file['fdmailattachmentscontent'][0];
            $attachments[]       = $fileInfo;
          }

          // Required before passing the array to the constructor mail.
          if (empty($attachments)) {
            $attachments = NULL;
          }

          $mail_controller = new MailController($setFrom,
            $setBCC,
            $recipients,
            $body,
            $signature,
            $subject,
            $receipt,
            $attachments);

          $mailSentResult = $mail_controller->sendMail();

          if ($mailSentResult[0] == "SUCCESS") {

            // The third arguments "2" is the status code of success for mail as of now 18/11/22
            $this->updateTaskMailStatus($mail["dn"], $mail["cn"][0], "2");
            $result[] = 'mail : ' . $mail["dn"] . ' was successfully sent';
            $this->updateLastMailExecTime($fdTasksConf[0]["dn"]);

          } else {
            $this->updateTaskMailStatus($mail["dn"], $mail["cn"][0], $mailSentResult[0]);
            $result[] = $mailSentResult;
          }

          // Verification anti-spam max mails to be sent and quit loop if matched
          $maxMailsIncrement += 1;
          if ($maxMailsIncrement == $maxMailsConfig) {
            break;
          }

        }
      }
    }

    return $result;
  }

  public function processLifeCycleTasks (array $list_tasks): array
  {
    json_encode('Testing LifeCycle Process');

    return ['yeayh'];
  }


  /*
   * Method which verify the last executed e-mails sent
   * Verify if the time interval is respected in order to protect from SPAM.
   */
  public function verifySpamProtection (array $fdTasksConf): bool
  {
    $lastExec     = $fdTasksConf[0]["fdtasksconflastexectime"][0] ?? NULL;
    $spamInterval = $fdTasksConf[0]["fdtasksconfintervalemails"][0] ?? NULL;

    // Multiplication is required to have the seconds
    $spamInterval = $spamInterval * 60;
    $antispam     = $lastExec + $spamInterval;
    if ($antispam <= time()) {
      return TRUE;
    }

    return FALSE;
  }


  // Verification of the schedule in complete string format and compare.
  public function verifySchedule (string $schedule): bool
  {
    $schedule = strtotime($schedule);
    if ($schedule < time()) {
      return TRUE;
    }

    return FALSE;
  }

  public function getLdapTasks (string $filter, array $attrs = [], string $attachmentsCN = NULL): array
  {
    $empty_array = [];

    // Copy the existing DCs from the passed DN
    if (preg_match('/(dc=.*)/', $_ENV["LDAP_OU_DSA"], $match)) {
      $dn = $match[0];
    } else {
      $dn = $_ENV["LDAP_OU_DSA"];
    }

    // This is the logic in order to get sub nodes attachments based on the mailTemplate parent cn.
    if (!empty($attachmentsCN)) {
      $dn = 'cn=' . $attachmentsCN . ',ou=mailTemplate,' . $dn;
    }

    $sr   = ldap_search($this->ds, $dn, $filter, $attrs);
    $info = ldap_get_entries($this->ds, $sr);

    if (is_array($info) && $info["count"] >= 1) {
      return $info;
    }

    return $empty_array;
  }

  /*
   * Update the status of the tasks.
   */
  public function updateTaskMailStatus (string $dn, string $cn, string $status): void
  {
    // prepare data
    $ldap_entry["cn"] = $cn;
    // Status subject to change
    $ldap_entry["fdTasksGranularStatus"] = $status;

    // Add data to LDAP
    try {

      $result = ldap_modify($this->ds, $dn, $ldap_entry);
    } catch (Exception $e) {

      echo json_encode(["Ldap Error" => "$e"]);
    }
  }

  /*
  * Update the attribute lastExecTime from fdTasksConf.
  */
  public function updateLastMailExecTime (string $dn): void
  {
    // prepare data
    $ldap_entry["fdTasksConfLastExecTime"] = time();

    // Add data to LDAP
    try {

      $result = ldap_modify($this->ds, $dn, $ldap_entry);
    } catch (Exception $e) {

      echo json_encode(["Ldap Error" => "$e"]);
    }
  }

}