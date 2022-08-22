<?php

declare(strict_types=1);

require __DIR__ . "/bootstrap.php";

$path = parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH);

$parts = explode("/", $path);

// We only need the name of the ressource
$resource = $parts[3];

// And the ID of that ressource. Ex: http://orchestrator/api/task(3)/id(4)/
$id = $parts[4] ?? NULL;

// Only focus on delivering a task ressource for now.
if ($resource != "tasks") {

    http_response_code(404);
    exit;
}

// Retrieve an authenticated ldap connection
$ldap_connect = new Ldap($_ENV["LDAP_HOST"], $_ENV["LDAP_ADMIN"], $_ENV["LDAP_PWD"]);

// Retrieve all user info based based on uid or username
$user_gateway = new UserGateway($ldap_connect);

// Encode &  Decode +  b64 tokens
$codec = new JWTCodec($_ENV["SECRET_KEY"]);

// Verify User With Related Token Access
$auth = new Auth($user_gateway, $codec);

// Quit script execution if access token is invalid or expired
if (!$auth->authenticateAccessToken()) {
    exit;
}

// To Get All Info Based On User ID.
$user_uid = $auth->getUserID();

$task_gateway = new TaskGateway($ldap_connect);
$controller = new TaskController($task_gateway, $user_uid);

// Process Resquest Passing Ressources Attributes Values ($id)
$controller->processRequest($_SERVER['REQUEST_METHOD'], $id);









