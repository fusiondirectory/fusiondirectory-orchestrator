<?php

declare(strict_types=1);

require __DIR__ . "/../config/bootstrap.php";

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

$ldap_connect = new Ldap($_ENV["LDAP_HOST"], $_ENV["LDAP_ADMIN"], $_ENV["LDAP_PWD"]);

$refresh_token_gateway = new RefreshTokenGateway($ldap_connect, $_ENV["SECRET_KEY"]);

if (!$refresh_token_gateway->delete($data["token"])) {
  echo json_encode(["message" => "Error logging out, either wrong refresh token passed or already logged out!"]);
}















