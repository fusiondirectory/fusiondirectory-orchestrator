<?php

declare(strict_types=1);

if ($_SERVER["REQUEST_METHOD"] !== "POST") {

  http_response_code(405);
  header("Allow: POST");
  exit;
}

$data = (array)json_decode(file_get_contents("php://input"), TRUE);

if (!array_key_exists("username", $data) || !array_key_exists("password", $data)) {

  http_response_code(400);
  echo json_encode(["message" => "missing login credentials"]);
  exit;
}
$ldap_connect = new Ldap($_ENV["LDAP_URI"], $_ENV["LDAP_BIND_DN"], $_ENV["LDAP_PASSWORD"]);
$user_gateway = new UserGateway($ldap_connect);

if (!$user_gateway->authenticateDSA($data["username"], $data["password"])) {
  http_response_code(401);
  echo json_encode(["message" => "invalid authentication"]);
  exit;
}

$user = $user_gateway->getDSAInfo($data["username"]);

$codec = new JWTCodec($_ENV["SECRET_KEY"]);

require __DIR__ . "/../include/tokens.php";

$refresh_token_gateway = new RefreshTokenGateway($ldap_connect, $_ENV["SECRET_KEY"], $user);

if (!empty($refresh_token) && !empty($refresh_token_expiry)) {
  $refresh_token_gateway->create($refresh_token, $refresh_token_expiry);
}
