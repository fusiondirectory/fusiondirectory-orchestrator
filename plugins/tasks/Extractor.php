<?php

class Extractor implements EndpointInterface
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
    // Retrieve tasks of type 'extract'
    return $this->gateway->getObjectTypeTask('extract');
  }

  /**
   * @param array|null $data
   * @return array
   * Note: Part of the interface of orchestrator plugin to treat POST method
   */
  public function processEndPointPost (array $data = NULL): array
  {
    return [];
  }

  /**
   * @param array|null $data
   * @return array
   * Note: Part of the interface of orchestrator plugin to treat DELETE method
   */
  public function processEndPointDelete (array $data = NULL): array
  {
    return [];
  }

  /**
   * @param array|null $data
   * @return array
   * @throws Exception
   * Note: Part of the interface of orchestrator plugin to treat PATCH method
   */
  public function processEndPointPatch (array $data = NULL): array
  {
    $result = [];
    $extractTasks = $this->gateway->getObjectTypeTask('extract');
    $processedAnyTask = FALSE; // Track if any task was actually processed

    // Path is now expected in the JSON body ($data)
    $path = $data['path'] ?? '/srv/orchestrator/';

    foreach ($extractTasks as $task) {
      try {
        // Use TaskGateway's status and schedule check correctly
        // This will check if status is 1 (ready) AND scheduled time is reached
        if (!$this->gateway->statusAndScheduleCheck($task)) {
          // Skip this task without adding to result
          continue;
        }

        // Check if it's the bulk task identifier we expect
        if (!isset($task['fdtasksgranulardn'][0]) || $task['fdtasksgranulardn'][0] !== 'bulkExtractorTask') {
          // Skip tasks without adding to result
          continue;
        }

        $processedAnyTask = TRUE; // We found a task to process

        // Get the main task configuration, including the list of DNs
        $mainTaskDn = $task['fdtasksgranularmaster'][0];
        $mainTaskConfig = $this->getExtractMainTaskConfig($mainTaskDn);

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
            $this->gateway->updateTaskStatus($task['dn'], $task['cn'][0], '2');
            $result[$task['dn']]['result'] = "No user DNs to process.";
            continue;
        }

        // Create directory if it doesn't exist
        $this->ensureDirectoryExists($path);

        // Get main task CN for filename
        $mainTaskCn = $this->getMainTaskCn($mainTaskDn);
        $date = date('Y-m-d_H');
        
        // Add a unique identifier based on microtime 
        $uniqueId = substr(md5(microtime(true)), 0, 8);
        
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
            $this->gateway->updateTaskStatus($task['dn'], $task['cn'][0], $finalMessage);
            $result[$task['dn']]['result'] = $finalMessage;
            continue;
        }

        $success = $this->extractToFileBatch($allUserAttributes, $filename, 'csv');

        if ($success) {
            $finalMessage = "Batch extraction successful to $filename.";
            if (!empty($errors)) {
                $finalMessage .= " Some errors encountered: " . implode("; ", $errors);
                $this->gateway->updateTaskStatus($task['dn'], $task['cn'][0], $finalMessage);
            } else {
                $this->gateway->updateTaskStatus($task['dn'], $task['cn'][0], '2');
            }
            $result[$task['dn']]['result'] = $finalMessage;
        } else {
            $errorMessage = "Failed to write batch data to $filename.";
            // Update the status to error ('1')
            $this->gateway->updateTaskStatus($task['dn'], $task['cn'][0], $errorMessage);
            // Add to result but DON'T throw an exception, which would cause the catch block to overwrite our status
            $result[$task['dn']]['result'] = $errorMessage;
            // Continue to next task
            continue;
        }

      } catch (Exception $e) {
        $result[$task['dn']]['result'] = "Error processing extractor task: " . $e->getMessage();
        $this->gateway->updateTaskStatus($task['dn'], $task['cn'][0], $e->getMessage());
      }
    }

    // After processing all tasks, if none were processed, return a simple message
    if (!$processedAnyTask && empty($result)) {
      $result['status'] = "No tasks to process for extractor.";
    }

    return $result;
  }

  /**
   * @param string $mainTaskDn
   * @return array
   * Note: Retrieve the configuration from the main extract task.
   */
  private function getExtractMainTaskConfig (string $mainTaskDn): array
  {
    return $this->gateway->getLdapTasks(
      '(objectClass=fdExtractorTasks)',
      // Add fdExtractorTaskListOfDN here
      ['fdExtractorTaskFormat', 'cn', 'fdExtractorTaskListOfDN'],
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
  private function getUserAttributes (string $userDn, array $mainTaskConfig): array
  {
    // Get all user data from LDAP
    $userData = $this->gateway->getLdapTasks(
      '(objectClass=*)',
      ['*'],
      '',
      $userDn
    );

    // Process and return user data
    $this->gateway->unsetCountKeys($userData);
    return $userData;
  }

  /**
   * @param string $path
   * @return bool
   * @throws Exception
   * Note: Create directory if it doesn't exist.
   */
  private function ensureDirectoryExists (string $path): bool
  {
    if (!is_dir($path)) {
      if (!mkdir($path, 0755, TRUE)) {
        throw new Exception("Failed to create directory: $path");
      }
    }
    return TRUE;
  }

  /**
   * @param array $allUserAttributes Array of user attribute arrays
   * @param string $filename
   * @param string $format Should always be 'csv' currently
   * @return bool
   * @throws Exception
   * Note: Extract a batch of user attributes to a file (CSV only).
   */
  private function extractToFileBatch (array $allUserAttributes, string $filename, string $format): bool
  {
    if (empty($allUserAttributes)) {
        // Nothing to write, consider it a success.
        return true;
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
    if (empty($allUserAttributes)) {
      return TRUE; // No attributes to write
    }

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
                    // For array values (typical LDAP return format), take first value
                    // Skip the 'count' element if present
                    $userData[$column] = isset($user[$column][0]) ? $user[$column][0] : '';
                } else {
                    // For scalar values
                    $userData[$column] = $user[$column];
                }
            } else {
                // Column doesn't exist for this user, use empty string
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
      fputcsv($handle, $finalColumns);

      // Write data rows
      foreach ($allUserData as $row) {
        fputcsv($handle, $row);
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
  private function getMainTaskCn (string $mainTaskDn): string
  {
    $mainTask = $this->gateway->getLdapTasks(
      '(objectClass=*)',
      ['cn'],
      '',
      $mainTaskDn
    );

    return $mainTask[0]['cn'][0] ?? 'extract';
  }
}