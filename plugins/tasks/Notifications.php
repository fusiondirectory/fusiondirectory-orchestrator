<?php

class Notifications implements EndpointInterface
{

  private TaskGateway $gateway;
  private CoreUtils $coreUtils;
  private MailUtils $mailUtils;

  public function __construct (TaskGateway $gateway)
  {
    $this->gateway = $gateway;
    $this->coreUtils = new CoreUtils();
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
    return $this->processNotifications($this->gateway->getObjectTypeTask('notifications'));
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
  public function processNotifications (array $notificationsSubTasks): array
  {
    $result = [];
    // It will contain all required notifications to be sent per main task.
    $notifications = [];

    foreach ($notificationsSubTasks as $task) {
      // If the tasks must be treated - status and scheduled - process the sub-tasks
      if ($this->gateway->statusAndScheduleCheck($task)) {

        // Retrieve data from the main task
        $mainTaskDn = $task['fdtasksgranularmaster'][0];

        $notificationsMainTask     = $this->getNotificationsMainTask($mainTaskDn);
        $notificationsMainTaskName = $mainTaskDn;

        // Gate repeatable schedule by flag
        $repeatableSchedule = NULL;
        $repeatableFlag     = $notificationsMainTask[0]['fdtasksrepeatable'][0] ?? NULL;

        if ($repeatableFlag !== NULL && strcasecmp($repeatableFlag, 'TRUE') === 0) {
          $repeatableSchedule = $notificationsMainTask[0]['fdtasksrepeatableschedule'][0] ?? NULL;
        }

        // Generate the mail form with all mail controller requirements
        $mailTemplateForm = $this->generateMainTaskMailTemplate($notificationsMainTask);

        // Simply retrieve the list of audited attributes
        $auditAttributes = $this->decodeAuditAttributes($task);

        // Recovering monitored attributes list from the defined notification task.
        $monitoredAttrs = $notificationsMainTask[0]['fdtasksnotificationsattributes'];
        // Reformat supann
        $monitoredSupannResource = $this->getSupannResourceState($notificationsMainTask[0]);

        // Simply remove keys with 'count' reported by ldap.
        $this->gateway->unsetCountKeys($monitoredAttrs);
        $this->gateway->unsetCountKeys($monitoredSupannResource);

        // Find matching attributes between audited and monitored attributes
        $matchingAttrs = $this->coreUtils->findMatchingKeys($auditAttributes, $monitoredAttrs);

        // Verify Supann resource state if applicable
        if ($this->shouldVerifySupannResource($monitoredSupannResource, $auditAttributes)) {
          // Adds it to the mating attrs for further notification process.
          $matchingAttrs[] = 'supannRessourceEtat';
        }

        if (!empty($matchingAttrs)) {
          // Fill an array with UID of audited user and related matching attributes
          $notifications[$notificationsMainTaskName]['subTask'][$task['cn'][0]]['attrs'] = $matchingAttrs;

          // Require to be set for updating the status of the task later on.
          $notifications[$notificationsMainTaskName]['subTask'][$task['cn'][0]]['dn']  = $task['dn'];
          $notifications[$notificationsMainTaskName]['subTask'][$task['cn'][0]]['uid'] = $task['fdtasksgranulardn'][0];

          // Persist main task DN for this notification batch (sustainable retrieval later)
          $notifications[$notificationsMainTaskName]['mainTaskDn'] = $mainTaskDn;

          // Persist repeatable schedule once at batch level if task is repeatable
          if ($repeatableSchedule !== NULL) {
            $notifications[$notificationsMainTaskName]['repeatableSchedule'] = $repeatableSchedule;
          }
          $notifications[$notificationsMainTaskName]['mailForm'] = $mailTemplateForm;

          // Add the POST attributs in the subtask to be able to use them later
          $notifications[$notificationsMainTaskName]['fdTasksNotificationsPostResource'] = $notificationsMainTask[0]['fdtasksnotificationspostresource'][0] ?? '';
          $notifications[$notificationsMainTaskName]['fdTasksNotificationsPostState']    = $notificationsMainTask[0]['fdtasksnotificationspoststate'][0] ?? '';
          $notifications[$notificationsMainTaskName]['fdTasksNotificationsPostSubState'] = $notificationsMainTask[0]['fdtasksnotificationspostsubstate'][0] ?? '';

          // Overwrite array notifications with complementing mail form body with uid and related attributes.
          $notifications = $this->completeNotificationsBody($notifications, $notificationsMainTaskName);

          // Change state and substate if fdTasksNotificationsPostResource and fdTasksNotificationsPostState is not = ''
          if ( ($notifications[$notificationsMainTaskName]['fdTasksNotificationsPostResource'] != '') && ($notifications[$notificationsMainTaskName]['fdTasksNotificationsPostState'] != '')) {
            // Login to webservice
            $webservice = new FusionDirectory\Rest\WebServiceCall($_ENV['FUSIONDIRECTORY_WEBSERVICE_URL'] . '/login', 'POST');

            // Required to prepare future webservice call. E.g. Retrieval of mandatory token.
            $webservice->setCurlSettings();

            // Get old supann status value for userdn
            $userdn          = $notifications[$notificationsMainTaskName]['subTask'][$task['cn'][0]]['uid'];
            $oldSupannStatus = $webservice->getUserTab($userdn, 'supannAccountStatus')['supannRessourceEtatDate'] ?? [];
            $newSupannStatus = [];

            // Change only the specific resource (or simple add the new one if there are none of them)
            if ($oldSupannStatus == []) {
              // Enable supannAccountStatus tab
              $newSupannStatus[] = '{' . $notifications[$notificationsMainTaskName]['fdTasksNotificationsPostResource'] . '}' . $notifications[$notificationsMainTaskName]['fdTasksNotificationsPostState'] . ':' . $notifications[$notificationsMainTaskName]['fdTasksNotificationsPostSubState'] . '::';
              $result[] = $webservice->setUser($userdn, [
                'supannAccount' => [],
                'supannAccountStatus' => [
                  'supannRessourceEtatDate' => $newSupannStatus
                ]
              ]);
            } else {
              $newSupannStatus = [];
              foreach ($oldSupannStatus as $supannStatus) {
                list($resourceState, $subState, $dateStart, $dateEnd) = explode(':', $supannStatus);

                // If ressource match replace only resource, state and substate part
                if (explode('}', $resourceState)[0] == '{' . $notifications[$notificationsMainTaskName]['fdTasksNotificationsPostResource']) {
                  $newSupannStatus[] = '{' . $notifications[$notificationsMainTaskName]['fdTasksNotificationsPostResource'] . '}' . $notifications[$notificationsMainTaskName]['fdTasksNotificationsPostState'] . ':' . $notifications[$notificationsMainTaskName]['fdTasksNotificationsPostSubState'] . ':' . $dateStart . ':' . $dateEnd;
                } else {
                  $newSupannStatus[] = $supannStatus;
                }

                // Update supannStatus
                $result[] = $webservice->setUserTabAttribute($userdn, 'supannAccountStatus', 'supannRessourceEtatDate', $newSupannStatus);
              }
            }
          }
        } else { // Simply update the sub-task with status 3 (nothing to be processed).
          $result[$task['dn']]['Status'] = $this->gateway->updateTaskStatus(
            $task['dn'],
            $task['cn'][0],
            '3',
            $mainTaskDn,
            $repeatableSchedule
          );
          $result[$task['dn']]['Message'] = 'No matching audited attributes with monitored attributes, nothing to process!';
        }
      }
    }

    if (!empty($notifications)) {
      $result[] = $this->sendNotificationsMail($notifications);
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
   * @param array $notificationsMainTask
   * @return array
   */
  private function getSupannResourceState (array $notificationsMainTask): array
  {
    return [
      'resource' => $notificationsMainTask['fdtasksnotificationsresource'],
      'state'    => $notificationsMainTask['fdtasksnotificationsstate'],
      'subState' => $notificationsMainTask['fdtasksnotificationssubstate'] ?? NULL
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
   * @param array $supannResource
   * @param array $auditedAttrs
   * @return bool
   * Note : Create the supann format and check for a match.
   */
  private function verifySupannState (array $supannResource, array $auditedAttrs): bool
  {
    $monitoredSupannState = '{' . $supannResource['resource'][0] . '}' . $supannResource['state'][0];

    //Construct Supann Resource State as string
    if (!empty($supannResource['subState'][0])) {
      $monitoredSupannState = $monitoredSupannState . ':' . $supannResource['subState'][0];
    }

    // Get all the values only of a multidimensional array.
    $auditedValues = $this->coreUtils->getArrayValuesRecursive($auditedAttrs);

    return in_array($monitoredSupannState, $auditedValues);
  }

  /**
   * @param string $mainTaskDn
   * @return array
   */
  public function getNotificationsMainTask (string $mainTaskDn): array
  {
    // Retrieve data from the main task
    return $this->gateway->getLdapTasks('(objectClass=fdTasksNotifications)', ['fdTasksNotificationsListOfRecipientsMails',
      'fdTasksNotificationsAttributes', 'fdTasksNotificationsMailTemplate', 'fdTasksNotificationsEmailSender',
      'fdTasksNotificationsSubState', 'fdTasksNotificationsState', 'fdTasksNotificationsResource',
      'fdTasksRepeatableSchedule', 'fdTasksRepeatable', 'fdTasksNotificationsPostResource',
      'fdTasksNotificationsPostState', 'fdTasksNotificationsPostSubState'], '', $mainTaskDn);
  }

  /**
   * @param array $mainTask
   * @return array
   * Note : Simply generate the email to be sent as notification.
   */
  private function generateMainTaskMailTemplate (array $mainTask): array
  {
    // Generate email configuration for each result of subtasks having the same main task.w
    $recipients = $mainTask[0]["fdtasksnotificationslistofrecipientsmails"];
    $this->gateway->unsetCountKeys($recipients);
    $sender           = $mainTask[0]["fdtasksnotificationsemailsender"][0];
    $mailTemplateName = $mainTask[0]['fdtasksnotificationsmailtemplate'][0];

    $mailInfos   = $this->gateway->getLdapTasks("(|(objectClass=fdMailTemplate)(objectClass=fdMailAttachments))", [], $mailTemplateName);
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
   * @param array $notificationTask
   * @return array
   * NOTE : receive a unique tasks of type notification (one subtask at a time)
   */
  protected function retrieveAuditedAttributes (array $notificationTask): array
  {
    $auditAttributes  = [];
    $auditInformation = [];

    // Retrieve audit data attributes from the list of references set in the sub-task
    if (!empty($notificationTask['fdtasksgranularref'])) {
      // Remove count keys (count is shared by ldap).
      $this->gateway->unsetCountKeys($notificationTask);

      foreach ($notificationTask['fdtasksgranularref'] as $auditDN) {
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
   * @param array $notifications
   * @param string $notificationsMainTaskName
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

  /**
   * @param array $notifications
   * @return array
   * Note : Collect information and send notification email.
   */
  protected function sendNotificationsMail (array $notifications): array
  {
    $result = [];
    // Re-use of the same mail processing template logic
    $fdTasksConf    = $this->mailUtils->getMailObjectConfiguration($this->gateway);
    $maxMailsConfig = $this->mailUtils->returnMaximumMailToBeSend($fdTasksConf);

    /*
      Increment var starts a zero and added values will be the number or recipients per main tasks, as one mail is
      sent per main task.
    */
    $maxMailsIncrement = 0;

    foreach ($notifications as $data) {
      $numberOfRecipients = count($data['mailForm']['recipients']);

      $mailSentResult = $this->mailUtils->sendMail($data['mailForm']['setFrom'],
          NULL,
          $data['mailForm']['recipients'],
          $data['mailForm']['body'],
          $data['mailForm']['signature'],
          $data['mailForm']['subject'],
          $data['mailForm']['receipt'],
          NULL);
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

    // Direct retrieval of main task DN & stored repeatable schedule from aggregation phase
    $mainTaskDn         = $subTask['mainTaskDn'] ?? NULL;
    $repeatableSchedule = $subTask['repeatableSchedule'] ?? NULL;

    // Removed runtime revalidation of repeatable flag/schedule for performance & simplicity per request

    if ($serverResults[0] == "SUCCESS") {
      foreach ($subTask['subTask'] as $subTaskCn => $details) {
        $cn = $subTaskCn;
        $dn = $details['dn'];
        $update = $this->updateResult($dn, $cn, "2", $mainTaskDn, $repeatableSchedule, 'Notification was successfully sent');
        $result = array_merge($result, $update);
        $result[$dn]['updateLastMailExec'] = $this->gateway->updateLastMailExecTime($mailTaskBackend[0]["dn"]);
      }
    } else {
      foreach ($subTask['subTask'] as $subTaskCn => $details) {
        $cn = $subTaskCn;
        $dn = $details['dn'];
        $update = $this->updateResult($dn, $cn, $serverResults[0], $mainTaskDn, $repeatableSchedule, $serverResults);
        $result = array_merge($result, $update);
      }
    }

    return $result;
  }

  private function updateResult ($dn, $cn, $status, $mainTaskDn, $repeatableSchedule, $message)
  {
    if ($mainTaskDn !== NULL) {
      if ($repeatableSchedule !== NULL) {
        $result[$dn]['statusUpdate'] = $this->gateway->updateTaskStatus($dn, $cn, $status, $mainTaskDn, $repeatableSchedule);
      } else {
        $result[$dn]['statusUpdate'] = $this->gateway->updateTaskStatus($dn, $cn, $status, $mainTaskDn);
      }
    } else {
      $result[$dn]['statusUpdate'] = $this->gateway->updateTaskStatus($dn, $cn, $status);
    }
      $result[$dn]['mailStatus'] = $message;
      return $result;
  }
}
