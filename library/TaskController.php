<?php

class TaskController
{
  private TaskGateway $gateway;

  public function __construct (TaskGateway $gateway)
  {
    $this->gateway = $gateway;
  }

  protected function parseJsonResult ($result = NULL): void
  {
    if (!empty($result)) {
      echo json_encode($result, JSON_PRETTY_PRINT);
    } else {
      // No result received
      echo json_encode("No results received from the endpoint");
    }
  }

  public function processRequest (string $method, ?string $objectType, $jsonBody = NULL): void
  {
    // If no specific tasks object specified, return all tasks
    if ($objectType === NULL) {
      if ($method == "GET") {
        echo json_encode($this->gateway->getTask(NULL));

      } else {
        $this->respondMethodAllowed("GET");
      }

      // Otherwise continue the process of the specific task / object type specified
    } else {
      switch ($method) {
        // GET methods
        case "GET":
          switch ($objectType) {
            case $objectType:
              if (class_exists($objectType)) {
                $endpoint = new $objectType;
                $result   = $endpoint->processEndPointGet();
              }
              break;
          }
          $this->parseJsonResult($result);
          break;

        // PATCH methods
        case "PATCH":
          switch ($objectType) {
            case "mail":
              $result = $this->gateway->processMailTasks($this->getObjectTypeTask($objectType));
              break;
            case 'lifeCycle':
              $result = $this->gateway->processLifeCycleTasks($this->getObjectTypeTask($objectType));
              break;
            case 'removeSubTasks':
              $result = $this->gateway->removeCompletedTasks();
              break;
            case 'activateCyclicTasks':
              $result = $this->gateway->activateCyclicTasks();
              break;
            case 'notifications':
              $result = $this->gateway->processNotifications($this->getObjectTypeTask($objectType));
              break;
            case $objectType:
              if (class_exists($objectType)) {
                $endpoint = new $objectType;
                $result   = $endpoint->processEndPointPatch($jsonBody);
              }
              break;
          }
          $this->parseJsonResult($result);
          break;

        case "DELETE":
          break;

        default:
          $this->respondMethodAllowed("GET, PATCH, DELETE");
      }
    }
  }

  /**
   * @param $objectType
   * @return array|string[]|void
   */
  private function getObjectTypeTask ($objectType)
  {
    $task = $this->gateway->getTask($objectType);
    if (!$task) {
      $this->respondNotFound($objectType);
      exit;
    }

    return $task;
  }

  private function respondMethodAllowed (string $allowed_methods): void
  {
    http_response_code(405);
    header("Allow: $allowed_methods");
  }

  private function respondNotFound (string $objectType): void
  {
    http_response_code(404);
    // Task ID is easier to be used - requires unique ID attributes during task creation (FD-Interface)
    echo json_encode(["message" => "Task object type : $objectType not found"]);
  }

}