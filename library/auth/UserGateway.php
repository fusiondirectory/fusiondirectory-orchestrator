<?php

class UserGateway
{
  private $ds;
  private string $orchestratorTokenBranch;
  private Backend $fdConfiguration;

  // Passed variable can be typed Ldap
  public function __construct ($ldap_connect)
  {
    $this->ds = $ldap_connect->getConnection();
    $this->fdConfiguration = new Backend();
  }

  public function authenticateDSA (string $dsaLogin, string $password): bool
  {
    $fdConfigAttributes  = $this->fdConfiguration->getFDConfigAttributes();
    $dsaBranch           = $fdConfigAttributes[0]['fdDSARDN'][0];

    $dn     = "cn=$dsaLogin," . $dsaBranch . "," . $_ENV["LDAP_BASE"];
    $userDs = ldap_connect($_ENV["LDAP_URI"]);

    ldap_set_option($userDs, LDAP_OPT_PROTOCOL_VERSION, 3);
    $bind = @ldap_bind($userDs, $dn, $password);
    ldap_unbind($userDs);

    // It will return TRUE if the bind was successful, FALSE otherwise.
    return $bind;
  }

  public function getDSAInfo (string $dsaLogin): array
  {
    $jwtCN  = $dsaLogin;

    $fdConfigAttributes  = $this->fdConfiguration->getFDConfigAttributes();
    $tokenBranch         = $fdConfigAttributes[0]['fdOrchestratorTokenRDN'][0] . ',' . $_ENV["LDAP_BASE"];

    $filter = "(&(objectClass=fdJWT)(cn=$jwtCN))";
    $attrs  = ["cn", "dn"];

    $sr = @ldap_search($this->ds, $tokenBranch, $filter, $attrs);
    if ($sr === FALSE) {
        // Search failed, construct DN for creation
        return [
            "cn" => $jwtCN,
            "dn" => "cn=$jwtCN," . $tokenBranch
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
            "dn" => "cn=$jwtCN," . $tokenBranch
        ];
    }
  }
}
