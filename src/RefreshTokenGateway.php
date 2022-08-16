<?php

/*
 * RefreshToken should be called periodically via cron job or specific trigger
 * To be designed properly.
 */
class RefreshTokenGateway
{
  private $conn;
  private string $key;

  // Ldap_connect could be typed Ldap
  public function __construct ($ldap_connect, string $key)
  {
    $this->conn = $ldap_connect->getConnection();
    $this->key = $key;
  }

  public function create (string $token, int $expiry): bool
  {
    $hash = hash_hmac("sha256", $token, $this->key);

    // Example in SQL - To be adapted in LDAP
    $sql = "INSERT INTO refresh_token (token_hash, expires_at)
            VALUES (:token_hash, :expires_at)";

    // return ldap_errors
  }

  public function delete (string $token): int
  {
    $hash = hash_hmac("sha256", $token, $this->key);

    // Example in SQL
    $sql = "DELETE FROM refresh_token
            WHERE token_hash = :token_hash";

    // return ldap_errors
  }

  public function getByToken (string $token): array | FALSE
  {
    $hash = hash_hmac("sha256", $token, $this->key);

    // SQL Example
    $sql = "SELECT *
            FROM refresh_token
            WHERE token_hash = :token_hash";

    // return ldap attributes values
  }

  public function deleteExpired (): int
  {
    // SQL Example
    $sql = "DELETE FROM refresh_token
            WHERE expires_at < UNIX_TIMESTAMP()";

    // return ldap errors
  }
}













