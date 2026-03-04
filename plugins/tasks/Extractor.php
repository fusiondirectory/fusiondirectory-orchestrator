<?php

class Extractor implements EndpointInterface
{
  private TaskGateway $gateway;
  // @phpstan-ignore property.onlyWritten
  private CoreUtils $utils;
  private MailUtils $mailUtils;

  public function __construct (TaskGateway $gateway)
  {
    $this->gateway = $gateway;
    $this->utils = new CoreUtils();
    $this->mailUtils = new MailUtils();
  }

  /**
   * @return array
   * Part of the interface of orchestrator plugin to treat GET method
   */
  public function processEndPointGet (): array
  {
    // Retrieve tasks of type 'extract'
    return $this->gateway->getObjectTypeTask('extract');
  }

  /**
   * @param array|null $data
   * @return array
   * Note: Part of the interface of orchestrator plugin to treat POST method
   */
  public function processEndPointPost (?array $data = NULL): array
  {
    return [];
  }

  /**
   * @param array|null $data
   * @return array
   * Note: Part of the interface of orchestrator plugin to treat DELETE method
   */
  public function processEndPointDelete (?array $data = NULL): array
  {
    return [];
  }

  /**
   * @param array|null $data
   * @return array
   * @throws Exception
   * Note: Part of the interface of orchestrator plugin to treat PATCH method
   */
  public function processEndPointPatch (?array $data = NULL): array
  {
    $result = [];
    $extractTasks = $this->gateway->getObjectTypeTask('extract');
    $processedAnyTask = FALSE; // Track if any task was actually processedy

    // Path is now expected in the JSON body ($data)
    $path = $data['path'] ?? '/srv/orchestrator/';

    foreach ($extractTasks as $task) {
      try {
        // Initialize variables to avoid undefined variable errors
        $mainTaskDn         = NULL;
        $repeatableSchedule = NULL;

        // Use TaskGateway's status and schedule check correctly
        // This will check if status is 1 (ready) AND scheduled time is reached
        // @phpstan-ignore argument.type
        if (!$this->gateway->statusAndScheduleCheck($task)) {
          // Skip this task without adding to result
          continue;
        }

        // Check if it's the bulk task identifier we expect
        // @phpstan-ignore isset.offset
        if (!isset($task['fdtasksgranulardn'][0]) || $task['fdtasksgranulardn'][0] !== 'bulkExtractorTask') { /* @phpstan-ignore-line */
          // Skip tasks without adding to result
          continue;
        }

        // @phpstan-ignore deadCode.unreachable
        $processedAnyTask = TRUE; // We found a task to process

        // Get the main task configuration, including the list of DNs
        $mainTaskDn = $task['fdtasksgranularmaster'][0];
        $mainTaskConfig = $this->getExtractMainTaskConfig($mainTaskDn);

        // Determine if main task is marked repeatable; only then use schedule
        $isRepeatableFlag = $mainTaskConfig[0]['fdtasksrepeatable'][0] ?? NULL; // may be TRUE/FALSE
        if ($isRepeatableFlag !== NULL && strcasecmp($isRepeatableFlag, 'TRUE') === 0) {
          $repeatableSchedule = $mainTaskConfig[0]['fdtasksrepeatableschedule'][0] ?? NULL;
        }

        // Process fdExtractorTaskListOfDN attribute
        $userDnListRaw = $mainTaskConfig[0]['fdextractortasklistofdn'] ?? [];
        $userDnList = [];

        if (is_array($userDnListRaw)) {
            $userDnList = $userDnListRaw;
            unset($userDnList['count']);
        } elseif (is_string($userDnListRaw) && !empty($userDnListRaw)) {
            $userDnList = [$userDnListRaw];
        }

        if (empty($userDnList)) {
          if ($repeatableSchedule !== NULL) {
            $this->gateway->updateTaskStatus($task['dn'], $task['cn'][0], '2', $mainTaskDn, $repeatableSchedule);
          } else {
            $this->gateway->updateTaskStatus($task['dn'], $task['cn'][0], '2');
          }
            $result[$task['dn']]['result'] = "No user DNs to process.";
            continue;
        }

        // Create directory if it doesn't exist
        $this->utils->ensureDirectoryExists($path);

        // Get main task CN for filename
        $mainTaskCn = $this->getMainTaskCn($mainTaskDn);
        $date = date('Y-m-d_H');

        // Add a unique identifier based on microtime
        $uniqueId = substr(md5((string)microtime(TRUE)), 0, 8);

        $filename = isset($data['filename']) ?
                   $path . $data['filename'] . '_' . $date . '_' . $uniqueId . '.csv' :
                   $path . $mainTaskCn . '_' . $date . '_' . $uniqueId . '.csv';

        // Batch Processing
        $allUserAttributes = [];
        $errors = [];

        foreach ($userDnList as $userDn) {
          if (empty($userDn)) {
              continue;
          }

          try {
              $userAttributes = $this->getUserAttributes($userDn, $mainTaskConfig);
            if (!empty($userAttributes)) {
                $allUserAttributes[] = $userAttributes[0];
            }
          } catch (Exception $e) {
              $errors[] = "Error fetching attributes for DN '$userDn': " . $e->getMessage();
          }
        }

        if (empty($allUserAttributes)) {
            $finalMessage = "No user attributes could be extracted.";
          if (!empty($errors)) {
              $finalMessage .= " Errors: " . implode("; ", $errors);
          }
            // Treat as successful completion (no data) so next execution can be scheduled
          if ($repeatableSchedule !== NULL) {
            $this->gateway->updateTaskStatus($task['dn'], $task['cn'][0], '2', $mainTaskDn, $repeatableSchedule);
          } else if ($mainTaskDn !== NULL) {
            $this->gateway->updateTaskStatus($task['dn'], $task['cn'][0], '2', $mainTaskDn);
          } else {
            $this->gateway->updateTaskStatus($task['dn'], $task['cn'][0], '2');
          }
            $result[$task['dn']]['result'] = $finalMessage;
            continue;
        }

        $success = $this->extractToFileBatch($allUserAttributes, $filename, 'csv');

        if ($success) {
            // --- EMAIL LOGIC START ---
            // Retrieve sender and recipients from main task
            $mainTaskDetails = $this->gateway->getLdapTasks(
                '(objectClass=fdExtractorTasks)',
                [
                  'fdExtractorEmailSender',
                  'fdExtractorListOfRecipientsMails'
                ],
                '',
                $mainTaskDn
            );
            $sender = $mainTaskDetails[0]['fdextractoremailsender'][0] ?? '';
            $recipients = $mainTaskDetails[0]['fdextractorlistofrecipientsmails'] ?? [];
            $this->gateway->unsetCountKeys($recipients);

            $finalMessage = $this->getFinalMessage($filename, $task, $recipients, $sender, $errors, $mainTaskDn, $repeatableSchedule);
            $result[$task['dn']]['result'] = $finalMessage;
            // --- EMAIL LOGIC END ---
        } else {
            $finalMessage = "Failed to write batch data to $filename.";
            // Update the status to error ('1')
            $this->gateway->updateTaskStatus($task['dn'], $task['cn'][0], $finalMessage);
            $result[$task['dn']]['result'] = $finalMessage;
            continue;
        }

      } catch (Exception $e) {
        // @phpstan-ignore booleanAnd.alwaysFalse
        if ($repeatableSchedule !== NULL && isset($mainTaskDn)) { /* @phpstan-ignore-line */
          // @phpstan-ignore offsetAccess.notFound
          $this->gateway->updateTaskStatus($task['dn'], $task['cn'][0], $e->getMessage(), $mainTaskDn, $repeatableSchedule); /* @phpstan-ignore-line */
          // @phpstan-ignore isset.variable
        } else if (isset($mainTaskDn)) {
          // @phpstan-ignore offsetAccess.notFound
          $this->gateway->updateTaskStatus($task['dn'], $task['cn'][0], $e->getMessage(), $mainTaskDn); /* @phpstan-ignore-line */
        } else {
          // @phpstan-ignore offsetAccess.notFound
          $this->gateway->updateTaskStatus($task['dn'], $task['cn'][0], $e->getMessage()); /* @phpstan-ignore-line */
        }
        // @phpstan-ignore offsetAccess.notFound
        $result[$task['dn']]['result'] = "Error processing extractor task: " . $e->getMessage();
      }
    }

    // After processing all tasks, if none were processed, return a simple message
    // @phpstan-ignore booleanNot.alwaysTrue
    if (!$processedAnyTask && empty($result)) {
      $result['status'] = "No tasks to process for extractor.";
    }

    return $result;
  }

