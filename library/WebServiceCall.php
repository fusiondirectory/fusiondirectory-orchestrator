<?php

class WebServiceCall
{
  private string $URL, $method, $token;
  private array $data, $authData;
  /**
   * @var false|resource
   */
  private $ch;

  /**
   * @param string $URL
   * @param array|NULL $data
   * @param string $method
   * @param array|NULL $authData
   */
  public function __construct (string $URL, string $method, array $data = [], array $authData = [])
  {
    $this->URL      = $URL;
    $this->data     = $data;
    $this->method   = $method;
    $this->authData = $authData;
  }

  /**
   * @param string|NULL $URL
   * @param array|NULL $data
   * @param string|NULL $method
   * @return void
   * Note : Allows setting custom curl parameters, if none passed it will use the object defined curl parameters.
   */
  public function setCurlSettings (string $URL = NULL, array $data = NULL, string $method = NULL)
  {
    $this->ch = !empty($URL) ? curl_init($URL) : curl_init($this->URL);

    if (!empty($data)) {
      $this->data = $data;
    }

    if (!empty($method)) {
      $this->method = $method;

      // set the curl options based on the method required.
      switch (strtolower($this->method)) {
        case 'patch' :
          curl_setopt($this->ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
          curl_setopt($this->ch, CURLOPT_POSTFIELDS, json_encode($this->data));
          break;
        case 'get' :
          // No curl_setopt required
          break;
        case 'put':
          curl_setopt($this->ch, CURLOPT_CUSTOMREQUEST, 'PUT');
          curl_setopt($this->ch, CURLOPT_POSTFIELDS, json_encode($this->data));
          break;
        case 'delete':
          curl_setopt($this->ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
          break;
        case 'post':
          curl_setopt($this->ch, CURLOPT_POST, TRUE);
          curl_setopt($this->ch, CURLOPT_POSTFIELDS, json_encode($this->data));
          break;
      }
    }

    // Handle token retrieval via basic user/pass authentication.

    if (empty($this->token)) {
      if (empty($this->authData)) {
        $this->token = $this->getAccessToken($_ENV['WEB_LOGIN'], $_ENV['WEB_PASS']);
      } else {
        $this->token = $this->getAccessToken($this->authData['username'], $this->authData['password']);
      }
    }

    // Headers for the patch curl method containing the access_token
    $headers = [
      "session-token: ".$this->token
    ];

    // Set up the basic and default curl options
    curl_setopt($this->ch, CURLOPT_RETURNTRANSFER, TRUE);
    curl_setopt($this->ch, CURLOPT_HTTPHEADER, $headers);
  }

  /**
   * @param string $user
   * @param string $password
   * @return string
   * Note : Simply retrieve the access token after auth from FusionDirectory webservice.
   */
  private function getAccessToken (string $user, string $password): string
  {
    // The login endpoint is waiting a json format.
    $loginData = [
      'user' => $user,
      'password' => $password
    ];

    $ch = curl_init($this->URL);

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($loginData));
    curl_setopt($ch, CURLOPT_POST, TRUE);

    $response = curl_exec($ch);
    $this->handleCurlError($ch);

    curl_close($ch);

    // Token should be decoded, to remove quotes.
    return json_decode($response);
  }

  /**
   * @param $response
   * @return void
   */
  private function handleCurlError ($ch): void
  {
    // String is returned on success but a boolean on error.
    if (curl_error($ch)) {
      $error = array(
        'Error'  => 'Error during process of authentication to FusionDirectory web-service!',
        'Status' => curl_errno($ch),
      );
      echo json_encode($error, JSON_PRETTY_PRINT);
      exit;
    }
  }

  /**
   * @param string $dn
   * Note : receive the DN of the main task and execute it, creating related sub-tasks.
   */
  public function activateCyclicTasks (string $dn)
  {
    $data = array(
      "tasks" => array(
        "fdSubTasksActivation" => "TRUE"
      )
    );

    $this->setCurlSettings($_ENV['FD_WEBSERVICE_FQDN'] . '/objects/tasks/' .$dn, $data, 'PATCH');
    $response = curl_exec($this->ch);

    $this->handleCurlError($this->ch);
    $response = json_decode(curl_multi_getcontent($this->ch), TRUE);

    curl_close($this->ch);
    return $response;
  }
}
