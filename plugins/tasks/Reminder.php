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
        $remindersMainTaskName = $task['fdtasksgranularmaster'][0]; //dn
        $remindersMainTask     = $this->getRemindersMainTask($remindersMainTaskName);
        // remove the count keys
        $this->gateway->unsetCountKeys($remindersMainTask);

        // Retrieve email attribute for the monitored members requiring reminding.
        $mailOfTheReminded = $this->getEmailFromReminded($task['fdtasksgranulardn'][0]);

        // Generate the mail form with all mail controller requirements
        $mailTemplateForm = $this->generateMainTaskMailTemplate($remindersMainTask, $mailOfTheReminded);

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
            // Recipient email form
            $reminders[$remindersMainTaskName]['subTask'][$task['cn'][0]]['mail'] = $mailTemplateForm;

          } else {
            // Not about to expire, delete subTask
            $result[$task['dn']]['Removed'] = $this->gateway->removeSubTask($task['dn']);
            $result[$task['dn']]['Status']  = 'No reminder triggers were found, therefore removing the sub-task!';
          }
        }

        // Case where supann is set and prolongation is desired.
        if ($monitoredResources['resource'][0] !== 'NONE' && $monitoredResources['prolongation'] === 'TRUE') {
          if ($this->supannAboutToExpire($task['fdtasksgranulardn'][0], $monitoredResources, $task['fdtasksgranularhelper'][0])) {

            // Require to be set for updating the status of the task later on and sent the email.
            $reminders[$remindersMainTaskName]['subTask'][$task['cn'][0]]['dn']  = $task['dn'];
            $reminders[$remindersMainTaskName]['subTask'][$task['cn'][0]]['uid'] = $task['fdtasksgranulardn'][0];

            // Create timeStamp expiration for token
            $tokenExpire = $this->getTokenExpiration($task['fdtasksgranularhelper'][0],
                                                     $remindersMainTask[0]['fdtasksreminderfirstcall'][0],
                                                     $remindersMainTask[0]['fdtasksremindersecondcall'][0]);
            // Create token for SubTask
            $token = $this->generateToken($task['fdtasksgranulardn'][0], $tokenExpire);
            // Edit the mailForm with the url link containing the token
            $tokenMailTemplateForm = $this->generateTokenUrl($token, $mailTemplateForm, $remindersMainTaskName);
            // Recipient email form
            $reminders[$remindersMainTaskName]['subTask'][$task['cn'][0]]['mail'] = $tokenMailTemplateForm;


          } else {
            // Not about to expire, delete subTask
            $result[$task['dn']]['Removed'] = $this->gateway->removeSubTask($task['dn']);
            $result[$task['dn']]['Status']  = 'No reminder triggers were found, therefore removing the sub-task!';
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
   * @param string $token
   * @param array $mailTemplateForm
   * @param string $taskName
   * @return array
   */
  private function generateTokenUrl (string $token, array $mailTemplateForm, string $taskDN): array
  {
    //Only take the cn of the main task name :
    preg_match('/cn=([^,]+),ou=/', $taskDN, $matches);
    $taskName = $matches[1];

    // Remove the API URI
    $cleanedUrl = preg_replace('#/rest\.php/v1$#', '', $_ENV['FUSION_DIRECTORY_API_URL']);
    $url        = $cleanedUrl . '/accountProlongation.php?token=' . $token . '&task=' . $taskName;

    $mailTemplateForm['body'] .= $url;

    return $mailTemplateForm;
  }

  /**
   * @param int $subTaskCall
   * @param int $firstCall
   * @param int $secondCall
   * @return int
   * Note : Simply return the difference between first and second call. (First call can be null).
   */
  private function getTokenExpiration (int $subTaskCall, int $firstCall, int $secondCall): int
  {
    // if firstCall is empty, secondCall is the timestamp expiry for the token.
    $result = $secondCall;

    if (!empty($firstCall)) {
      // Verification if the subTask is the second reminder or the first reminder.
      if ($subTaskCall === $firstCall) {
        $result = $firstCall - $secondCall;
      }
    }

    return $result;
  }

  /**
   * @param string $userDN
   * @param int $timeStamp
   * @return string
   * @throws Exception
   */
  private function generateToken (string $userDN, int $timeStamp): string
  {
    $token = NULL;
    // Salt has been generated with APG.
    $salt    = '8onOlEsItKond';
    $payload = json_encode($userDN . $salt);
    // This allows the token to be different every time.
    $time = time();

    // Create hmac with sha256 alg and the key provided for JWT token signature in ENV.
    $token_hmac = hash_hmac("sha256", $time . $payload, $_ENV["SECRET_KEY"], TRUE);

    // We need to have a token allowed to be used within an URL.
    $token = $this->base64urlEncode($token_hmac);

    // Save token within LDAP
    $this->saveTokenInLdap($userDN, $token, $timeStamp);

    return $token;
  }

  /**
   * @param string $userDN
   * @param string $token
   * NOTE : UID is the full DN of the user. (uid=...).
   * @param int $timeStamp
   * @return bool
   * @throws Exception
   */
  private function saveTokenInLdap (string $userDN, string $token, int $timeStamp): bool
  {
    $result = FALSE;

    preg_match('/uid=([^,]+),ou=/', $userDN, $matches);
    $uid = $matches[1];
    $dn  = 'cn=' . $uid . ',' . 'ou=tokens' . ',' . $_ENV["LDAP_BASE"];

    $ldap_entry["objectClass"]      = ['top', 'fdTokenEntry'];
    $ldap_entry["fdTokenUserDN"]    = $userDN;
    $ldap_entry["fdTokenType"]      = 'reminder';
    $ldap_entry["fdToken"]          = $token;
    $ldap_entry["fdTokenTimestamp"] = $timeStamp;
    $ldap_entry["cn"]               = $uid;

    // set the dn for the token, only take what's between "uid=" and ",ou="


    // Verify if token ou branch exists
    if (!$this->tokenBranchExist('ou=tokens' . ',' . $_ENV["LDAP_BASE"])) {
      // Create the branch
      $this->createBranchToken();
    }

    // The user token DN creation
    $userTokenDN = 'cn=' . $uid . ',ou=tokens' . ',' . $_ENV["LDAP_BASE"];
    // Verify if a token already exists for specified user and remove it to create new one correctly.
    if ($this->tokenBranchExist($userTokenDN)) {
      // Remove the user token
      $this->removeUserToken($userTokenDN);
    }

    // Add token to LDAP for specific UID
    try {
      $result = ldap_add($this->gateway->ds, $dn, $ldap_entry); // bool returned
    } catch (Exception $e) {
      echo json_encode(["Ldap Error - Token could not be saved!" => "$e"]); // string returned
      exit;
    }

    return $result;
  }

  /**
   * @param $userTokenDN
   * @return void
   * Note : Simply remove the token for specific user DN
   */
  private function removeUserToken ($userTokenDN): void
  {
    // Add token to LDAP for specific UID
    try {
      $result = ldap_delete($this->gateway->ds, $userTokenDN); // bool returned
    } catch (Exception $e) {
      echo json_encode(["Ldap Error - User token could not be removed!" => "$e"]); // string returned
      exit;
    }
  }

  /**
   * Create ou=pluginManager LDAP branch
   * @throws Exception
   */
  protected function createBranchToken (): void
  {
    try {
      ldap_add(
        $this->gateway->ds, 'ou=tokens' . ',' . $_ENV["LDAP_BASE"],
        [
          'ou'          => 'tokens',
          'objectClass' => 'organizationalUnit',
        ]
      );
    } catch (Exception $e) {

      echo json_encode(["Ldap Error - Impossible to create the token branch" => "$e"]); // string returned
      exit;
    }
  }


  /**
   * @param string $dn
   * @return bool
   * Note : Simply inspect if the branch for token is existing.
   */
  private function tokenBranchExist (string $dn): bool
  {
    $result = FALSE;

    try {
      $search = ldap_search($this->gateway->ds, $dn, "(objectClass=*)");
      // Check if the search was successful
      if ($search) {
        // Get the number of entries found
        $entries = ldap_get_entries($this->gateway->ds, $search);

        // If entries are found, set result to true
        if ($entries["count"] > 0) {
          $result = TRUE;
        }
      }
    } catch (Exception $e) {
      $result = FALSE;
    }

    return $result;
  }

  /**
   * @param string $text
   * @return string
   * Note : This come from jwtToken, as it is completely private - it is cloned here for now.
   */
  private function base64urlEncode (string $text): string
  {
    return str_replace(["+", "/", "="], ["-", "_", ""], base64_encode($text));
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
    return $this->gateway->getLdapTasks(                                                                                                              '(objectClass=fdTasksReminder)', ['fdTasksReminderListOfRecipientsMails',
      'fdTasksReminderResource', 'fdTasksReminderState', 'fdTasksReminderPosix', 'fdTasksReminderMailTemplate',
      'fdTasksReminderPPolicy', 'fdTasksReminderSupannNewEndDate', 'fdTasksReminderRecipientsMembers', 'fdTasksReminderEmailSender',
      'fdTasksReminderManager', 'fdTasksReminderAccountProlongation', 'fdTasksReminderMembers', 'fdTasksReminderNextResource',
      'fdTasksReminderNextState', 'fdTasksReminderNextSubState', 'fdTasksReminderSubState', 'fdTasksReminderFirstCall', 'fdTasksReminderSecondCall'], '', $mainTaskDn);
  }

  /**
   * @param array $mainTask
   * @param string $remindedEmail
   * @return array
   * Note : Simply generate the email to be sent as reminder.
   * Note 2 : The boolean is created to generate the token and is only sent to reminded. Not recipients.
   */
  private function generateMainTaskMailTemplate (array $mainTask, string $remindedEmail): array
  {
    // Generate email configuration for each result of subtasks having the same main task.
    $sender           = $mainTask[0]['fdtasksreminderemailsender'][0];
    $mailTemplateName = $mainTask[0]['fdtasksremindermailtemplate'][0];

    $mailInfos   = $this->gateway->getLdapTasks("(|(objectClass=fdMailTemplate)(objectClass=fdMailAttachments))", [], $mailTemplateName);
    $mailContent = $mailInfos[0];

    // If no forward-to mail recipients is set, simply send the reminder to the monitored members.
    if (!empty($mainTask[0]["fdtasksreminderlistofrecipientsmails"])) {
      $recipients = array_merge($mainTask[0]["fdtasksreminderlistofrecipientsmails"], [$remindedEmail]);
      $this->gateway->unsetCountKeys($recipients);

      // There is no reason to send an email twice to the same person. Render the array unique.
      $recipients = array_unique($recipients);
    } else {
      $recipients = $remindedEmail;
    }

    // Render the array unique.

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

    // Each reminders
    foreach ($reminders as $reminder) {
      // Each main task reminder
      foreach ($reminder as $reminderItem) {
        // Each subTask reminder
        foreach ($reminderItem as $mailDetails) {
          $numberOfRecipients = count($mailDetails['mail']['recipients']);

          $mail_controller = new \FusionDirectory\Mail\MailLib(
            $mailDetails['mail']['setFrom'],
            NULL,
            $mailDetails['mail']['recipients'],
            $mailDetails['mail']['body'],
            $mailDetails['mail']['signature'],
            $mailDetails['mail']['subject'],
            $mailDetails['mail']['receipt'],
            NULL
          );

          $mailSentResult = $mail_controller->sendMail();
          // Here we incremented as well the counter of spam to the backend.
          $result[] = $this->processMailResponseAndUpdateTasks($mailSentResult, $reminder, $fdTasksConf);

          // Verification anti-spam max mails to be sent and quit loop if matched.
          $maxMailsIncrement += $numberOfRecipients;
          if ($maxMailsIncrement == $maxMailsConfig) {
            break;
          }
        }
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