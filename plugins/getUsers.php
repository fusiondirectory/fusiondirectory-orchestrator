<?php
class getUsers extends TaskGateway
{
  protected $ds;

  public function __construct()
  {
    $ldap_connect = new Ldap($_ENV["FD_LDAP_MASTER_URL"], $_ENV["LDAP_ADMIN"], $_ENV["LDAP_PWD"]);
    $this->ds = $ldap_connect->getConnection();
  }

  public function processEndPoint()
  {
    return $this->customLdapSearch("(&(objectClass=person))", ['cn']);
  }

  // This custom ldap search should be within parent as simplified version of what already exists. Refactor required.
  public function customLdapSearch (string $filter = '', array $attrs = [], string $dn = NULL): array
  {
    $result = [];

    if (empty($dn)) {
      $dn = $_ENV["LDAP_BASE"];
    }

    try {
      $sr   = ldap_search($this->ds, $dn, $filter, $attrs);
      $info = ldap_get_entries($this->ds, $sr);
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
