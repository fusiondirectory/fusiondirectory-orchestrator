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

  // Return the task specified by object type for specific user ID
  // Subject to removal as user_uid might not be useful anymore.
  public function getTask (string $user_uid, ?string $object_type): array
  {
    $list_tasks = [];
    // if id - mail, change filter and search/return for task mail only

    switch ($object_type) {
      case "mail":
        $list_tasks = $this->getLdapTasks("(&(objectClass=fdTasksGranular)(fdtasksgranulartype=Mail Object))");
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

  public function processMailTasks (array $list_tasks) : array
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

        // verify status before processing (to be check with schedule as well).
        if ($mail["fdtasksgranularstatus"][0] == 1 && $this->verifySchedule($mail["fdtasksgranularschedule"][0])) {

          // Search for the related attached mail object.
          $cn = $mail["fdtasksgranularref"][0];
          $mail_content = $this->getLdapTasks("(&(objectClass=fdMailTemplate)(cn=$cn))");

          $setFrom     = $mail["fdtasksgranularmailfrom"][0];
          $replyTo     = $mail["fdtasksemailreplyto"][0] ?? NULL;
          $recipients  = $mail["fdtasksgranularmail"];
          $body        = $mail_content[0]["fdmailtemplatebody"][0];
          $signature   = $mail_content[0]["fdmailtemplatesignature"][0];
          $subject     = $mail_content[0]["fdmailtemplatesubject"][0];
          $receipt     = $mail_content[0]["fdmailtemplatereadreceipt"][0];
          $attachments = $mail_content[0]["fdmailtemplateattachment"] ?? NULL;

          $mail_controller = new MailController($setFrom,
                                            $replyTo,
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
            $result[] = 'PROCESSED';
            $this->updateLastMailExecTime($fdTasksConf[0]["dn"] );

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

  /*
   * Method which verify the last executed e-mails sent
   * Verify if the time interval is respected in order to protect from SPAM.
   */
  public function verifySpamProtection (array $fdTasksConf) : BOOL
  {
    $lastExec     = $fdTasksConf[0]["fdtasksconflastexectime"][0] ?? NULL;
    $spamInterval = $fdTasksConf[0]["fdtasksconfintervalemails"][0] ?? NULL;

    // Multiplication is required to have the minutes
    $antispam = $lastExec + $spamInterval * 100;
    if ($antispam <= date("YmdHis")) {

      return TRUE;
    }

    return FALSE;
  }


  // Verification of the schedule in complete string format and compare.
  public function verifySchedule (string $schedule) : bool
  {
    $date = (new DateTime)->format('Y-m-d-H-i-s');
    $dateEx  = explode('-', $date);
    $dateStringerized = implode("", $dateEx);

    if ($schedule < $dateStringerized) {
      return TRUE;
    }

    return FALSE;
  }

  public function getLdapTasks (string $filter, array $attrs = []): array
  {
    $empty_array = [];

    // Copy the existing DCs from the passed DN
    if (preg_match('/(dc=.*)/', $_ENV["LDAP_OU_USER"], $match)) {
      $dn = $match[0];
    } else {
      $dn = $_ENV["LDAP_OU_USER"];
    }

    $sr = ldap_search($this->ds, $dn, $filter, $attrs);
    $info = ldap_get_entries($this->ds, $sr);

    if (is_array($info) && $info["count"] >= 1 ) {
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
    $ldap_entry["cn"]                    = $cn;
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
    $ldap_entry["fdTasksConfLastExecTime"] = date("YmdHis");

    // Add data to LDAP
    try {

      $result = ldap_modify($this->ds, $dn, $ldap_entry);
    } catch (Exception $e) {

        echo json_encode(["Ldap Error" => "$e"]);
    }
  }

}











