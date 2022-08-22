<?php

declare(strict_types=1);

require __DIR__ . "/bootstrap.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {

  http_response_code(405);
  header("Allow: POST");
  exit;
}

$data = (array) json_decode(file_get_contents("php://input"), TRUE);

if (!array_key_exists("token", $data)) {

  http_response_code(400);
  echo json_encode(["message" => "missing token"]);
  exit;
}

$codec = new JWTCodec($_ENV["SECRET_KEY"]);

try {
  $payload = $codec->decode($data["token"]);

} catch (Exception $e) {

  http_response_code(400);
  echo json_encode(["message" => "invalid token"]);
  exit;
}

$user_uid = $payload["sub"];

$ldap_connect = new Ldap($_ENV["LDAP_HOST"], $_ENV["LDAP_ADMIN"], $_ENV["LDAP_PWD"]);

$user_gateway = new UserGateway($ldap_connect);

$user = $user_gateway->getByID($user_uid);
if ($user == FALSE) {

  http_response_code(401);
  echo json_encode(["message" => "invalid authentication"]);
  exit;
}

$refresh_token_gateway = new RefreshTokenGateway($ldap_connect, $_ENV["SECRET_KEY"], $user);

$refresh_token = $refresh_token_gateway->getByToken($data["token"]);

if ($refresh_token == FALSE) {

    http_response_code(400);
    echo json_encode(["message" => "invalid token (not on whitelist)"]);
    exit;
}

require __DIR__ . "/tokens.php";

$refresh_token_gateway->delete($data["token"]);
$refresh_token_gateway->create($refresh_token, $refresh_token_expiry);















