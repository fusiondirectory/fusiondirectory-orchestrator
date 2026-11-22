<?php

class ErrorHandler
{
  /**
   * @throws ErrorException
   */
  public static function handleError (int $errno, string $errstr, string $errfile, int $errline): void
  {
    throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
  }

  public static function handleException (Throwable $exception): void
  {
    http_response_code(500);

    $response = [
      "code" => $exception->getCode(),
      "message" => $exception->getMessage(),
    ];

    // Only include debug info in development
    if ($_ENV['DEBUG'] ?? FALSE) {
      $response["file"] = $exception->getFile();
      $response["line"] = $exception->getLine();
    }

    echo json_encode($response);
  }
}
