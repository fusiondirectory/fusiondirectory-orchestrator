<?php

/**
 * Note : Tasks engine for FusionDirectory.
 * The gateway, often known as a data gateway or data access layer, is responsible for abstracting and encapsulating the interaction with an external system or a data source.
 * (e.g., an LDAP, an API, or another service).
 * It provides a unified interface for these operations.
 */
class TaskGateway
{
  public $ds;

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

      case "lifeCycle":
        $list_tasks = $this->getLdapTasks("(&(objectClass=fdTasksGranular)(fdtasksgranulartype=Life Cycle))");
        $this->unsetCountKeys($list_tasks);
        break;

      case "notifications":
        $list_tasks = $this->getLdapTasks("(&(objectClass=fdTasksGranular)(fdtasksgranulartype=Notifications))");
        $this->unsetCountKeys($list_tasks);;
        break;

      case "removeSubTasks":
      case "activateCyclicTasks":
        // No need to get any parent tasks here, but to note break logic - we will return an array.
        $list_tasks = ['Generic tasks execution'];
        break;

      // If no tasks object type declared , return all tasks
      case NULL:
        $list_tasks = $this->getLdapTasks("(objectClass=fdTasks)", ["cn", "objectClass"]);
        break;

      case $object_type:
        $list_tasks = $this->getLdapTasks("(&(objectClass=fdTasksGranular)(fdtasksgranulartype=" . $object_type . ")");
        $this->unsetCountKeys($list_tasks);
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
   * @param array $task
   * @return bool
   * @throws Exception
   */
  public function statusAndScheduleCheck (array $task): bool
  {
    return $task["fdtasksgranularstatus"][0] == 1 && $this->verifySchedule($task["fdtasksgranularschedule"][0]);
  }

  /**
   * @param string $mainTaskDn
   * @return array
   */
  public function getNotificationsMainTask (string $mainTaskDn): array
  {
    // Retrieve data from the main task
    return $this->getLdapTasks('(objectClass=fdTasksNotifications)', ['fdTasksNotificationsListOfRecipientsMails',
      'fdTasksNotificationsAttributes', 'fdTasksNotificationsMailTemplate', 'fdTasksNotificationsEmailSender'],
                               '', $mainTaskDn);
  }


  private function generateMainTaskMailTemplate (array $mainTask): array
  {
    // Generate email configuration for each result of subtasks having the same main task.w
    $recipients = $mainTask[0]["fdtasksnotificationslistofrecipientsmails"];
    unset($recipients['count']);
    $sender           = $mainTask[0]["fdtasksnotificationsemailsender"][0];
    $mailTemplateName = $mainTask[0]['fdtasksnotificationsmailtemplate'][0];

    $mailInfos   = $this->retrieveMailTemplateInfos($mailTemplateName);
    $mailContent = $mailInfos[0];

    // Set the notification array with all required variable for all sub-tasks of same main task origin.
    $mailForm['setFrom']    = $sender;
    $mailForm['recipients'] = $recipients;
    $mailForm['body']       = $mailContent["fdmailtemplatebody"][0];
    $mailForm['signature']  = $mailContent["fdmailtemplatesignature"][0] ?? NULL;
    $mailForm['subject']    = $mailContent["fdmailtemplatesubject"][0];
    $mailForm['receipt']    = $mailContent["fdmailtemplatereadreceipt"][0];

    return $mailForm;
  }

  /**
   * @param $array
   * @return void
   * Simple take an array as referenced and loop to remove all key having count
   */
  public function unsetCountKeys (&$array)
  {
    foreach ($array as $key => &$value) {
      if (is_array($value)) {
        $this->unsetCountKeys($value);
      } elseif ($key === 'count') {
        unset($array[$key]);
      }
    }
  }

  /**
   * @param array $notificationTask
   * @return array
   * NOTE : receive a unique tasks of type notification (one subtask at a time)
   */
  protected function retrieveAuditedAttributes (array $notificationTask): array
  {
    $auditAttributes = [];

    // Retrieve audit data attributes from the list of references set in the sub-task
    if (!empty($notificationTask['fdtasksgranularref'])) {
      // Remove count keys (count is shared by ldap).
      $this->unsetCountKeys($notificationTask);

      foreach ($notificationTask['fdtasksgranularref'] as $auditDN) {
        $auditInformation[] = $this->getLdapTasks('(&(objectClass=fdAuditEvent))',
                                                  ['fdAuditAttributes'], '', $auditDN);
      }
      // Again remove key: count retrieved from LDAP.
      $this->unsetCountKeys($auditInformation);
      // It is possible that an audit does not contain any attributes changes, condition is required.
      foreach ($auditInformation as $audit => $attr) {
        if (!empty($attr[0]['fdauditattributes'])) {
          // Clear and compact received results from above ldap search
          $auditAttributes = $attr[0]['fdauditattributes'];
        }
      }
    }

    return $auditAttributes;
  }

