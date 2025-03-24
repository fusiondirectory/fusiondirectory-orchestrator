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

    foreach ($archiveTasks as $task) {
      // Verify the task status and schedule
      if ($this->gateway->statusAndScheduleCheck($task)) {
        // Retrieve the user's supannAccountStatus
        $userStatus = $this->getUserSupannAccountStatus($task['fdtasksgranulardn'][0]);

        // Check if the user meets the "toBeArchived" condition
        if ($userStatus === 'toBeArchived') {
          // Construct the archive URL
          $archiveUrl = $_ENV['FUSION_DIRECTORY_API_URL'] . '/archive/user/' . rawurlencode($task['fdtasksgranulardn'][0]);

          // Initialize the WebServiceCall object
          $webServiceCall = new WebServiceCall($archiveUrl, 'POST');
          $webServiceCall->setCurlSettings();

          // Execute the request
          $response = curl_exec($webServiceCall->ch);

          // Handle any cURL errors
          $webServiceCall->handleCurlError($webServiceCall->ch);

          // Decode and process the response
          $responseData = json_decode($response, true);
          if ($responseData && isset($responseData['success']) && $responseData['success'] === true) {
            $result[$task['dn']]['result'] = "User successfully archived.";
            $this->gateway->updateTaskStatus($task['dn'], $task['cn'][0], '2'); // Mark task as completed
          } else {
            $result[$task['dn']]['result'] = "Error archiving user.";
            $this->gateway->updateTaskStatus($task['dn'], $task['cn'][0], '3'); // Mark task as failed
          }

          // Close the cURL resource
          curl_close($webServiceCall->ch);
        } else {
          $result[$task['dn']]['result'] = "User does not meet the criteria for archiving.";
        }
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
    // Logic to retrieve the supannAccountStatus attribute of the user
    $user = $this->gateway->getLdapEntry($userDn, ['supannAccountStatus']);
    return $user['supannAccountStatus'][0] ?? NULL;
  }
}