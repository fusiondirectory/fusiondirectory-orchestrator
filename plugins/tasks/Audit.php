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
    return $this->processAuditDeletion($this->gateway->getObjectTypeTask('Audit'));
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

    // todo - Logic to iterate through audit timestamp and delete passed time.

    foreach ($auditSubTasks as $task) {
      // If the tasks must be treated - status and scheduled - process the sub-tasks
      if ($this->gateway->statusAndScheduleCheck($task)) {

        // Retrieve data from the main task.
        $auditMainTask = $this->getAuditMainTask($task['fdtasksgranularmaster'][0]);
        // Simply get the days to retain audit.
        $auditRetention = $auditMainTask[0]['fdaudittasksretention'][0];

        $result = $this->deleteAuditPassedRetention($auditRetention);
      }

    }

    return $result;
  }

  /**
   * @param $auditRetention
   * @return array
   * Note : This will return a validation of audit log suppression
   */
  public function deleteAuditPassedRetention ($auditRetention): array
  {
    $result = [];

    // Date time object will use the timezone defined in FD, code is in index.php
    $date = new DateTime();
    // today in Human Readable format.
    $todayHR = $date->format('Y-m-d H:i:s');

    // Search in LDAP for audit entries
    $audit = $this->gateway->getLdapTasks('(objectClass=fdAuditEvent)', ['fdAuditDateTime'], '', '');
    // Remove the count key from the audit array.
    $this->gateway->unsetCountKeys($audit);

    foreach ($audit as $record) {
      // Record in Human Readable date time object
      $recordHR = $this->generalizeLdapTimeToPhpObject($record['fdauditdatetime'][0]);

      $result[] = $recordHR;
    }

    return $result;
  }

  public function generalizeLdapTimeToPhpObject ($generalizeLdapDateTime): array
  {
    // Extract the date part (first 8 characters: YYYYMMDD), we do not care about hour and seconds.
    $datePart = substr($generalizeLdapDateTime, 0, 8);

    // Create a DateTime object using only the date part, carefully setting the timezone to UTC. Audit timestamp is UTC
    $datetime = DateTime::createFromFormat('Ymd', $datePart, new DateTimeZone('UTC'));

    // Check if the DateTime object was created successfully
    if (!$datetime) {
      return ['Error in Time conversion from Audit record with timestamp :' . $generalizeLdapDateTime];
    }

    return [];

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