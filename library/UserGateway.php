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
  public function getDSAInfo (string $dsaLogin): array
  {
    /* Select all from ou=dsa where dsaLogin = dsaLogin
    * Verify if user actually exists and return TRUE if yes.
    * A default empty array used as default in case no user is found
    */
    $emptyArray = [];

    // During refreshEndPoint, dsaLogin already contains -jwt, it must be removed.
    $dsaLogin = str_replace('-jwt', '', $dsaLogin);

    $filter = "(|(cn=$dsaLogin))";
    $attrs = ["cn", "userPassword"];

    $sr = ldap_search($this->ds, $_ENV["LDAP_OU_DSA"], $filter, $attrs);
    $info = ldap_get_entries($this->ds, $sr);

    ldap_unbind($this->ds);

    if (is_array($info) && $info["count"] >= 1 ) {
      // CN is modified with '-jwt' in order to create a new entry in LDAP (Modifying existing CN is not allowed).
      $info['cn'] = $info[0]['cn'][0].'-jwt';
      $info['dn'] = str_replace($info[0]['cn'][0], $info['cn'], $info[0]['dn']);

      // During
      $info['password_hash'] = $info[0]['userpassword'][0];

      return $info;
    }

    return $emptyArray;
  }

  // Inspired by https://blog.michael.kuron-germany.de/2012/07/hashing-and-verifying-ldap-passwords-in-php/comment-page-1/
  // Can be made in switch mode. Only MD5 SHA SSHA and clear text are managed for now.
  //  !! To be removed in order to use the FD LDAP library already existing !!
  public function validateDSAPassword ($password, $hash): bool
  {
    if ($hash == '') { //No Password
      return FALSE;
    }

    if ($hash[0] != '{') { //Plain Text
      return $password == $hash;
    }

    // Crypt requires better implementation - not working as sub hash are required.
    if (substr($hash, 0, 7) == '{crypt}') {

      return crypt($password, substr($hash, 7)) == substr($hash, 7);
    } elseif (substr($hash, 0, 5) == '{MD5}') {

      $encrypted_password = '{MD5}' .base64_encode(md5($password, TRUE));
    } elseif (substr($hash, 0, 5) == '{SHA}') {

      $encrypted_password = '{SHA}' . base64_encode(sha1($password, TRUE ));
    } elseif (substr($hash, 0, 6) == '{SSHA}') {

      $salt = substr(base64_decode(substr($hash, 6)), 20);
      $encrypted_password = '{SSHA}' . base64_encode(sha1( $password.$salt, TRUE ). $salt);
    } else {

      echo json_encode(["System error" => "Unsupported password hash format"]);
      return FALSE;
    }

    if ($hash === $encrypted_password) {

      return TRUE;
    }

    return FALSE;
  }
}









