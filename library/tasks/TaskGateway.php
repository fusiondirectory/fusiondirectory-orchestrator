<?php

/**
 * Note : Tasks engine for FusionDirectory.
 * The gateway, often known as a data gateway or data access layer, is responsible for abstracting and encapsulating the interaction with an external system or a data source.
 * (e.g., an LDAP, an API, or another service).
 * It provides a unified interface for these operations.
 */
class TaskGateway
{
  /**
   * @var \LDAP\Connection|null
   */
  public $ds;

  // Variable type can be LDAP : enhancement (php8.2)
  public function __construct ($ldap_connect)
  {
    $this->ds = $ldap_connect->getConnection();
  }

  /**
   * @param string|null $object_type
   * @return array
   * Note : Return the task specified by object type.
   */
  public function getTask (?string $object_type): array
  {
    switch ($object_type) {

      case "lifeCycle":
        $list_tasks = $this->getLdapTasks("(&(objectClass=fdTasksGranular)(fdtasksgranulartype=Life Cycle))");
        $this->unsetCountKeys($list_tasks);
        break;

      case "notifications":
        $list_tasks = $this->getLdapTasks("(&(objectClass=fdTasksGranular)(fdtasksgranulartype=Notifications))");
        $this->unsetCountKeys($list_tasks);
        break;

      case "reminder":
        $list_tasks = $this->getLdapTasks("(&(objectClass=fdTasksGranular)(fdtasksgranulartype=Reminder))");
        $this->unsetCountKeys($list_tasks);
        break;

      case "removeSubTasks":
      case "activateCyclicTasks":
      case "restartFailedTasks":
        // No need to get any parent tasks here, but to note break logic - we will return an array.
        $list_tasks = ['Generic tasks execution'];
        break;

      // If no tasks object type declared , return all tasks
      case NULL:
        $list_tasks = $this->getLdapTasks("(objectClass=fdTasks)", ["cn", "objectClass"]);
        break;

      case $object_type:
        $list_tasks = $this->getLdapTasks("(&(objectClass=fdTasksGranular)(fdtasksgranulartype=" . $object_type . "))");
        $this->unsetCountKeys($list_tasks);
        break;

      //Will match any object type passed not found.
      default:
        // return empty array which will be interpreted as FALSE by parent.
        $list_tasks = [];
        break;
    }

    return $list_tasks;
  }

  /**
   * @param array $task
   * @return bool
   * @throws Exception
   */
  public function statusAndScheduleCheck (array $task): bool
  {
    return $task["fdtasksgranularstatus"][0] == 1 && $this->verifySchedule($task["fdtasksgranularschedule"][0]);
  }

  /**
   * @param array $array
   * @return void
   * Simple take an array as referenced and loop to remove all key having count
   */
  public function unsetCountKeys (array &$array)
  {
    foreach ($array as $key => &$value) {
      if (is_array($value)) {
        $this->unsetCountKeys($value);
      } elseif ($key === 'count') {
        unset($array[$key]);
      }
    }
    unset($value); //unset the reference after the loop for security best practise.
  }

  /**
   * @param bool|string $subTaskDn
   * @return bool|string
   */
  public function removeSubTask ($subTaskDn)
  {
    try {
      $result = ldap_delete($this->ds, $subTaskDn);
    } catch (Exception $e) {
      $result = json_encode(["Ldap Error" => "$e"]);
    }

    return $result;
  }

  /**
   * @return array
   * Note Search for all sub-tasks having status equals to 2 (completed) or 3 (nothing to process).
   */
  public function removeCompletedTasks (): array
  {
    $result            = [];
    $subTasksCompleted = $this->getLdapTasks(
      "(&(objectClass=fdTasksGranular)(|(fdTasksGranularStatus=2)(fdTasksGranularStatus=3)))",
      ["dn"]
    );
    // remove the count key from the arrays, keeping only DN.
    $this->unsetCountKeys($subTasksCompleted);
    if (!empty($subTasksCompleted)) {
      foreach ($subTasksCompleted as $subTasks) {
        $result[$subTasks['dn']]['result'] = $this->removeSubTask($subTasks['dn']);
      }
    } else {
      $result[] = 'No completed or nothing-to-process sub-tasks were removed.';
    }

    return $result;
  }

