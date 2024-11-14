<?php


class LifeCycle implements EndpointInterface
{
  private TaskGateway $gateway;

  function __construct (TaskGateway $gateway)
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
   * Note : Part of the interface of orchestrator plugin to treat POST method
   */
  public function processEndPointPost (array $data = NULL): array
  {
    return [];
  }

  /**
   * @param array|null $data
   * @return array
   * Note : Part of the interface of orchestrator plugin to treat DELETE method
   */
  public function processEndPointDelete (array $data = NULL): array
  {
    return [];
  }

  /**
   * @param array|null $data
   * @return array
   * @throws Exception
   * Note : Part of the interface of orchestrator plugin to treat PATCH method
   */
  public function processEndPointPatch (array $data = NULL): array
  {
    return $this->processLifeCycleTasks($this->gateway->getObjectTypeTask('lifeCycle'));
  }

  /**
   * @param array $list_tasks
   * @return array[]|string[]
   * @throws Exception
   * Note : Verify the status and schedule as well as searching for the correct life cycle behavior from main task.
   */
  public function processLifeCycleTasks (array $list_tasks): array
  {
    // Array representing the status of the subtask.
    $result = [];
    // Initiate the object webservice.
    $webservice = new FusionDirectory\Rest\WebServiceCall($_ENV['FUSION_DIRECTORY_API_URL'] . '/login', 'POST');
    // Required to prepare future webservice call. E.g. Retrieval of mandatory token.
    $webservice->setCurlSettings();

    foreach ($list_tasks as $task) {
      // If the tasks must be treated - status and scheduled - process the sub-tasks
      if ($this->gateway->statusAndScheduleCheck($task)) {

        // Simply retrieve the lifeCycle behavior from the main related tasks, sending the dns and desired attributes
        $lifeCycleBehavior = $this->getLifeCycleBehaviorFromMainTask($task['fdtasksgranularmaster'][0]);

        // Simply retrieve the current supannStatus of the user DN related to the task at hand.
        $currentUserLifeCycle = $this->getUserSupannHistory($task['fdtasksgranulardn'][0]);

        // Compare both the required schedule and the current user status - returning TRUE if modification is required.
        if ($this->isLifeCycleRequiringModification($lifeCycleBehavior, $currentUserLifeCycle)) {

          // This will call a method to modify the ressourcesSupannEtatDate of the DN linked to the subTask
          $lifeCycleResult = $this->updateLifeCycle($lifeCycleBehavior, $task['fdtasksgranulardn'][0], $currentUserLifeCycle);

          if ($lifeCycleResult === TRUE) {

            $result[$task['dn']]['results'] = json_encode("Account states have been successfully modified for " . $task['fdtasksgranulardn'][0]);
            // Status of the task must be updated to success
            $updateResult = $this->gateway->updateTaskStatus($task['dn'], $task['cn'][0], '2');

            // Here the user is refresh in order to activate methods based on supann Status changes.
            $result[$task['dn']]['refreshUser'] = $webservice->refreshUserInfo($task['fdtasksgranulardn'][0]);

            // In case the modification failed
          } else {
            $result[$task['dn']]['results'] = json_encode("Error updating " . $task['fdtasksgranulardn'][0] . "-" . $lifeCycleResult);
            // Update of the task status error message
            $updateResult = $this->gateway->updateTaskStatus($task['dn'], $task['cn'][0], $lifeCycleResult);
          }
          // Verification if the sub-task status has been updated correctly
          if ($updateResult === TRUE) {
            $result[$task['dn']]['statusUpdate'] = 'Success';
          } else {
            $result[$task['dn']]['statusUpdate'] = $updateResult;
          }
          // Remove the subtask has it is not required to update it nor to process it.
        } else {
          $result[$task['dn']]['results']      = 'Sub-task removed for : ' . $task['fdtasksgranulardn'][0] . ' with result : '
            . $this->gateway->removeSubTask($task['dn']);
          $result[$task['dn']]['statusUpdate'] = 'No updates required, sub-task will be removed.';
        }
      }
    }
    // If array is empty, no tasks of type life cycle needs to be treated.
    if (empty($result)) {
      $result = 'No tasks of type "Life Cycle" requires processing.';
    }
    return [$result];
  }