  /**
   * @param array $notifications
   * @return array
   * Note : This method is present to add to the mailForm body the proper uid and attrs info.
   */
  private function completeNotificationsBody (array $notifications, string $notificationsMainTaskName): array
  {
    // Iterate through each subTask and related attrs
    $uidAttrsText = [];

    foreach ($notifications[$notificationsMainTaskName]['subTask'] as $value) {
      $uidName = $value['uid'];
      $attrs   = [];

      foreach ($value['attrs'] as $attr) {
        $attrs[] = $attr;
      }
      $uidAttrsText[] = "\n$uidName attrs=[" . implode(', ', $attrs) . "]";
    }

    // Make the array unique, avoiding uid and same attribute duplication.
    $uidAttrsText = array_unique($uidAttrsText);
    // Add uid names and related attrs to mailForm['body']
    $notifications[$notificationsMainTaskName]['mailForm']['body'] .= " " . implode(" ", $uidAttrsText);

    return $notifications;
  }

  protected function sendNotificationsMail (array $notifications): array
  {
    $result = [];
    // Re-use of the same mail processing template logic
    $fdTasksConf    = $this->getMailObjectConfiguration();
    $maxMailsConfig = $this->returnMaximumMailToBeSend($fdTasksConf);

    /*
      Increment var starts a zero and added values will be the humber or recipients per main tasks, as one mail is
      sent per main task.
    */
    $maxMailsIncrement = 0;

    foreach ($notifications as $data) {
      $numberOfRecipients = count($data['mailForm']['recipients']);

      $mail_controller = new MailController(
        $data['mailForm']['setFrom'],
        NULL,
        $data['mailForm']['recipients'],
        $data['mailForm']['body'],
        $data['mailForm']['signature'],
        $data['mailForm']['subject'],
        $data['mailForm']['receipt'],
        NULL
      );

      $mailSentResult = $mail_controller->sendMail();
      $result[]       = $this->processMailResponseAndUpdateTasks($mailSentResult, $data, $fdTasksConf);

      // Verification anti-spam max mails to be sent and quit loop if matched.
      $maxMailsIncrement += $numberOfRecipients;
      if ($maxMailsIncrement == $maxMailsConfig) {
        break;
      }
    }

    return $result;
  }

