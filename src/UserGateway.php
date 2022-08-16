<?php

class UserGateway
{
  private $conn;

  // Passed variable can be typed Ldap
  public function __construct ($ldap_connect)
  {
    $this->conn = $ldap_connect->getConnection();
  }

  public function getByUsername (string $username): array
  {
    // Select all from user where username = username
  }

  // Is used by the refresh token endpoint as ID is recovered from the refresh token
  // Allowing verification if the user has still proper priveleges.
  public function getByID (int $id): array
  {
    // Select all from user where id = id
  }
}









