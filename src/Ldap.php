<?php

class Ldap
{

  private $ldap_host;
  private $ldap_admin;
  private $ldap_pwd;

  public function __construct (string $ldap_host, string $ldap_admin, string $ldap_pwd)
  {
    $this->ldap_host  = $ldap_host;
    $this->ldap_admin = $ldap_admin;
    $this->ldap_pwd   = $ldap_pwd;
  }

  //return type can be ldap\connection
  static public function getConnection ()
  {
    $ds = ldap_connect('ldap://'.$_ENV["LDAP_HOST"])
          or die("Could no connect to ".$_ENV["LDAP_HOST"]);

    // Set ldap version
    ldap_set_option($ds, LDAP_OPT_PROTOCOL_VERSION, 3);

    $ldapbind = ldap_bind($ds, $_ENV["LDAP_ADMIN"], $_ENV["LDAP_PWD"]);

    if (!$ldapbind) {
      echo "Fail connection to ldap in admin";
      exit;
    }

    // Important to keep in mind that this return an opened connection which
    // requires closing after use.

    return $ds;
  }
}











