<?php

use FusionDirectory\Rest\WebServiceCall;

class Archive implements EndpointInterface
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
      // Retrieve tasks of type 'archive'
      return $this->gateway->getObjectTypeTask('archive');
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
      $archiveTasks = $this->gateway->getObjectTypeTask('archive');

      // Initialize the WebServiceCall object for login
      $webServiceCall = new WebServiceCall($_ENV['FUSIONDIRECTORY_WEBSERVICE_URL'] . '/login', 'POST');
      $webServiceCall->setCurlSettings(); // Perform login and set the token

    foreach ($archiveTasks as $task) {
      try {
        // Initialize variables to avoid undefined variable errors
        $mainTaskDn         = NULL;
        $repeatableSchedule = NULL;

        if (!$this->gateway->statusAndScheduleCheck($task)) {
            // Skip this task if it does not meet the status and schedule criteria
            continue;
        }

        // Get the main task DN
        $mainTaskDn = $task['fdtasksgranularmaster'][0];

        // Retrieve the main task configuration
        $mainTaskConfig = $this->getArchiveTaskBehaviorFromMainTask($mainTaskDn);
        $repeatableSchedule = $mainTaskConfig[0]['fdtasksrepeatableschedule'][0] ?? NULL;
        $desiredSupannStatus = $mainTaskConfig;

        // Retrieve the current supann status of the user
        $currentSupannStatus = $this->getUserSupannAccountStatus($task['fdtasksgranulardn'][0]);

        // Check if the current supann status matches the desired status
        if (!$this->isSupannStatusMatching($desiredSupannStatus, $currentSupannStatus)) {
            // The task does not meet the criteria for archiving and can therefore be suppressed
            $result[$task['dn']]['result'] = "User does not meet the criteria for archiving.";
            $this->gateway->removeSubTask($task['dn']);
            continue;
        }

        // Set the archive endpoint and method using the same WebServiceCall object
        $archiveUrl = $_ENV['FUSIONDIRECTORY_WEBSERVICE_URL'] . '/archive/user/' . rawurlencode($task['fdtasksgranulardn'][0]);
        $webServiceCall->setCurlSettings($archiveUrl, NULL, 'POST'); // Update settings for the archive request
        $response = $webServiceCall->execute();

        // Check if the HTTP status code is 204
        if ($webServiceCall->getHttpStatusCode() === 204) {
            $result[$task['dn']]['result'] = "User " . $task['fdtasksgranulardn'][0] . " successfully archived.";
            $this->gateway->updateTaskStatus($task['dn'], $task['cn'][0], '2', $mainTaskDn, $repeatableSchedule);
        } else {
            throw new Exception("Unexpected HTTP status code: " . $webServiceCall->getHttpStatusCode());
        }
      } catch (Exception $e) {
            $result[$task['dn']]['result'] = "Error archiving user: " . $e->getMessage();
            $this->gateway->updateTaskStatus($task['dn'], $task['cn'][0], $e->getMessage(), $mainTaskDn, $repeatableSchedule);
      }
    }

      return $result;
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
     * Retrieve the supannAccountStatus of a user
     * @param string $userDn
     * @return array
     */
  private function getUserSupannAccountStatus (string $userDn): array
  {
      return $this->gateway->getLdapTasks(
          '(objectClass=supannPerson)',
          ['supannRessourceEtatDate'],
          '',
          $userDn
      );
  }

    /**
     * @param string $taskDN
     * @return array
     * Note: Retrieve the desired supann status and repeatable schedule from the main task attributes.
     */
  private function getArchiveTaskBehaviorFromMainTask (string $taskDN): array
  {
      return $this->gateway->getLdapTasks(
          '(objectClass=*)',
          ['fdArchiveTaskResource', 'fdArchiveTaskState', 'fdArchiveTaskSubState', 'fdTasksRepeatableSchedule'],
          '',
          $taskDN
      );
  }

    /**
     * @param array $desiredStatus
     * @param array $currentStatus
     * @return bool
     * Note: Compare the desired supann status with the current status to determine if they match.
     */
  private function isSupannStatusMatching (array $desiredStatus, array $currentStatus): bool
  {
    if (empty($currentStatus[0]['supannressourceetatdate'])) {
        return FALSE;
    }

      // Extract the desired attributes
      $desiredAttributes = $this->extractDesiredAttributes($desiredStatus);

    if (!$desiredAttributes['resource'] || !$desiredAttributes['state']) {
        return FALSE;
    }

      // Check if any of the current supannressourceetatdate values match the desired attributes
    foreach ($currentStatus[0]['supannressourceetatdate'] as $key => $resource) {
      if (!is_numeric($key)) {
          continue;
      }

      if ($this->doesResourceMatch($resource, $desiredAttributes)) {
          return TRUE;
      }
    }

      return FALSE;
  }

  private function extractDesiredAttributes (array $desiredStatus): array
  {
      return [
          'resource' => $desiredStatus[0]['fdarchivetaskresource'][0] ?? NULL,
          'state'    => $desiredStatus[0]['fdarchivetaskstate'][0] ?? NULL,
          'substate' => $desiredStatus[0]['fdarchivetasksubstate'][0] ?? NULL,
      ];
  }

  private function doesResourceMatch (string $resource, array $desiredAttributes): bool
  {
      // Extract parts from the resource string
      $parts = explode(':', $resource);
      $resourcePart = str_replace(['{', '}'], '', $parts[0]);
      $substatePart = $parts[1] ?? '';

      $resourceMatch = $resourcePart === $desiredAttributes['resource'] . $desiredAttributes['state'];
      $substateMatch = empty($desiredAttributes['substate']) || $substatePart === $desiredAttributes['substate'];

      return $resourceMatch && $substateMatch;
  }
}
