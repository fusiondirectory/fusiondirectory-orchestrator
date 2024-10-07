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

 //[fdtasksgranularmaster] => Array
    //                (
    //                    [0] => cn=Reminder,ou=tasks,dc=example,dc=com
    //                )
    //
    //            [3] => fdtasksgranularmaster
    //            [fdtasksgranulartype] => Array
    //                (
    //                    [0] => Reminder
    //                )
    //
    //            [4] => fdtasksgranulartype
    //            [fdtasksgranularhelper] => Array
    //                (
    //                    [0] => 30
    //                )
    //
    //            [5] => fdtasksgranularhelper
    //            [fdtasksgranularschedule] => Array
    //                (
    //                    [0] => 20240926010000
    //                )
    //
    //            [6] => fdtasksgranularschedule
    //            [fdtasksgranulardn] => Array
    //                (
    //                    [0] => uid=testing2,ou=people,dc=example,dc=com
    //                )
    //
    //            [7] => fdtasksgranulardn
    //            [dn] => cn=Reminder-SubTask-1728293363_4827,ou=tasks,dc=example,dc=com

    foreach ($reminderSubTasks as $task) {
      // If the tasks must be treated - status and scheduled - process the sub-tasks
      if ($this->gateway->statusAndScheduleCheck($task)) {

        // Retrieve data from the main task
        $remindersMainTaskName = $task['fdtasksgranularmaster'][0];
        $remindersMainTask     = $this->getRemindersMainTask($remindersMainTaskName);


        // Generate the mail form with all mail controller requirements
        $mailTemplateForm = $this->generateMainTaskMailTemplate($remindersMainTask);

        $monitoredSupannResource = $this->getSupannResourceState($remindersMainTask[0]);

        print_r($monitoredSupannResource);
        exit;
        // Find matching attributes between audited and monitored attributes
        $matchingAttrs = $this->findMatchingAttributes($auditAttributes, $monitoredAttrs);

        // Verify Supann resource state if applicable
        if ($this->shouldVerifySupannResource($monitoredSupannResource, $auditAttributes)) {
          // Adds it to the mating attrs for further reminder process.
          $matchingAttrs[] = 'supannRessourceEtat';
        }

        if (!empty($matchingAttrs)) {
          // Fill an array with UID of audited user and related matching attributes
          $reminders[$remindersMainTaskName]['subTask'][$task['cn'][0]]['attrs'] = $matchingAttrs;

          // Require to be set for updating the status of the task later on.
          $reminders[$remindersMainTaskName]['subTask'][$task['cn'][0]]['dn']  = $task['dn'];
          $reminders[$remindersMainTaskName]['subTask'][$task['cn'][0]]['uid'] = $task['fdtasksgranulardn'][0];
          $reminders[$remindersMainTaskName]['mailForm']                       = $mailTemplateForm;
          // Overwrite array reminders with complementing mail form body with uid and related attributes.
          $reminders = $this->completeremindersBody($reminders, $remindersMainTaskName);

        } else { // Simply remove the subTask has no reminders are required
          $result[$task['dn']]['Removed'] = $this->gateway->removeSubTask($task['dn']);
          $result[$task['dn']]['Status']  = 'No matching audited attributes with monitored attributes, safely removed!';
        }
      }
    }

    if (!empty($reminders)) {
      $result[] = $this->sendremindersMail($reminders);
    }

    return $result;
  }

  /**
   * Determine if Supann resource verification is needed.
   *
   * @param array $monitoredSupannResource
   * @param array|null $auditAttributes
   * @return bool
   */
  private function shouldVerifySupannResource (array $monitoredSupannResource, ?array $auditAttributes): bool
  {
    if (!empty($auditAttributes)) {
      return $monitoredSupannResource['resource'][0] !== 'NONE' &&
        $this->verifySupannState($monitoredSupannResource, $auditAttributes);
    }
    return FALSE;
  }

  /**
   * Get the Supann resource state.
   *
   * @param array $remindersMainTask
   * @return array
   */
  private function getSupannResourceState (array $remindersMainTask): array
  {
    return [
      'resource' => $remindersMainTask['fdtasksreminderresource'],
      'state'    => $remindersMainTask['fdtasksreminderstate'],
      'subState' => $remindersMainTask['fdtasksremindersubstate'] ?? NULL
    ];
  }

  /**
   * Decode audit attributes from the task.
   *
   * @param array $task
   * @return array
   */
  private function decodeAuditAttributes (array $task): array
  {
    $auditAttributesJson = $this->retrieveAuditedAttributes($task);
    $auditAttributes     = [];

    // Decoding the json_format into an associative array, implode allows to put all values of array together.(forming the json correctly).
    foreach ($auditAttributesJson as $auditAttribute) {
      $auditAttributes[] = json_decode(implode($auditAttribute), TRUE);
    }

    return $auditAttributes;
  }

  /**
   * Find matching attributes between audit and monitored attributes.
   *
   * @param array|null $auditAttributes
   * @param array $monitoredAttrs
   * @return array
   */
  private function findMatchingAttributes (?array $auditAttributes, array $monitoredAttrs): array
  {
    $matchingAttrs = [];

    if (!empty($auditAttributes)) {
      foreach ($auditAttributes as $attributeName) {
        foreach ($monitoredAttrs as $monitoredAttr) {
          if (!empty($attributeName) && array_key_exists($monitoredAttr, $attributeName)) {
            $matchingAttrs[] = $monitoredAttr;
          }
        }
      }
    }

    return $matchingAttrs;
  }

  /**
   * @param array $supannResource
   * @param array $auditedAttrs
   * @return bool
   * Note : Create the supann format and check for a match.
   */
  private function verifySupannState (array $supannResource, array $auditedAttrs): bool
  {
    $result = FALSE;

    //Construct Supann Resource State as string
    if (!empty($supannResource['subState'][0])) {
      $monitoredSupannState = '{' . $supannResource['resource'][0] . '}' . $supannResource['state'][0] . ':' . $supannResource['subState'][0];
    } else {
      $monitoredSupannState = '{' . $supannResource['resource'][0] . '}' . $supannResource['state'][0];
    }

    // Get all the values only of a multidimensional array.
    $auditedValues = $this->getArrayValuesRecursive($auditedAttrs);

    if (in_array($monitoredSupannState, $auditedValues)) {
      $result = TRUE;
    } else {
      $result = FALSE;
    }

    return $result;
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
    return $this->gateway->getLdapTasks('(objectClass=fdTasksReminder)', ['fdTasksReminderListOfRecipientsMails',
      'fdTasksReminderResource', 'fdTasksReminderState', 'fdTasksReminderPosix', 'fdTasksReminderMailTemplate',
      'fdTasksReminderPPolicy', 'fdTasksReminderSupannNewEndDate', 'fdTasksReminderRecipientsMembers', 'fdTasksReminderEmailSender',
      'fdTasksReminderManager', 'fdTasksReminderAccountProlongation', 'fdTasksReminderMembers'], '', $mainTaskDn);
  }

  /**
   * @param array $mainTask
   * @return array
   * Note : Simply generate the email to be sent as reminder.
   */
  private function generateMainTaskMailTemplate (array $mainTask): array
  {
    // Generate email configuration for each result of subtasks having the same main task.
    $recipients = $mainTask[0]["fdtasksreminderlistofrecipientsmails"];
    $this->gateway->unsetCountKeys($recipients);
    $sender           = $mainTask[0]['fdtasksreminderemailsender'][0];
    $mailTemplateName = $mainTask[0]['fdtasksremindermailtemplate'][0];

    $mailInfos   = $this->gateway->getLdapTasks("(|(objectClass=fdMailTemplate)(objectClass=fdMailAttachments))", [], $mailTemplateName);
    $mailContent = $mailInfos[0];

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
   * @param array $reminderTask
   * @return array
   * NOTE : receive a unique tasks of type reminder (one subtask at a time)
   */
  protected function retrieveAuditedAttributes (array $reminderTask): array
  {
    $auditAttributes  = [];
    $auditInformation = [];

    // Retrieve audit data attributes from the list of references set in the sub-task
    if (!empty($reminderTask['fdtasksgranularref'])) {
      // Remove count keys (count is shared by ldap).
      $this->gateway->unsetCountKeys($reminderTask);

      foreach ($reminderTask['fdtasksgranularref'] as $auditDN) {
        $auditInformation[] = $this->gateway->getLdapTasks('(&(objectClass=fdAuditEvent))',
                                                           ['fdAuditAttributes'], '', $auditDN);
      }

      // Again remove key: count retrieved from LDAP.
      $this->gateway->unsetCountKeys($auditInformation);
      // It is possible that an audit does not contain any attributes changes, condition is required.
      foreach ($auditInformation as $attr) {
        if (!empty($attr[0]['fdauditattributes'])) {
          // Clear and compact received results from above ldap search
          $auditAttributes[] = $attr[0]['fdauditattributes'];
        }
      }
    }

    return $auditAttributes;
  }

  /**
   * @param array $reminders
   * @param string $remindersMainTaskName
   * @return array
   * Note : This method is present to add to the mailForm body the proper uid and attrs info.
   */
  private function completeremindersBody (array $reminders, string $remindersMainTaskName): array
  {
    // Iterate through each subTask and related attrs
    $uidAttrsText = [];

    foreach ($reminders[$remindersMainTaskName]['subTask'] as $value) {
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
    $reminders[$remindersMainTaskName]['mailForm']['body'] .= " " . implode(" ", $uidAttrsText);

    return $reminders;
  }

  /**
   * @param array $reminders
   * @return array
   * Note : Collect information and send reminder email.
   */
  protected function sendremindersMail (array $reminders): array
  {
    $result = [];
    // Re-use of the same mail processing template logic
    $fdTasksConf    = $this->gateway->getLdapTasks(
      "(objectClass=fdTasksConf)",
      ["fdTasksConfLastExecTime", "fdTasksConfIntervalEmails", "fdTasksConfMaxEmails"]
    );
    $maxMailsConfig = $fdTasksConf[0]["fdtasksconfmaxemails"][0] ?? 50;

    /*
      Increment var starts a zero and added values will be the humber or recipients per main tasks, as one mail is
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