  protected function processMailResponseAndUpdateTasks (array $serverResults, array $subTask, array $mailTaskBackend): array
  {
    $result = [];
    if ($serverResults[0] == "SUCCESS") {
      foreach ($subTask['subTask'] as $subTask => $details) {

        // CN of the main task
        $cn = $subTask;
        // DN of the main task
        $dn = $details['dn'];

        // Update task status for the current $dn
        $result[$dn]['statusUpdate']       = $this->updateTaskStatus($dn, $cn, "2");
        $result[$dn]['mailStatus']         = 'Notification was successfully sent';
        $result[$dn]['updateLastMailExec'] = $this->updateLastMailExecTime($mailTaskBackend[0]["dn"]);
      }
    } else {
      foreach ($subTask['subTask'] as $subTask => $details) {

        // CN of the main task
        $cn = $subTask;
        // DN of the main task
        $dn = $details['dn'];

        $result[$dn]['statusUpdate'] = $this->updateTaskStatus($dn, $cn, $serverResults[0]);
        $result[$dn]['mailStatus']   = $serverResults;
      }
    }

    return $result;
  }

//  /**
//   * @param array $list_tasks
//   * @return array[]|string[]
//   * @throws Exception
//   * Note : Verify the status and schedule as well as searching for the correct life cycle behavior from main task.
//   */
//  public function processLifeCycleTasks (array $list_tasks): array
//  {
//    // Array representing the status of the subtask.
//    $result = [];
//    // Initiate the object webservice.
//    $webservice = new WebServiceCall($_ENV['FUSION_DIRECTORY_API_URL'] . '/login', 'POST');
//    // Required to prepare future webservice call. E.g. Retrieval of mandatory token.
//    $webservice->setCurlSettings();
//
//    foreach ($list_tasks as $task) {
//      // If the tasks must be treated - status and scheduled - process the sub-tasks
//      if ($this->statusAndScheduleCheck($task)) {
//
//        // Simply retrieve the lifeCycle behavior from the main related tasks, sending the dns and desired attributes
//        $lifeCycleBehavior = $this->getLdapTasks('(objectClass=*)', ['fdTasksLifeCyclePreResource',
//          'fdTasksLifeCyclePreState', 'fdTasksLifeCyclePreSubState',
//          'fdTasksLifeCyclePostResource', 'fdTasksLifeCyclePostState', 'fdTasksLifeCyclePostSubState', 'fdTasksLifeCyclePostEndDate'],
//                                                 '', $task['fdtasksgranularmaster'][0]);
//
//        // Simply retrieve the current supannStatus of the user DN related to the task at hand.
//        $currentUserLifeCycle = $this->getLdapTasks('(objectClass=supannPerson)', ['supannRessourceEtatDate'],
//                                                    '', $task['fdtasksgranulardn'][0]);
//
//        // Compare both the required schedule and the current user status - returning TRUE if modification is required.
//        if ($this->isLifeCycleRequiringModification($lifeCycleBehavior, $currentUserLifeCycle)) {
//
//          // This will call a method to modify the ressourcesSupannEtatDate of the DN linked to the subTask
//          $lifeCycleResult = $this->updateLifeCycle($lifeCycleBehavior, $task['fdtasksgranulardn'][0]);
//          if ($lifeCycleResult === TRUE) {
//
//            $result[$task['dn']]['results'] = json_encode("Account states have been successfully modified for " . $task['fdtasksgranulardn'][0]);
//            // Status of the task must be updated to success
//            $updateResult = $this->updateTaskStatus($task['dn'], $task['cn'][0], '2');
//
//            // Here the user is refresh in order to activate methods based on supann Status changes.
//            $result[$task['dn']]['refreshUser'] = $webservice->refreshUserInfo($task['fdtasksgranulardn'][0]);
//
//            // In case the modification failed
//          } else {
//            $result[$task['dn']]['results'] = json_encode("Error updating " . $task['fdtasksgranulardn'][0] . "-" . $lifeCycleResult);
//            // Update of the task status error message
//            $updateResult = $this->updateTaskStatus($task['dn'], $task['cn'][0], $lifeCycleResult);
//          }
//          // Verification if the sub-task status has been updated correctly
//          if ($updateResult === TRUE) {
//            $result[$task['dn']]['statusUpdate'] = 'Success';
//          } else {
//            $result[$task['dn']]['statusUpdate'] = $updateResult;
//          }
//          // Remove the subtask has it is not required to update it nor to process it.
//        } else {
//          $result[$task['dn']]['results']      = 'Sub-task removed for : ' . $task['fdtasksgranulardn'][0] . ' with result : '
//            . $this->removeSubTask($task['dn']);
//          $result[$task['dn']]['statusUpdate'] = 'No updates required, sub-task will be removed.';
//        }
//      }
//    }
//    // If array is empty, no tasks of type life cycle needs to be treated.
//    if (empty($result)) {
//      $result = 'No tasks of type "Life Cycle" requires processing.';
//    }
//    return [$result];
//  }

//  /**
//   * @param array $lifeCycleBehavior
//   * @param string $userDN
//   * @return bool|string
//   */
//  protected function updateLifeCycle (array $lifeCycleBehavior, string $userDN)
//  {
//    // Extracting values of desired post-state behavior
//    $newEntry['Resource'] = $lifeCycleBehavior[0]['fdtaskslifecyclepostresource'][0];
//    $newEntry['State']    = $lifeCycleBehavior[0]['fdtaskslifecyclepoststate'][0];
//    $newEntry['SubState'] = $lifeCycleBehavior[0]['fdtaskslifecyclepostsubstate'][0] ?? ''; //SubState is optional
//    $newEntry['EndDate']  = $lifeCycleBehavior[0]['fdtaskslifecyclepostenddate'][0] ?? 0; //EndDate is optional
//
//    // Require the date of today to update the start of the new resources (If change of status).
//    $currentDate = new DateTime();
//    // Date of today + numbers of days to add for end date.
//    $newEndDate = new DateTime();
//    $newEndDate->modify("+" . $newEntry['EndDate'] . " days");
//
//    // Prepare the ldap entry to be modified
//    $ldapEntry                            = [];
//    $ldapEntry['supannRessourceEtatDate'] = "{" . $newEntry['Resource'] . "}"
//      . $newEntry['State'] . ":"
//      . $newEntry['SubState'] . ":" .
//      $currentDate->format('Ymd') . ":"
//      . $newEndDate->format('Ymd');
//
//    try {
//      $result = ldap_modify($this->ds, $userDN, $ldapEntry);
//    } catch (Exception $e) {
//      $result = json_encode(["Ldap Error" => "$e"]);
//    }
//
//    return $result;
//  }