  /**
   * @return array
   * @throws Exception
   */
  public function activateCyclicTasks (): array
  {
    $result = [];
    $tasks  = $this->getLdapTasks(
      "(&(objectClass=fdTasks)(fdTasksRepeatable=TRUE))",
      ["dn", "fdTasksRepeatableSchedule", "fdTasksLastActivation", "fdTasksScheduleDate"]
    );
    // remove the count key from the arrays, keeping only DN.
    $this->unsetCountKeys($tasks);

    if (!empty($tasks)) {

      // Initiate the object webservice.
      $webservice = new FusionDirectory\Rest\WebServiceCall($_ENV['FUSIONDIRECTORY_WEBSERVICE_URL'] . '/login', 'POST');

      // Required to prepare future webservice call. E.g. Retrieval of mandatory token.
      $webservice->setCurlSettings();
      // Is used to verify cyclic schedule with date format. This use de local timezone - not UTC
      $now = new DateTime('now');

      foreach ($tasks as $task) {
        // Transform schedule time (it is a simple string)
        $schedule = DateTime::createFromFormat("YmdHis", $task['fdtasksscheduledate'][0]);

        // First verification of the schedule of the task itself.
        if ($schedule <= $now) {
          // Case where the tasks were never run before but schedule is met, execute tasks.
          if (empty($task['fdtaskslastactivation'][0])) {
            $result[$task['dn']]['result'] = $webservice->activateCyclicTasks($task['dn']);

            // Case where the tasks were once run, verification of the cyclic schedule and last activation.
          } else if (!empty($task['fdtasksrepeatableschedule'][0])) {
            $lastActivation = new DateTime($task['fdtaskslastactivation'][0]);

            // Efficient way to verify timelapse
            $interval = $now->diff($lastActivation);

            $scheduleStr = $task['fdtasksrepeatableschedule'][0];
            switch ($scheduleStr) {
              case 'Yearly' :
                if ($interval->y >= 1) {
                  $result[$task['dn']]['result'] = $webservice->activateCyclicTasks($task['dn']);
                }
                break;
              case 'Monthly' :
                if ($interval->m >= 1 || $interval->y >= 1) { // handle year change too
                  $result[$task['dn']]['result'] = $webservice->activateCyclicTasks($task['dn']);
                }
                break;
              case 'Weekly' :
                if ($interval->days >= 7) {
                  $result[$task['dn']]['result'] = $webservice->activateCyclicTasks($task['dn']);
                }
                break;
              case 'Daily' :
                if ($interval->days >= 1 || $interval->m >= 1 || $interval->y >= 1) {
                  $result[$task['dn']]['result'] = $webservice->activateCyclicTasks($task['dn']);
                }
                break;
              case 'Hourly' :
                if ($interval->h >= 1 || $interval->days >= 1) {
                  $result[$task['dn']]['result'] = $webservice->activateCyclicTasks($task['dn']);
                }
                break;
              default:
                // Support minute-based schedule: "Minutes:NN" where NN in 00..59
                if (strpos($scheduleStr, 'Minutes:') === 0) {
                  $minutes = $this->parseMinuteSchedule($scheduleStr);
                  if ($minutes !== NULL) {
                    $totalMinutes = ($interval->days * 24 * 60) + ($interval->h * 60) + $interval->i;
                    if ($totalMinutes >= $minutes) {
                      $result[$task['dn']]['result'] = $webservice->activateCyclicTasks($task['dn']);
                    }
                  }
                }
                break;
            }
          }
          // Case where cyclic tasks where found but the schedule is no ready.
        } else {
          $result[$task['dn']]['Status'] = 'This cyclic task has yet to reach its next activation cycle.';
        }
      }
    } else {
      $result[] = 'No tasks require activation.';
    }

    return $result;
  }

  /**
   * @param string $schedule
   * @return bool
   * @throws Exception
   * Note : Verification of the schedule in complete string format and compare.
   * DateTime will use the system timezone by default.
   */
  public function verifySchedule (string $schedule): bool
  {
    $currentDateTime   = new DateTime('now'); // Get current datetime in locale timezone
    $scheduledDateTime = new DateTime($schedule); // Parse scheduled datetime string in local timezone

    if ($scheduledDateTime < $currentDateTime) {
      return TRUE; // Schedule has passed
    }

    return FALSE;
  }

  /**
   * @param string $filter
   * @param array $attrs
   * @param string|NULL $attachmentsCN
   * @param string|NULL $dn
   * @return array
   * NOTE : Filter in ldap_search cannot be an empty string or NULL, if not filters are required, use (objectClass=*).
   */
  public function getLdapTasks (string $filter = '', array $attrs = [], ?string $attachmentsCN = NULL, ?string $dn = NULL): array
  {
    $result = [];

    // Verify if an optional DN is passed, set de default if not.
    if (empty($dn)) {
      $dn = $_ENV["LDAP_BASE"];
    }

    // This is the logic in order to get sub nodes attachments based on the mailTemplate parent cn.
    if (!empty($attachmentsCN)) {
      $dn = 'cn=' . $attachmentsCN . ',ou=mailTemplate,' . $dn;
    }

    /** Verification if the search report a FALSE, possible in case of non-existing DN passed in sub-tasks from a past
     *members registration which is now obsolete. (Array of members with non-existing DN reported in FD).
     */
    try {
      $sr   = ldap_search($this->ds, $dn, $filter, $attrs);
      $info = ldap_get_entries($this->ds, $sr);
    } catch (Exception $e) {
      // build array for return response
      $result = [json_encode(["Ldap Error" => "$e"] )]; // string returned
    }

    // Verify if the above ldap search succeeded.
    if (!empty($info) && is_array($info) && $info["count"] >= 1) {
      return $info;
    }

    return $result;
  }

