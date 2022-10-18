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
  // Subject to removal as user_uid might not be useful anymore.
  public function getTask (string $user_uid, ?string $id): array
  {
    $list_tasks = [];
    // if id - mail, change filter and search/return for task mail only

    switch ($id) {
      case "mail" :
        $list_tasks = $this->getLdapTasks("(objectClass=fdTasksMail)");
        unset($list_tasks["count"]);
        break;

      default:
        $list_tasks = $this->getLdapTasks("(objectClass=fdTasks)", ["cn", "objectClass"]);
        break;
    }

    // undbinging ds properly is required, dev required ($this->ds)
    return $list_tasks;
  }

  public function processMailTasks (array $list_tasks) : array
  {
    $result = [];

    foreach ($list_tasks as $mail) {
      // verify status before processing (to be check with schedule as well).
      if ($mail["fdtasksstatus"][0] == 1 && $this->verifySchedule($mail["fdtasksscheduledate"][0])) {

        // Search for the related attached mail object.
        $cn = $mail["fdtasksmailobject"][0];
        $mail_content = $this->getLdapTasks("(&(objectClass=fdMailTemplate)(cn=$cn))");

        $setFrom     = $mail["fdtasksemailsender"][0];
        $replyTo     = $mail["fdtasksemailreplyto"][0] ?? NULL;
        $recipients  = $mail["fdtasksemailsfromdn"];
        $body        = $mail_content[0]["fdmailtemplatebody"][0];
        $signature   = $mail_content[0]["fdmailtemplatesignature"][0];
        $subject     = $mail_content[0]["fdmailtemplatesubject"][0];
        $receipt     = $mail_content[0]["fdmailtemplatereadreceipt"][0];
        $attachments = $mail_content[0]["fdmailtemplateattachment"] ?? NULL;

        $mail_controller = new MailController($setFrom,
                                          $replyTo,
                                          $recipients,
                                          $body,
                                          $signature,
                                          $subject,
                                          $receipt,
                                          $attachments);

        if ($mail_controller->sendMail()) {
          $this->updateTaskMailStatus($mail["dn"], $mail["cn"][0]);
          $result[] = 'mail_processed';
        }
      }
    }

    return $result;
  }

  // Verification of the schedule in complete string format and compare.
  public function verifySchedule (string $schedule) : bool
  {
    $date = (new DateTime)->format('Y-m-d-H-i-s');
    $dateEx  = explode('-', $date);
    $dateStringerized = implode("", $dateEx);

    if ($schedule < $dateStringerized) {
      return TRUE;
    }

    return FALSE;
  }

  public function getLdapTasks (string $filter, array $attrs = []): array
  {
    $empty_array = [];

    // Note: If multiple DC exists within OU, a new match is required.
    if (preg_match('/(dc=.*)/', $_ENV["LDAP_OU_USER"], $match)) {
      $dn = $match[0];
    } else {
      $dn = $_ENV["LDAP_OU_USER"];
    }

    $sr = ldap_search($this->ds, $dn, $filter, $attrs);
    $info = ldap_get_entries($this->ds, $sr);

    if (is_array($info) && $info["count"] >= 1 ) {
      return $info;
    }

    return $empty_array;
  }

  public function updateTaskMailStatus (string $dn, string $cn): void
  {
    // prepare data
    $ldap_entry["cn"]                   = $cn;
    // Status subject to change
    $ldap_entry["fdTasksStatus"]        = "2";

    // Add data to LDAP
    try {

      $result = ldap_modify($this->ds, $dn, $ldap_entry);
    } catch (Exception $e) {

       echo "Message : " .$e.PHP_EOL;
    }
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











