<?php

use FusionDirectory\Rest\WebServiceCall;

class Archive implements EndpointInterface
{
  private TaskGateway $gateway;

  public function __construct(TaskGateway $gateway)
  {
    $this->gateway = $gateway;
  }

  /**
   * @return array
   * Part of the interface of orchestrator plugin to treat GET method
   */
  public function processEndPointGet(): array
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
  public function processEndPointPatch(array $data = NULL): array
  {
    $result = [];
    $archiveTasks = $this->gateway->getObjectTypeTask('archive');

    // Initialize the WebServiceCall object for login
    $webServiceCall = new WebServiceCall($_ENV['FUSION_DIRECTORY_API_URL'] . '/login', 'POST');
    $webServiceCall->setCurlSettings(); // Perform login and set the token

    foreach ($archiveTasks as $task) {
        try {
            if (!$this->gateway->statusAndScheduleCheck($task)) {
                // Skip this task if it does not meet the status and schedule criteria
                continue;
            }

            // Receive null or 'toBeArchived'
            $supannState = $this->getUserSupannAccountStatus($task['fdtasksgranulardn'][0]);

            if ($supannState !== 'toBeArchived') {
                // The task does not meet the criteria for archiving and can therefore be suppressed
                $result[$task['dn']]['result'] = "User does not meet the criteria for archiving.";
                $this->gateway->removeSubTask($task['dn']);
                continue;
            }

            // Set the archive endpoint and method using the same WebServiceCall object
            $archiveUrl = $_ENV['FUSION_DIRECTORY_API_URL'] . '/archive/user/' . rawurlencode($task['fdtasksgranulardn'][0]);
            $webServiceCall->setCurlSettings($archiveUrl, [], 'POST'); // Update settings for the archive request
            $response = $webServiceCall->execute();

            print_r([$response]);
              exit;

            if (isset($response['success']) && $response['success'] === true) {
                $result[$task['dn']]['result'] = "User successfully archived.";
                $this->gateway->updateTaskStatus($task['dn'], $task['cn'][0], '2');
            } else {
                throw new Exception("Invalid API response format");
            }
        } catch (Exception $e) {
            $result[$task['dn']]['result'] = "Error archiving user: " . $e->getMessage();
            $this->gateway->updateTaskStatus($task['dn'], $task['cn'][0], $e->getMessage());
        }
    }

    return $result;
  }

  /**
   * @param array|null $data
   * @return array
   * Note: Part of the interface of orchestrator plugin to treat POST method
   */
  public function processEndPointPost(array $data = NULL): array
  {
    return [];
  }

  /**
   * @param array|null $data
   * @return array
   * Note: Part of the interface of orchestrator plugin to treat DELETE method
   */
  public function processEndPointDelete(array $data = NULL): array
  {
    return [];
  }

  /**
   * Retrieve the supannAccountStatus of a user
   * @param string $userDn
   * @return string|null
   */
  private function getUserSupannAccountStatus(string $userDn): ?string
  {
      $supannState = $this->gateway->getLdapTasks(
          '(objectClass=supannPerson)',
          ['supannRessourceEtatDate'],
          '',
          $userDn
      );
  
      if ($this->hasToBeArchived($supannState)) {
          return 'toBeArchived';
      }
  
      return null;
  }

  private function hasToBeArchived(array $supannState): bool
  {
      if (!isset($supannState[0]['supannressourceetatdate']) || !is_array($supannState[0]['supannressourceetatdate'])) {
          return false;
      }

      foreach ($supannState[0]['supannressourceetatdate'] as $key => $value) {
          // Skip non-numeric keys (e.g., 'count')
          if (!is_numeric($key)) {
              continue;
          }

          if (strpos($value, '{COMPTE}I:toBeArchived') !== false) {
              return true;
          }
      }

      return false;
  }
}