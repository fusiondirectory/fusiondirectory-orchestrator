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

  public function processRequest (string $method, ?string $id): void
  {
    if ($id === NULL) {
      if ($method == "GET") {

        echo json_encode($this->gateway->getTask($this->user_uid, ''));

      } elseif ($method == "POST") {

        $data = (array) json_decode(file_get_contents("php://input"), TRUE);

        $id = $this->gateway->createTask($this->user_uid, $data);
        $this->respondCreated($id);

      } else {

        $this->respondMethodNotAllowed("GET, POST");
      }
    } else {

      $task = $this->gateway->getTask($this->user_uid, $id);
      if ($task == FALSE) {

        $this->respondNotFound($id);
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
          $this->respondMethodNotAllowed("GET, PATCH, DELETE");
      }
    }
  }

  private function respondMethodNotAllowed (string $allowed_methods): void
  {
    http_response_code(405);
    header("Allow: $allowed_methods");
  }

  private function respondNotFound (string $id): void
  {
    http_response_code(404);
    // Task ID is easier to be used - requires unique ID attributes during task creation (FD-Intefarce)
    echo json_encode(["message" => "Task with ID $id not found"]);
  }

  private function respondCreated (string $id): void
  {
    // To be completed if tasks can be created via webservice.
    http_response_code(201);
    echo json_encode(["message" => "Task created", "id" => $id]);
  }

}










