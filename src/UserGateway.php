<?php

class UserGateway
{
    private $conn;

    // Passed variable can be typed Ldap
    public function __construct($ldap_connect)
    {
        $this->conn = $ldap_connect->getConnection();
    }
    
    public function getByAPIKey(string $key): array | false
    {
       // return all from user where api key = api key 
    }
    
    public function getByUsername(string $username): array | false
    {
      // Select all from user where username = username
    }
    
    public function getByID(int $id): array | false
    {
      // Select all from user where id = id 
    }
}









