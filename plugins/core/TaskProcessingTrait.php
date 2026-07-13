<?php

/**
 * Trait providing common task processing patterns for orchestrator plugins.
 * Reduces boilerplate by extracting the repeated status-check + main-task-config + schedule-extraction loop.
 */
trait TaskProcessingTrait
{
  /**
   * Process a single task if it passes status and schedule checks.
   * Extracts mainTaskDn and repeatableSchedule automatically.
   *
   * @param array    $task      The granular task LDAP entry
   * @param callable $processor function(array $task, string $mainTaskDn, ?string $repeatableSchedule): array
   * @return array Result from processor, or empty array if task was skipped
   */
  protected function processTask (array $task, callable $processor): array
  {
    if (!$this->gateway->statusAndScheduleCheck($task)) {
      return [];
    }

    $mainTaskDn       = $task['fdtasksgranularmaster'][0];
    $mainTaskConfig   = $this->getMainTaskConfig($mainTaskDn);
    $repeatableSchedule = $this->gateway->extractRepeatableSchedule($mainTaskConfig);

    return $processor($task, $mainTaskDn, $repeatableSchedule);
  }

  /**
   * Retrieve the main task configuration from LDAP.
   * Must be implemented by the using class.
   *
   * @param string $mainTaskDn The DN of the main task
   * @return array The main task configuration
   */
  abstract protected function getMainTaskConfig (string $mainTaskDn): array;
}