  /**
   * @param bool|string $subTaskDn
   * @return bool|string
   */
  public function removeSubTask ($subTaskDn)
  {
    try {
      $result = ldap_delete($this->ds, $subTaskDn);
    } catch (Exception $e) {
      $result = json_encode(["Ldap Error" => "$e"]);
    }

    return $result;
  }

  /**
   * @return array
   * Note Search for all sub-tasks having status equals to 2 (completed).
   */
  public function removeCompletedTasks (): array
  {
    $result            = [];
    $subTasksCompleted = $this->getLdapTasks(
      "(&(objectClass=fdTasksGranular)(fdTasksGranularStatus=2))",
      ["dn"]
    );
    // remove the count key from the arrays, keeping only DN.
    $this->unsetCountKeys($subTasksCompleted);
    if (!empty($subTasksCompleted)) {
      foreach ($subTasksCompleted as $subTasks) {
        $result[$subTasks['dn']]['result'] = $this->removeSubTask($subTasks['dn']);
      }
    } else {
      $result[] = 'No completed sub-tasks were removed.';
    }

    return $result;
  }

  /**
   * @return array
   * @throws Exception
   */
  public function activateCyclicTasks (): array
  {
    $result = [];
    $tasks  = $this->getLdapTasks(
      "(&(objectClass=fdTasks)(fdTasksRepeatable=TRUE))",
      ["dn", "fdTasksRepeatableSchedule", "fdTasksLastExec", "fdTasksScheduleDate"]
    );
    // remove the count key from the arrays, keeping only DN.
    $this->unsetCountKeys($tasks);

    if (!empty($tasks)) {
      // Initiate the object webservice.
      $webservice = new WebServiceCall($_ENV['FUSION_DIRECTORY_API_URL'] . '/login', 'POST');

      // Required to prepare future webservice call. E.g. Retrieval of mandatory token.
      $webservice->setCurlSettings();
      // Is used to verify cyclic schedule with date format.
      $now = new DateTime('now');

      foreach ($tasks as $task) {
        // Transform schedule time (it is a simple string)
        $schedule = DateTime::createFromFormat("YmdHis", $task['fdtasksscheduledate'][0]);

        // First verification of the schedule of the task itself.
        if ($schedule <= $now) {
          // Case where the tasks were never run before but schedule is met, execute tasks.
          if (empty($task['fdtaskslastexec'][0])) {
            $result[$task['dn']]['result'] = $webservice->activateCyclicTasks($task['dn']);

            // Case where the tasks were once run, verification of the cyclic schedule and last exec.
          } else if (!empty($task['fdtasksrepeatableschedule'][0])) {
            $lastExec = new DateTime($task['fdtaskslastexec'][0]);

            // Efficient way to verify timelapse
            $interval = $now->diff($lastExec);

            switch ($task['fdtasksrepeatableschedule'][0]) {
              case 'Yearly' :
                if ($interval->y >= 1) {
                  $result[$task['dn']]['result'] = $webservice->activateCyclicTasks($task['dn']);
                } else {
                  $result[$task['dn']]['lastExecFailed'] = 'This cyclic task has yet to reached its next execution cycle.';
                }
                break;
              case 'Monthly' :
                if ($interval->m >= 1) {
                  $result[$task['dn']]['result'] = $webservice->activateCyclicTasks($task['dn']);
                } else {
                  $result[$task['dn']]['lastExecFailed'] = 'This cyclic task has yet to reached its next execution cycle.';
                }
                break;
              case 'Weekly' :
                if ($interval->d >= 7) {
                  $result[$task['dn']]['result'] = $webservice->activateCyclicTasks($task['dn']);
                } else {
                  $result[$task['dn']]['lastExecFailed'] = 'This cyclic task has yet to reached its next execution cycle.';
                }
                break;
              case 'Daily' :
                if ($interval->d >= 1) {
                  $result[$task['dn']]['result'] = $webservice->activateCyclicTasks($task['dn']);
                } else {
                  $result[$task['dn']]['lastExecFailed'] = 'This cyclic task has yet to reached its next execution cycle.';
                }
                break;
              case 'Hourly' :
                if ($interval->h >= 1) {
                  $result[$task['dn']]['result'] = $webservice->activateCyclicTasks($task['dn']);
                } else {
                  $result[$task['dn']]['lastExecFailed'] = 'This cyclic task has yet to reached its next execution cycle.';
                }
                break;
            }
          }
          // Case where cyclic tasks where found but the schedule is no ready.
        } else {
          $result[$task['dn']]['Status'] = 'This cyclic task has yet to reach its scheduled date.';
        }
      }
    } else {
      $result[] = 'No tasks require activation.';
    }

    return $result;
  }

//  /**
//   * @param array $lifeCycleBehavior
//   * @param array $currentUserLifeCycle
//   * @return bool
//   * Note receive the life cycle behavior desired and compare it the received current user life cycle, returning TRUE
//   * if there is indeed a difference and therefore must update the user information.
//   * In case the comparison is impossible due to the use not having a status listed, it will report false.
//   */
//  protected function isLifeCycleRequiringModification (array $lifeCycleBehavior, array $currentUserLifeCycle): bool
//  {
//    $result = FALSE;
//    // Regular expression in order to extract the supann format within an array
//    $pattern = '/\{(\w+)\}(\w):([^:]*)(?::([^:]*))?(?::([^:]*))?(?::([^:]*))?/';
//
//    // In case the tasks is launched without supann being activated on the user account, return error
//    if (empty($currentUserLifeCycle[0]['supannressourceetatdate'][0])) {
//      return FALSE;
//    }
//    // Perform the regular expression match
//    preg_match($pattern, $currentUserLifeCycle[0]['supannressourceetatdate'][0], $matches);
//
//    // Extracting values of current user
//    $userSupann['Resource'] = $matches[1] ?? '';
//    $userSupann['State']    = $matches[2] ?? '';
//    $userSupann['SubState'] = $matches[3] ?? '';
//    // Array index 4 is skipped, we only use end date to apply our life cycle logic. Start date has no use here.
//    $userSupann['EndDate'] = $matches[5] ?? '';
//
//    // Extracting values of desired pre-state behavior
//    $preStateSupann['Resource'] = $lifeCycleBehavior[0]['fdtaskslifecyclepreresource'][0];
//    $preStateSupann['State']    = $lifeCycleBehavior[0]['fdtaskslifecycleprestate'][0];
//    $preStateSupann['SubState'] = $lifeCycleBehavior[0]['fdtaskslifecyclepresubstate'][0] ?? ''; //SubState is optional
//
//    //  Verifying if the user end date for selected resource is overdue
//    if (!empty($userSupann['EndDate']) && strtotime($userSupann['EndDate']) <= time()) {
//      // Comparing value in a nesting conditions
//      if ($userSupann['Resource'] == $preStateSupann['Resource']) {
//        if ($userSupann['State'] == $preStateSupann['State']) {
//          // as SubState is optional, if both resource and state match at this point, modification is allowed.
//          if (empty($preStateSupann['SubState'])) {
//            $result = TRUE;
//          } else if ($preStateSupann['SubState'] == $userSupann['SubState']) {
//            $result = TRUE;
//          }
//        }
//      }
//    }
//
//    return $result;
//  }

