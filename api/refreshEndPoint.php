<?php

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

$dsaCN = $payload["sub"];

$ldap_connect  = new Ldap($_ENV["LDAP_URI"], $_ENV["LDAP_BIND_DN"], $_ENV["LDAP_PASSWORD"]);
$fdConfiguration = new Backend();

$fdConfigAttributes        = $fdConfiguration->getFDConfigAttributes();
$orchestratorAccountBranch = $fdConfigAttributes[0]['fdOrchestratorTokenRDN'][0];

// Construct user info
$user = [
  "cn" => $dsaCN,
  "dn" => "cn=" . $dsaCN . "," . $orchestratorAccountBranch ."," . $_ENV["LDAP_BASE"]
];

// Pass user info to the RefreshTokenGateway, only the cn and dn are used.
$refresh_token_gateway = new RefreshTokenGateway($ldap_connect, $_ENV["SECRET_KEY"], $user);

$refresh_token = $refresh_token_gateway->getByToken($data["token"]);

if (!$refresh_token) {
    http_response_code(400);
    echo json_encode(["message" => "invalid token (not on whitelist)"]);
    exit;
}

require __DIR__ . "/../include/tokens.php";

$refresh_token_gateway->delete($data["token"]);
if (!empty($refresh_token_expiry)) {
  $refresh_token_gateway->create($refresh_token, $refresh_token_expiry);
}
