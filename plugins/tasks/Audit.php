<?php

class Audit implements EndpointInterface
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
   * @param array|null $data
   * @return array
   */
  public function processEndPointPost (array $data = NULL): array
  {
    return [];
  }

  /**
   * @param array|NULL $data
   * @return array
   * @throws Exception
   */
  public function processEndPointPatch (array $data = NULL): array
  {
    return $this->processAuditDeletion($this->gateway->getObjectTypeTask('Audit'));
  }

  /**
   * @param array|NULL $data
   * @return array
   */
  public function processEndPointDelete (array $data = NULL): array
  {
    return [];
  }

  /**
   * @param array $auditSubTasks
   * @return array
   * @throws Exception
   */
  public function processAuditDeletion (array $auditSubTasks): array
  {
    $result = [];

    // todo - Logic to iterate through audit timestamp and delete passed time.
    $result = $auditSubTasks;

    return $result;
  }

  /**
   * @param string $mainTaskDn
   * @return array
   */
  public function getAuditMainTask (string $mainTaskDn): array
  {
    // Retrieve data from the main task
    return $this->gateway->getLdapTasks('(objectClass=fdTasksNotifications)', ['attr1',
      'attr2', 'attr3'], '', $mainTaskDn);
  }

}