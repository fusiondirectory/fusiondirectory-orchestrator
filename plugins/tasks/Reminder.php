<?php

class Reminder implements EndpointInterface
{

  private TaskGateway $gateway;

  public function __construct (TaskGateway $gateway)
  {
    $this->gateway = $gateway;
  }

  /**
   * @return array
   * Part of the interface of orchestrator plugin to treat GET method
   */
  public function processEndPointGet (): array
  {
    return [];
  }

  /**
   * @param array|null $data
   * @return array
   */
  public function processEndPointPost (array $data = NULL): array
  {
    return [];
  }

  /**
   * @param array|NULL $data
   * @return array
   * @throws Exception
   */
  public function processEndPointPatch (array $data = NULL): array
  {
    return $this->processReminder($this->gateway->getObjectTypeTask('reminder'));
  }

  /**
   * @param array|NULL $data
   * @return array
   */
  public function processEndPointDelete (array $data = NULL): array
  {
    return [];
  }

  /**
   * @param array $notificationsSubTasks
   * @return array
   * @throws Exception
   */
  public function processReminder (array $reminderSubTasks): array
  {
    $result = [];
    // It will contain all required reminders to be potentially sent per main task.
    $reminders = [];

    foreach ($reminderSubTasks as $task) {
      // If the tasks must be treated - status and scheduled - process the sub-tasks
      if ($this->gateway->statusAndScheduleCheck($task)) {

        // Retrieve data from the main task
        $remindersMainTaskName = $task['fdtasksgranularmaster'][0];
        $remindersMainTask     = $this->getRemindersMainTask($remindersMainTaskName);
        // remove the count keys
        $this->gateway->unsetCountKeys($remindersMainTask);

        // Generate the mail form with all mail controller requirements
        $mailTemplateRecipients = $this->generateMainTaskMailTemplate($remindersMainTask, FALSE);

        // Retrieve email attribute for the monitored members requiring reminding.
        $mailTemplateMonitoredMember = $this->getEmailFromReminded($task['fdtasksgranulardn'][0]);

        // Get monitored resources
        $monitoredResources = $this->getMonitoredResources($remindersMainTask[0]);

        // Case where no supann are monitored nor prolongation desired. (Useless subTask).
        if ($monitoredResources['resource'][0] === 'NONE' && $monitoredResources['prolongation'] === 'FALSE') {
          // Removal subtask
          $result[$task['dn']]['Removed'] = $this->gateway->removeSubTask($task['dn']);
          $result[$task['dn']]['Status']  = 'No reminder triggers were found, therefore removing the sub-task!';
        }

        // Case where supann is set monitored but no prolongation desired.
        if ($monitoredResources['resource'][0] !== 'NONE' && $monitoredResources['prolongation'] === 'FALSE') {
          if ($this->supannAboutToExpire($task['fdtasksgranulardn'][0], $monitoredResources, $task['fdtasksgranularhelper'][0])) {

            // Require to be set for updating the status of the task later on and sent the email.
            $reminders[$remindersMainTaskName]['subTask'][$task['cn'][0]]['dn']  = $task['dn'];
            $reminders[$remindersMainTaskName]['subTask'][$task['cn'][0]]['uid'] = $task['fdtasksgranulardn'][0];
            $reminders[$remindersMainTaskName]['mailForm']                       = $mailTemplateRecipients;
            // Add the reminded email form
            $reminders[$remindersMainTaskName]['mailForm']                       = $mailTemplateMonitoredMember;

          } else {
            // Not about to expire, delete subTask
            $result[$task['dn']]['Removed'] = $this->gateway->removeSubTask($task['dn']);
            $result[$task['dn']]['Status']  = 'No reminder triggers were found, therefore removing the sub-task!';
          }
        }


        //        // Here we must have a logic to create the token for the subTask.
        //        $reminders = $this->completeremindersBody($reminders, $remindersMainTaskName);
        //


      }
    }

    if (!empty($reminders)) {
      $result[] = $this->sendRemindersMail($reminders);
    }

    return $result;
  }

  /**
   * @param string $dn
   * @return string
   * Note : return the mail attribute from gosaMail objectclass.
   */
  private function getEmailFromReminded (string $dn): string
  {
    // in case the DN do not have an email set. - Return string FALSE.
    $result = "FALSE";
    $email  = $this->gateway->getLdapTasks('(objectClass=gosaMailAccount)', ['mail'],
                                           '', $dn);
    // Simply remove key "count"
    $this->gateway->unsetCountKeys($email);

    // Removing un-required keys (ldap return array with count and 0).
    if (!empty($email[0]['mail'][0])) {
      $result = $email[0]['mail'][0];
    }

    return $result;
  }