  // @phpstan-ignore method.unused
  private function getFinalMessage (string $filename, array $task, array $recipients, $sender, array $errors, $mainTaskDn, $repeatableSchedule): string
  {
      $subject    = "FusionDirectory Extractor - Export file";
      $body       = "Your requested extract is attached.\n\nFile: $filename";
      // Prepare attachment
      $attachments = [[
          'cn' => basename($filename),
          'content' => file_get_contents($filename)
      ]];

      if (empty($sender) || empty($recipients)) {
        $finalMessage = "Batch extraction successful to $filename. Email not sent: sender or recipient missing.";
        if (!empty($errors)) {
              $finalMessage .= " Some errors encountered: " . implode("; ", $errors);
        }
        // Success without email
        if ($repeatableSchedule !== NULL) {
          $this->gateway->updateTaskStatus($task['dn'], $task['cn'][0], '2', $mainTaskDn, $repeatableSchedule);
        } else if ($mainTaskDn !== NULL) {
          $this->gateway->updateTaskStatus($task['dn'], $task['cn'][0], '2', $mainTaskDn);
        } else {
          $this->gateway->updateTaskStatus($task['dn'], $task['cn'][0], '2');
        }
      } else {
          // Send mail using MailLib
        $mailSentResult = $this->mailUtils->sendMail($sender, NULL, $recipients,
              $body, NULL, $subject, NULL, $attachments);

        if ($mailSentResult[0] == "SUCCESS") {
          $finalMessage = "Batch extraction successful to $filename. Email sent to recipients.";
          if (!empty($errors)) {
                  $finalMessage .= " Some errors encountered: " . implode("; ", $errors);
          }
          if ($repeatableSchedule !== NULL) {
            $this->gateway->updateTaskStatus($task['dn'], $task['cn'][0], '2', $mainTaskDn, $repeatableSchedule);
          } else if ($mainTaskDn !== NULL) {
            $this->gateway->updateTaskStatus($task['dn'], $task['cn'][0], '2', $mainTaskDn);
          } else {
            $this->gateway->updateTaskStatus($task['dn'], $task['cn'][0], '2');
          }
        } else {
          $finalMessage = "Batch extraction successful to $filename, but email failed: " . $mailSentResult[0];
          if ($repeatableSchedule !== NULL) {
            $this->gateway->updateTaskStatus($task['dn'], $task['cn'][0], $finalMessage, $mainTaskDn, $repeatableSchedule);
          } else if ($mainTaskDn !== NULL) {
            $this->gateway->updateTaskStatus($task['dn'], $task['cn'][0], $finalMessage, $mainTaskDn);
          } else {
            $this->gateway->updateTaskStatus($task['dn'], $task['cn'][0], $finalMessage);
          }
        }
      }
      return $finalMessage;
  }

