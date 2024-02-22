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

    echo $URL . PHP_EOL;
    // Handle token retrieval via basic user/pass authentication.

    if (empty($this->token)) {
      if (empty($this->authData)) {
        $this->token = $this->getAccessToken($_ENV['WEB_LOGIN'], $_ENV['WEB_PASS']);
      } else {
        $this->token = $this->getAccessToken($this->authData['username'], $this->authData['password']);
      }
      // Required due to the token received by FD contains quotes at start and end of its token string.
    }
//    } else {
//      $this->token = str_replace('"', '', $this->token);
//    }

    // Headers for the patch curl method containing the access_token
    $headers = [
      "content-type" => "application/json",
      "session-token" => $this->token
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
      'user'     => $user,
      'password' => $password
    ];

//    $this->ch = curl_init($this->URL);

    //jonathan
    $headers = [
      "content-type" => "application/json",
    ];
    curl_setopt($this->ch, CURLOPT_HTTPHEADER, $headers);
    //jonathan


    curl_setopt($this->ch, CURLOPT_RETURNTRANSFER, TRUE);
    curl_setopt($this->ch, CURLOPT_POSTFIELDS, json_encode($loginData));
    curl_setopt($this->ch, CURLOPT_POST, TRUE);

    //Note that curl session must remain up and not close due to FD requiring the session ID to be identical.
    $response = curl_exec($this->ch);
    $this->handleCurlError($response, $this->ch);

    // jonathan
    return json_decode($response);
    // jonathan

    //return $response;
  }

  /**
   * @param $response
   * @return void
   */
  private function handleCurlError ($response, $ch): void
  {
    if (curl_errno($ch)) {
      echo 'cURL error: ' . curl_error($ch) . PHP_EOL;
    }

    // String is returned on success but a boolean on error.
    if (!is_string($response)) {
      $errorInfo = array(
        'Info'     => 'Error during process of authentication to FusionDirectory web-service!',
        'Status'   => $response,
        'Error No' => curl_error($ch)
      );
      echo json_encode($errorInfo, JSON_PRETTY_PRINT);
      exit;
    }
  }

  /**
   * @param string $dn
   * @return string
   * Note : receive the DN of the main task and execute it, creating related sub-tasks.
   */
  public function activateCyclicTasks (string $dn): string
  {
    $data = array(
      "Tasks" => array(
        "fdSubTasksActivation" => "TRUE"
      )
    );

//    $this->setCurlSettings($_ENV['FD_WEBSERVICE_FQDN'] . 'objects/tasks/' .$dn, $data, 'PATCH');
    $this->setCurlSettings($_ENV['FD_WEBSERVICE_FQDN'] . '/objects/tasks', [], 'GET');
    $response = curl_exec($this->ch);
    print_r($this->token);
    print_r($response);
    $this->handleCurlError($response, $this->ch);

    $response = json_decode(curl_multi_getcontent($this->ch), TRUE);
    curl_close($this->ch);

    return $response;
  }
}
