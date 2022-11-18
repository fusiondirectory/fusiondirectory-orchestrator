<?php

class TaskController
{
  private $gateway;

  // To be used later on for granularity.
  private $user_uid;

  public function __construct (TaskGateway $gateway, string $user_uid)
  {
    $this->user_uid = $user_uid;
    $this->gateway  = $gateway;
  }

  public function processRequest (string $method, ?string $object_type): void
  {
    // If no specific tasks object specified, return all tasks
    if ($object_type === NULL) {
      if ($method == "GET") {
        echo json_encode($this->gateway->getTask($this->user_uid, ''));

      } else {
        $this->respondMethodAllowed("GET");
      }

      // Otherwise return the tasks object specified
    } else {
      $task = $this->gateway->getTask($this->user_uid, $object_type);
      if ($task == FALSE) {

        $this->respondNotFound($object_type);
        return;
      }

      switch ($method) {
        case "GET":
          echo json_encode($task);
          break;

        case "PATCH":
          $result = $this->gateway->processMailTasks($task);

          if (!empty($result)) {
            echo json_encode($result);

          } else {
            echo json_encode("No emails were sent.");
          }

          break;

        case "DELETE":
          break;

        default:
          $this->respondMethodAllowed("GET, PATCH, DELETE");
      }
    }
  }

  private function respondMethodAllowed (string $allowed_methods): void
  {
    http_response_code(405);
    header("Allow: $allowed_methods");
  }

  private function respondNotFound (string $object_type): void
  {
    http_response_code(404);
    // Task ID is easier to be used - requires unique ID attributes during task creation (FD-Intefarce)
    echo json_encode(["message" => "Task object type : $object_type not found"]);
  }

}










