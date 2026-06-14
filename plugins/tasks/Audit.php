<?php

class Audit implements EndpointInterface
{
  private TaskGateway $gateway;
  private CoreUtils $utils;

  public function __construct (TaskGateway $gateway)
  {
    $this->gateway = $gateway;
    $this->utils = new CoreUtils();
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
   */
  public function processEndPointDelete (?array $data = NULL): array
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
    // Check if audit type is specified in data
    $auditType = $data['type'] ?? 'standard'; // Default to standard audit

    if ($auditType === 'syslog') {
      // Process syslog audit
      $result = $this->processSyslogAuditTransformation($this->gateway->getObjectTypeTask('auditSyslog'));
    } else {
      // Process standard audit
      $result = $this->processAuditDeletion($this->gateway->getObjectTypeTask('audit'));
    }

    // Recursive function to filter out empty arrays at any depth
    $nonEmptyResults = $this->utils->recursiveArrayFilter($result);

    if (!empty($nonEmptyResults)) {
      return $nonEmptyResults;
    }
    if ($auditType === 'syslog') {
      return ['No audit entries requiring transformation'];
    }
    return ['No standard audit entries requiring removal'];
  }

  /**
   * @param array $auditSubTasks
   * @return array
   * @throws Exception
   */
  public function processAuditDeletion (array $auditSubTasks): array
  {
    return array_values(array_map(
      function ($task) {
        return $this->processScheduledTask($task);
      },
      array_filter($auditSubTasks, function ($task) {
        return $this->gateway->statusAndScheduleCheck($task);
      })
    ));
  }

  /**
   * @param array $task
   * @return array
   * @throws Exception
   */
  private function processScheduledTask (array $task): array
  {
    // Retrieve main task DN
    $mainTaskDn    = $task['fdtasksgranularmaster'][0];
    // Retrieve data from the main task (now also repeatable attributes)
    $auditMainTask = $this->getAuditMainTask($mainTaskDn);

    // Determine repeatable schedule only if main task marked repeatable
    $repeatableSchedule = NULL;
    $repeatableFlag     = $auditMainTask[0]['fdtasksrepeatable'][0] ?? NULL;
    if ($repeatableFlag !== NULL && strcasecmp($repeatableFlag, 'TRUE') === 0) {
      $repeatableSchedule = $auditMainTask[0]['fdtasksrepeatableschedule'][0] ?? NULL;
    }

    // Simply get the days to retain audit.
    $auditRetention = $auditMainTask[0]['fdaudittasksretention'][0];
    // Verification of all audit and their potential removal based on retention days passed, also update subtasks.
    return $this->checkAuditPassedRetention($auditRetention, $task['dn'], $task['cn'][0], $mainTaskDn, $repeatableSchedule);
  }

