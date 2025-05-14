<?php

class UserGateway
{
  private $ds;

  // Passed variable can be typed Ldap
  public function __construct ($ldap_connect)
  {
    $this->ds = $ldap_connect->getConnection();
  }

  public function authenticateDSA (string $dsaLogin, string $password): bool
  {
    // Construct DN directly (adjust as needed for your LDAP structure)
    $dn = "cn=$dsaLogin," . $_ENV["ORCHESTRATOR_ACCOUNT_BRANCH"];

    $userDs = ldap_connect($_ENV["LDAP_URI"]);
    ldap_set_option($userDs, LDAP_OPT_PROTOCOL_VERSION, 3);
    $bind = @ldap_bind($userDs, $dn, $password);
    ldap_unbind($userDs);

    // It will return TRUE if the bind was successful, FALSE otherwise.
    return $bind;
  }

  public function getDSAInfo (string $dsaLogin): array
  {
    $baseDN = $_ENV["ORCHESTRATOR_TOKEN_BRANCH"];
    $filter = "(&(objectClass=fdJWT)(cn=$jwtCN))";
    $attrs  = ["cn", "dn"];

    $sr = @ldap_search($this->ds, $baseDN, $filter, $attrs);
    if ($sr === FALSE) {
        // Search failed, construct DN for creation
        return [
            "cn" => $jwtCN,
            "dn" => "cn=$jwtCN," . $baseDN
        ];
    }

    $info = ldap_get_entries($this->ds, $sr);

    if ($info["count"] > 0) {
        return [
            "cn" => $info[0]["cn"][0],
            "dn" => $info[0]["dn"]
        ];
    } else {
        return [
            "cn" => $jwtCN,
            "dn" => "cn=$jwtCN," . $baseDN
        ];
    }
  }
}
