#!/usr/bin/php
<?php

declare(strict_types=1);
require "/usr/share/fusiondirectory-orchestrator/config/bootstrap.php";

class OrchestratorClient
{
  private bool $verbose;
  private string $loginEndPoint;
  private array $loginData;
  private array $listOfArguments;
  private ?string $accessToken;
  private ?string $refreshToken;
  private string $emailEndPoint;

  public function __construct ()
  {
    $this->accessToken = NULL;
    $this->refreshToken = NULL;
    $this->verbose = FALSE;
    $this->listOfArguments = ['--help', '--verbose', '--emails'];

    $orchestratorFQDN = $_ENV["ORCHESTRATOR_FQDN"];
    $this->loginEndPoint = 'https://' . $orchestratorFQDN . '/api/login';
    $this->emailEndPoint = 'https://' . $orchestratorFQDN . '/api/tasks/mail';

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
    if ($this->verbose === TRUE) {
      if (curl_errno($ch)) {
        echo 'cURL error: ' . curl_error($ch);
      }
      // Print cURL verbose output
      echo 'cURL verbose output: ' . curl_multi_getcontent($ch);
    }
  }

  // Method managing the authentication mechanism of JWT.
  private function manageAuthentication (): void
  {
    // 1.Read from LDAP to get the refresh token for the specific dsa
    // 2.Use the refresh token to get new access token
    // 3.If refresh token expired -> normal authentication.

    // Should only be executed if access_token is empty
    if ($this->accessToken == NULL) {
      $tokens = $this->getAccessToken();
      // Create an object from the JSON string received.
      $tokens = json_decode($tokens);

      $this->accessToken = $tokens->access_token;
      $this->refreshToken = $tokens->refresh_token;
    }

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
    if (in_array('--help', $args) || count($args) < 2 || (in_array('--verbose', $args) && count($args) == 2)) {
      $this->printUsage();
      return 1; // Return error code
    }
    // Remove the first arg or args - it contains the name of the script only.
    array_shift($args);

    foreach ($args as $arg) {
      if (!in_array($arg, $this->listOfArguments)) {
        echo 'Error, the following argument:' . $arg . ' is not recognised!' . PHP_EOL;
      }
      switch ($arg) {
        case '--verbose':
          $this->verbose = TRUE;
          break;
        case '--emails':
          $this->subTaskEmails();
          break;
      }
    }

    return 0; // Return success code
  }

  private function printUsage ()
  {
    echo "Usage: php fusiondirectory-orchestrator-client.php --args" . PHP_EOL . "
    --help      : Show this helper message." . PHP_EOL . "
    --verbose   : Show debug and details messages." . PHP_EOL . "
    --emails    : Execute subtasks of type emails." . PHP_EOL;
  }

}

// Create instance of our above class
$orchestratorClient = new OrchestratorClient();
try {
  $status = $orchestratorClient->run($argv);
} catch (Exception $e) {
  echo 'An error occurred: ' . $e->getMessage();
}

// Exit with the status code returned
if (!empty($status)) {
  exit($status);
}