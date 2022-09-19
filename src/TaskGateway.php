<?php


// Class to Get / Create FD Tasks
class TaskGateway
{
  private $ds;

  // Variable type can be LDAP
  public function __construct ($ldap_connect)
  {
      $this->ds = $ldap_connect->getConnection();
  }

  // Return the task specified by ID for specific user ID
  public function getTask (string $user_uid, string $id): string
  {
    // Will Trigger Integrator And Return List Of Tasks

    //LDAP search all tasks from object Tasks

    return "Integrator list of task".PHP_EOL;
  }

  public function createTask (string $user_uid, array $data): string
  {
    // Will Trigger Integrator And Return Last Created LDAP Entry
    return "Integrator created task".PHP_EOL;
  }

  public function updateTask (string $user_uid, string $id, array $data): string
  {
    return "Integrator updated task".PHP_EOL;
    // Will Trigger Integrator And Update Specific Task
  }

  public function deleteTask (string $user_uid, string $id): string
  {
    // Will Trigger Integrator And Delete Specific Task
    // Archiving the Task And Set Proper Status
    return "Integrator deleted task".PHP_EOL;
  }
}











