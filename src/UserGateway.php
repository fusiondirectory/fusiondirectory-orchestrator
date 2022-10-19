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
  public function getByUsername (string $username): array
  {
    /* Select all from user where username = username
    * Verify if user actually exists and return TRUE if yes.
    * A default empty array used as default in case no user is found
    */
    $empty_array = [];
    $user_password = [];
    $filter = "(|(uid=$username*))";
    $attrs = ["uid", "userPassword"];

    $sr = ldap_search($this->ds, $_ENV["LDAP_OU_USER"], $filter, $attrs);
    $info = ldap_get_entries($this->ds, $sr);

    ldap_unbind($this->ds);

    if (is_array($info) && $info["count"] >= 1 ) {

      // Format array for easier use aftewards and removes uid by substring
      $info['uid'] = $info[0]['uid'][0];
      $info['cn'] = "cn=".substr($info[0]['dn'], 4);
      $info['password_hash'] = $info[0]['userpassword'][0];

      return $info;
    }

    return $empty_array;
  }

  // Is used by the refresh token endpoint as ID is recovered from the refresh token
  // Allowing verification if the user has still proper priveleges.
  public function getByID (string $uid): array
  {
    /* This ID was taken from the token payload passed during refresh.
    * Select all from user where uid = uid
    * A default empty array used as default in case no user is found
    */
    $empty_array = [];
    $filter = "(|(uid=$uid*))";
    $attrs = ["uid"];

    $sr = ldap_search($this->ds, $_ENV["LDAP_OU_USER"], $filter, $attrs);
    $info = ldap_get_entries($this->ds, $sr);
    $info['uid'] = $info[0]['uid'][0];
    $info['cn'] = "cn=".substr($info[0]['dn'], 4);

    ldap_unbind($this->ds);

    if (is_array($info) && $info["count"] >= 1 ) {

      return $info;
    }

    return $empty_array;
  }

  // Inspired by https://blog.michael.kuron-germany.de/2012/07/hashing-and-verifying-ldap-passwords-in-php/comment-page-1/
  // Can be made in switch mode. Only MD5 SHA SSHA and clear text are managed for now.
  //  !! To be removed to used the FD LDAP library already existing !!
  public function check_password ($password, $hash)
  {
    if ($hash == '') { //No Password
      return FALSE;
    }

    if ($hash[0] != '{') { //Plain Text
      return $password == $hash ? TRUE : FALSE;
    }

    // Crypt requires better implementation - not working as sub hash are required.
    if (substr($hash, 0, 7) == '{crypt}') {

      return crypt($password, substr($hash, 7)) == substr($hash, 7) ? TRUE : FALSE;
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









