<?php

/**
 * Note : Tasks engine for FusionDirectory.
 */
class TaskGateway
{
  private $ds;

  // Variable type can be LDAP : enhancement
  public function __construct ($ldap_connect)
  {
    $this->ds = $ldap_connect->getConnection();
  }

  /**
   * @param string|null $object_type
   * @return array
   * Note : Return the task specified by object type.
   */
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

  /**
   * @param array $list_tasks
   * @return array
   */
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
            $result[$mail["dn"]]['statusUpdate']   = $this->updateTaskStatus($mail["dn"], $mail["cn"][0], "2");
            $result[$mail["dn"]]                   = 'mail : ' . $mail["dn"] . ' was successfully sent';
            $result[$mail["dn"]]['updateLastExec'] = $this->updateLastMailExecTime($fdTasksConf[0]["dn"]);

          } else {
            $result[$mail["dn"]]['statusUpdate'] = $this->updateTaskStatus($mail["dn"], $mail["cn"][0], $mailSentResult[0]);
            $result[$mail["dn"]]                 = $mailSentResult;
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

  /**
   * @param array $list_tasks
   * @return array
   * Note : Verify the status and schedule as well as searching for the correct life cycle behavior from main task.
   */
  public function processLifeCycleTasks (array $list_tasks): array
  {
    // Array representing the status of the subtask.
    $result = [];
    foreach ($list_tasks as $task) {
      // If the tasks must be treated - status and scheduled - process the sub-tasks
      if ($task["fdtasksgranularstatus"][0] == 1 && $this->verifySchedule($task["fdtasksgranularschedule"][0])) {

        // Simply retrieve the lifeCycle behavior from the main related tasks, sending the dns and desired attributes
        $lifeCycleBehavior = $this->getLdapTasks('(objectClass=*)', ['fdTasksLifeCyclePreResource',
          'fdTasksLifeCyclePreState', 'fdTasksLifeCyclePreSubState',
          'fdTasksLifeCyclePostResource', 'fdTasksLifeCyclePostState', 'fdTasksLifeCyclePostSubState', 'fdTasksLifeCyclePostEndDate'],
          '', $task['fdtasksgranularmaster'][0]);

        // Simply retrieve the current supannStatus of the user DN related to the task at hand.
        $currentUserLifeCycle = $this->getLdapTasks('(objectClass=supannPerson)', ['supannRessourceEtatDate'],
          '', $task['fdtasksgranulardn'][0]);

        // Compare both the required schedule and the current user status - returning TRUE if modification is required.
        if ($this->isLifeCycleRequiringModification($lifeCycleBehavior, $currentUserLifeCycle)) {

          // This will call a method to modify the ressourcesSupannEtatDate of the DN linked to the subTask
          $lifeCycleResult = $this->updateLifeCycle($lifeCycleBehavior, $task['fdtasksgranulardn'][0]);
          if ($lifeCycleResult === TRUE) {

            $result[$task['dn']]['results'] = json_encode("Account states have been successfully modified for " . $task['fdtasksgranulardn'][0]);
            // Status of the task must be updated to success
            $updateResult = $this->updateTaskStatus($task['dn'], $task['cn'][0], '2');

            // In case the modification failed
          } else {
            $result[$task['dn']]['results'] = json_encode("Error updating " . $task['fdtasksgranulardn'][0] . "-" . $lifeCycleResult);
            // Update of the task status error message
            $updateResult = $this->updateTaskStatus($task['dn'], $task['cn'][0], $lifeCycleResult);
          }
          // Verification if the sub-task status has been updated correctly
          if ($updateResult === TRUE) {
            $result[$task['dn']]['statusUpdate'] = 'Success';
          } else {
            $result[$task['dn']]['statusUpdate'] = $updateResult;
          }
          // Remove the subtask has it is not required to update it nor to process it.
        } else {
          $result[$task['dn']]['delete'] = $this->removeSubTask($task['dn']);
        }
      }
    }
    // If array is empty, no tasks of type life cycle needs to be treated.
    if (empty($result)) {
      $result = 'No tasks of type "Life Cycle" requires processing.';
    }
    return [$result];
  }

  /**
   * @param array $lifeCycleBehavior
   * @param string $userDN
   * @return bool|string
   */
  protected function updateLifeCycle (array $lifeCycleBehavior, string $userDN)
  {
    // Extracting values of desired post-state behavior
    $newEntry['Resource'] = $lifeCycleBehavior[0]['fdtaskslifecyclepostresource'][0];
    $newEntry['State']    = $lifeCycleBehavior[0]['fdtaskslifecyclepoststate'][0];
    $newEntry['SubState'] = $lifeCycleBehavior[0]['fdtaskslifecyclepostsubstate'][0] ?? ''; //SubState is optional
    $newEntry['EndDate']  = $lifeCycleBehavior[0]['fdtaskslifecyclepostenddate'][0] ?? 0; //EndDate is optional

    // Require the date of today to update the start of the new resources (If change of status).
    $currentDate  = new DateTime();
    // Date of today + numbers of days to add for end date.
    $newEndDate   = new DateTime();
    $newEndDate->modify("+" . $newEntry['EndDate'] . " days");

    // Prepare the ldap entry to be modified
    $ldapEntry                            = [];
    $ldapEntry['supannRessourceEtatDate'] = "{" . $newEntry['Resource'] . "}"
      . $newEntry['State'] . ":"
      . $newEntry['SubState'] . ":" .
      $currentDate->format('Ymd') . ":"
      . $newEndDate->format('Ymd');

    try {
      $result = ldap_modify($this->ds, $userDN, $ldapEntry);
    } catch (Exception $e) {
      $result = json_encode(["Ldap Error" => "$e"]);
    }

    return $result;
  }

  protected function removeSubTask ($subTaskDn): bool
  {
    try {
      $result = ldap_delete($this->ds, $subTaskDn);
    } catch (Exception $e) {
      $result = json_encode(["Ldap Error" => "$e"]);
    }

    return $result;
  }

  /**
   * @param array $lifeCycleBehavior
   * @param array $currentUserLifeCycle
   * @return bool
   * Note receive the life cycle behavior desired and compare it the received current user life cycle, returning TRUE
   * if there is indeed a difference and therefore must update the user information.
   * In case the comparison is impossible due to the use not having a status listed, it will report false.
   */
  protected function isLifeCycleRequiringModification (array $lifeCycleBehavior, array $currentUserLifeCycle): bool
  {
    $result = FALSE;
    // Regular expression in order to extract the supann format within an array
    $pattern = '/\{(\w+)\}(\w):([^:]*)(?::([^:]*))?(?::([^:]*))?(?::([^:]*))?/';

    // In case the tasks is launched without supann being activated on the user account, return error
    if (empty($currentUserLifeCycle[0]['supannressourceetatdate'][0])) {
      return FALSE;
    }
    // Perform the regular expression match
    preg_match($pattern, $currentUserLifeCycle[0]['supannressourceetatdate'][0], $matches);

    // Extracting values of current user
    $userSupann['Resource'] = $matches[1] ?? '';
    $userSupann['State']    = $matches[2] ?? '';
    $userSupann['SubState'] = $matches[3] ?? '';
    // Array index 4 is skipped, we only use end date to apply our life cycle logic. Start date has no use here.
    $userSupann['EndDate'] = $matches[5] ?? '';

    // Extracting values of desired pre-state behavior
    $preStateSupann['Resource'] = $lifeCycleBehavior[0]['fdtaskslifecyclepreresource'][0];
    $preStateSupann['State']    = $lifeCycleBehavior[0]['fdtaskslifecycleprestate'][0];
    $preStateSupann['SubState'] = $lifeCycleBehavior[0]['fdtaskslifecyclepresubstate'][0] ?? ''; //SubState is optional

    //  Verifying if the user end date for selected resource is overdue
    if (!empty($userSupann['EndDate']) && strtotime($userSupann['EndDate']) <= time()) {
      // Comparing value in a nesting conditions
      if ($userSupann['Resource'] == $preStateSupann['Resource']) {
        if ($userSupann['State'] == $preStateSupann['State']) {
          // as SubState is optional, if both resource and state match at this point, modification is allowed.
          if (empty($preStateSupann['SubState'])) {
            $result = TRUE;
          } else if ($preStateSupann['SubState'] == $userSupann['SubState']) {
            $result = TRUE;
          }
        }
      }
    }

    return $result;
  }

  /**
   * @param array $fdTasksConf
   * @return bool
   * Note : Method which verify the last executed e-mails sent
   *  Verify if the time interval is respected in order to protect from SPAM
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

  /**
   * @param string $schedule
   * @return bool
   */
  // Verification of the schedule in complete string format and compare.
  public function verifySchedule (string $schedule): bool
  {
    $schedule = strtotime($schedule);
    if ($schedule < time()) {
      return TRUE;
    }

    return FALSE;
  }

  /**
   * @param string $filter
   * @param array $attrs
   * @param string|NULL $attachmentsCN
   * @param string|NULL $dn
   * @return array
   * NOTE : Filter in ldap_search cannot be an empty string or NULL, if not filters are required, use (objectClass=*).
   */
  public function getLdapTasks (string $filter = '', array $attrs = [], string $attachmentsCN = NULL, string $dn = NULL): array
  {
    $result = [];

    // Verify if an optional DN is passed, set de default if not.
    if (empty($dn)) {
      $dn = $_ENV["LDAP_BASE"];
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

    return $result;
  }

  /**
   * @param string $dn
   * @param string $cn
   * @param string $status
   * @return bool|string
   * Note : Update the status of the tasks.
   */
  public function updateTaskStatus (string $dn, string $cn, string $status)
  {
    // prepare data
    $ldap_entry["cn"] = $cn;
    // Status subject to change
    $ldap_entry["fdTasksGranularStatus"] = $status;

    // Add status to LDAP
    try {
      $result = ldap_modify($this->ds, $dn, $ldap_entry); // bool returned
    } catch (Exception $e) {
      $result = json_encode(["Ldap Error" => "$e"]); // string returned
    }

    return $result;
  }

  /**
   * @param string $dn
   * @return bool|string
   * Note: Update the attribute lastExecTime from fdTasksConf.
   */
  public function updateLastMailExecTime (string $dn)
  {
    // prepare data
    $ldap_entry["fdTasksConfLastExecTime"] = time();

    // Add data to LDAP
    try {
      $result = ldap_modify($this->ds, $dn, $ldap_entry);
    } catch (Exception $e) {

      $result = json_encode(["Ldap Error" => "$e"]);
    }
    return $result;
  }

}