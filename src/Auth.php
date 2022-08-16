<?php

class Auth
{
  private int $user_id;
  private UserGateway $user_gateway;
  private JWTCodec $codec;

  public function __construct (UserGateway $user_gateway, JWTCodec $codec)
  {
    $this->user_gateway = $user_gateway;
    $this->codec        = $codec;
  }

  public function authenticateAPIKey (): bool
  {
    if (empty($_SERVER["HTTP_X_API_KEY"])) {
      http_response_code(400);
      echo json_encode(["message" => "missing API key"]);
      return FALSE;
    }

    $api_key = $_SERVER["HTTP_X_API_KEY"];

    $user = $this->user_gateway->getByAPIKey($api_key);

    if ($user === FALSE) {
        http_response_code(401);
        echo json_encode(["message" => "invalid API key"]);
        return FALSE;
    }

    $this->user_id = $user["id"];

    return TRUE;
  }

  public function getUserID (): int
  {
      return $this->user_id;
  }

  // Note: PHP8.0 will let pass the Exception without variables. PHP 7.4 requires variable assignment.
  public function authenticateAccessToken (): bool
  {
    if (!preg_match("/^Bearer\s+(.*)$/", $_SERVER["HTTP_AUTHORIZATION"], $matches)) {
      http_response_code(400);
      echo json_encode(["message" => "incomplete authorization header"]);

      return FALSE;
    }

    try {
      $data = $this->codec->decode($matches[1]);

    } catch (InvalidSignatureException $e) {

        http_response_code(401);
        echo json_encode(["message" => "invalid signature"]);

        return FALSE;

    } catch (TokenExpiredException $e) {

        http_response_code(401);
        echo json_encode(["message" => "token has expired"]);

        return FALSE;

    } catch (Exception $e) {

        http_response_code(400);
        echo json_encode(["message" => $e->getMessage()]);

        return FALSE;
    }

    $this->user_id = $data["sub"];

    return TRUE;
  }
}