  /**
   * @param array $lifeCycleBehavior
   * @param array $currentUserLifeCycle
   * @return bool
   * Note receive the life cycle behavior desired and compare it the received current user life cycle, returning TRUE
   * if there is indeed a difference and therefore must update the user information.
   * In case the comparison is impossible due to the use not having a status listed, it will report false.
   */
  protected function isLifeCycleRequiringModification (array $lifeCycleBehavior, array $currentUserLifeCycle): bool
  {
    $result = FALSE;
    // Regular expression in order to extract the supann format within an array
    $pattern = '/\{(\w+)\}(\w):([^:]*)(?::([^:]*))?(?::([^:]*))?(?::([^:]*))?/';

    // In case the tasks is launched without supann being activated on the user account, return error
    if (empty($currentUserLifeCycle[0]['supannressourceetatdate'][0])) {
      return FALSE;
    }

    // Extracting values of desired pre-state behavior
    $preStateSupann['Resource'] = $lifeCycleBehavior[0]['fdtaskslifecyclepreresource'][0];
    $preStateSupann['State']    = $lifeCycleBehavior[0]['fdtaskslifecycleprestate'][0];
    $preStateSupann['SubState'] = $lifeCycleBehavior[0]['fdtaskslifecyclepresubstate'][0] ?? ''; //SubState is optional

    // Iteration of all potential existing supann states of the user in order to find a match
    foreach ($currentUserLifeCycle[0]['supannressourceetatdate'] as $resource) {
      // Perform the regular expression match
      preg_match($pattern, $resource, $matches);

      // Extracting values of current user
      $userSupann['Resource'] = $matches[1] ?? '';
      $userSupann['State']    = $matches[2] ?? '';
      $userSupann['SubState'] = $matches[3] ?? '';
      // Array index 4 is skipped, we only use end date to apply our life cycle logic. Start date has no use here.
      $userSupann['EndDate'] = $matches[5] ?? '';

      //  Verifying if the user end date for selected resource is overdue
      if (!empty($userSupann['EndDate']) && strtotime($userSupann['EndDate']) <= time()) {
        // Comparing value in a nesting conditions
        if ($userSupann['Resource'] == $preStateSupann['Resource']) {
          if ($userSupann['State'] == $preStateSupann['State']) {
            // as SubState is optional, if both resource and state match at this point, modification is allowed.
            if (empty($preStateSupann['SubState'])) {
              $result = TRUE;
            } else if ($preStateSupann['SubState'] == $userSupann['SubState']) {
              $result = TRUE;
            }
          }
        }
      }
    }

    return $result;
  }

