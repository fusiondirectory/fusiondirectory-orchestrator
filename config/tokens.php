<?php

// Note  equals to Subject defined by IANA standard of JWT.
// Such as exp equals expiry time (int)
// https://www.iana.org/assignments/jwt/jwt.xhtml

if (!empty($user)) {
  $payload = [
    "sub"  => $user["cn"],
    "name" => $user["cn"],
    "exp"  => time() + $_ENV['TOKEN_EXPIRY']
  ];
}

if (!empty($codec) && !empty($payload)) {
  $access_token = $codec->encode($payload);


  // To be adapted (in Seconds) Equals 5 days
  $refresh_token_expiry = time() + $_ENV['REFRESH_EXPIRY'];

  if (!empty($user)) {
    $refresh_token = $codec->encode([
      "sub" => $user["cn"],
      "exp" => $refresh_token_expiry
    ]);
  }

  if (!empty($refresh_token)) {
    echo json_encode([
        "access_token"  => $access_token,
        "refresh_token" => $refresh_token
      ]) . PHP_EOL;
  }
}