  /**
   * @param string $mainTaskDn
   * @return array
   * Note: Retrieve the configuration from the main extract task.
   */
  // @phpstan-ignore method.unused
  private function getExtractMainTaskConfig (string $mainTaskDn): array
  {
    return $this->gateway->getLdapTasks(
      '(objectClass=fdExtractorTasks)',
      [
        'fdExtractorTaskFormat',
        'cn',
        'fdExtractorTaskListOfDN',
        'fdExtractorTaskAttributes',
        'fdTasksRepeatableSchedule',
        'fdTasksRepeatable'
      ],
      '',
      $mainTaskDn
    );
  }

  /**
   * @param string $userDn
   * @param array $mainTaskConfig
   * @return array
   * Note: Get all user attributes from the user DN.
   */
  // @phpstan-ignore method.unused
  private function getUserAttributes (string $userDn, array $mainTaskConfig): array
  {
    // Default to all attributes
    $attributesToFetch = ['*'];

    // Try to get fdExtractorTaskAttributes from main task config
    if (!empty($mainTaskConfig[0]['fdextractortaskattributes'])) {
      $attrList = $mainTaskConfig[0]['fdextractortaskattributes'];
      // Remove all 'count' keys using TaskGateway utility
      $this->gateway->unsetCountKeys($attrList);

      // If not "ALL", use only the listed attributes
      // @phpstan-ignore function.impossibleType
      if (is_array($attrList) && !(count($attrList) === 1 && strtoupper($attrList[0]) === 'ALL')) { /* @phpstan-ignore-line */
        $attributesToFetch = [];
        foreach ($attrList as $attr) {
          if (is_string($attr)) {
            $attributesToFetch[] = $attr;
          }
        }
        // @phpstan-ignore function.impossibleType
      } elseif (is_string($attrList) && strtoupper($attrList) !== 'ALL') { /* @phpstan-ignore-line */
        $attributesToFetch = [$attrList];
      }
    }

    // Get user data from LDAP for the selected attributes
    $userData = $this->gateway->getLdapTasks(
      '(objectClass=inetOrgPerson)',
      $attributesToFetch,
      '',
      $userDn
    );

    // Process and return user data
    $this->gateway->unsetCountKeys($userData);
    return $userData;
  }

