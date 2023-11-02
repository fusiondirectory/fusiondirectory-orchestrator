#!/usr/bin/php
<?php

declare(strict_types=1);
require "/usr/share/fusiondirectory-orchestrator/config/bootstrap.php";

class OrchestratorClient
{
  private bool $verbose, $debug;
  private string $loginEndPoint, $emailEndPoint, $tasksEndPoint;
  private array $loginData, $listOfArguments;
  private ?string $accessToken;

  public function __construct ()
  {
    // Tokens details
    $this->accessToken = NULL;

    // App details
    $this->verbose = FALSE;
    $this->debug = FALSE;

    $this->listOfArguments = ['--help', '-h', '--verbose', '-v', '--debug', '-d', '--emails', '-m', '--tasks', '-t'];

    $orchestratorFQDN = $_ENV["ORCHESTRATOR_FQDN"];
    $this->loginEndPoint = 'https://' . $orchestratorFQDN . '/api/login';
    $this->tasksEndPoint = 'https://' . $orchestratorFQDN . '/api/tasks/';
    $this->emailEndPoint = $this->tasksEndPoint . 'mail';


    $this->loginData = array(
      'username' => $_ENV["DSA_LOGIN"],
      'password' => $_ENV["DSA_PASS"]
    );
  }

  private function getAccessToken (): string
  {
    // The login endpoint is waiting a json format.
    $loginData = json_encode($this->loginData);

    $ch = curl_init($this->loginEndPoint);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $loginData);
    curl_setopt($ch, CURLOPT_POST, true);

    $response = curl_exec($ch);

    // Show curl errors or details if necessary
    $this->showCurlDetails($ch);
    curl_close($ch);
    return $response;
  }

  private function showCurlDetails ($ch): void
  {
    // Check for errors if verbose args is passed
    if ($this->debug === TRUE) {
      if (curl_errno($ch)) {
        echo 'cURL error: ' . curl_error($ch) . PHP_EOL;
      }
    }
    if ($this->verbose === TRUE) {
      // Print cURL verbose output
      echo PHP_EOL . 'cURL verbose output: ' . PHP_EOL . curl_multi_getcontent($ch) . PHP_EOL;
    }
  }

  // Method managing the authentication mechanism of JWT.
  private function manageAuthentication (): void
  {
      if (empty($this->accessToken)) {
      $tokens = $this->getAccessToken();
      // Create an object from the JSON string received.
      $tokens = json_decode($tokens);

      $this->accessToken = $tokens->access_token;
    }
  }
  private function showTasks (): void
  {
    // Retrieve or refresh access tokens
    $this->manageAuthentication();
    $ch = curl_init($this->tasksEndPoint);

    //headers for the patch curl method containing the access_token
    $headers = [
      "Authorization: Bearer $this->accessToken",
      "Content-Type: application/json"
    ];
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
    curl_setopt($ch, CURLOPT_HTTPGET, TRUE);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    curl_exec($ch);
    $tasks = json_decode(curl_multi_getcontent($ch), TRUE);

    unset($tasks['count']);
    $printTasks = [];

    foreach ($tasks as $task) {
      if (!empty($task['cn'])) {
        $printTasks[] = $task['cn'][0];
      }
    }

    // Print the existing tasks list
    if (!empty($printTasks)) {
      print_r(array_unique($printTasks));
    } else {
        echo json_encode('No tasks available.').PHP_EOL;
    }

    $this->showCurlDetails($ch);
    curl_close($ch);
  }

  private function subTaskEmails (): void
  {
    // Retrieve or refresh access tokens
    $this->manageAuthentication();
    $ch = curl_init($this->emailEndPoint);

    //headers for the patch curl method containing the access_token
    $headers = [
      "Authorization: Bearer $this->accessToken",
      "Content-Type: application/json"
    ];
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    curl_exec($ch);
    $this->showCurlDetails($ch);
    curl_close($ch);
  }

  public function run ($args): int
  {
    if (in_array('--help', $args) || count($args) < 2 || in_array('-h', $args)) {
      $this->printUsage();
      return 1; // Return error code
    }
    // Remove the first arg or args - it contains the name of the script only.
    array_shift($args);

    // Array of methods to be processed
    $tasksToBeExecuted = [];
    foreach ($args as $arg) {
      if (!in_array($arg, $this->listOfArguments)) {
        echo 'Error, the following argument : ' . $arg . ' is not recognised!' . PHP_EOL;
        $this->printUsage();
      }
      switch ($arg) {
        case '--verbose':
        case '-v':
          $this->verbose = TRUE;
          break;
        case '--debug':
        case '-d':
          $this->debug = TRUE;
          break;
        case '--emails':
        case '-m':
          $tasksToBeExecuted[] = 'emails';
          break;
        case '--tasks':
        case '-t':
          $tasksToBeExecuted[] = 'tasks';
          break;
      }
    }

    // Execute methods passed in arguments
    foreach ($tasksToBeExecuted as $task)
      switch ($task) {
        case 'emails' :
          $this->subTaskEmails();
          break;
        case 'tasks' :
          $this->showTasks();
          break;
      }

    return 0; // Return success code
  }

  private function printUsage ()
  {
    echo "Usage: php fusiondirectory-orchestrator-client.php --args" . PHP_EOL . "
    --help (-h)     : Show this helper message." . PHP_EOL . "
    --verbose (-v)  : Show curl returned messages." . PHP_EOL . "
    --debug (-d)    : Show debug and errors messages." . PHP_EOL . "
    --emails (-m)   : Execute subtasks of type emails." . PHP_EOL . "
    --tasks (-t)    : Show all tasks." . PHP_EOL;

    exit;
  }

}

// Create instance of our above class
$orchestratorClient = new OrchestratorClient();
try {
  $status = $orchestratorClient->run($argv);
} catch (Exception $e) {
  echo 'An error occurred: ' . $e->getMessage() . PHP_EOL;
}

// Exit with the status code returned
if (!empty($status)) {
  exit($status);
}