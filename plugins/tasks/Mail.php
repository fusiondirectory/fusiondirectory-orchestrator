<?php


class Mail implements EndpointInterface
{
  private TaskGateway $gateway;
  private MailUtils $mailUtils;

  function __construct (TaskGateway $gateway)
  {
    $this->gateway = $gateway;
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
   * @return array
   * Note : Part of the interface of orchestrator plugin to treat POST method
   */
  public function processEndPointPost (?array $data = NULL): array
  {
    return [];
  }

  /**
   * @return array
   * Note : Part of the interface of orchestrator plugin to treat DELETE method
   */
  public function processEndPointDelete (?array $data = NULL): array
  {
    return [];
  }

  /**
   * @param array|NULL $data
   * @return array
   * @throws Exception
   * Note : Part of the interface of orchestrator plugin to treat PATCH method
   */
  public function processEndPointPatch (?array $data = NULL): array
  {
    return $this->processMailTasks($this->gateway->getObjectTypeTask('mail'));
  }

  /**
   * @param string $mainTaskDn
   * @return array
   * Note: Fetch main task configuration data including repeatableSchedule
   */
  private function getMailTaskMainTask (string $mainTaskDn): array
  {
    return $this->gateway->getLdapTasks(
      "(objectClass=fdTasks)",
      ["fdTasksRepeatableSchedule", "fdTasksRepeatable", "fdTasksEmailAttribute"],
      "",
      $mainTaskDn
    );
  }

  /**
   * @param array $tasks
   * @return array
   * @throws Exception
   */
  public function processMailTasks (array $tasks): array
  {
    $result = [];
    $fdTasksConf  = $this->getMailObjectConfiguration();
    $maxMailsConfig = $this->returnMaximumMailToBeSend($fdTasksConf);

    // Increment for anti=spam, starts at 0, each mail task only contain one email, addition if simply + one.
    $maxMailsIncrement = 0;

    if ($this->verifySpamProtection($fdTasksConf)) {
      // Note : if list_tasks is empty, the controller receive null as result and will log/process it properly.
      foreach ($tasks as $task) {
        // verify status before processing (to be checked with schedule as well).
        if ($this->gateway->statusAndScheduleCheck($task)) {

          // Get the main task DN
          $mainTaskDn = $task['fdtasksgranularmaster'][0];

          // Retrieve data from the main task including the repeatable schedule
          $mainTaskConfig = $this->getMailTaskMainTask($mainTaskDn);
          // Gate schedule usage by repeatable flag
          $repeatableSchedule = NULL;
          $repeatableFlag     = $mainTaskConfig[0]['fdtasksrepeatable'][0] ?? NULL;

          if ($repeatableFlag !== NULL && strcasecmp($repeatableFlag, 'TRUE') === 0) {
            $repeatableSchedule = $mainTaskConfig[0]['fdtasksrepeatableschedule'][0] ?? NULL;
          }

          // Search for the related attached mail object.
          $mailInfos   = $this->retrieveMailTemplateInfos($task["fdtasksgranularref"][0]);

          // Remove count from array.
          $this->gateway->unsetCountKeys($mailInfos);

          $mailContent = $mailInfos[0];

          // Only takes arrays related to files attachments for the mail template selected
          unset($mailInfos[0]);
          $mailAttachments = array_values($mailInfos);

          $mailMacros = isset($mailContent["fdmailtemplatemacro"]) ? $mailContent["fdmailtemplatemacro"] : [];

          // Get the mail from DN
          $recipientDN = $task["fdsubtaskmemberdn"][0];
          $mailType    = $mainTaskConfig[0]["fdtasksemailattribute"][0] ?? "mail";
          $email       = $this->mailUtils->resolveEmailFromDn($this->gateway, $recipientDN, $mailType);

          if (empty($email)) {
            $result[$task["dn"]] = [
              'statusUpdate' => $this->gateway->updateTaskStatus($task["dn"], $task["cn"][0], "ERROR"),
              'Error' => "Could not resolve email for DN: $recipientDN"
            ];
            continue;
          }

          $setFrom    = $task["fdtasksgranularmailfrom"][0];
          $setBCC     = $task["fdtasksgranularmailbcc"][0] ?? NULL;
          $recipients = [$email];
          $body       = $this->mailUtils->replaceMacros($this->gateway, $recipients, $mailContent["fdmailtemplatebody"][0], $mailMacros);
          $signature  = $mailContent["fdmailtemplatesignature"][0] ?? NULL;
          $subject    = $mailContent["fdmailtemplatesubject"][0];
          $receipt    = $mailContent["fdmailtemplatereadreceipt"][0];

          $attachments = [];
          foreach ($mailAttachments as $file) {
            $fileInfo['cn']      = $file['cn'][0];
            $fileInfo['content'] = $file['fdmailattachmentscontent'][0];
            $attachments[]       = $fileInfo;
          }

          $mailSentResult = $this->mailUtils->sendMail($setFrom, $setBCC, $recipients, $body, $signature, $subject, $receipt, $attachments);
          $result[$task["dn"]] = $this->updateResult($mailSentResult, $task, $fdTasksConf, $mainTaskDn, $repeatableSchedule);

          // Track task execution on the user
          if ($mailSentResult[0] == "SUCCESS" && $recipientDN) {
            $this->gateway->trackTaskExecutionOnUser($recipientDN, $mainTaskDn);
          }

          // Verification anti-spam max mails to be sent and quit loop if matched
          $maxMailsIncrement += 1; //Only one as recipients in mail object is always one email.
          if ($maxMailsIncrement == $maxMailsConfig) {
            break;
          }
        }
      }
    } else {
      $msg = "Anti-spam protection active, skipping mail tasks. Last exec=" . ($fdTasksConf[0]["fdtasksconflastexectime"][0] ?? "never") . ", interval=" . ($fdTasksConf[0]["fdtasksconfintervalemails"][0] ?? "?") . "min";
      error_log("Mail::processMailTasks: $msg");
      $result['antiSpam'] = $msg;
    }

    return $result;
  }

  private function updateResult (array $mailSentResult, $task, $fdTasksConf, $mainTaskDn = NULL, $repeatableSchedule = NULL): array
  {
    $result = [];
    if ($mailSentResult[0] == "SUCCESS") {
      $result['statusUpdate'] = $this->gateway->updateTaskStatus($task["dn"], $task["cn"][0], "2", $mainTaskDn, $repeatableSchedule);
      $result['mailStatus'] = 'mail : ' . $task["dn"] . ' was successfully sent';
      $result['updateLastMailExec'] = $this->gateway->updateLastMailExecTime($fdTasksConf[0]["dn"]);
    } else {
      $result['statusUpdate'] = $this->gateway->updateTaskStatus($task["dn"], $task["cn"][0], $mailSentResult[0], $mainTaskDn, $repeatableSchedule);
      $result['Error'] = $mailSentResult;
    }
    return $result;
  }

  /**
   * @return array
   * Note : A simple retrieval methods of the mail backend configuration set in FusionDirectory
   */
  private function getMailObjectConfiguration (): array
  {
    return $this->gateway->getLdapTasks(
      '(objectClass=fdTasksConf)',
      ['fdTasksConfLastExecTime', 'fdTasksConfIntervalEmails', 'fdTasksConfMaxEmails']
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

  /**
   * @param array $fdTasksConf
   * @return bool
   * Note : Method which verify the last executed e-mails sent
   *  Verify if the time interval is respected in order to protect from SPAM
   */
  public function verifySpamProtection (array $fdTasksConf): bool
  {
    $lastExec     = $fdTasksConf[0]["fdtasksconflastexectime"][0] ?? NULL;
    $spamInterval = $fdTasksConf[0]["fdtasksconfintervalemails"][0];

    // Return TRUE if $lastExec is NULL because it means it is the first time to run
    if ($lastExec == NULL) {
      return TRUE;
    }

    $currentDateTime = new DateTime('now', new DateTimeZone('UTC'));
    $lastExecDate    = \FusionDirectory\Ldap\GeneralizedTime::fromString($lastExec);
    $antispam        = $lastExecDate->modify("+ " . $spamInterval . " minutes");

    if ($antispam <= $currentDateTime) {
      return TRUE;
    }

    return FALSE;
  }

  /**
   * @param string $templateName
   * @return array
   * Note :simply retrieve all information linked to a mail template object.
   */
  public function retrieveMailTemplateInfos (string $templateName): array
  {
    return $this->gateway->getLdapTasks("(|(objectClass=fdMailTemplate)(objectClass=fdMailAttachments))", [], $templateName);
  }
}
