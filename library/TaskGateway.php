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
   * @var resource|null
   */
  public $ds;

  // Variable type can be LDAP : enhancement
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

      case "removeSubTasks":
      case "activateCyclicTasks":
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
   * @param $array
   * @return void
   * Simple take an array as referenced and loop to remove all key having count
   */
  public function unsetCountKeys (&$array)
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
   * Note Search for all sub-tasks having status equals to 2 (completed).
   */
  public function removeCompletedTasks (): array
  {
    $result            = [];
    $subTasksCompleted = $this->getLdapTasks(
      "(&(objectClass=fdTasksGranular)(fdTasksGranularStatus=2))",
      ["dn"]
    );
    // remove the count key from the arrays, keeping only DN.
    $this->unsetCountKeys($subTasksCompleted);
    if (!empty($subTasksCompleted)) {
      foreach ($subTasksCompleted as $subTasks) {
        $result[$subTasks['dn']]['result'] = $this->removeSubTask($subTasks['dn']);
      }
    } else {
      $result[] = 'No completed sub-tasks were removed.';
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
      ["dn", "fdTasksRepeatableSchedule", "fdTasksLastExec", "fdTasksScheduleDate"]
    );
    // remove the count key from the arrays, keeping only DN.
    $this->unsetCountKeys($tasks);

    if (!empty($tasks)) {

      // Initiate the object webservice.
      $webservice = new FusionDirectory\Rest\WebServiceCall($_ENV['FUSION_DIRECTORY_API_URL'] . '/login', 'POST');

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
          if (empty($task['fdtaskslastexec'][0])) {
            $result[$task['dn']]['result'] = $webservice->activateCyclicTasks($task['dn']);

            // Case where the tasks were once run, verification of the cyclic schedule and last exec.
          } else if (!empty($task['fdtasksrepeatableschedule'][0])) {
            $lastExec = new DateTime($task['fdtaskslastexec'][0]);

            // Efficient way to verify timelapse
            $interval = $now->diff($lastExec);

            switch ($task['fdtasksrepeatableschedule'][0]) {
              case 'Yearly' :
                if ($interval->y >= 1) {
                  $result[$task['dn']]['result'] = $webservice->activateCyclicTasks($task['dn']);
                } else {
                  $result[$task['dn']]['lastExecFailed'] = 'This cyclic task has yet to reached its next execution cycle.';
                }
                break;
              case 'Monthly' :
                if ($interval->m >= 1) {
                  $result[$task['dn']]['result'] = $webservice->activateCyclicTasks($task['dn']);
                } else {
                  $result[$task['dn']]['lastExecFailed'] = 'This cyclic task has yet to reached its next execution cycle.';
                }
                break;
              case 'Weekly' :
                if ($interval->days >= 7) {
                  $result[$task['dn']]['result'] = $webservice->activateCyclicTasks($task['dn']);
                } else {
                  $result[$task['dn']]['lastExecFailed'] = 'This cyclic task has yet to reached its next execution cycle.';
                }
                break;
              case 'Daily' :
                if ($interval->days >= 1) {
                  $result[$task['dn']]['result'] = $webservice->activateCyclicTasks($task['dn']);
                } else {
                  $result[$task['dn']]['lastExecFailed'] = 'This cyclic task has yet to reached its next execution cycle.';
                }
                break;
              case 'Hourly' :
                // When checking for hourly schedules, consider both the days and hours
                $totalHours = $interval->days * 24 + $interval->h;
                if ($totalHours >= 1) {
                  $result[$task['dn']]['result'] = $webservice->activateCyclicTasks($task['dn']);
                } else {
                  $result[$task['dn']]['lastExecFailed'] = 'This cyclic task has yet to reached its next execution cycle.';
                }
                break;
            }
          }
          // Case where cyclic tasks where found but the schedule is no ready.
        } else {
          $result[$task['dn']]['Status'] = 'This cyclic task has yet to reach its next execution cycle.';
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
  public function getLdapTasks (string $filter = '', array $attrs = [], string $attachmentsCN = NULL, string $dn = NULL): array
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
      $result = [json_encode(["Ldap Error" => "$e"])]; // string returned
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
   * @return bool|string
   * Note : Update the status of the tasks.
   */
  public function updateTaskStatus (string $dn, string $cn, string $status)
  {
    // prepare data
    if (!empty($dn)) {
      $ldap_entry["cn"] = $cn;
    }

    // Status subject to change
    $ldap_entry["fdTasksGranularStatus"] = $status;

    // Add status to LDAP
    try {
      $result = ldap_modify($this->ds, $dn, $ldap_entry); // bool returned
    } catch (Exception $e) {
      $result = json_encode(["Ldap Error" => "$e"]); // string returned
    }

    return $result;
  }

  /**
   * @param $objectType
   * @return array|string[]|void
   */
  public function getObjectTypeTask ($objectType)
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
    // prepare data
    $ldap_entry["fdTasksConfLastExecTime"] = time();

    // Add data to LDAP
    try {
      $result = ldap_modify($this->ds, $dn, $ldap_entry);
    } catch (Exception $e) {

      $result = json_encode(["Ldap Error" => "$e"]);
    }
    return $result;
  }
}