  /**
   * @param array $syslogAuditSubTasks
   * @return array
   * @throws Exception
   */
  public function processSyslogAuditTransformation (array $syslogAuditSubTasks): array
  {
    $result = [];

    $path = '/var/log/fusiondirectory/';
    $this->utils->ensureDirectoryExists($path);

    foreach ($syslogAuditSubTasks as $task) {
      try {
        // Initialize for safety
        $mainTaskDn         = NULL;
        $repeatableSchedule = NULL;
        // If the task must be treated - status and scheduled - process the sub-tasks
        if ($this->gateway->statusAndScheduleCheck($task)) {
          // Retrieve data from the main task
          $mainTaskDn   = $task['fdtasksgranularmaster'][0];
          $auditMainTask = $this->getAuditMainTask($mainTaskDn);
          // Repeatable logic
          $repeatableFlag = $auditMainTask[0]['fdtasksrepeatable'][0] ?? NULL;
          if ($repeatableFlag !== NULL && strcasecmp($repeatableFlag, 'TRUE') === 0) {
            $repeatableSchedule = $auditMainTask[0]['fdtasksrepeatableschedule'][0] ?? NULL;
          }
          // Get the prefix from the main task configuration (default to 'fd_syslog' if not set)
          $prefix = $auditMainTask[0]['fdauditsyslogprefix'][0] ?? 'fd_syslog';

          // Get the most recent audit timestamp that was already processed
            $lastProcessedTime = NULL;

          // Check if we have a state file recording last processed time (with prefix)
          $stateFile = $path . $prefix . '-last-processed.txt';
          if (file_exists($stateFile)) {
            $fileContent = trim(file_get_contents($stateFile));
            if (!empty($fileContent)) {
              $lastProcessedTime = $fileContent;
            }
          }

          // Only process entries newer than last processed
          $filter = '(objectClass=fdAuditEvent)';
          if ($lastProcessedTime !== NULL) {
            $filter = "(&(objectClass=fdAuditEvent)(fdauditdatetime>=$lastProcessedTime))";
          }

          // Get only new audit entries
          $auditEntries = $this->gateway->getLdapTasks($filter, ['*'], '', '');
          $this->gateway->unsetCountKeys($auditEntries);

          // Check if there are no audit entries
          if (count($auditEntries) === 0) {
            if ($repeatableSchedule !== NULL) {
              $this->gateway->updateTaskStatus($task['dn'], $task['cn'][0], '2', $mainTaskDn, $repeatableSchedule);
            } elseif ($mainTaskDn !== NULL) {
              $this->gateway->updateTaskStatus($task['dn'], $task['cn'][0], '2', $mainTaskDn);
            } else {
              $this->gateway->updateTaskStatus($task['dn'], $task['cn'][0], '2');
            }
            $result[] = ["dn" => $task['dn'], "message" => "No audit entries found to transform"];
            continue;
          }

          // Create syslog file with prefix (path already defined at the beginning)
          $date = date('Y-m-d');
          $filename = $path . $prefix . '-' . $date . '.log';

          // Track which audit IDs are already in the file to prevent duplicates
          $existingAuditIds = [];

          // Read existing file if it exists to extract audit IDs
          if (file_exists($filename)) {
            $existingContent = file($filename, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($existingContent as $line) {
              // Extract audit ID from the line using regex
              if (preg_match('/id="([^"]+)"/', $line, $matches)) {
                $existingAuditIds[] = $matches[1];
              }
            }
          }

          // Open file for writing (append mode)
          $handle = fopen($filename, 'a');
          if ($handle === FALSE) {
            throw new Exception("Could not open file: $filename");
          }

          $count   = 0;
          $skipped = 0;

          foreach ($auditEntries as $entry) {
            // Skip entry if its ID is already in the file
            $auditId = $entry['fdauditid'][0] ?? 'unknown';
            if (in_array($auditId, $existingAuditIds)) {
              $skipped++;
              continue;
            }

            // Parse LDAP timestamp format (YYYYMMDDHHmmss.SSSSSSZ)
            $timestamp = '';
            if (isset($entry['fdauditdatetime'][0])) {
              // Extract date parts from LDAP format
              $dateStr = $entry['fdauditdatetime'][0];
              if (preg_match('/^(\d{4})(\d{2})(\d{2})(\d{2})(\d{2})(\d{2})/', $dateStr, $matches)) {
                $year  = $matches[1];
                $month = $matches[2];
                $day   = $matches[3];
                $hour  = $matches[4];
                $min   = $matches[5];
                $sec   = $matches[6];

                // Create a datetime object in UTC first, then convert to local timezone
                $dt = new DateTime("$year-$month-$day $hour:$min:$sec", new DateTimeZone('UTC'));
                $dt->setTimezone(new DateTimeZone(date_default_timezone_get()));
                $timestamp = $dt->format('M d H:i:s');
              } else {
                $timestamp = date('M d H:i:s');
              }
            } else {
              $timestamp = date('M d H:i:s');
            }

            $syslogMessage = $this->createSyslogMessage($entry, $timestamp, $auditId);

            // Write the message to the file
            fwrite($handle, $syslogMessage . PHP_EOL);
            $count++;
          }

          fclose($handle);

          // After processing all entries, save the latest timestamp
          // Find the most recent timestamp
          $latestTime = NULL;
          foreach ($auditEntries as $entry) {
            if (isset($entry['fdauditdatetime'][0])) {
              if ($latestTime === NULL || $entry['fdauditdatetime'][0] > $latestTime) {
                $latestTime = $entry['fdauditdatetime'][0];
              }
            }
          }

          // Save it to the state file
          if ($latestTime !== NULL) {
            file_put_contents($stateFile, $latestTime);
          }

          if ($repeatableSchedule !== NULL) {
            $this->gateway->updateTaskStatus($task['dn'], $task['cn'][0], '2', $mainTaskDn, $repeatableSchedule);
          } elseif ($mainTaskDn !== NULL) {
            $this->gateway->updateTaskStatus($task['dn'], $task['cn'][0], '2', $mainTaskDn);
          } else {
            $this->gateway->updateTaskStatus($task['dn'], $task['cn'][0], '2');
          }

          // Include information about skipped entries in the result message
          $resultMsg = "Successfully transformed $count audit entries to syslog format in $filename";
          if ($skipped > 0) {
            $resultMsg .= " (skipped $skipped duplicate entries)";
          }
          $result[] = ["dn" => $task['dn'], "message" => $resultMsg];
        }
      } catch (Exception $e) {
        if ($repeatableSchedule !== NULL && $mainTaskDn !== NULL) {
          $this->gateway->updateTaskStatus($task['dn'], $task['cn'][0], $e->getMessage(), $mainTaskDn, $repeatableSchedule);
        } elseif ($mainTaskDn !== NULL) {
          $this->gateway->updateTaskStatus($task['dn'], $task['cn'][0], $e->getMessage(), $mainTaskDn);
        } else {
          $this->gateway->updateTaskStatus($task['dn'], $task['cn'][0], $e->getMessage());
        }
        $result[] = ["dn" => $task['dn'], "message" => "Error transforming audit entries: " . $e->getMessage()];
      }
    }

    return $result;
  }

  /**
   * @param string $mainTaskDn
   * @return array
   * Note : Simply return attributes from the main related audit tasks.
   */
  public function getAuditMainTask (string $mainTaskDn): array
  {
    return $this->gateway->getLdapTasks(
      '(objectClass=fdAuditTasks)',
      ['fdAuditTasksRetention', 'fdAuditSyslogPrefix', 'fdTasksRepeatableSchedule', 'fdTasksRepeatable'],
      '',
      $mainTaskDn
    );
  }

  /**
   * @param int $auditRetention
   * @return array
   * Note : This will return a validation of audit log suppression
   * @throws Exception
   */
  public function checkAuditPassedRetention (int $auditRetention, $subTaskDN, $subTaskCN, $mainTaskDn = NULL, $repeatableSchedule = NULL): array
  {
    $auditLib = new FusionDirectory\Audit\AuditLib(
      $auditRetention,
      $this->returnLdapAuditEntries()
    );

    $actions = $auditLib->getRetentionActions($subTaskDN, $subTaskCN, $mainTaskDn, $repeatableSchedule);
    $result  = [];

    foreach ($actions as $action) {
      if ($action instanceof FusionDirectory\Audit\Action\MarkTaskCompleted) {
        $result[$action->subTaskCN]['result']       = TRUE;
        $result[$action->subTaskCN]['info']         = 'No audit to be removed.';
        $result[$action->subTaskCN]['statusUpdate'] = $this->gateway->updateTaskStatus(
          $action->subTaskDN, $action->subTaskCN, "2", $action->mainTaskDn, $action->repeatableSchedule
        );
      } elseif ($action instanceof FusionDirectory\Audit\Action\RemoveAuditRecord) {
        $removed = $this->gateway->removeSubTask($action->dn);
        $result[$subTaskCN]['result'] = $removed;
        $result[$subTaskCN]['info']   = 'Audit record removed.';

        if ($removed) {
          $result[$subTaskCN]['statusUpdate'] = $this->gateway->updateTaskStatus(
            $subTaskDN, $subTaskCN, "2", $mainTaskDn, $repeatableSchedule
          );
        } else {
          $result[$subTaskCN]['statusUpdate'] = $this->gateway->updateTaskStatus(
            $subTaskDN, $subTaskCN, 'error', $mainTaskDn, $repeatableSchedule
          );
        }
      }
    }

    return $result;
  }

  /**
   * @return array
   * NOTE : simply return the list of audit entries existing in LDAP
   */
  public function returnLdapAuditEntries () : array
  {
    // Search in LDAP for audit entries (All entries ! This can be pretty heavy.
    $audit = $this->gateway->getLdapTasks('(objectClass=fdAuditEvent)', ['fdAuditDateTime'], '', '');
    // Remove the count key from the audit array.
    $this->gateway->unsetCountKeys($audit);

    return $audit;
  }

  private function createSyslogMessage (array $entry, string $timestamp, string $auditId)
  {
    // Get hostname (use IP if available, otherwise use system hostname)
    $hostname = $entry['fdauditauthorip'][0] ?? gethostname();

    // Get user information (use DN if available)
    $author = $entry['fdauditauthordn'][0] ?? 'unknown';

    // Get action
    $action = $entry['fdauditaction'][0] ?? 'unknown';

    // Get object type and object
    $objectType = $entry['fdauditobjecttype'][0] ?? '';

    $object = $entry['fdauditobject'][0] ?? '';

    // Get result
    $auditResult = $entry['fdauditresult'][0] ?? '';

    // Format the syslog message
    // <priority>timestamp hostname tag: message
    $syslogMessage = "<local4.info>$timestamp $hostname FusionDirectory-Audit: ";
    $syslogMessage .= "id=\"" . $auditId . "\" ";
    $syslogMessage .= "author=\"$author\" ";
    $syslogMessage .= "action=\"$action\" ";

    if (!empty($objectType)) {
      $syslogMessage .= "objectType=\"$objectType\" ";
    }

    if (!empty($object)) {
      $syslogMessage .= "object=\"$object\" ";
    }

    if (!empty($auditResult)) {
      $syslogMessage .= "result=\"$auditResult\" ";
    }

    // Add attributes if available (contains changes made)
    if (isset($entry['fdauditattributes'][0])) {
      $syslogMessage .= "changes=\"" . $entry['fdauditattributes'][0] . "\" ";
    }

    return $syslogMessage;
  }
}
