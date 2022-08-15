<?php

class Ldap
{   

  private $ldap_host;
  private $ldap_admin;
  private $ldap_pwd;  
  
  public function __construct(string $ldap_host, string $ldap_admin, string $ldap_pwd)
  {
    $this->ldap_host  = $ldap_host;
    $this->ldap_admin = $ldap_admin;
    $this->ldap_pwd   = $ldap_pwd; 
  }

  //return type can be ldap\connection 
  static private function getConnection ()
  {
    $ds = ldap_connect('ldap://'.self::$ldap_host)
          or die("Could no connect to ".self::$ldap_host);

    // Set ldap version
    ldap_set_option($ds, LDAP_OPT_PROTOCOL_VERSION, 3);

    $ldapbind = ldap_bind($ds, self::$ldap_admin, self::$ldap_pwd);

    if (!$ldapbind) {
      echo "Fail connection to ldap in admin";
      exit;
    }

    return $ldapbind;
  }
}











