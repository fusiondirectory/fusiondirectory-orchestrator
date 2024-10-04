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
}