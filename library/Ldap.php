<?php

class Ldap
{

  private string $ldap_host;
  private string $ldap_admin;
  private string $ldap_pwd;

  public function __construct (string $ldap_host, string $ldap_admin, string $ldap_pwd)
  {
    $this->ldap_host  = $ldap_host;
    $this->ldap_admin = $ldap_admin;
    $this->ldap_pwd   = $ldap_pwd;
  }

  //return type can be ldap\connection
  public function getConnection ()
  {
    $ds = ldap_connect($this->ldap_host)
          or die("Could no connect to ".$this->ldap_host);

    // Set ldap version
    ldap_set_option($ds, LDAP_OPT_PROTOCOL_VERSION, 3);

    $ldapbind = ldap_bind($ds, $this->ldap_admin, $this->ldap_pwd);

    if (!$ldapbind) {
      echo json_encode(["Message" => "Fail connection to LDAP in admin"]);
      exit;
    }

    return $ds;
  }
}