  /**
   * @param string $dn
   * @param string $cn
   * @param string $status
   * @param string|null $mainTaskDn
   * @param string|null $repeatableSchedule
   * @return bool|string
   * Note : Update the status of the tasks.
   */
  public function updateTaskStatus (string $dn, string $cn, string $status, ?string $mainTaskDn = NULL, ?string $repeatableSchedule = NULL)
  {
    // prepare data
    if (!empty($dn)) {
      $ldap_entry["cn"] = $cn;
    }

    // Get current timestamp in the correct format
    $currentTime = date("Y-m-d H:i:s");

    // Status subject to change
    $ldap_entry["fdTasksGranularStatus"]   = $status;
    $ldap_entry["fdTasksGranularLastExec"] = $currentTime;

    // Calculate the next execution time if repeatable schedule is provided
    if (!empty($repeatableSchedule)) {
      $nextExecTime = $this->calculateNextExecutionTime($repeatableSchedule, $currentTime);
      if ($nextExecTime !== NULL) {
        $ldap_entry["fdTasksGranularNextExec"] = $nextExecTime;
      }
    }

    // Add status to LDAP
    try {
      $result = ldap_modify($this->ds, $dn, $ldap_entry); // bool returned

      // Now update the main task's fdTasksLastExec
      if ($result) {
        if ($mainTaskDn) {
          // Use the provided main task DN directly
          $this->updateMainTaskLastExec($mainTaskDn, $currentTime);
          if (isset($ldap_entry["fdTasksGranularNextExec"])) {
            $this->updateMainTaskNextExec($mainTaskDn, $ldap_entry["fdTasksGranularNextExec"]);
          }
        } else {
          // Fallback to LDAP lookup if main task DN not provided (Case of Audit E.g)
          $subtask = $this->getLdapTasks("(&(objectClass=fdTasksGranular)(cn=" . $cn . "))", ["fdTasksGranularMaster"]);
          if (!empty($subtask) && isset($subtask[0]['fdtasksgranularmaster'][0])) {
            $this->updateMainTaskLastExec($subtask[0]['fdtasksgranularmaster'][0], $currentTime);
            if (isset($ldap_entry["fdTasksGranularNextExec"])) {
              $this->updateMainTaskNextExec($subtask[0]['fdtasksgranularmaster'][0], $ldap_entry["fdTasksGranularNextExec"]);
            }
          }
        }
      }
    } catch (Exception $e) {
      $result = json_encode(["Ldap Error" => "$e"]); // string returned
    }

    return $result;
  }

  /**
   * @param string $objectType
   * @return array|string[]|void
   */
  public function getObjectTypeTask (string $objectType)
  {
    $task = $this->getTask($objectType);
    if (!$task) {
      TaskController::respondNotFound($objectType);
      exit;
    }

    return $task;
  }

  /**
   * @param string $dn
   * @return bool|string
   * Note: Update the attribute lastExecTime from fdTasksConf.
   */
  public function updateLastMailExecTime (string $dn)
  {
    $ldap_entry["fdTasksConfLastExecTime"] = time();

    // Add data to LDAP
    try {
      $result = ldap_modify($this->ds, $dn, $ldap_entry);
    } catch (Exception $e) {

      $result = json_encode(["Ldap Error" => "$e"]);
    }
    return $result;
  }

  /**
   * @param string $mainTaskDn
   * @param string $timestamp
   * @return bool|string
   * Note: Update the attribute fdTasksLastExec of the main task when a subtask is processed
   */
  public function updateMainTaskLastExec (string $mainTaskDn, string $timestamp)
  {
    $ldap_entry["fdTasksLastExec"] = $timestamp;

    // Add data to LDAP
    try {
      $result = ldap_modify($this->ds, $mainTaskDn, $ldap_entry);
    } catch (Exception $e) {
      $result = json_encode(["Ldap Error" => "$e"]);
    }
    return $result;
  }

