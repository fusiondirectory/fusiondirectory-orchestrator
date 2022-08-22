<?php

/*
 * RefreshToken should be called periodically via cron job or specific trigger
 * To be designed properly.
 */
class RefreshTokenGateway
{
  private $ds;
  private string $key;
  private array $user;

  // Ldap_connect could be typed Ldap
  public function __construct ($ldap_connect, string $key, array $user)
  {
    $this->ds = $ldap_connect->getConnection();
    $this->key = $key;
    $this->user = $user;
  }

  public function create (string $token, int $expiry): bool
  {
    $hash = hash_hmac("sha256", $token, $this->key);

    // prepare data
    $ldap_entry["cn"] = $this->user["cn"];
    $ldap_entry["fdRefreshToken"] = $hash;
    $ldap_entry["fdRefreshTokenExpiry"] = $expiry;
    $ldap_entry["objectclass"] = "fdJWT";

    // Add data to LDAP
    try {
      
      $result = ldap_add($this->ds, $this->user["cn"], $ldap_entry);
    } catch (Exception $e) {
      
      try {

        // ObjectClass and CN cannot be modified
        unset($ldap_entry["objectclass"]);
        unset($ldap_entry["cn"]);

        $result = ldap_modify($this->ds, $this->user["cn"], $ldap_entry);
      } catch (Exception $e) {
          echo "Message : " .$e.PHP_EOL;
      }
    }
    
    ldap_unbind($this->ds);
    
    return $result;
  }

  public function delete (string $token): bool
  {
    $hash = hash_hmac("sha256", $token, $this->key);
    
    $filter = "(|(fdRefreshToken=$hash*))";
    $attrs = ["fdRefreshToken"];

    $sr = ldap_search($this->ds, $_ENV["LDAP_OU_USER"], $filter, $attrs);
    $info = ldap_get_entries($this->ds, $sr);

    // Delete Hash from LDAP by passing empty array.
    try {
      
      $result = ldap_mod_del($this->ds, $info[0]["dn"], array("fdRefreshToken" => array()));
    } catch (Exception $e) {

          echo "Message" .$e.PHP_EOL;
    }

    // Must remain available for create
    //ldap_unbind($this->ds);
    
    return $result;
  }

  //Refresh token is stored in DB. PHP 8.0 returns the token or false it not existent.
  public function getByToken (string $token): array
  {
    $hash = hash_hmac("sha256", $token, $this->key);

    $empty_array = [];
    $filter = "(|(fdRefreshToken=$hash*))";
    $attrs = ["fdRefreshToken"];

    $sr = ldap_search($this->ds, $_ENV["LDAP_OU_USER"], $filter, $attrs);
    $info = ldap_get_entries($this->ds, $sr);

    //Must be available for delete and create(latest of the chain).
    //ldap_unbind($this->ds);

    if (is_array($info) && $info["count"] >= 1 ) {

      return $info;
    }

    return $empty_array;
  }

  public function deleteExpired (): int
  {
    // SQL Example
    $sql = "DELETE FROM refresh_token
            WHERE expires_at < UNIX_TIMESTAMP()";

    // return ldap errors
    // testing purposes
    return 1;
  }
}













