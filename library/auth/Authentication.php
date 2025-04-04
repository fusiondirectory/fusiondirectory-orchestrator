<?php

class Authentication
{
  private string $dsaCN;
  private JWTCodec $codec;

  public function __construct (JWTCodec $codec)
  {
    $this->codec = $codec;
  }

  public function getDSAcn (): string
  {
    return $this->dsaCN;
  }

  // Note: PHP8.0 will let pass the Exception without variables. PHP 7.4 requires variable assignment.
  public function authenticateAccessToken (): bool
  {
    // Bearer contains the access token content as variable from an HTTP HEADER point of view
    // HTTP Header looks like this : "Authorization:Bearer <access_token>"
    if (!preg_match("/^Bearer\s+(.*)$/", $_SERVER["HTTP_AUTHORIZATION"], $matches)) {
      http_response_code(400);
      echo json_encode(["message" => "incomplete authorization header"]);

      return FALSE;
    }

    try {
      // Second array entry in matches is the payload
      $data = $this->codec->decode($matches[1]);

    } catch (InvalidSignatureException $e) {

      http_response_code(401);
      echo json_encode(["message" => "invalid signature"]);

      return FALSE;
    } catch (TokenExpiredException $e) {

      http_response_code(401);
      echo json_encode(["message" => "token has expired"]);

      return FALSE;
    }
    $this->dsaCN = $data["sub"];

    return TRUE;
  }

}