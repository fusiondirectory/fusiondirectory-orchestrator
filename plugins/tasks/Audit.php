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
   * @throws Exception
   */
  public function processEndPointPatch (array $data = NULL): array
  {
    $result = $this->processAuditDeletion($this->gateway->getObjectTypeTask('Audit'));

    // Recursive function to filter out empty arrays at any depth
    $nonEmptyResults = $this->recursiveArrayFilter($result);

    if (!empty($nonEmptyResults)) {
      return $nonEmptyResults;
    } else {
      return ['No audit requiring removal'];
    }

  }

  /**
   * @param array $array
   * @return array
   * Note : Recursively filters out empty values and arrays at any depth.
   */
  private function recursiveArrayFilter (array $array): array
  {
    // First filter the array for non-empty elements
    $filtered = array_filter($array, function ($item) {
      if (is_array($item)) {
        // Recursively filter the sub-array
        $item = $this->recursiveArrayFilter($item);
        // Only retain non-empty arrays
        return !empty($item);
      } else {
        // Retain non-empty scalar values
        return !empty($item);
      }
    });

    return $filtered;
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
   * @param $auditRetention
   * @return array
   * Note : This will return a validation of audit log suppression
   * @throws Exception
   */
  public function checkAuditPassedRetention ($auditRetention, $subTaskDN, $subTaskCN): array
  {
    $result = [];

    // Date time object will use the timezone defined in FD, code is in index.php
    $today = new DateTime();

    // Search in LDAP for audit entries (All entries ! This can be pretty heavy.
    $audit = $this->gateway->getLdapTasks('(objectClass=fdAuditEvent)', ['fdAuditDateTime'], '', '');
    // Remove the count key from the audit array.
    $this->gateway->unsetCountKeys($audit);

    foreach ($audit as $record) {
      // Record in Human Readable date time object
      $auditDateTime = $this->generalizeLdapTimeToPhpObject($record['fdauditdatetime'][0]);

      $interval = $today->diff($auditDateTime);

      // Check if the interval is equal or greater than auditRetention setting
      if ($interval->days >= $auditRetention) {
        // If greater, delete the DN audit entry, we reuse removeSubTask method from gateway and get ldap response.(bool).
        $result[$subTaskCN]['result'] = $this->gateway->removeSubTask($record['dn']);
        $result[$subTaskCN]['info']   = 'Audit record removed.';

        // Update tasks accordingly if LDAP succeeded. TRUE Boolean returned by ldap.
        if ($result[$subTaskCN]['result']) {
          // Update the subtask with the status completed a.k.a "2".
          $result[$subTaskCN]['statusUpdate'] = $this->gateway->updateTaskStatus($subTaskDN, $subTaskCN, "2");
        } else {
          // Update the task with the LDAP potential error code.
          $result[$subTaskCN]['statusUpdate'] = $this->gateway->updateTaskStatus($subTaskDN, $subTaskCN, $result[$record['dn']]['result']);
        }
      }
    }

    return $result;
  }


  /**
   * @param $generalizeLdapDateTime
   * @return DateTime|string[]
   * @throws Exception
   * Note : Simply take a generalized Ldap time (with UTC = Z) and transform it to php object dateTime.
   */
  public function generalizeLdapTimeToPhpObject ($generalizeLdapDateTime)
  {
    // Extract the date part (first 8 characters: YYYYMMDD), we do not care about hour and seconds.
    $auditTimeFormatted = substr($generalizeLdapDateTime, 0, 8);

    // Create a DateTime object using only the date part, carefully setting the timezone to UTC. Audit timestamp is UTC
    $auditDate = DateTime::createFromFormat('Ymd', $auditTimeFormatted, new DateTimeZone('UTC'));

    // Check if the DateTime object was created successfully
    if (!$auditDate) {
      return ['Error in Time conversion from Audit record with timestamp :' . $generalizeLdapDateTime];
    }

    // Transform dateTime object from UTC to local defined dateTime. (Timezone is set in index.php).
    $auditDate->setTimezone(new DateTimeZone(date_default_timezone_get()));

    return $auditDate;
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

}