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

      $user_password  = $info[0]['userpassword'][0];

      $info['password_hash'] = $user_password;

      return $info;
    }

    return $empty_array;
  }

  // Is used by the refresh token endpoint as ID is recovered from the refresh token
  // Allowing verification if the user has still proper priveleges.
  public function getByID (int $id): array
  {
    // Select all from user where id = id
    // Always return TRUE for testing
    return TRUE;
  }


  //Inspired by https://blog.michael.kuron-germany.de/2012/07/hashing-and-verifying-ldap-passwords-in-php/comment-page-1/
  // To be modified in switch mode.
  public function check_password ($password, $hash)
  {
    if ($hash == '') { //No Password
      return FALSE;
    }

    if ($hash{0} != '{') { //Plain Text
      if ($password == $hash) {

        return TRUE;
      } else {
        return FALSE;
      }
    }

    if (substr($hash, 0, 7) == '{crypt}') {
      if (crypt($password, substr($hash, 7)) == substr($hash, 7)) {

        return TRUE;
      } else {

        return FALSE;
      }
    } elseif (substr($hash, 0, 5) == '{MD5}') {

      $encrypted_password = '{MD5}' .base64_encode(md5($password, TRUE));
    } elseif (substr($hash, 0, 6) == '{SHA1}') {

      $encrypted_password = '{SHA}' . base64_encode(sha1($password, TRUE ));
    } elseif (substr($hash, 0, 6) == '{SSHA}') {

      $salt = substr(base64_decode(substr($hash, 6)), 20);
      $encrypted_password = '{SSHA}' . base64_encode(sha1( $password.$salt, TRUE ). $salt);
    } else {

      echo "Unsupported password hash format";
      return FALSE;
    }

    if ($hash === $encrypted_password) {

      return TRUE;
    }

    return FALSE;
  }
}









