<?php

class TaskController
{
  private TaskGateway $gateway;
    // user_id might become obsolete as FD->Orchestrator->Integrator might always be the same user.
  private int $user_id;

  public function __construct (TaskGateway $gateway, int $user_id)
  {
    $this->user_id = $user_id;
  }

  public function processRequest (string $method, ?string $id): void
  {
    if ($id === NULL) {
      if ($method == "GET") {

            echo json_encode($this->gateway->getTask($this->user_id));

      } elseif ($method == "POST") {

          $data = (array) json_decode(file_get_contents("php://input"), TRUE);

          $id = $this->gateway->createTask($this->user_id, $data);
          $this->respondCreated($id);

      } else {
        $this->respondMethodNotAllowed("GET, POST");
      }
    } else {

      $task = $this->gateway->getTask($this->user_id, $id);
      if ($task === FALSE) {

        $this->respondNotFound($id);
        return;
      }

      switch ($method) {
        case "GET":
          echo json_encode($task);
          break;

        case "PATCH":
          $data = (array) json_decode(file_get_contents("php://input"), TRUE);
          $rows = $this->gateway->updateTask($this->user_id, $id, $data);
          echo json_encode(["message" => "Task updated", "rows" => $rows]);
          break;

        case "DELETE":
          $rows = $this->gateway->deleteTask($this->user_id, $id);
          echo json_encode(["message" => "Task deleted", "rows" => $rows]);
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
    //Task ID is easier to be used - requires unique ID attributes during task creation (FD-Intefarce)
    echo json_encode(["message" => "Task with ID $id not found"]);
  }

  private function respondCreated (string $id): void
  {
    http_response_code(201);
    echo json_encode(["message" => "Task created", "id" => $id]);
  }

}