  /**
   * @param array $lifeCycleBehavior
   * @param string $userDN
   * @param array $currentUserLifeCycle
   * @return bool|string
   * Note receive the required behavior and the previous list of supann state to update in LDAP.
   */
  protected function updateLifeCycle (array $lifeCycleBehavior, string $userDN, array $currentUserLifeCycle)
  {
    // Init return value
    $result = '';
    // Hosting the final entry of supann attributes to be pushed to LDAP
    $ldapEntry = [];

    // Only keep the supann state from the received array and removing the count key
    $userStateHistory = $currentUserLifeCycle[0]['supannressourceetatdate'];
    $this->gateway->unsetCountKeys($userStateHistory);

    // Extracting values of desired post-state behavior
    $newEntry = $this->prepareNewEntry($lifeCycleBehavior[0]);

    // Create the new resource without start / end date
    $newResource = "{" . $newEntry['Resource'] . "}" . $newEntry['State'] . ":" . $newEntry['SubState'];
    // Get the resource name, it will be used to compare if the resource exists in history
    $newResourceName = $this->returnSupannResourceBetweenBrackets($newResource);

    // Find a matching resource in the user state history
    $matchedResource = $this->findMatchedResource($userStateHistory, $newResourceName);
    if ($matchedResource) {

      // Fetch the end date of the matched resource.
      $currentEndDate = $this->extractCurrentEndDate($matchedResource);
      // Create a DateTime object from the string
      $currentEndDateObject = DateTime::createFromFormat("Ymd", $currentEndDate);
      $currentEndDateObject->modify("+" . $newEntry['EndDate'] . " days");
      $finalRessourceEtatDate = $newResource . ':' . $currentEndDate . ':' . $currentEndDateObject->format('Ymd');

      // Iterate again through the supann state and get a match
      foreach ($userStateHistory as $userState => $value) {
        // Extract resource in curly braces (brackets) from the current supannRessourceEtatDate
        $currentResource = $this->returnSupannResourceBetweenBrackets($value);

        // Get the resource matched
        if ($currentResource === $newResourceName) {
          $userStateHistory[$userState] = $finalRessourceEtatDate;
          break;
        }
      }

      // Creation of the ldap entry
      $ldapEntry['supannRessourceEtatDate'] = $userStateHistory;
      try {
        $result = ldap_modify($this->gateway->ds, $userDN, $ldapEntry);
      } catch (Exception $e) {
        $result = json_encode(["Ldap Error" => "$e"]);
      }
    }
    return $result;
  }

  /**
   * @param array $userStateHistory
   * @param string $newResourceName
   * @return string|null
   * Note : Simple helper method to return the matched resource.
   */
  private function findMatchedResource (array $userStateHistory, string $newResourceName): ?string
  {
    foreach ($userStateHistory as $value) {
      if ($this->returnSupannResourceBetweenBrackets($value) === $newResourceName) {
        return $value;
      }
    }
    return NULL;
  }

  /**
   * @param array $lifeCycleBehavior
   * @return array
   * Simple helper method for readiness.
   */
  private function prepareNewEntry (array $lifeCycleBehavior): array
  {
    return [
      'Resource' => $lifeCycleBehavior['fdtaskslifecyclepostresource'][0],
      'State'    => $lifeCycleBehavior['fdtaskslifecyclepoststate'][0],
      'SubState' => $lifeCycleBehavior['fdtaskslifecyclepostsubstate'][0] ?? '',
      'EndDate'  => $lifeCycleBehavior['fdtaskslifecyclepostenddate'][0] ?? 0,
    ];
  }

  /**
   * @param string|null $matchedResource
   * @return string
   * Note : Simply return the end date of a supann ressource etat date
   */
  private function extractCurrentEndDate (?string $matchedResource): string
  {
    $parts = explode(":", $matchedResource);
    // Get the last element, which is the date
    return end($parts);
  }

  /**
   * @param string $supannRessourceEtatDate
   * @return string|null
   * Note : Simple method to return the content between {} of a supannRessourceEtatDate.
   */
  private function returnSupannResourceBetweenBrackets (string $supannRessourceEtatDate): ?string
  {
    preg_match('/\{(.*?)\}/', $supannRessourceEtatDate, $matches);
    return $matches[1] ?? NULL;
  }

  /**
   * @param string $taskDN
   * @return array
   * Note : Simply return attributes from main task, here supann desired behavior
   */
  private function getLifeCycleBehaviorFromMainTask (string $taskDN): array
  {
    return $this->gateway->getLdapTasks('(objectClass=*)', ['fdTasksLifeCyclePreResource',
      'fdTasksLifeCyclePreState', 'fdTasksLifeCyclePreSubState',
      'fdTasksLifeCyclePostResource', 'fdTasksLifeCyclePostState', 'fdTasksLifeCyclePostSubState', 'fdTasksLifeCyclePostEndDate'],
                                        '', $taskDN);
  }

  /**
   * @param $userDN
   * @return array
   * Note : simply return the current values of supannRessourceEtatDate of the specified user.
   */
  private function getUserSupannHistory ($userDN): array
  {
    return $this->gateway->getLdapTasks('(objectClass=supannPerson)', ['supannRessourceEtatDate'],
                                        '', $userDN);
  }

}