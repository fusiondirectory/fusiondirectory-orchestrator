<?php

class Reminder implements EndpointInterface
{

  private TaskGateway $gateway;
  private ReminderTokenUtils $reminderTokenUtils;
  private MailUtils $mailUtils;

  public function __construct (TaskGateway $gateway)
  {
    $this->gateway = $gateway;
    $this->reminderTokenUtils = new ReminderTokenUtils();
    $this->mailUtils = new MailUtils();
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
  public function processEndPointPost (?array $data = NULL): array
  {
    return [];
  }

  /**
   * @param array|NULL $data
   * @return array
   * @throws Exception
   */
  public function processEndPointPatch (?array $data = NULL): array
  {
    return $this->processReminder($this->gateway->getObjectTypeTask('reminder'));
  }

  /**
   * @param array|NULL $data
   * @return array
   */
  public function processEndPointDelete (?array $data = NULL): array
  {
    return [];
  }

  /**
   * @param array $reminderSubTasks
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
        $remindersMainTaskName = $task['fdtasksgranularmaster'][0]; //dn
        // Get the main task DN
        $mainTaskDn = $remindersMainTaskName;
        $remindersMainTask     = $this->getRemindersMainTask($remindersMainTaskName);
        // remove the count keys
        $this->gateway->unsetCountKeys($remindersMainTask);

        // Determine repeatable schedule only if main task marked repeatable
        $repeatableSchedule = NULL;
        $repeatableFlag     = $remindersMainTask[0]['fdtasksrepeatable'][0] ?? NULL;

        if ($repeatableFlag !== NULL && strcasecmp($repeatableFlag, 'TRUE') === 0) {
          $repeatableSchedule = $remindersMainTask[0]['fdtasksrepeatableschedule'][0] ?? NULL;
        }

        // Retrieve email attribute for the monitored members requiring reminding.
        $mailOfTheReminded = $this->getEmailFromReminder($task['fdtasksgranulardn'][0]);

        // Generate the mail form with all mail controller requirements
        $mailTemplateForm = $this->generateMainTaskMailTemplate($remindersMainTask, $mailOfTheReminded);

        // Get monitored resources
        $monitoredResources = $this->getMonitoredResources($remindersMainTask[0]);

        // Case where no supann are monitored nor prolongation desired. (Useless subTask).
        if ($monitoredResources['resource'][0] === 'NONE' && $monitoredResources['prolongation'] === 'FALSE') {
          // Update subtask status to 3 (nothing to process) instead of removing it
          $result[$task['dn']]['Status'] = $this->gateway->updateTaskStatus(
            $task['dn'],
            $task['cn'][0],
            '3',
            $mainTaskDn,
            $repeatableSchedule
          );
          $result[$task['dn']]['Message'] = 'No reminder triggers were found, nothing to process!';
        }

        // Case where supann is set monitored but no prolongation desired.
        if ($monitoredResources['resource'][0] !== 'NONE' && $monitoredResources['prolongation'] === 'FALSE') {
          if ($this->supannAboutToExpire($task['fdtasksgranulardn'][0], $monitoredResources, $task['fdtasksgranularhelper'][0])) {

            // Require to be set for updating the status of the task later on and sent the email.
            $reminders[$remindersMainTaskName]['subTask'][$task['cn'][0]]['dn']  = $task['dn'];
            $reminders[$remindersMainTaskName]['subTask'][$task['cn'][0]]['uid'] = $task['fdtasksgranulardn'][0];
            // Store repeatable schedule for later use in processMailResponseAndUpdateTasks
            $reminders[$remindersMainTaskName]['repeatableSchedule'] = $repeatableSchedule;
            // Recipient email form
            $reminders[$remindersMainTaskName]['subTask'][$task['cn'][0]]['mail'] = $mailTemplateForm;

          } else {
            // Not about to expire, update status to 3 (nothing to process)
            $result[$task['dn']]['Status'] = $this->gateway->updateTaskStatus(
              $task['dn'],
              $task['cn'][0],
              '3',
              $mainTaskDn,
              $repeatableSchedule
            );
            $result[$task['dn']]['Message'] = 'Account not about to expire, nothing to process!';
          }
        }

        // Case where supann is set and prolongation is desired.
        if ($monitoredResources['resource'][0] !== 'NONE' && $monitoredResources['prolongation'] === 'TRUE') {
          if ($this->supannAboutToExpire($task['fdtasksgranulardn'][0], $monitoredResources, $task['fdtasksgranularhelper'][0])) {
            // Require to be set for updating the status of the task later on and sent the email.
            $reminders[$remindersMainTaskName]['subTask'][$task['cn'][0]]['dn']  = $task['dn'];
            $reminders[$remindersMainTaskName]['subTask'][$task['cn'][0]]['uid'] = $task['fdtasksgranulardn'][0];
            // Store repeatable schedule for later use in processMailResponseAndUpdateTasks
            $reminders[$remindersMainTaskName]['repeatableSchedule'] = $repeatableSchedule;

            // Create timeStamp expiration for token
            $tokenExpire = $this->reminderTokenUtils->getTokenExpiration($task['fdtasksgranularhelper'][0],
              $remindersMainTask[0]['fdtasksreminderfirstcall'][0],
              $remindersMainTask[0]['fdtasksremindersecondcall'][0]);
            // Create token for SubTask
            $token = $this->reminderTokenUtils->generateToken($task['fdtasksgranulardn'][0], $tokenExpire, $this->gateway);
            // Edit the mailForm with the url link containing the token
            $tokenMailTemplateForm = $this->reminderTokenUtils->generateTokenUrl($token, $mailTemplateForm, $remindersMainTaskName);
            // Recipient email form
            $reminders[$remindersMainTaskName]['subTask'][$task['cn'][0]]['mail'] = $tokenMailTemplateForm;


          } else {
            // Not about to expire, update status to 3 (nothing to process)
            $result[$task['dn']]['Status'] = $this->gateway->updateTaskStatus(
              $task['dn'],
              $task['cn'][0],
              '3',
              $mainTaskDn,
              $repeatableSchedule
            );
            $result[$task['dn']]['Message'] = 'Account not about to expire, nothing to process!';
          }
        }

        // Case where prolongation is set without supann.
        if ($monitoredResources['resource'][0] === 'NONE' && $monitoredResources['prolongation'] === 'TRUE') {
          if ($this->posixAboutToExpire($task['fdtasksgranulardn'][0], $task['fdtasksgranularhelper'][0])) {

            // Require to be set for updating the status of the task later on and sent the email.
            $reminders[$remindersMainTaskName]['subTask'][$task['cn'][0]]['dn']  = $task['dn'];
            $reminders[$remindersMainTaskName]['subTask'][$task['cn'][0]]['uid'] = $task['fdtasksgranulardn'][0];
            // Store repeatable schedule for later use in processMailResponseAndUpdateTasks
            $reminders[$remindersMainTaskName]['repeatableSchedule'] = $repeatableSchedule;

            // Create timeStamp expiration for token
            $tokenExpire = $this->reminderTokenUtils->getTokenExpiration($task['fdtasksgranularhelper'][0],
              $remindersMainTask[0]['fdtasksreminderfirstcall'][0],
              $remindersMainTask[0]['fdtasksremindersecondcall'][0]);
            // Create token for SubTask
            $token = $this->reminderTokenUtils->generateToken($task['fdtasksgranulardn'][0], $tokenExpire, $this->gateway);
            // Edit the mailForm with the url link containing the token
            $tokenMailTemplateForm = $this->reminderTokenUtils->generateTokenUrl($token, $mailTemplateForm, $remindersMainTaskName);
            // Recipient email form
            $reminders[$remindersMainTaskName]['subTask'][$task['cn'][0]]['mail'] = $tokenMailTemplateForm;


          } else {
            // Not about to expire, update status to 3 (nothing to process)
            $result[$task['dn']]['Status'] = $this->gateway->updateTaskStatus(
              $task['dn'],
              $task['cn'][0],
              '3',
              $mainTaskDn,
              $repeatableSchedule
            );
            $result[$task['dn']]['Message'] = 'Posix account not about to expire, nothing to process!';
          }
        }
      }
    }

    if (!empty($reminders)) {
      $result[] = $this->sendRemindersMail($reminders);
    }

    return $result;
  }

  /**
   * @param string $dn
   * @param int $days
   * @return bool
   * Note : Compare the date of today and the shadowExpire epoch to see if expiration is soon to happen.
   */
  private function posixAboutToExpire (string $dn, int $days) : bool
  {
    $result = FALSE;

    $userShadowExpire = $this->retrieveUserPosix($dn);
    // Verification if shadowExpire was retrieved
    if (!empty($userShadowExpire)) {
      // Create the date of today
      $today    = new DateTime();
      // Create a proper timestamp for verification
      $epoch = new DateTime("1970-01-01");
      // Add the shadowExpire days to the epoch
      $epoch->add(new DateInterval("P{$userShadowExpire}D"));

      // Get the interval between today and the expiration of shadow expire.
      $interval = $today->diff($epoch);

      // Interval can be negative if date is in the past - we make sure it is not in the past by using invert.
      if ($interval->invert == 0) {
        if (($interval->days < $days) || (($interval->days == $days) && ($interval->h == 0))) {
          $result = TRUE;
        }
      }
    }

    return $result;
  }

  /**
   * @param string $dn
   * @return string
   * Note : Simply retrieve shadowExpire attribute for the DN specified.
   */
  private function retrieveUserPosix (string $dn) : string
  {
    $result = '';
    $userPosix = $this->gateway->getLdapTasks('(objectClass=shadowAccount)', ['shadowExpire'],
      '', $dn);

    // Simply remove key "count"
    $this->gateway->unsetCountKeys($userPosix);

    // Removing un-required keys
    if (!empty($userPosix[0]['shadowexpire'][0])) {
      $result = $userPosix[0]['shadowexpire'][0];
    }

    return $result;
  }

  /**
   * @param string $dn
   * @return string
   * Note : return the mail attribute from the user DN using configurable mail type.
   */
  private function getEmailFromReminder (string $dn): string
  {
    $email = $this->mailUtils->resolveEmailFromDn($this->gateway, $dn, 'mail');
    return empty($email) ? "FALSE" : $email;
  }

  /**
   * @param string $dn
   * @param array $monitoredResources
   * @param int $days
   * @return bool
   *  Note : Verify the account status of the DN with the requirements of main tasks.
   */
  private function supannAboutToExpire (string $dn, array $monitoredResources, int $days): bool
  {
    $result = FALSE;

    // Search the DN for supannRessourceEtatDate (With DATE)
    $supannResources = $this->retrieveSupannResources($dn);
    // Get the matching resource (without date)
    $matchedResource = $this->verifySupannState($monitoredResources, $supannResources);

    if ($matchedResource) {
      // verify
      $DnSupannDateObject = $this->retrieveDateFromSupannResourceState($supannResources['supannressourceetatdate'], $matchedResource);
      //Verification if the time is lower or equal than the reminder time.
      if ($DnSupannDateObject !== FALSE) {
        $today    = new DateTime();
        $interval = $today->diff($DnSupannDateObject);

        // Interval can be negative if date is in the past - we make sure it is not in the past by using invert.
        if ($interval->invert == 0) {
          if (($interval->days < $days) || (($interval->days == $days) && ($interval->h == 0))) {
            $result = TRUE;
          }
        }
      }
    }

    return $result;
  }

  /**
   * @param string $dn
   * @return array
   * Note : Simply return supann resource array from the specific passed DN.
   */
  private function retrieveSupannResources (string $dn): array
  {
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
      // Posix attributes
      $monitoredResourcesArray['fdTasksReminderPosix']   = $remindersMainTask['fdtasksreminderposix'] ?? FALSE;

    }

    // For development logic, add the prolongation attribute. It will be checked later in the logic process.
    $monitoredResourcesArray['prolongation'] = $remindersMainTask['fdtasksreminderaccountprolongation'][0] ?? FALSE;

    return $monitoredResourcesArray;
  }