  /**
   * @param array $allUserAttributes Array of user attribute arrays
   * @param string $filename
   * @param string $format Should always be 'csv' currently
   * @return bool
   * @throws Exception
   * Note: Extract a batch of user attributes to a file (CSV only).
   */
  // @phpstan-ignore method.unused
  private function extractToFileBatch (array $allUserAttributes, string $filename, string $format): bool
  {
    if (empty($allUserAttributes)) {
        // Nothing to write, consider it a success.
        return TRUE;
    }

    // Only CSV is supported
    if (strtolower($format) !== 'csv') {
        throw new InvalidArgumentException("Unsupported format '$format' requested. Only CSV is supported.");
    }

    return $this->exportToCsvBatch($allUserAttributes, $filename);
  }

  /**
   * @param array $allUserAttributes Array of user attribute arrays
   * @param string $filename
   * @return bool
   * @throws Exception
   * Note: Export a batch of user attributes to CSV. Overwrites the file.
   */
  private function exportToCsvBatch (array $allUserAttributes, string $filename): bool
  {
    $allColumns = [];
    $allUserData = [];

    // First pass: Collect all unique attributes across all users
    foreach ($allUserAttributes as $user) {
      foreach ($user as $attribute => $values) {
          // Skip numeric keys and 'count' entries that come from LDAP results
        if (is_string($attribute) && $attribute !== 'count') {
            $allColumns[$attribute] = TRUE;
        }
      }
    }

    // Second pass: Build data rows with consistent column structure
    foreach ($allUserAttributes as $user) {
        $userData = [];
      foreach (array_keys($allColumns) as $column) {
        if (isset($user[$column])) {
          if (is_array($user[$column])) {
            // All values, since 'count' is already removed
            $userData[$column] = implode(';', $user[$column]);
          } else {
              $userData[$column] = $user[$column];
          }
        } else {
            $userData[$column] = '';
        }
      }
        $allUserData[] = $userData;
    }

    if (empty($allUserData)) {
        return TRUE; // No valid user data extracted
    }

    $finalColumns = array_keys($allColumns);

    // Write to file (overwrite mode 'w')
    $handle = fopen($filename, 'w');
    if ($handle === FALSE) {
        throw new Exception("Could not open file for writing: $filename");
    }

    try {
        // Write headers
        fputcsv($handle, $finalColumns, escape: "\\");

        // Write data rows
      foreach ($allUserData as $row) {
          fputcsv($handle, $row, escape: "\\");
      }

        return TRUE;
    } finally {
        fclose($handle);
    }
  }

  /**
   * Get the CN (Common Name) of the main task
   *
   * @param string $mainTaskDn
   * @return string
   */
  // @phpstan-ignore method.unused
  private function getMainTaskCn (string $mainTaskDn): string
  {
    $mainTask = $this->gateway->getLdapTasks(
      '(objectClass=fdTasks)',
      ['cn'],
      '',
      $mainTaskDn
    );

    return $mainTask[0]['cn'][0] ?? 'extract';
  }
}
