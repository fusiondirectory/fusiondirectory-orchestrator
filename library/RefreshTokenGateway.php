<?php

class RefreshTokenGateway
{
  private $ds;
  private string $key;
  private ?array $user;

  // Ldap_connect could be of typed Ldap - enhancement.
  public function __construct ($ldap_connect, string $key, array $user = NULL)
  {
    $this->ds   = $ldap_connect->getConnection();
    $this->key  = $key;
    $this->user = $user;
  }

  public function create ($token, int $expiry): bool
  {
    $result = NULL;
    $hash   = hash_hmac("sha256", $token, $this->key);

    // prepare data
    $ldap_entry["cn"]                   = $this->user["cn"];
    $ldap_entry["fdRefreshToken"]       = $hash;
    $ldap_entry["fdRefreshTokenExpiry"] = $expiry;
    $ldap_entry["objectclass"]          = "fdJWT";

    // Try to create new CN and if not update it.
    try {
      $result = ldap_add($this->ds, $this->user["dn"], $ldap_entry);
    } catch (Exception $e) {
      try {
        // Note : ObjectClass and CN cannot be modified
        unset($ldap_entry["objectclass"]);
        unset($ldap_entry["cn"]);
        $result = ldap_modify($this->ds, $this->user["dn"], $ldap_entry);
      } catch (Exception $e) {
        echo json_encode(["Ldap Error" => "$e"]);
      }
    }

    ldap_unbind($this->ds);

    return $result;
  }

  public function delete (string $token): bool
  {
    $result = FALSE;
    $hash   = hash_hmac("sha256", $token, $this->key);

    $filter = "(|(fdRefreshToken=$hash*))";
    $attrs  = ["fdRefreshToken"];

    $sr   = ldap_search($this->ds, $_ENV["LDAP_OU_DSA"], $filter, $attrs);
    $info = ldap_get_entries($this->ds, $sr);

    if (!empty($info[0])) {
      // Delete Hash from LDAP by passing empty array.
      try {

        $result = ldap_mod_del($this->ds, $info[0]["dn"], ["fdRefreshToken" => []]);
      } catch (Exception $e) {

        echo json_encode(["Ldap Error" => "$e"]);
      }
    }

    return $result;
  }

  // Refresh token is stored in LDAP - PHP 8.0 returns the token or false if not existent.
  public function getByToken (string $token): array
  {
    $hash = hash_hmac("sha256", $token, $this->key);

    $empty_array = [];
    $filter      = "(|(fdRefreshToken=$hash*))";
    $attrs       = ["fdRefreshToken"];

    $sr   = ldap_search($this->ds, $_ENV["LDAP_OU_DSA"], $filter, $attrs);
    $info = ldap_get_entries($this->ds, $sr);

    if (is_array($info) && $info["count"] >= 1) {

      return $info;
    }

    return $empty_array;
  }
}