<?php

// Note  equals to Subject defined by IANA standard of JWT.
// Such as exp equals expiry time (int)
// https://www.iana.org/assignments/jwt/jwt.xhtml
//$payload = [
//  "" => $user["id"],
//  "name" => $user["name"],
//  "exp" => time() + 300
//];
$payload = [
  "sub" => 1,
  "name" => "name",
  "exp" => time() + 300
];


$access_token = $codec->encode($payload);

// To be adapted (in Seconds)
$refresh_token_expiry = time() + 432000;

//$refresh_token = $codec->encode([
//  "" => $user["id"],
//  "exp" => $refresh_token_expiry
//]);

$refresh_token = $codec->encode([
  "sub" => 1,
  "exp" => $refresh_token_expiry
]);

echo json_encode([
  "access_token"  => $access_token,
  "refresh_token" => $refresh_token
]);