  /**
   * @param array $reminderSupann
   * @param array $dnSupann
   * @return string
   * Note : Create the supann format and check for a match.
   */
  private function verifySupannState (array $reminderSupann, array $dnSupann): string
  {
    // Result will contain the supann resource matching.
    $result               = '';
    $monitoredSupannState = '{' . $reminderSupann['resource'][0] . '}' . $reminderSupann['state'][0];
    //Construct the reminder Supann Resource State as string
    if (!empty($reminderSupann['subState'][0])) {
       $monitoredSupannState = $monitoredSupannState. ':' . $reminderSupann['subState'][0];
    }

    if (!empty($dnSupann['supannressourceetat'])) {
      // Simply iterate within the resource available till a match is found.
      foreach ($dnSupann['supannressourceetat'] as $resource) {
        if ($monitoredSupannState === $resource) {
          $result = $resource;
          break;
        }
      }
    }

    return $result;
  }

  /**
   * @param array $supannEtatDate
   * @param string $resource
   * @return DateTime|false
   * Note : Simply transform string date of supann to a dateTime object.
   * Can return bool (false) or dateTime object.
   */
  private function retrieveDateFromSupannResourceState (array $supannEtatDate, string $resource)
  {
    $dateString = NULL;
    $matchFound = NULL;  // Variable to store the match if found

    // Create a regex pattern to match the exact resource at the beginning, followed by ":" or ":::".
    $pattern = '/^' . preg_quote($resource, '/') . '(:|:::)?.*/';

    foreach ($supannEtatDate as $resourceWithDate) {
      if (preg_match($pattern, $resourceWithDate)) {
        $matchFound = $resourceWithDate;
        break; // Stop once a match is found
      }
    }

    // Simply take the last 8 digit
    preg_match('/(\d{8})$/', $matchFound, $matches);

    if (!empty($matches)) {
      $dateString = $matches[0];
    }

    return DateTime::createFromFormat('Ymd', $dateString);
  }

