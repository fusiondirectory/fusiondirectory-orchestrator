<?php

// Note  equals to Subject defined by IANA standard of JWT.
// Such as exp equals expiry time (int)
// https://www.iana.org/assignments/jwt/jwt.xhtml

$payload = [
  "sub" => $user["uid"],
  "name" => $user["cn"],
  "exp" => time() + $_ENV['TOKEN_EXPIRY']
];

$access_token = $codec->encode($payload);

// To be adapted (in Seconds) Equals 5 days
$refresh_token_expiry = time() + $_ENV['REFRESH_EXPIRY'];

$refresh_token = $codec->encode([
  "sub" => $user["uid"],
  "exp" => $refresh_token_expiry
]);

echo json_encode([
  "access_token"  => $access_token,
  "refresh_token" => $refresh_token
]).PHP_EOL;
