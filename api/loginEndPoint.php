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
$ldap_connect = new Ldap($_ENV["LDAP_HOST"], $_ENV["LDAP_ADMIN"], $_ENV["LDAP_PWD"]);
$user_gateway = new UserGateway($ldap_connect);

$user = $user_gateway->getDSAInfo($data["username"]);
if ($user == NULL) {

  http_response_code(401);
  echo json_encode(["message" => "invalid authentication"]);
  exit;
}

if (!$user_gateway->validateDSAPassword($data["password"], $user["password_hash"])) {

  http_response_code(401);
  echo json_encode(["message" => "invalid authentication"]);
  exit;
}

$codec = new JWTCodec($_ENV["SECRET_KEY"]);

require __DIR__ . "/../config/tokens.php";

$refresh_token_gateway = new RefreshTokenGateway($ldap_connect, $_ENV["SECRET_KEY"], $user);

if (!empty($refresh_token) && !empty($refresh_token_expiry)) {
  $refresh_token_gateway->create($refresh_token, $refresh_token_expiry);
}