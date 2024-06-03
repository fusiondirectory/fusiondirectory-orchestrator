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
    echo json_encode('within lifeCycle patch');
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
    $webservice = new WebServiceCall($_ENV['FUSION_DIRECTORY_API_URL'] . '/login', 'POST');
    // Required to prepare future webservice call. E.g. Retrieval of mandatory token.
    $webservice->setCurlSettings();

    foreach ($list_tasks as $task) {
      // If the tasks must be treated - status and scheduled - process the sub-tasks
      if ($this->gateway->statusAndScheduleCheck($task)) {

        // Simply retrieve the lifeCycle behavior from the main related tasks, sending the dns and desired attributes
        $lifeCycleBehavior = $this->gateway->getLdapTasks('(objectClass=*)', ['fdTasksLifeCyclePreResource',
          'fdTasksLifeCyclePreState', 'fdTasksLifeCyclePreSubState',
          'fdTasksLifeCyclePostResource', 'fdTasksLifeCyclePostState', 'fdTasksLifeCyclePostSubState', 'fdTasksLifeCyclePostEndDate'],
                                                          '', $task['fdtasksgranularmaster'][0]);

        // Simply retrieve the current supannStatus of the user DN related to the task at hand.
        $currentUserLifeCycle = $this->gateway->getLdapTasks('(objectClass=supannPerson)', ['supannRessourceEtatDate'],
                                                             '', $task['fdtasksgranulardn'][0]);

        // Compare both the required schedule and the current user status - returning TRUE if modification is required.
        if ($this->isLifeCycleRequiringModification($lifeCycleBehavior, $currentUserLifeCycle)) {

          // This will call a method to modify the ressourcesSupannEtatDate of the DN linked to the subTask
          $lifeCycleResult = $this->updateLifeCycle($lifeCycleBehavior, $task['fdtasksgranulardn'][0]);
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
    // Perform the regular expression match
    preg_match($pattern, $currentUserLifeCycle[0]['supannressourceetatdate'][0], $matches);

    // Extracting values of current user
    $userSupann['Resource'] = $matches[1] ?? '';
    $userSupann['State']    = $matches[2] ?? '';
    $userSupann['SubState'] = $matches[3] ?? '';
    // Array index 4 is skipped, we only use end date to apply our life cycle logic. Start date has no use here.
    $userSupann['EndDate'] = $matches[5] ?? '';

    // Extracting values of desired pre-state behavior
    $preStateSupann['Resource'] = $lifeCycleBehavior[0]['fdtaskslifecyclepreresource'][0];
    $preStateSupann['State']    = $lifeCycleBehavior[0]['fdtaskslifecycleprestate'][0];
    $preStateSupann['SubState'] = $lifeCycleBehavior[0]['fdtaskslifecyclepresubstate'][0] ?? ''; //SubState is optional

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

    return $result;
  }

  /**
   * @param array $lifeCycleBehavior
   * @param string $userDN
   * @return bool|string
   */
  protected function updateLifeCycle (array $lifeCycleBehavior, string $userDN)
  {
    // Extracting values of desired post-state behavior
    $newEntry['Resource'] = $lifeCycleBehavior[0]['fdtaskslifecyclepostresource'][0];
    $newEntry['State']    = $lifeCycleBehavior[0]['fdtaskslifecyclepoststate'][0];
    $newEntry['SubState'] = $lifeCycleBehavior[0]['fdtaskslifecyclepostsubstate'][0] ?? ''; //SubState is optional
    $newEntry['EndDate']  = $lifeCycleBehavior[0]['fdtaskslifecyclepostenddate'][0] ?? 0; //EndDate is optional

    // Require the date of today to update the start of the new resources (If change of status).
    $currentDate = new DateTime();
    // Date of today + numbers of days to add for end date.
    $newEndDate = new DateTime();
    $newEndDate->modify("+" . $newEntry['EndDate'] . " days");

    // Prepare the ldap entry to be modified
    $ldapEntry                            = [];
    $ldapEntry['supannRessourceEtatDate'] = "{" . $newEntry['Resource'] . "}"
      . $newEntry['State'] . ":"
      . $newEntry['SubState'] . ":" .
      $currentDate->format('Ymd') . ":"
      . $newEndDate->format('Ymd');

    try {
      $result = ldap_modify($this->gateway->ds, $userDN, $ldapEntry);
    } catch (Exception $e) {
      $result = json_encode(["Ldap Error" => "$e"]);
    }

    return $result;
  }

}
