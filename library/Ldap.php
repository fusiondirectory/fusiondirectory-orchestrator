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

    /**
     * @param $ds
     * @param string $filter
     * @param array $attrs
     * @param string|NULL $dn
     * @return array
     * Note : A generic method allowing to search in LDAP.
     */
  public function searchInLdap ($ds, string $filter = '', array $attrs = [], string $dn = NULL): array
  {
      $result = [];

    if (empty($dn)) {
        $dn = $_ENV["LDAP_BASE"];
    }

    try {
        $sr   = ldap_search($ds, $dn, $filter, $attrs);
        $info = ldap_get_entries($ds, $sr);
    } catch (Exception $e) {
        // build array for return response
        $result = [json_encode(["Ldap Error" => "$e"])]; // string returned
    }

      // Verify if the above ldap search succeeded.
    if (!empty($info) && is_array($info) && $info["count"] >= 1) {
        return $info;
    }

      return $result;
  }
}