  /**
   * @param $task
   * @return bool
   * Note : Verify the account status of the DN with the requirements of main tasks.
   */
  private function supannAboutToExpire (string $dn, array $monitoredResources, int $days): bool
  {
    $result = FALSE;

    // Search the DN for supannResourceState
    $supannResources = $this->retrieveSupannResources($dn);
    if ($this->verifySupannState($monitoredResources, $supannResources)) {
      // verify the date
      $DnSupannDateObject = $this->retrieveDateFromSupannResourceState($supannResources['supannressourceetatdate'][0]);

      //Verification if the time is lower or equal than the reminder time.
      if ($DnSupannDateObject !== FALSE) {
        $today    = new DateTime();
        $interval = $today->diff($DnSupannDateObject);

        // Interval can be negative if date is in the past - we make sure it is not in the past by using invert.
        if ($interval->days <= $days && $interval->invert == 0) {
          $result = TRUE;
        }
      }
    }

    return $result;
  }

  /**
   * @param $dn
   * @return array
   * Note : Simply return supann resource array from the specific passed DN.
   */
  private function retrieveSupannResources ($dn): array
  {
    $supannResources = [];
    $supannResources = $this->gateway->getLdapTasks('(objectClass=supannPerson)', ['supannRessourceEtatDate', 'supannRessourceEtat'],
                                                    '', $dn);
    // Simply remove key "count"
    $this->gateway->unsetCountKeys($supannResources);

    // Removing un-required keys
    if (!empty($supannResources)) {
      $supannResources = $supannResources[0];
    }

    return $supannResources;

  }


  /**
   * Get the monitored resources for reminder to be activated.
   *
   * @param array $remindersMainTask
   * @return array
   */
  private function getMonitoredResources (array $remindersMainTask): array
  {

    $monitoredResourcesArray = [
      'resource' => $remindersMainTask['fdtasksreminderresource'],
      'state'    => $remindersMainTask['fdtasksreminderstate'],
      'subState' => $remindersMainTask['fdtasksremindersubstate'] ?? NULL
    ];

    // Boolean returned by ldap is a string.
    if (isset($remindersMainTask['fdtasksreminderaccountprolongation'][0]) && $remindersMainTask['fdtasksreminderaccountprolongation'][0] === 'TRUE') {
      // Add the potential next resources states to the array
      if (isset($remindersMainTask['fdtasksremindernextresource'])) {

        $monitoredResourcesArray['nextResource'] = $remindersMainTask['fdtasksremindernextresource'];
        $monitoredResourcesArray['nextState']    = $remindersMainTask['fdtasksremindernextstate'];
        $monitoredResourcesArray['nextSubState'] = $remindersMainTask['fdtasksremindernextsubstate'] ?? NULL;
      }

      $monitoredResourcesArray['fdTasksReminderPosix']   = $remindersMainTask['fdtasksreminderposix'] ?? FALSE;
      $monitoredResourcesArray['fdTasksReminderPPolicy'] = $remindersMainTask['fdtasksreminderppolicy'] ?? FALSE;

    }

    // For development logic, add the prolongation attribute. It will be checked later in the logic process.
    $monitoredResourcesArray['prolongation'] = $remindersMainTask['fdtasksreminderaccountprolongation'][0] ?? FALSE;

    return $monitoredResourcesArray;
  }


  /**
   * @param array $supannResource
   * @param array $auditedAttrs
   * @return bool
   * Note : Create the supann format and check for a match.
   */
  private function verifySupannState (array $reminderSupann, array $dnSupann): bool
  {
    $result = FALSE;

    //Construct the reminder Supann Resource State as string
    if (!empty($reminderSupann['subState'][0])) {
      $monitoredSupannState = '{' . $reminderSupann['resource'][0] . '}' . $reminderSupann['state'][0] . ':' . $reminderSupann['subState'][0];
    } else {
      $monitoredSupannState = '{' . $reminderSupann['resource'][0] . '}' . $reminderSupann['state'][0];
    }


    if (!empty($dnSupann['supannressourceetat'][0]) && $dnSupann['supannressourceetat'][0] === $monitoredSupannState) {

      $result = TRUE;
    }

    return $result;
  }

  /**
   * @param $supannEtatDate
   * @return DateTime|false
   * Note : Simply transform string date of supann to a dateTime object.
   * Can return bool (false) or dateTime object.
   */
  private function retrieveDateFromSupannResourceState ($supannEtatDate)
  {
    $dateString = NULL;
    // Simply take the last 8 digit
    preg_match('/(\d{8})$/', $supannEtatDate, $matches);

    if (!empty($matches)) {
      $dateString = $matches[0];
    }

    return DateTime::createFromFormat('Ymd', $dateString);
  }