  /**
   * @param string $schedule
   * @return bool
   * @throws Exception
   * Note : Verification of the schedule in complete string format and compare.
   * DateTime will use the system timezone by default.
   */
  public function verifySchedule (string $schedule): bool
  {
    $currentDateTime   = new DateTime('now'); // Get current datetime in locale timezone
    $scheduledDateTime = new DateTime($schedule); // Parse scheduled datetime string in local timezone

    if ($scheduledDateTime < $currentDateTime) {
      return TRUE; // Schedule has passed
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

    /** Verification if the search report a FALSE, possible in case of non-existing DN passed in sub-tasks from a past
     *members registration which is now obsolete. (Array of members with non-existing DN reported in FD).
     */
    try {
      $sr   = ldap_search($this->ds, $dn, $filter, $attrs);
      $info = ldap_get_entries($this->ds, $sr);
    } catch (Exception $e) {
      // build array for return response
      $result = [json_encode(["Ldap Error" => "$e"])]; // string returned
    }

    // Verify if the above ldap search succeeded.
    if (!empty($info) && is_array($info) && $info["count"] >= 1) {
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
    if (!empty($dn)) {
      $ldap_entry["cn"] = $cn;
    }

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
   * @param $objectType
   * @return array|string[]|void
   */
  public function getObjectTypeTask ($objectType)
  {
    $task = $this->getTask($objectType);
    if (!$task) {
      taskController::respondNotFound($objectType);
      exit;
    }

    return $task;
  }

}