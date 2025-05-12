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
        } else {
          // Remove the subtask as it is not required to update it nor to process it.
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

      // Case 1: Pre-REGEX, Post-Static
      if ($preResourceIsRegex && !$postResourceIsRegex) {
        // Update the specific static post-resource, if this is it.
        // The overall task runs if *any* pre-regex match was found (by isLifeCycleRequiringModification).
        if ($userOriginalResourceName === $taskPostResourceRaw) {
          $targetThisResourceForUpdate = TRUE;
        } // Case 2: Pre-REGEX, Post-REGEX
      } else if ($preResourceIsRegex && $postResourceIsRegex) {
        // Update "that same resource" that was a pre-match.
        // If $isPreMatchedAndExpired is true, it implies the $userOriginalResourceName
        // already matched the $regexPattern (since $preResourceIsRegex is true).
        // The $regexPattern is used for both pre and post matching in this scenario.
        if ($isPreMatchedAndExpired) {
          $targetThisResourceForUpdate = TRUE;
        } // Case 3: Pre-Static, Post-REGEX
      } else if (!$preResourceIsRegex && $postResourceIsRegex) {
        // Overall task runs if the static pre-resource was matched & expired.
        // Update all user resources whose names match the post-regex.
        if ($regexPattern && !empty($userOriginalResourceName) && @preg_match('/' . $regexPattern . '/', $userOriginalResourceName)) {
          $targetThisResourceForUpdate = TRUE;
        }  // Implied Case: Pre-Static, Post-Static
      } else if (!$preResourceIsRegex && !$postResourceIsRegex) {
        // Overall task runs if the static pre-resource was matched & expired.
        // Update the specific static post-resource, if this is it.
        if ($userOriginalResourceName === $taskPostResourceRaw) {
          $targetThisResourceForUpdate = TRUE;
        }
      }

      if ($targetThisResourceForUpdate) {
        // Cannot update this resource if it lacks a valid end date to serve as the new start date.
        if (empty($userOriginalPeriodEndDateStr) || !DateTime::createFromFormat("Ymd", $userOriginalPeriodEndDateStr)) {
          // Log or skip. For now, skipping this specific resource update.
          continue;
        }

        $newPeriodStartDateStr  = $userOriginalPeriodEndDateStr;
        $newPeriodEndDateObject = DateTime::createFromFormat("Ymd", $newPeriodStartDateStr);
        // $newPeriodEndDateObject will be valid due to the check above.
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
      'fdTasksLifeCyclePostResource', 'fdTasksLifeCyclePostState', 'fdTasksLifeCyclePostSubState', 'fdTasksLifeCyclePostEndDate',
      'fdTasksLifeCycleRegexPattern'],
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
