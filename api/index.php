<?php

declare(strict_types=1);
require __DIR__ . "/../config/bootstrap.php";

// Parsing of the URI received as WEB request.
$path  = parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH);
$parts = explode("/", $path);
// We only need the name of the resource
$resource = $parts[3];
// And the tasks object required Ex: http://orchestrator/api/task(3)/object(4)/
// Example : mail is an object type of tasks
$object_type = $parts[4] ?? NULL;

// Parsing of the raw data potentially passed as json REST data to the API
$rawBody = file_get_contents('php://input');
// Decode the JSON data and set to null if no body received
$jsonBody = !empty ($rawBody) ? json_decode($rawBody, TRUE) : null;

switch ($resource) {

  case "login" :
    require __DIR__ . "/loginEndPoint.php";
    exit;

  case "refresh" :
    require __DIR__ . "/refreshEndPoint.php";
    exit;

  case "tasks" :
    // Continue below script -> can be a specific separate endpoint as well.
    break;

  case "logout" :
    require __DIR__ . "/logoutEndPoint.php";
    exit;

  default :
    http_response_code(404);
    exit;
}

// Retrieve an authenticated ldap connection
$ldap_connect = new Ldap($_ENV["FD_LDAP_MASTER_URL"], $_ENV["LDAP_ADMIN"], $_ENV["LDAP_PWD"]);

// Set timezone according to what's referenced in FusionDirectory configuration
$timezone = $ldap_connect->searchInLdap($ldap_connect->getConnection(),
    '(objectClass=FusionDirectoryConf)', ['fdTimezone'], "cn=config,ou=fusiondirectory,".$_ENV["LDAP_BASE"]);
// Set default timezone retrieved.
date_default_timezone_set($timezone[0]['fdtimezone'][0]);

// Retrieve all user info based on the dsa common name (CN).
$user_gateway = new UserGateway($ldap_connect);

// Encode &  Decode +  b64 tokens
$codec = new JWTCodec($_ENV["SECRET_KEY"]);

// Verify User With Related Token Access
$auth = new Authentication($codec);

// Quit script execution if access token is invalid or expired
if (!$auth->authenticateAccessToken()) {
  exit;
}

// Retrieve the CN of the DSA.
$dsaCN = $auth->getDSAcn();

$task_gateway = new TaskGateway($ldap_connect);
$controller   = new TaskController($task_gateway);

// Process Request Passing Resources Attributes Values ($id)
$controller->processRequest($_SERVER['REQUEST_METHOD'], $object_type, $jsonBody);