  /**
   * @param $array
   * @return array
   * Note : simply return all values of a multi-dimensional array.
   */
  public function getArrayValuesRecursive ($array)
  {
    $values = [];
    foreach ($array as $value) {
      if (is_array($value)) {
        // If value is an array, merge its values recursively
        $values = array_merge($values, $this->getArrayValuesRecursive($value));
      } else {
        // If value is not an array, add it to the result
        $values[] = $value;
      }
    }
    return $values;
  }

  /**
   * @param string $mainTaskDn
   * @return array
   */
  public function getRemindersMainTask (string $mainTaskDn): array
  {
    // Retrieve data from the main Reminder task
    return $this->gateway->getLdapTasks(                                                     '(objectClass=fdTasksReminder)', ['fdTasksReminderListOfRecipientsMails',
      'fdTasksReminderResource', 'fdTasksReminderState', 'fdTasksReminderPosix', 'fdTasksReminderMailTemplate',
      'fdTasksReminderPPolicy', 'fdTasksReminderSupannNewEndDate', 'fdTasksReminderRecipientsMembers', 'fdTasksReminderEmailSender',
      'fdTasksReminderManager', 'fdTasksReminderAccountProlongation', 'fdTasksReminderMembers', 'fdTasksReminderNextResource',
      'fdTasksReminderNextState', 'fdTasksReminderNextSubState', 'fdTasksReminderSubState'], '', $mainTaskDn);
  }

  /**
   * @param array $mainTask
   * @param bool $reminded
   * @return array
   * Note : Simply generate the email to be sent as reminder.
   * Note 2 : The boolean is created to generate the token and is only sent to reminded. Not recipients.
   */
  private function generateMainTaskMailTemplate (array $mainTask, bool $reminded): array
  {
    // Generate email configuration for each result of subtasks having the same main task.
    $sender           = $mainTask[0]['fdtasksreminderemailsender'][0];
    $mailTemplateName = $mainTask[0]['fdtasksremindermailtemplate'][0];

    $mailInfos   = $this->gateway->getLdapTasks("(|(objectClass=fdMailTemplate)(objectClass=fdMailAttachments))", [], $mailTemplateName);
    $mailContent = $mailInfos[0];

    if (!$reminded) {
      $recipients = $mainTask[0]["fdtasksreminderlistofrecipientsmails"];
      $this->gateway->unsetCountKeys($recipients);
    } else {
      // reminded with token creation.
    }

    // Set the reminder array with all required variable for all sub-tasks of same main task origin.
    $mailForm['setFrom']    = $sender;
    $mailForm['recipients'] = $recipients;
    $mailForm['body']       = $mailContent["fdmailtemplatebody"][0];
    $mailForm['signature']  = $mailContent["fdmailtemplatesignature"][0] ?? NULL;
    $mailForm['subject']    = $mailContent["fdmailtemplatesubject"][0];
    $mailForm['receipt']    = $mailContent["fdmailtemplatereadreceipt"][0];

    return $mailForm;
  }

  /**
   * @param array $reminders
   * @return array
   * Note : Collect information and send reminder email.
   */
  protected function sendRemindersMail (array $reminders): array
  {
    $result = [];
    // Re-use of the same mail processing template logic
    $fdTasksConf    = $this->gateway->getLdapTasks(
      "(objectClass=fdTasksConf)",
      ["fdTasksConfLastExecTime", "fdTasksConfIntervalEmails", "fdTasksConfMaxEmails"]
    );
    $maxMailsConfig = $fdTasksConf[0]["fdtasksconfmaxemails"][0] ?? 50;

    /*
      Increment var starts a zero and added values will be the number of recipients per main tasks, as one mail is
      sent per main task.
    */
    $maxMailsIncrement = 0;

    foreach ($reminders as $data) {
      $numberOfRecipients = count($data['mailForm']['recipients']);

      $mail_controller = new \FusionDirectory\Mail\MailLib(
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

  /**
   * @param array $serverResults
   * @param array $subTask
   * @param array $mailTaskBackend
   * @return array
   * Note :
   */
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
        $result[$dn]['statusUpdate']       = $this->gateway->updateTaskStatus($dn, $cn, "2");
        $result[$dn]['mailStatus']         = 'reminder was successfully sent';
        $result[$dn]['updateLastMailExec'] = $this->gateway->updateLastMailExecTime($mailTaskBackend[0]["dn"]);
      }
    } else {
      foreach ($subTask['subTask'] as $subTask => $details) {

        // CN of the main task
        $cn = $subTask;
        // DN of the main task
        $dn = $details['dn'];

        $result[$dn]['statusUpdate'] = $this->gateway->updateTaskStatus($dn, $cn, $serverResults[0]);
        $result[$dn]['mailStatus']   = $serverResults;
      }
    }

    return $result;
  }

}