<?php

class AutomaticGroups implements EndpointInterface
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
    return $this->gateway->getObjectTypeTask('Automatic Groups');
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
   * @param array|null $data
   * @return array
   * @throws Exception
   */
  public function processEndPointPatch (array $data = NULL): array
  {
    return $this->processAutomaticGroups($this->gateway->getObjectTypeTask('Automatic Groups'));
  }

  /**
   * @param array|null $data
   * @return array
   */
  public function processEndPointDelete (array $data = NULL): array
  {
    return [];
  }

  /**
   * Process automatic group assignment tasks
   *
   * @param array $automaticGroupsTasks
   * @return array
   * @throws Exception
   */
  public function processAutomaticGroups (array $automaticGroupsTasks): array
  {
    $result = [];

    if (empty($automaticGroupsTasks)) {
      return ['No automatic groups tasks require processing.'];
    }

    foreach ($automaticGroupsTasks as $task) {
      try {
        // Check if task should be processed (status and schedule)
        if (!$this->gateway->statusAndScheduleCheck($task)) {
          continue;
        }

        // Get the DN of the user/group to process
        $userDn = $task['fdtasksgranulardn'][0] ?? NULL;
        if (empty($userDn)) {
          throw new Exception("Missing user DN in task");
        }

        // Get main task configuration
        $mainTaskConfig = $this->getAutomaticGroupsMainTask($task['fdtasksgranularmaster'][0]);

        // Get target group and resource/state criteria
        $targetGroup  = $mainTaskConfig[0]['fdtasksautomaticgroupsofname'][0] ?? NULL;
        $resource     = $mainTaskConfig[0]['fdtasksautomaticgroupsresource'][0] ?? NULL;
        $state        = $mainTaskConfig[0]['fdtasksautomaticgroupsstate'][0] ?? NULL;
        $subState     = $mainTaskConfig[0]['fdtasksautomaticgroupssubstate'][0] ?? NULL;

        if (empty($targetGroup)) {
          throw new Exception("Missing target group in task configuration");
        }

        // Check if user meets the criteria (if resource/state specified)
        $shouldAddToGroup = TRUE;
        if ($resource !== 'NONE' && !empty($resource) && !empty($state)) {
          $userSupannState = $this->getUserSupannState($userDn);
          $shouldAddToGroup = $this->checkUserSupannState($userSupannState, $resource, $state, $subState);
        }

        // Add/remove user from group based on criteria
        if ($shouldAddToGroup) {
          $this->addUserToGroup($userDn, $targetGroup);
          $result[$task['dn']]['result'] = "User $userDn successfully added to group $targetGroup";
        } else {
          $this->removeUserFromGroup($userDn, $targetGroup);
          $result[$task['dn']]['result'] = "User $userDn doesn't meet criteria - removed from group $targetGroup";
        }

        // Update task status
        $this->gateway->updateTaskStatus($task['dn'], $task['cn'][0], '2');
      } catch (Exception $e) {
        $result[$task['dn']]['result'] = "Error processing task: " . $e->getMessage();
        $this->gateway->updateTaskStatus($task['dn'], $task['cn'][0], $e->getMessage());
      }
    }

    return $result;
  }

  /**
   * Get main task configuration
   *
   * @param string $mainTaskDn
   * @return array
   */
  private function getAutomaticGroupsMainTask (string $mainTaskDn): array
  {
    return $this->gateway->getLdapTasks(
      '(objectClass=fdTasksAutomaticGroups)',
      [
        'fdTasksAutomaticGroupsOfName',
        'fdTasksAutomaticGroupsResource',
        'fdTasksAutomaticGroupsState',
        'fdTasksAutomaticGroupsSubState'
      ],
      '',
      $mainTaskDn
    );
  }

  /**
   * Get user's Supann state
   *
   * @param string $userDn
   * @return array
   */
  private function getUserSupannState (string $userDn): array
  {
    $result = $this->gateway->getLdapTasks(
      '(objectClass=*)',
      ['supannRessourceEtat'],
      '',
      $userDn
    );

    $this->gateway->unsetCountKeys($result);
    return $result;
  }

  /**
   * Check if user matches the required Supann state
   *
   * @param array $userSupannState
   * @param string $resource
   * @param string $state
   * @param string|null $subState
   * @return bool
   */
  private function checkUserSupannState (array $userSupannState, string $resource, string $state, ?string $subState): bool
  {
    if (empty($userSupannState[0]['supannressourceetat'])) {
      return FALSE;
    }

    foreach ($userSupannState[0]['supannressourceetat'] as $value) {
      // Create the expected format for comparison
      $expectedState = '';
      if (!empty($subState)) {
        $expectedState = '{' . $resource . '}' . $state . ':' . $subState;
      } else {
        $expectedState = '{' . $resource . '}' . $state;
      }

      if ($value === $expectedState) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Add user to LDAP group
   *
   * @param string $userDn
   * @param string $groupDn
   * @return bool
   * @throws Exception
   */
  private function addUserToGroup (string $userDn, string $groupDn): bool
  {
    // Get current group members
    $groupInfo = $this->gateway->getLdapTasks(
      '(objectClass=groupOfNames)',
      ['member'],
      '',
      $groupDn
    );

    $this->gateway->unsetCountKeys($groupInfo);
    $members = $groupInfo[0]['member'] ?? [];

    // If member is already in the group, nothing to do
    if (in_array($userDn, $members)) {
      return TRUE;
    }

    // Add member to the group
    $members[] = $userDn;
    $entry = ['member' => $members];

    // Update the group in LDAP
    try {
      $result = ldap_modify($this->gateway->ds, $groupDn, $entry);
      if (!$result) {
        throw new Exception("Failed to add $userDn to group $groupDn: " . ldap_error($this->gateway->ds));
      }
      return TRUE;
    } catch (Exception $e) {
      throw new Exception("Error adding member to group: " . $e->getMessage());
    }
  }

  /**
   * Remove user from LDAP group
   *
   * @param string $userDn
   * @param string $groupDn
   * @return bool
   * @throws Exception
   */
  private function removeUserFromGroup (string $userDn, string $groupDn): bool
  {
    // Get current group members
    $groupInfo = $this->gateway->getLdapTasks(
      '(objectClass=groupOfNames)',
      ['member'],
      '',
      $groupDn
    );

    $this->gateway->unsetCountKeys($groupInfo);
    $members = $groupInfo[0]['member'] ?? [];

    // If member is not in the group, nothing to do
    if (!in_array($userDn, $members)) {
      return TRUE;
    }

    // Remove member from the group
    $members = array_diff($members, [$userDn]);

    // Groups must have at least one member, so check if this would empty the group
    if (empty($members)) {
      return TRUE; // Do nothing if it would empty the group
    }

    $entry = ['member' => $members];

    // Update the group in LDAP
    try {
      $result = ldap_modify($this->gateway->ds, $groupDn, $entry);
      if (!$result) {
        throw new Exception("Failed to remove $userDn from group $groupDn: " . ldap_error($this->gateway->ds));
      }
      return TRUE;
    } catch (Exception $e) {
      throw new Exception("Error removing member from group: " . $e->getMessage());
    }
  }
}