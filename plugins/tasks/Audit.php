<?php

class Audit implements EndpointInterface
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
   */
  public function processEndPointDelete (array $data = NULL): array
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
    // Check if audit type is specified in data
    $auditType = $data['type'] ?? 'standard'; // Default to standard audit
    
    if ($auditType === 'syslog') {
      // Process syslog audit
      $result = $this->processSyslogAuditTransformation($this->gateway->getObjectTypeTask('Audit-Syslog'));
    } else {
      // Process standard audit
      $result = $this->processAuditDeletion($this->gateway->getObjectTypeTask('Audit'));
    }

    // Recursive function to filter out empty arrays at any depth
    $nonEmptyResults = $this->recursiveArrayFilter($result);

    if (!empty($nonEmptyResults)) {
      return $nonEmptyResults;
    } else {
      if ($auditType === 'syslog') {
        return ['No audit entries requiring transformation'];
      } else {
        return ['No standard audit entries requiring removal'];
      }
    }
  }

  /**
   * @param array $auditSubTasks
   * @return array
   * @throws Exception
   */
  public function processAuditDeletion (array $auditSubTasks): array
  {
    $result = [];

    foreach ($auditSubTasks as $task) {

      // If the tasks must be treated - status and scheduled - process the sub-tasks
      if ($this->gateway->statusAndScheduleCheck($task)) {

        // Retrieve data from the main task.
        $auditMainTask = $this->getAuditMainTask($task['fdtasksgranularmaster'][0]);
        // Simply get the days to retain audit.
        $auditRetention = $auditMainTask[0]['fdaudittasksretention'][0];

        // Verification of all audit and their potential removal based on retention days passed, also update subtasks.
        $result[] = $this->checkAuditPassedRetention($auditRetention, $task['dn'], $task['cn'][0]);
      }
    }

    return $result;
  }

  /**
   * @param array $syslogAuditSubTasks
   * @return array
   * @throws Exception
   */
  public function processSyslogAuditTransformation (array $syslogAuditSubTasks): array
  {
    $result = [];

    foreach ($syslogAuditSubTasks as $task) {
      try {
        // If the task must be treated - status and scheduled - process the sub-tasks
        if ($this->gateway->statusAndScheduleCheck($task)) {
          // Retrieve data from the main task
          $auditMainTask = $this->getAuditMainTask($task['fdtasksgranularmaster'][0]);
          
          // Get all audit entries with all attributes
          $auditEntries = $this->gateway->getLdapTasks('(objectClass=fdAuditEvent)', ['*'], '', '');
          $this->gateway->unsetCountKeys($auditEntries);
          
          if (empty($auditEntries)) {
            $this->gateway->updateTaskStatus($task['dn'], $task['cn'][0], '2');
            $result[] = ["dn" => $task['dn'], "message" => "No audit entries found to transform"];
            continue;
          }
          
          // Create syslog file
          $path = '/var/log/fusiondirectory/';
          $this->ensureDirectoryExists($path);
          
          $date = date('Y-m-d');
          $filename = $path . 'fd-audit-' . $date . '.log';
          
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
          if ($handle === false) {
            throw new Exception("Could not open file: $filename");
          }
          
          $count = 0;
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
                $year = $matches[1];
                $month = $matches[2];
                $day = $matches[3];
                $hour = $matches[4];
                $min = $matches[5];
                $sec = $matches[6];
                
                // Create a datetime object and format for syslog
                $dt = new DateTime("$year-$month-$day $hour:$min:$sec");
                $timestamp = $dt->format('M d H:i:s');
              } else {
                $timestamp = date('M d H:i:s');
              }
            } else {
              $timestamp = date('M d H:i:s');
            }
            
            // Get hostname (use IP if available, otherwise use system hostname)
            $hostname = isset($entry['fdauditauthorip'][0]) ? 
                       $entry['fdauditauthorip'][0] : gethostname();
            
            // Get user information (use DN if available)
            $user = isset($entry['fdauditauthordn'][0]) ? 
                   $entry['fdauditauthordn'][0] : 'unknown';
            
            // Get action
            $action = isset($entry['fdauditaction'][0]) ? 
                     $entry['fdauditaction'][0] : 'unknown';
            
            // Get object type and object
            $objectType = isset($entry['fdauditobjecttype'][0]) ? 
                         $entry['fdauditobjecttype'][0] : '';
            
            $object = isset($entry['fdauditobject'][0]) ? 
                     $entry['fdauditobject'][0] : '';
            
            // Get result
            $auditResult = isset($entry['fdauditresult'][0]) ? 
                         $entry['fdauditresult'][0] : '';
            
            // Format the syslog message
            // <priority>timestamp hostname tag: message
            $syslogMessage = "<local4.info>$timestamp $hostname FusionDirectory-Audit: ";
            $syslogMessage .= "id=\"" . $auditId . "\" ";
            $syslogMessage .= "user=\"$user\" ";
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
            
            // Write the message to the file
            fwrite($handle, $syslogMessage . PHP_EOL);
            $count++;
          }
          
          fclose($handle);
          
          // Update task status
          $this->gateway->updateTaskStatus($task['dn'], $task['cn'][0], '2');
          
          // Include information about skipped entries in the result message
          $resultMsg = "Successfully transformed $count audit entries to syslog format in $filename";
          if ($skipped > 0) {
            $resultMsg .= " (skipped $skipped duplicate entries)";
          }
          
          $result[] = ["dn" => $task['dn'], "message" => $resultMsg];
        }
      } catch (Exception $e) {
        $this->gateway->updateTaskStatus($task['dn'], $task['cn'][0], $e->getMessage());
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
    // Retrieve data from the main task
    return $this->gateway->getLdapTasks('(objectClass=fdAuditTasks)', ['fdAuditTasksRetention'], '', $mainTaskDn);
  }

  /**
   * @param $auditRetention
   * @return array
   * Note : This will return a validation of audit log suppression
   * @throws Exception
   */
  public function checkAuditPassedRetention ($auditRetention, $subTaskDN, $subTaskCN): array
  {
    $auditLib = new FusionDirectory\Audit\AuditLib($auditRetention, $this->returnLdapAuditEntries(), $this->gateway, $subTaskDN, $subTaskCN);
    return $auditLib->checkAuditPassedRetentionOrchestrator();
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

   /**
   * @param array $array
   * @return array
   * Note : Recursively filters out empty values and arrays at any depth.
   */
  public function recursiveArrayFilter (array $array): array
  {
    return array_filter($array, function ($item) {
      if (is_array($item)) {
          $item = $this->recursiveArrayFilter($item);
      }
      return !empty($item);
    });
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
      if (!mkdir($path, 0755, true)) {
        throw new Exception("Failed to create directory: $path");
      }
    }
    return true;
  }
}