  /**
   * @param string $mainTaskDn
   * @return array
   */
  public function getRemindersMainTask (string $mainTaskDn): array
  {
    // Retrieve data from the main Reminder task
    return $this->gateway->getLdapTasks('(objectClass=fdTasksReminder)', ['fdTasksReminderRecipientsMembers',
      'fdTasksReminderResource', 'fdTasksReminderState', 'fdTasksReminderPosix', 'fdTasksReminderMailTemplate',
      'fdTasksReminderSupannNewEndDate', 'fdTasksReminderEmailSender', 'fdTasksReminderAccountProlongation',
      'fdTasksReminderMembers', 'fdTasksReminderNextResource',
      'fdTasksReminderNextState', 'fdTasksReminderNextSubState', 'fdTasksReminderSubState', 'fdTasksReminderFirstCall', 'fdTasksReminderSecondCall',
      'fdTasksRepeatableSchedule', 'fdTasksRepeatable'], '', $mainTaskDn);
  }

  /**
   * @param array $mainTask main task array
   * @param string $remindedEmail email of the monitored member
   * @return array mailform data
   * Note : Simply generate the email to be sent as reminder.
   * Note 2 : The boolean is created to generate the token and is only sent to reminded. Not recipients.
   */
  private function generateMainTaskMailTemplate (array $mainTask, string $remindedEmail): array
  {
    // Generate email configuration for each result of subtasks having the same main task.
    $sender           = $mainTask[0]['fdtasksreminderemailsender'][0];
    $mailTemplateName = $mainTask[0]['fdtasksremindermailtemplate'][0];

    $mailInfos   = $this->gateway->getLdapTasks("(|(objectClass=fdMailTemplate)(objectClass=fdMailAttachments))", [], $mailTemplateName);

    // Remove count from array.
    $this->gateway->unsetCountKeys($mailInfos);

    $mailContent = $mailInfos[0];

    // If no forward-to mail recipients is set, simply send the reminder to the monitored members.
    if (!empty($mainTask[0]["fdtasksreminderrecipientsmembers"])) {
      $recipientsDNs = $mainTask[0]["fdtasksreminderrecipientsmembers"];
      $this->gateway->unsetCountKeys($recipientsDNs);

      $mailType = $mainTask[0]["fdtasksemailattribute"][0] ?? "mail";
      $recipientsEmails = [];
      foreach ($recipientsDNs as $recipientsDN) {
        $recipientsEmails[] = $this->mailUtils->resolveEmailFromDn($this->gateway, $recipientsDN, $mailType);
      }

      // Merge recipientsEmails and remindedEmail
      $recipients = array_merge($recipientsEmails, [$remindedEmail]);

      // There is no reason to send an email twice to the same person. Render the array unique.
      $recipients = array_unique($recipients);
    } else {
      $recipients = $remindedEmail;
    }

    // Render the array unique.

    // Set the reminder array with all required variable for all sub-tasks of same main task origin.
    $mailMacros             = isset($mailContent["fdmailtemplatemacro"]) ? $mailContent["fdmailtemplatemacro"] : [];
    $mailForm['setFrom']    = $sender;
    $mailForm['recipients'] = $recipients;
    $mailForm['body']       = $this->mailUtils->replaceMacros($this->gateway, $recipients, $mailContent["fdmailtemplatebody"][0], $mailMacros);
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

    // Each reminders (main tasks)
    foreach ($reminders as $mainTaskDn => $reminder) {
      // Get the repeatable schedule for this main task
      $repeatableSchedule = $reminder['repeatableSchedule'] ?? NULL;

      // Each subTask reminder
      foreach ($reminder['subTask'] as $subTaskCn => $mailDetails) {

          // It is not impossible that only one recipient exist, therefore it won't be an array.
        if (!is_array($mailDetails['mail']['recipients'])) {
          // Simply transform the string into an array
          $mailDetails['mail']['recipients'] = [$mailDetails['mail']['recipients']];
        }
          $numberOfRecipients = count($mailDetails['mail']['recipients']);

          $mailSentResult = $this->mailUtils->sendMail(
              $mailDetails['mail']['setFrom'],
              NULL,
              $mailDetails['mail']['recipients'],
              $mailDetails['mail']['body'],
              $mailDetails['mail']['signature'],
              $mailDetails['mail']['subject'],
              $mailDetails['mail']['receipt'],
              NULL);

          // Create a simplified structure to pass to processMailResponseAndUpdateTasks
          $taskInfo = [
            'mainTaskDn' => $mainTaskDn,
            'repeatableSchedule' => $repeatableSchedule,
            'subTask' => [$subTaskCn => $mailDetails]
          ];

          // Here we incremented as well the counter of spam to the backend.
          $result[] = $this->processMailResponseAndUpdateTasks($mailSentResult, $taskInfo, $fdTasksConf);

          // Verification anti-spam max mails to be sent and quit loop if matched.
          $maxMailsIncrement += $numberOfRecipients;
          if ($maxMailsIncrement == $maxMailsConfig) {
            break;
          }
      }
    }


    return $result;
  }

