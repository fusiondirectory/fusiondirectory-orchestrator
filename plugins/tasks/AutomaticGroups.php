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
    // Check what type of task we need to process
    if (isset($data['type']) && $data['type'] === 'dynamic-group') {
      return $this->processDynamicGroupCreation($this->gateway->getObjectTypeTask('Dynamic-Group'));
    } else {
      return $this->processAutomaticGroups($this->gateway->getObjectTypeTask('Automatic Groups'));
    }
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
        $targetGroup   = $mainTaskConfig[0]['fdtasksautomaticgroupsofname'][0] ?? NULL;
        $resource      = $mainTaskConfig[0]['fdtasksautomaticgroupspreresource'][0] ?? NULL;
        $state         = $mainTaskConfig[0]['fdtasksautomaticgroupsprestate'][0] ?? NULL;
        $subState      = $mainTaskConfig[0]['fdtasksautomaticgroupspresubstate'][0] ?? NULL;
        $pattern       = $mainTaskConfig[0]['fdtasksautomaticgroupsregexpattern'][0] ?? NULL;
        $resultMessage = [];

        if (empty($targetGroup)) {
          throw new Exception("Missing target group in task configuration");
        }

        // Check if user meets the criteria (if resource/state specified)
        $shouldAddToGroup = FALSE;

        if ($resource !== 'NONE' && !empty($resource) && !empty($state)) {
          // If resource is a regex, we need to check against all resources
          if (isset($pattern)) {
            // Get all ressources
            $supannResources = $this->gateway->getLdapTasks('(objectClass=fdSupannRessource)', ['fdSupannRessourceName'], '', $_ENV["LDAP_BASE"]);

            // Need to unset to work for the foreach
            unset($supannResources['count']);
            foreach ($supannResources as $supannRessource) {
              if (@preg_match('/' . $pattern . '/', $supannRessource['fdsupannressourcename'][0])) {
                // Uppercase this time for ressource
                $resourceReplace = str_replace('REGEX', $supannRessource['fdsupannressourcename'][0], $resource);

                $userSupannState = $this->getUserSupannState($userDn);
                $shouldAddToGroup = $this->checkUserSupannState($userSupannState, $resourceReplace, $state, $subState);

                if ($shouldAddToGroup) {
                  // If one match then quit
                  break;
                }
              }
            }
          } else {
            $userSupannState = $this->getUserSupannState($userDn);
            $shouldAddToGroup = $this->checkUserSupannState($userSupannState, $resource, $state, $subState);
          }
          $resultMessage = $this->manageGroup($shouldAddToGroup, $userDn, $targetGroup);
        }

        // Update task status
        $result[$task['dn']]['result'] = implode(PHP_EOL, $resultMessage);
        $this->gateway->updateTaskStatus($task['dn'], $task['cn'][0], '2');
      } catch (Exception $e) {
        $result[$task['dn']]['result'] = "Error processing task: " . $e->getMessage();
        $this->gateway->updateTaskStatus($task['dn'], $task['cn'][0], $e->getMessage());
      }
    }

    return $result;
  }

  private function manageGroup (bool $shouldAddToGroup, string $userDn, string $targetGroup)
  {
      if ($shouldAddToGroup) {
          $this->addUserToGroup($userDn, $targetGroup);
          $resultMessage[] = "User $userDn successfully added to group $targetGroup";
      } else {
          $this->removeUserFromGroup($userDn, $targetGroup);
          $resultMessage[] = "User $userDn doesn't meet criteria - removed from group $targetGroup";
      }
      return $resultMessage;
  }

  /**
   * Process dynamic group creation tasks
   *
   * @param array $dynamicGroupTasks
   * @return array
   * @throws Exception
   */
  public function processDynamicGroupCreation (array $dynamicGroupTasks): array
  {
    $result = [];

    if (empty($dynamicGroupTasks)) {
      return ['No dynamic group tasks require processing.'];
    }

    foreach ($dynamicGroupTasks as $task) {
      try {
        // Check if task should be processed (status and schedule)
        if (!$this->gateway->statusAndScheduleCheck($task)) {
          continue;
        }

        // Get main task configuration
        $mainTaskConfig = $this->getAutomaticGroupsMainTask($task['fdtasksgranularmaster'][0]);

        // Get pre-computed values for dynamic group
        $dynamicURL    = $mainTaskConfig[0]['fdtasksautomaticgroupsdynamicurl'][0] ?? NULL;
        $dynamicName   = $mainTaskConfig[0]['fdtasksautomaticgroupsdynamicname'][0] ?? NULL;
        $pattern       = $mainTaskConfig[0]['fdtasksautomaticgroupsregexpattern'][0] ?? NULL;
        $resultMessage = [];

        // Create the dynamic group using pre-computed or generated values
        if (isset($pattern)) {
          // Get all ressources
          $supannResources = $this->gateway->getLdapTasks('(objectClass=fdSupannRessource)', ['fdSupannRessourceName'], '', $_ENV["LDAP_BASE"]);

          // Need to unset to work for the foreach
          unset($supannResources['count']);
          foreach ($supannResources as $supannRessource) {
            if (@preg_match('/' . $pattern . '/', $supannRessource['fdsupannressourcename'][0])) {
              // lowercase for ressource in the name but uppercase for the URL
              $dynamicNameReplace = str_replace('regex', strToLower($supannRessource['fdsupannressourcename'][0]), $dynamicName);
              $dynamicURLReplace  = str_replace('REGEX', $supannRessource['fdsupannressourcename'][0], $dynamicURL);
              $this->createDynamicGroup($dynamicNameReplace, $dynamicURLReplace);
              $resultMessage[] = "Successfully created dynamic group '$dynamicNameReplace' with URL: $dynamicURLReplace";
            }
          }
        } else {
          $this->createDynamicGroup($dynamicName, $dynamicURL);
          $resultMessage[] = "Successfully created dynamic group '$dynamicName' with URL: $dynamicURL";
        }

        // Update task status
        $result[$task['dn']]['result'] = implode(PHP_EOL, $resultMessage);
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
        'fdTasksAutomaticGroupsPreResource',
        'fdTasksAutomaticGroupsPreState',
        'fdTasksAutomaticGroupsPreSubState',
        'fdTasksAutomaticGroupsDynamicGroup',
        'fdTasksAutomaticGroupsDynamicURL',
        'fdTasksAutomaticGroupsDynamicName',
        'fdtasksautomaticgroupsregexpattern',
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
        $expectedState = '{' . $resource . '}' . $state;
      if (!empty($subState)) {
        $expectedState = $expectedState . ':' . $subState;
      }

      if ($value === $expectedState) {
        return TRUE;
      }
    }

    return FALSE;
  }

  private function getGroupMembers (string $groupDn): array
  {
    $groupInfo = $this->gateway->getLdapTasks(
      '(objectClass=groupOfNames)',
      ['member'],
      '',
      $groupDn
    );
    $this->gateway->unsetCountKeys($groupInfo);
    return $groupInfo[0]['member'] ?? [];
  }

  /**
   * @throws Exception
  */
  private function updateLdap (string $groupDn, array $entry, string $message, string $userDn = ''): bool
  {
    // Update the group in LDAP
    try {
      if ($message === "create") {
          $result = ldap_add($this->gateway->ds, $groupDn, $entry);
      } else {
          $result = ldap_modify($this->gateway->ds, $groupDn, $entry);
      }
      if (!$result) {
        throw new Exception($this->getFailedMessage($userDn, $message, $groupDn) . ldap_error($this->gateway->ds));
      }
      return TRUE;
    } catch (Exception $e) {
      throw new Exception($this->getErrorMessage($message) . $e->getMessage());
    }
  }

  private function getFailedMessage(string $userDn, string $message, string $groupDn): string
  {
      return match ($message) {
          'create' => "Failed to create dynamic group: ",
          'add' => "Failed to add $userDn to group $groupDn: ",
          default => "Failed to remove $userDn to group $groupDn: ",
      };
  }

    private function getErrorMessage(string $message): string
    {
        return match ($message) {
            'create' => "Error creating dynamic group: ",
            'add' => "Error adding member to group: ",
            default => "Error removing member to group: ",
        };
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
    $members = $this->getGroupMembers($groupDn);

    // If member is already in the group, nothing to do
    if (in_array($userDn, $members)) {
      return TRUE;
    }

    // Add member to the group
    $members[] = $userDn;
    $entry = ['member' => $members];

    return $this->updateLdap($groupDn, $entry, "add", $userDn);
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
    $members = $this->getGroupMembers($groupDn);

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

    return $this->updateLdap($groupDn, $entry, "remove", $userDn);
  }

  /**
   * Create a dynamic group in LDAP
   *
   * @param string $groupName The name of the dynamic group
   * @param string $ldapUrl The LDAP URL for the dynamic group filter
   * @return bool True on success
   * @throws Exception On failure
   */
  private function createDynamicGroup (string $groupName, string $ldapUrl): bool
  {
    if (empty($groupName) || empty($ldapUrl)) {
        throw new Exception("Missing required parameters for dynamic group creation");
    }

    // Get base DN from environment variables
    $baseDN   = $_ENV["LDAP_BASE"];
    $groupDN  = "cn=$groupName,ou=groups,$baseDN";

    // Check if the group already exists
    $existingGroup = $this->gateway->getLdapTasks(
        "(cn=$groupName)",
        ['cn', 'objectClass', 'memberURL'],
        NULL,
        "ou=groups,$baseDN"
    );

    // If group exists, mark as success but don't modify it
    if (!empty($existingGroup) && isset($existingGroup[0]['cn'])) {
        return TRUE; // Group already exists, return success without modifying
    }

    // Group doesn't exist, create it
    $description = "Dynamic group for " . str_replace('dynamic-', '', $groupName);

    $entry = [
        'objectClass' => ['groupOfURLs', 'extensibleObject'],
        'cn' => $groupName,
        'memberURL' => $ldapUrl,
        'description' => $description
    ];

    return $this->updateLdap($groupDN, $entry, "create");
  }
}
