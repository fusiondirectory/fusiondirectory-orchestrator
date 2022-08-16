<?php

// Note sub equals to Subject defined by IANA standard of JWT.
// Such as exp equals expiry time (int)
// https://www.iana.org/assignments/jwt/jwt.xhtml
$payload = [
  "sub" => $user["id"],
  "name" => $user["name"],
  "exp" => time() + 300
];

$access_token = $codec->encode($payload);

// To be adapted (in Seconds)
$refresh_token_expiry = time() + 432000;

$refresh_token = $codec->encode([
  "sub" => $user["id"],
  "exp" => $refresh_token_expiry
]);

echo json_encode([
  "access_token"  => $access_token,
  "refresh_token" => $refresh_token
]);
