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
   * @param string $taskDN
   * @return array
   * Note : Simply return attributes from main task, here supann desired behavior
   */
  private function getLifeCycleBehaviorFromMainTask (string $taskDN): array
  {
    return $this->gateway->getLdapTasks('(objectClass=*)', ['fdTasksLifeCyclePreResource',
      'fdTasksLifeCyclePreState', 'fdTasksLifeCyclePreSubState',
      'fdTasksLifeCyclePostResource', 'fdTasksLifeCyclePostState', 'fdTasksLifeCyclePostSubState', 'fdTasksLifeCyclePostEndDate',
      'fdTasksLifeCycleRegexPattern', 'fdTasksLifeCycleEnableAccountClosure'],
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

  /**
   * @param array $lifeCycleBehavior
   * @param array $currentUserLifeCycle
   * @return bool
   * Note: Check if account closure conditions are met
   */
  protected function shouldProcessAccountClosure(array $lifeCycleBehavior, array $currentUserLifeCycle): bool
  {
    // Check if account closure is enabled
    $enableAccountClosure = ($lifeCycleBehavior[0]['fdtaskslifecycleenableaccountclosure'][0] ?? 'FALSE') === 'TRUE';
    
    if (!$enableAccountClosure) {
      return false;
    }
    
    // The rest of the logic will be handled in processAccountClosure
    return true;
  }

  /**
   * @param array $lifeCycleBehavior
   * @param string $userDNprocessAccountClosure
   * @param array $currentUserLifeCycle
   * @return bool|string
   * Note: Process account closure if enabled and conditions are met
   */
  protected function processAccountClosure (array $lifeCycleBehavior, string $userDN, array $currentUserLifeCycle)
  {
    $pattern = '/\{(\w+)\}(\w):([^:]*)(?::([^:]*))?(?::([^:]*))?(?::([^:]*))?/';
    $userStateHistory = $currentUserLifeCycle[0]['supannressourceetatdate'] ?? [];
    $this->gateway->unsetCountKeys($userStateHistory);

    // Get task parameters
    $taskPreResourceRaw = $lifeCycleBehavior[0]['fdtaskslifecyclepreresource'][0] ?? '';
    $taskPreState = $lifeCycleBehavior[0]['fdtaskslifecycleprestate'][0] ?? '';
    $taskPreSubState = $lifeCycleBehavior[0]['fdtaskslifecyclepresubstate'][0] ?? '';
    $regexPattern = $lifeCycleBehavior[0]['fdtaskslifecycleregexpattern'][0] ?? NULL;
    $preResourceIsRegex = ($taskPreResourceRaw === 'REGEX');

    // Find matching resources
    $matchingResources = [];
    $hasActiveResource = false;

    foreach ($userStateHistory as $resourceString) {
      preg_match($pattern, $resourceString, $matches);

      $resourceName = $matches[1] ?? '';
      $resourceState = $matches[2] ?? '';
      
      // Skip if there's no resource name or state
      if (empty($resourceName) || empty($resourceState)) {
        continue;
      }

      // Check if this resource matches our criteria
      $isMatched = false;
      if ($preResourceIsRegex) {
        if ($regexPattern && @preg_match('/' . $regexPattern . '/', $resourceName)) {
          $isMatched = true;
        }
      } else {
        if ($resourceName === $taskPreResourceRaw) {
          $isMatched = true;
        }
      }

      if ($isMatched) {
        $matchingResources[] = [
          'name' => $resourceName,
          'state' => $resourceState,
        ];
        if ($resourceState === 'A') {
          $hasActiveResource = true;
        }
      }
    }

    // If we have matching resources and none are active, lock the account
    if (!empty($matchingResources) && !$hasActiveResource) {
      // Find the ACCOUNT resource to update
      $accountResourceFound = false;
      $updatedStateHistory = $userStateHistory;
      
      for ($i = 0; $i < count($userStateHistory); $i++) {
        $resourceString = $userStateHistory[$i];
        preg_match($pattern, $resourceString, $matches);
        
        $resourceName = $matches[1] ?? '';
        $resourceState = $matches[2] ?? '';
        $resourceSubState = $matches[3] ?? '';
        $startDate = $matches[4] ?? '';
        $endDate = $matches[5] ?? '';
        
        if ($resourceName === 'COMPTE') {
          // Set ACCOUNT resource to inactive (I)
          $newResourceString = "{COMPTE}I:"; // Empty substate

          // If start date exists, preserve it, otherwise use today's date
          if (!empty($startDate)) {
            $newResourceString .= ":" . $startDate;
            // If end date exists, preserve it
            if (!empty($endDate)) {
              $newResourceString .= ":" . $endDate;
            }
          } else {
            // No dates exist, use today's date for both start and end date
            $todayDate = date('Ymd');
            $newResourceString .= ":" . $todayDate . ":" . $todayDate;
          }
          
          $updatedStateHistory[$i] = $newResourceString;
          $accountResourceFound = true;
          break;
        }
      }
      
      // If no ACCOUNT resource found.
      if (!$accountResourceFound) {
        return "No ACCOUNT resource found to deactivate";
      }
      
      // Update LDAP with the modified state history
      $ldapEntry = ['supannRessourceEtatDate' => $updatedStateHistory];
      
      try {
        $op_result = ldap_modify($this->gateway->ds, $userDN, $ldapEntry);
        if ($op_result) {
          return "ACCOUNT_CLOSURE_APPLIED"; // Successfully applied changes
        } else {
          return "LDAP modification failed"; 
        }
      } catch (Exception $e) {
        return "Ldap Error: " . $e->getMessage();
      }
    } else if (empty($matchingResources)) {
      return "NO_MATCHING_RESOURCES"; // No resources match the criteria
    } else {
      return "NO_CLOSURE_NEEDED"; // Has active resources, no need to close
    }
  }

  /**
   * @param array $list_tasks
   * @return array[]|string[]
   * @throws Exception
   * Note : Verify the status and schedule as well as searching for the correct life cycle behavior from main task.
   */
  public function processLifeCycleTasks (array $list_tasks): array
  {
    $result = [];
    $webservice = new FusionDirectory\Rest\WebServiceCall($_ENV['FUSIONDIRECTORY_WEBSERVICE_URL'] . '/login', 'POST');
    // Required to prepare future webservice call. E.g. Retrieval of mandatory token.
    $webservice->setCurlSettings();

    foreach ($list_tasks as $task) {
      // If the tasks must be treated - status and scheduled - process the sub-tasks
      if ($this->gateway->statusAndScheduleCheck($task)) {

        // Simply retrieve the lifeCycle behavior from the main related tasks
        $lifeCycleBehavior = $this->getLifeCycleBehaviorFromMainTask($task['fdtasksgranularmaster'][0]);

        // Simply retrieve the current supannStatus of the user DN related to the task at hand
        $currentUserLifeCycle = $this->getUserSupannHistory($task['fdtasksgranulardn'][0]);

        // Check if we should process account closure or normal lifecycle changes
        $isAccountClosureEnabled = $this->shouldProcessAccountClosure($lifeCycleBehavior, $currentUserLifeCycle);
        
        if ($isAccountClosureEnabled) {
          // Process account closure
          $lifeCycleResult = $this->processAccountClosure($lifeCycleBehavior, $task['fdtasksgranulardn'][0], $currentUserLifeCycle);
          
          if ($lifeCycleResult === "ACCOUNT_CLOSURE_APPLIED") {
            $result[$task['dn']]['results'] = json_encode("Account closure processed successfully for " . $task['fdtasksgranulardn'][0]);
            // Status of the task must be updated to success
            $updateResult = $this->gateway->updateTaskStatus($task['dn'], $task['cn'][0], '2');
            // Here the user is refresh in order to activate methods based on supann Status changes.
            $result[$task['dn']]['refreshUser'] = $webservice->refreshUserInfo($task['fdtasksgranulardn'][0]);
          } 
          else if ($lifeCycleResult === "NO_MATCHING_RESOURCES") {
            $result[$task['dn']]['results'] = json_encode("No matching resources found for " . $task['fdtasksgranulardn'][0] . " - nothing to process");
            // The task is still considered "complete" as we checked what we needed to
            $updateResult = $this->gateway->updateTaskStatus($task['dn'], $task['cn'][0], '2');
          }
          else if ($lifeCycleResult === "NO_CLOSURE_NEEDED") {
            $result[$task['dn']]['results'] = json_encode("Account closure not needed for " . $task['fdtasksgranulardn'][0] . " - user has active resources");
            // The task is still considered "complete" as we checked what we needed to
            $updateResult = $this->gateway->updateTaskStatus($task['dn'], $task['cn'][0], '2');
          }
          else {
            // In case the modification failed
            $result[$task['dn']]['results'] = json_encode("Error processing account closure for " . $task['fdtasksgranulardn'][0] . " - " . $lifeCycleResult);
            // Update of the task status error message
            $updateResult = $this->gateway->updateTaskStatus($task['dn'], $task['cn'][0], $lifeCycleResult);
          }
        } else {
          // Compare both the required schedule and the current user status - returning TRUE if modification is required
          if ($this->isLifeCycleRequiringModification($lifeCycleBehavior, $currentUserLifeCycle)) {
            // This will call a method to modify the ressourcesSupannEtatDate of the DN linked to the subTask
            $lifeCycleResult = $this->updateLifeCycle($lifeCycleBehavior, $task['fdtasksgranulardn'][0], $currentUserLifeCycle);

            if ($lifeCycleResult === TRUE) {
              $result[$task['dn']]['results'] = json_encode("Account states have been successfully modified for " . $task['fdtasksgranulardn'][0]);
              // Status of the task must be updated to success
              $updateResult = $this->gateway->updateTaskStatus($task['dn'], $task['cn'][0], '2');
              // Here the user is refresh in order to activate methods based on supann Status changes.
              $result[$task['dn']]['refreshUser'] = $webservice->refreshUserInfo($task['fdtasksgranulardn'][0]);
            } else {
              // In case the modification failed
              $result[$task['dn']]['results'] = json_encode("Error updating " . $task['fdtasksgranulardn'][0] . " - " . $lifeCycleResult);
              // Update of the task status error message
              $updateResult = $this->gateway->updateTaskStatus($task['dn'], $task['cn'][0], $lifeCycleResult);
            }
          } else {
            // Remove the subtask as it is not required to update it nor to process it.
            $result[$task['dn']]['results'] = 'Sub-task removed for : ' . $task['fdtasksgranulardn'][0] . ' with result : '
              . $this->gateway->removeSubTask($task['dn']);
            $result[$task['dn']]['statusUpdate'] = 'No updates required, sub-task will be removed.';
          }
        }
        
        // Verification if the sub-task status has been updated correctly
        if (isset($updateResult) && $updateResult === TRUE) {
          $result[$task['dn']]['statusUpdate'] = 'Success';
        } else if (isset($updateResult)) {
          $result[$task['dn']]['statusUpdate'] = $updateResult;
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
    // Regular expression to extract parts of the supannRessourceEtatDate string
    $pattern = '/\{(\w+)\}(\w):([^:]*)(?::([^:]*))?(?::([^:]*))?(?::([^:]*))?/';

    if (empty($currentUserLifeCycle[0]['supannressourceetatdate'][0])) {
      return FALSE;
    }

    $taskPreResource = $lifeCycleBehavior[0]['fdtaskslifecyclepreresource'][0] ?? '';
    $taskPreState    = $lifeCycleBehavior[0]['fdtaskslifecycleprestate'][0] ?? '';
    $taskPreSubState = $lifeCycleBehavior[0]['fdtaskslifecyclepresubstate'][0] ?? ''; // Optional
    $regexPattern    = $lifeCycleBehavior[0]['fdtaskslifecycleregexpattern'][0] ?? NULL;

    if (empty($taskPreResource) || empty($taskPreState)) {
        return FALSE;
    }

    $preResourceIsRegex = ($taskPreResource === 'REGEX');

    foreach ($currentUserLifeCycle[0]['supannressourceetatdate'] as $resourceString) {
      preg_match($pattern, $resourceString, $matches);

      $userResourceName     = $matches[1] ?? '';
      $userCurrentState     = $matches[2] ?? '';
      $userCurrentSubState  = $matches[3] ?? '';
      $userEndDateStr       = $matches[5] ?? '';

      // Check if expired
      if (empty($userEndDateStr)) {
        continue;
      }
      $userEndDateTimestamp = strtotime($userEndDateStr);
      $nowTimestamp = time();
      if ($userEndDateTimestamp === FALSE) {
        continue;
      }

      if ($userEndDateTimestamp > $nowTimestamp) {
        continue;
      }

      $nameMatch = FALSE;
      if ($preResourceIsRegex) {
        if ($regexPattern && !empty($userResourceName) && @preg_match('/' . $regexPattern . '/', $userResourceName)) {
          $nameMatch = TRUE;
        }
      } else {
        if ($userResourceName === $taskPreResource) {
          $nameMatch = TRUE;
        }
      }

      if ($nameMatch) {
        if ($userCurrentState === $taskPreState) {
          if (empty($taskPreSubState) || $userCurrentSubState === $taskPreSubState) {
            return TRUE;
          }
        }
      }
    }
    return FALSE;
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
    $pattern = '/\{(\w+)\}(\w):([^:]*)(?::([^:]*))?(?::([^:]*))?(?::([^:]*))?/';
    $userStateHistory = $currentUserLifeCycle[0]['supannressourceetatdate'] ?? [];
    $this->gateway->unsetCountKeys($userStateHistory);

    $taskPreResourceRaw = $lifeCycleBehavior[0]['fdtaskslifecyclepreresource'][0] ?? '';
    $taskPreState       = $lifeCycleBehavior[0]['fdtaskslifecycleprestate'][0] ?? '';
    $taskPreSubState    = $lifeCycleBehavior[0]['fdtaskslifecyclepresubstate'][0] ?? '';

    $taskPostResourceRaw = $lifeCycleBehavior[0]['fdtaskslifecyclepostresource'][0] ?? '';
    $taskPostState       = $lifeCycleBehavior[0]['fdtaskslifecyclepoststate'][0] ?? '';
    $taskPostSubState    = $lifeCycleBehavior[0]['fdtaskslifecyclepostsubstate'][0] ?? '';
    $taskPostExtraDays   = (int)($lifeCycleBehavior[0]['fdtaskslifecyclepostenddate'][0] ?? 0);
    $regexPattern        = $lifeCycleBehavior[0]['fdtaskslifecycleregexpattern'][0] ?? NULL;

    if (empty($taskPostResourceRaw) || empty($taskPostState)) {
      return "Error: Post-resource or Post-state not defined in task configuration.";
    }

    $preResourceIsRegex  = ($taskPreResourceRaw === 'REGEX');
    $postResourceIsRegex = ($taskPostResourceRaw === 'REGEX');

    $updatedStateHistory    = $userStateHistory; // Work on a copy
    $modificationsMadeCount = 0;

    for ($i = 0; $i < count($userStateHistory); $i++) {
      $currentUserResourceString = $userStateHistory[$i];
      preg_match($pattern, $currentUserResourceString, $matches);

      $userOriginalResourceName     = $matches[1] ?? '';
      $userOriginalRawState         = $matches[2] ?? '';
      $userOriginalRawSubState      = $matches[3] ?? '';
      $userOriginalPeriodEndDateStr = $matches[5] ?? '';

      // Determine if the current user resource was a "pre-match"
      $isPreMatchedAndExpired = FALSE;
      if (!empty($userOriginalPeriodEndDateStr) && strtotime($userOriginalPeriodEndDateStr) <= time()) {
        $namePreMatch = FALSE;
        if ($preResourceIsRegex) {
          if ($regexPattern && !empty($userOriginalResourceName) && @preg_match('/' . $regexPattern . '/', $userOriginalResourceName)) {
            $namePreMatch = TRUE;
          }
        } else {
          if ($userOriginalResourceName === $taskPreResourceRaw) {
            $namePreMatch = TRUE;
          }
        }
        if ($namePreMatch && $userOriginalRawState === $taskPreState && (empty($taskPreSubState) || $userOriginalRawSubState === $taskPreSubState)) {
          $isPreMatchedAndExpired = TRUE;
        }
      }

      $targetThisResourceForUpdate = FALSE;

      if ($preResourceIsRegex) {
        // Pre-condition is REGEX
        if (!$postResourceIsRegex) {
          // Case 1: Pre-REGEX, Post-Static
          if ($userOriginalResourceName === $taskPostResourceRaw) {
            $targetThisResourceForUpdate = TRUE;
          }
        } else {
          // Case 2: Pre-REGEX, Post-REGEX
          if ($isPreMatchedAndExpired) {
            $targetThisResourceForUpdate = TRUE;
          }
        }
      } else {
        // Pre-condition is Static (NOT REGEX)
        if ($postResourceIsRegex) {
          // Case 3: Pre-Static, Post-REGEX
          if ($regexPattern && !empty($userOriginalResourceName) && @preg_match('/' . $regexPattern . '/', $userOriginalResourceName)) {
            $targetThisResourceForUpdate = TRUE;
          }
        } else {
          // Case 4: Pre-Static, Post-Static
          if ($userOriginalResourceName === $taskPostResourceRaw) {
            $targetThisResourceForUpdate = TRUE;
          }
        }
      }

      if ($targetThisResourceForUpdate) {
        // Cannot update this resource if it lacks a valid end date to serve as the new start date.
        if (empty($userOriginalPeriodEndDateStr) || !DateTime::createFromFormat("Ymd", $userOriginalPeriodEndDateStr)) {
          continue;
        }

        $newPeriodStartDateStr  = $userOriginalPeriodEndDateStr;
        $newPeriodEndDateObject = DateTime::createFromFormat("Ymd", $newPeriodStartDateStr);
        $newPeriodEndDateObject->modify("+" . $taskPostExtraDays . " days");
        $newPeriodEndDateFormatted = $newPeriodEndDateObject->format('Ymd');

        $newResourceStringCore = "{" . $userOriginalResourceName . "}" . $taskPostState;
        if (!empty($taskPostSubState)) {
          $newResourceStringCore .= ":" . $taskPostSubState;
        } else {
          $newResourceStringCore .= ":"; // Placeholder for empty substate
        }

        $updatedStateHistory[$i] = $newResourceStringCore . ":" . $newPeriodStartDateStr . ":" . $newPeriodEndDateFormatted;
        $modificationsMadeCount++;
      }
    }

    if ($modificationsMadeCount === 0) {
      return TRUE; // No effective changes to save, or no targets met update criteria.
    }

    $ldapEntry = ['supannRessourceEtatDate' => $updatedStateHistory];

    try {
      $op_result = ldap_modify($this->gateway->ds, $userDN, $ldapEntry);
      return $op_result; // TRUE on success, FALSE on LDAP failure
    } catch (Exception $e) {
      return "Ldap Error: " . $e->getMessage();
    }
  }
}