  /**
   * @param array $serverResults
   * @param array $taskInfo
   * @param array $mailTaskBackend
   * @return array
   * Note : Process the mail response and update the task status with the main task DN and repeatable schedule
   */
  protected function processMailResponseAndUpdateTasks (array $serverResults, array $taskInfo, array $mailTaskBackend): array
  {
    $result = [];
    $mainTaskDn         = $taskInfo['mainTaskDn'];
    $repeatableSchedule = $taskInfo['repeatableSchedule'];

    // Re-validate repeatable flag on main task before using stored schedule
    if ($repeatableSchedule !== NULL) {
      $mainTaskConfig = $this->getRemindersMainTask($mainTaskDn);
      $repeatableFlag = $mainTaskConfig[0]['fdtasksrepeatable'][0] ?? NULL;
      if ($repeatableFlag === NULL || strcasecmp($repeatableFlag, 'TRUE') !== 0) {
        $repeatableSchedule = NULL; // Do not apply schedule if flag not TRUE anymore
      }
    }

    if ($serverResults[0] == "SUCCESS") {
      foreach ($taskInfo['subTask'] as $subTask => $details) {
        $cn = $subTask;
        $dn = $details['dn'];
        $result[$dn]['statusUpdate'] = $this->gateway->updateTaskStatus($dn, $cn, "2", $mainTaskDn, $repeatableSchedule);
        $result[$dn]['mailStatus']         = 'reminder was successfully sent';
        $result[$dn]['updateLastMailExec'] = $this->gateway->updateLastMailExecTime($mailTaskBackend[0]["dn"]);
        // Track task execution on the user
        $userDn = $details['uid'] ?? NULL;
        if ($userDn && $mainTaskDn) {
          $this->gateway->trackTaskExecutionOnUser($userDn, $mainTaskDn);
        }
      }
    } else {
      foreach ($taskInfo['subTask'] as $subTask => $details) {
        $cn = $subTask;
        $dn = $details['dn'];
        $result[$dn]['statusUpdate'] = $this->gateway->updateTaskStatus($dn, $cn, $serverResults[0], $mainTaskDn, $repeatableSchedule);
        $result[$dn]['mailStatus']   = $serverResults;
      }
    }
    return $result;
  }
}
