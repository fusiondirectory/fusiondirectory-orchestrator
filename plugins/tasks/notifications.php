<?php

class notifications implements EndpointInterface
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
    return [];
  }

  /**
   * @return array
   */
  public function processEndPointPost (): array
  {
    return [];
  }

  /**
   * @return array
   */
  public function processEndPointPatch (): array
  {
    return $this->processNotifications($this->gateway->getObjectTypeTask('notifications)'));
  }

  /**
   * @return array
   */
  public function processEndPointDelete (): array
  {
    return [];
  }

  public function processNotifications (array $notificationsSubTasks): array
  {
    $result = [];
    // It will contain all required notifications to be sent per main task.
    $notifications = [];

    foreach ($notificationsSubTasks as $task) {
      // If the tasks must be treated - status and scheduled - process the sub-tasks
      if ($this->statusAndScheduleCheck($task)) {

        // Retrieve data from the main task
        $notificationsMainTask     = $this->getNotificationsMainTask($task['fdtasksgranularmaster'][0]);
        $notificationsMainTaskName = $task['fdtasksgranularmaster'][0];

        // Generate the mail form with all mail controller requirements
        $mailTemplateForm = $this->generateMainTaskMailTemplate($notificationsMainTask);
        // Simply retrieve the list of audited attributes
        $auditAttributes = $this->retrieveAuditedAttributes($task);

        $monitoredAttrs = $notificationsMainTask[0]['fdtasksnotificationsattributes'];
        $this->unsetCountKeys($monitoredAttrs);

        // Verify if there is a match between audited attributes and monitored attributes from main task.
        $matchingAttrs = array_intersect($auditAttributes, $monitoredAttrs);

        if (!empty($matchingAttrs)) {
          // Fill an array with UID of audited user and related matching attributes
          $notifications[$notificationsMainTaskName]['subTask'][$task['cn'][0]]['attrs'] = $matchingAttrs;

          // Require to be set for updating the status of the task later on.
          $notifications[$notificationsMainTaskName]['subTask'][$task['cn'][0]]['dn']  = $task['dn'];
          $notifications[$notificationsMainTaskName]['subTask'][$task['cn'][0]]['uid'] = $task['fdtasksgranulardn'][0];
          $notifications[$notificationsMainTaskName]['mailForm']                       = $mailTemplateForm;
          // Overwrite array notifications with complementing mail form body with uid and related attributes.
          $notifications = $this->completeNotificationsBody($notifications, $notificationsMainTaskName);

        } else { // Simply remove the subTask has no notifications are required
          $result[$task['dn']]['Removed'] = $this->removeSubTask($task['dn']);
          $result[$task['dn']]['Status']  = 'No matching audited attributes with monitored attributes, safely removed!';
        }
      }
    }

    if (!empty($notifications)) {
      $result[] = $this->sendNotificationsMail($notifications);
    }

    return $result;
  }
}