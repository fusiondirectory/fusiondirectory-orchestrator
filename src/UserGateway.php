<?php

class UserGateway
{
  private $ds;

  // Passed variable can be typed Ldap
  public function __construct ($ldap_connect)
  {
    $this->ds = $ldap_connect->getConnection();
  }

  /*
   * return value should be bool or array
   * but | joint only exists as of php 8.0
   */
  public function getByUsername (string $username): bool
  {
    // Select all from user where username = username
    // Verify if user actually exists and return TRUE if yes.

    $filter="(|(uid=$username*))";
    $attrs = array("uid", "userPassword");
    
    $sr=ldap_search($this->ds, $_ENV["LDAP_OU_USER"], $filter, $attrs);
    $info = ldap_get_entries($this->ds, $sr);
    
    ldap_unbind($this->ds);
    
    if ($info["count"] >= 1){
     
      print_r($info);
      return TRUE;
    }
    
    return FALSE;
  }

  // Is used by the refresh token endpoint as ID is recovered from the refresh token
  // Allowing verification if the user has still proper priveleges.
  public function getByID (int $id): array
  {
    // Select all from user where id = id
    // Always return TRUE for testing
    return TRUE;
  }
}