  /**
   * @param string $mainTaskDn
   * @param string $timestamp
   * @return bool|string
   * Note: Update the attribute fdTasksNextExec of the main task when a subtask is processed
   */
  public function updateMainTaskNextExec (string $mainTaskDn, string $timestamp)
  {
    $ldap_entry["fdTasksNextExec"] = $timestamp;

    // Add data to LDAP
    try {
      $result = ldap_modify($this->ds, $mainTaskDn, $ldap_entry);
    } catch (Exception $e) {
      $result = json_encode(["Ldap Error" => "$e"]);
    }
    return $result;
  }

  /**
   * @param string $repeatableSchedule
   * @param string $currentTime
   * @return string|null
   * Note: Calculate the next execution time based on the repeatable schedule
   */
  private function calculateNextExecutionTime (string $repeatableSchedule, string $currentTime): ?string
  {
    $currentDateTime = new DateTime($currentTime);
    $nextExecutionTime = clone $currentDateTime;

    // Calculate next execution time based on repeatable schedule
    switch ($repeatableSchedule) {
      case 'Yearly':
        $nextExecutionTime->modify('+1 year');
        break;
      case 'Monthly':
        $nextExecutionTime->modify('+1 month');
        break;
      case 'Weekly':
        $nextExecutionTime->modify('+1 week');
        break;
      case 'Daily':
        $nextExecutionTime->modify('+1 day');
        break;
      case 'Hourly':
        $nextExecutionTime->modify('+1 hour');
        break;
      default:
        // Support minute-based schedule: "Minutes:NN"
        if (strpos($repeatableSchedule, 'Minutes:') === 0) {
          $minutes = $this->parseMinuteSchedule($repeatableSchedule);
          if ($minutes !== NULL) {
            $nextExecutionTime->modify("+{$minutes} minutes");
          } else {
            return NULL; // Invalid schedule type
          }
        } else {
          return NULL; // Invalid schedule type
        }
    }

    // Return the next execution time in the same format as currentTime
    return $nextExecutionTime->format('Y-m-d H:i:s');
  }

  /**
   * @param string $scheduleStr
   * @return int|null Number of minutes, or null if invalid
   */
  private function parseMinuteSchedule (string $scheduleStr): ?int
  {
    if (preg_match('/^Minutes:(\d{2})$/', $scheduleStr, $m)) {
      $val = intval($m[1], 10);
      if ($val >= 0 && $val <= 59) {
        return $val === 0 ? 0 : $val; // allow 0, means no wait (run immediately if due)
      }
    }
    return NULL;
  }

  // All the logic of restarting failed subtasks. Setting the subtask status back to '1' (scheduled).
  public function restartFailedSubtasks (?string $taskName = NULL): array
  {
    $result = [
      'updated' => [],
      'errors'  => []
    ];

    $filterParts = [
      '(&(objectClass=fdTasksGranular)',
      '(!(fdTasksGranularStatus=1))',
      '(!(fdTasksGranularStatus=2))',
      '(!(fdTasksGranularStatus=3))'
    ];

    // If a task name is passed, resolve its DN and add master filter
    if (!empty($taskName)) {
      $mainTaskDn = $this->resolveMainTaskDnByName($taskName);
      if ($mainTaskDn === NULL) {
        return ['errors' => ["Main task with cn '$taskName' not found"]];
      }
      $filterParts[] = '(fdTasksGranularMaster=' . $mainTaskDn . ')';
    }

    $filterParts[] = ')';
    $filter = implode('', $filterParts);

    // Retrieve failed subtasks with their DN and cn
    $subtasks = $this->getLdapTasks($filter, ['dn', 'cn']);
    $this->unsetCountKeys($subtasks);

    if (empty($subtasks)) {
      return ['message' => 'No failed subtasks found to restart.'];
    }

    foreach ($subtasks as $entry) {
      if (empty($entry['dn'])) { continue;
      }
      $dn = $entry['dn'];
      $cn = $entry['cn'][0] ?? basename($dn);

      $ldap_entry = [
        'fdTasksGranularStatus'   => '1',
      ];

      try {
        $ok = ldap_modify($this->ds, $dn, $ldap_entry);
        if ($ok) {
          $result['updated'][] = $dn;
        } else {
          $result['errors'][] = [ 'dn' => $dn, 'error' => 'ldap_modify returned false' ];
        }
      } catch (Exception $e) {
        $result['errors'][] = [ 'dn' => $dn, 'error' => (string)$e ];
      }
    }

    return $result;
  }

  /**
   * Resolve the DN of a main task by its cn.
   * @param string $name
   * @return string|null
   */
  private function resolveMainTaskDnByName (string $name): ?string
  {
    $filter = '(&(objectClass=fdTasks)(cn=' . $name . '))';
    $entries = $this->getLdapTasks($filter, ['dn']);
    $this->unsetCountKeys($entries);
    if (!empty($entries) && !empty($entries[0]['dn'])) {
      return $entries[0]['dn'];
    }
    return NULL;
  }

}