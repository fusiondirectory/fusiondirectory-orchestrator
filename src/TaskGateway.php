<?php


// Class to Get / Create FD Tasks
class TaskGateway
{
    private $conn;

  // Variable type can be LDAP
  public function __construct ($ldap_connect)
  {
      $this->conn = $ldap_connect->getConnection();
  }

  // Return the task specified by ID for specific user ID
  public function getTask (string $user_uid, string $id): bool
  {
    // Will Trigger Integrator And Return List Of Tasks
    return TRUE;
  }

  public function createTask (string $user_uid, array $data): string
  {
    // Will Trigger Integrator And Return Last Created LDAP Entry
  }

  public function updateTask (string $user_uid, string $id, array $data): int
  {
    // Will Trigger Integrator And Update Specific Task
  }

  public function deleteTask (string $user_uid, string $id): int
  {
    // Will Trigger Integrator And Delete Specific Task
    // Archiving the Task And Set Proper Status
  }
}











