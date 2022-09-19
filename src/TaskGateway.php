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
  public function getTask (string $user_uid, string $id): array
  {
    $list_tasks = [];
    // if id - mail, change filter and search/return for task mail only

    switch ($id) {
      case "mail" :
        $list_tasks = $this->getLdapTasks("fdTasksMail");
        unset($list_tasks["count"]);

        // FOR POC - we trigger here - mail send
        // Construct a MailController for each tasks
        foreach ($list_tasks as $mail) {
          $setFrom     = "from@be.be";
          $replyTo     = "test@be.be";
          $recipients  = $mail["fdtasksemailsfromddn"];
          $body        = "A Test Body";
          $subject     = "A Test Subject";
          $receipt     = FALSE;
          $attachments = NULL;

          $mail_controller = new MailController($setFrom, $replyTo, $recipients, $body, $subject, $receipt, $attachments);

          $mail_controller->sendMail();
        }

        break;

      default:
        //get all tasks
        break;
    }

    return $list_tasks;
  }

  public function getLdapTasks (string $type): array
  {
    $empty_array = [];
    $filter = "(objectClass=$type)";
    $attrs = [];

    // Note: If multiple DC exists within OU, a new match is required.
    if (preg_match('/(dc=.*)/', $_ENV["LDAP_OU_USER"], $match)) {
      $dn = $match[0];
    } else {
      $dn = $_ENV["LDAP_OU_USER"];
    }

    $sr = ldap_search($this->ds, $dn, $filter, $attrs);
    $info = ldap_get_entries($this->ds, $sr);

    ldap_unbind($this->ds);

    if (is_array($info) && $info["count"] >= 1 ) {
      return $info;
    }

    return $empty_array;
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











