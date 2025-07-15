<?php


class Mail implements EndpointInterface
{
  private TaskGateway $gateway;

  function __construct (TaskGateway $gateway)
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
   * @return array
   * Note : Part of the interface of orchestrator plugin to treat POST method
   */
  public function processEndPointPost (array $data = NULL): array
  {
    return [];
  }

  /**
   * @return array
   * Note : Part of the interface of orchestrator plugin to treat DELETE method
   */
  public function processEndPointDelete (array $data = NULL): array
  {
    return [];
  }

  /**
   * @param array|NULL $data
   * @return array
   * @throws Exception
   * Note : Part of the interface of orchestrator plugin to treat PATCH method
   */
  public function processEndPointPatch (array $data = NULL): array
  {
    return $this->processMailTasks($this->gateway->getObjectTypeTask('Mail Object'));
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
      ["fdTasksRepeatableSchedule"],
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
    $fdTasksConf    = $this->getMailObjectConfiguration();
    $maxMailsConfig = $this->returnMaximumMailToBeSend($fdTasksConf);

    // Increment for anti=spam, starts at 0, each mail task only contain one email, addition if simply + one.
    $maxMailsIncrement = 0;

    if ($this->verifySpamProtection($fdTasksConf)) {
      // Note : if list_tasks is empty, the controller receive null as result and will log/process it properly.
      foreach ($tasks as $mail) {


        // verify status before processing (to be checked with schedule as well).
        if ($mail["fdtasksgranularstatus"][0] == 1 && $this->gateway->verifySchedule($mail["fdtasksgranularschedule"][0])) {

          // Get the main task DN
          $mainTaskDn = $mail['fdtasksgranularmaster'][0];

          // Retrieve data from the main task including the repeatable schedule
          $mainTaskConfig = $this->getMailTaskMainTask($mainTaskDn);
          $repeatableSchedule = $mainTaskConfig[0]['fdtasksrepeatableschedule'][0] ?? NULL;

          // Search for the related attached mail object.
          $mailInfos   = $this->retrieveMailTemplateInfos($mail["fdtasksgranularref"][0]);
          $mailContent = $mailInfos[0];

          // Only takes arrays related to files attachments for the mail template selected
          unset($mailInfos[0]);
          // Remove count from array.
          $this->gateway->unsetCountKeys($mailInfos);
          $mailAttachments = array_values($mailInfos);

          $setFrom    = $mail["fdtasksgranularmailfrom"][0];
          $setBCC     = $mail["fdtasksgranularmailbcc"][0] ?? NULL;
          $recipients = $mail["fdtasksgranularmail"];
          $body       = $mailContent["fdmailtemplatebody"][0];
          $signature  = $mailContent["fdmailtemplatesignature"][0] ?? NULL;
          $subject    = $mailContent["fdmailtemplatesubject"][0];
          $receipt    = $mailContent["fdmailtemplatereadreceipt"][0];

          // Process hardcoded macros %uid%, %sn% and %givenName%
          // TODO: grab them from LDAP later
          // Macro (without % before and after) => ldapAttribute
          $hardcodedMacros = [
            'givenName' => 'givenName',
            'sn'        => 'sn',
            'uid'       => 'uid'
          ];

          // Replace each $macro with the attribute in $body
          foreach ($recipients as $recipient) {
            foreach ($hardcodedMacros as $macro) {
              $filter        = "(&(objectClass=inetOrgPerson)(|(mail=$recipient)(gosaMailAlternateAddress=$recipient)(gosaMailForwardingAddress=$recipient)(supannAutreMail=$recipient)(supannMailPerso=$recipient)(supannMailPrive={*}$recipient)))";
              $ldapAttribute = $this->gateway->getLdapTasks("$filter", ["$macro"]);
              if (isset($ldapAttribute[0][strtolower($macro)][0])) {
                $body          = preg_replace('/%' . $macro . '%/', $ldapAttribute[0][strtolower($macro)][0], $body);
              }
            };
          };

          foreach ($mailAttachments as $file) {
            $fileInfo['cn']      = $file['cn'][0];
            $fileInfo['content'] = $file['fdmailattachmentscontent'][0];
            $attachments[]       = $fileInfo;
          }

          // Required before passing the array to the constructor mail.
          if (empty($attachments)) {
            $attachments = NULL;
          }

          $mail_controller = new \FusionDirectory\Mail\MailLib($setFrom,
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
            $result[$mail["dn"]]['statusUpdate']       = $this->gateway->updateTaskStatus($mail["dn"], $mail["cn"][0], "2", $mainTaskDn, $repeatableSchedule);
            $result[$mail["dn"]]['mailStatus']         = 'mail : ' . $mail["dn"] . ' was successfully sent';
            $result[$mail["dn"]]['updateLastMailExec'] = $this->gateway->updateLastMailExecTime($fdTasksConf[0]["dn"]);

          } else {
            $result[$mail["dn"]]['statusUpdate'] = $this->gateway->updateTaskStatus($mail["dn"], $mail["cn"][0], $mailSentResult[0], $mainTaskDn, $repeatableSchedule);
            $result[$mail["dn"]]['Error']        = $mailSentResult;
          }

          // Verification anti-spam max mails to be sent and quit loop if matched
          $maxMailsIncrement += 1; //Only one as recipients in mail object is always one email.
          if ($maxMailsIncrement == $maxMailsConfig) {
            break;
          }

        }
      }
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
      "(objectClass=fdTasksConf)",
      ["fdTasksConfLastExecTime", "fdTasksConfIntervalEmails", "fdTasksConfMaxEmails"]
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
   * @param string $templateName
   * @return array
   * Note :simply retrieve all information linked to a mail template object.
   */
  public function retrieveMailTemplateInfos (string $templateName): array
  {
    return $this->gateway->getLdapTasks("(|(objectClass=fdMailTemplate)(objectClass=fdMailAttachments))", [], $templateName);
  }

}
