<?php

class TokenUtils
{
  private function __construct ()
  {
  }

  /**
   * @param string $userDN
   * @param int $timeStamp
   * @return string
   * @throws Exception
   */
  public static function generateToken (string $userDN, int $timeStamp): string
  {
    $token = NULL;
    // Salt has been generated with APG.
    $salt  = '8onOlEsItKond';
    $payload = json_encode($userDN . $salt);
    // This allows the token to be different every time.
    $time = time();

    // Create hmac with sha256 alg and the key provided for JWT token signature in ENV.
    $token_hmac = hash_hmac("sha256", $time . $payload, $_ENV["SECRET_KEY"], TRUE);

    // We need to have a token allowed to be used within an URL.
    $token = Utils::base64urlEncode($token_hmac);

    // Save token within LDAP
    self::saveTokenInLdap($userDN, $token, $timeStamp);

    return $token;
  }

  /**
   * @param string $userDN
   * @param string $token
   * NOTE : UID is the full DN of the user. (uid=...).
   * @param int $days
   * @return bool
   * @throws Exception
   */
  public static function saveTokenInLdap (string $userDN, string $token, int $days, TaskGateway $gateway): bool
  {
    $result = FALSE;

    $currentTimestamp = time();
    // Calculate the future timestamp by adding days to the current timestamp (We actually adds number of seconds).
    $futureTimestamp = $currentTimestamp + ($days * 24 * 60 * 60);

    preg_match('/uid=([^,]+),ou=/', $userDN, $matches);
    $uid = $matches[1];
    $dn  = 'cn=' . $uid . ',' . 'ou=tokens' . ',' . $_ENV["LDAP_BASE"];

    $ldap_entry["objectClass"]    = ['top', 'fdTokenEntry'];
    $ldap_entry["fdTokenUserDN"]  = $userDN;
    $ldap_entry["fdTokenType"]    = 'reminder';
    $ldap_entry["fdToken"]      = $token;
    $ldap_entry["fdTokenTimestamp"] = $futureTimestamp;
    $ldap_entry["cn"]         = $uid;

    // set the dn for the token, only take what's between "uid=" and ",ou="


    // Verify if token ou branch exists
    if (!self::tokenBranchExist('ou=tokens' . ',' . $_ENV["LDAP_BASE"])) {
      // Create the branch
      self::createBranchToken();
    }

    // The user token DN creation
    $userTokenDN = 'cn=' . $uid . ',ou=tokens' . ',' . $_ENV["LDAP_BASE"];
    // Verify if a token already exists for specified user and remove it to create new one correctly.
    if (self::tokenBranchExist($userTokenDN)) {
      // Remove the user token
      self::removeUserToken($userTokenDN);
    }

    // Add token to LDAP for specific UID
    try {
      $result = ldap_add($gateway->ds, $dn, $ldap_entry); // bool returned
    } catch (Exception $e) {
      echo json_encode(["Ldap Error - Token could not be saved!" => "$e"]); // string returned
      exit;
    }

    return $result;
  }

  /**
   * @param int $subTaskCall
   * @param int $firstCall
   * @param int $secondCall
   * @return int
   * Note : Simply return the difference between first and second call. (First call can be null).
   */
  public static function getTokenExpiration (int $subTaskCall, int $firstCall, int $secondCall): int
  {
    // if firstCall is empty, secondCall is the timestamp expiry for the token.
    $result = $secondCall;

    if (!empty($firstCall)) {
      // Verification if the subTask is the second reminder or the first reminder.
      if ($subTaskCall === $firstCall) {
        $result = $firstCall - $secondCall;
      }
    }

    return $result;
  }

  /**
   * @param $userTokenDN
   * @return void
   * Note : Simply remove the token for specific user DN
   */
  public static function removeUserToken ($userTokenDN, TaskGateway $gateway): void
  {
    // Add token to LDAP for specific UID
    try {
      $result = ldap_delete($gateway->ds, $userTokenDN); // bool returned
    } catch (Exception $e) {
      echo json_encode(["Ldap Error - User token could not be removed!" => "$e"]); // string returned
      exit;
    }
  }

  /**
   * Create ou=pluginManager LDAP branch
   * @throws Exception
   */
  public static function createBranchToken (TaskGateway $gateway): void
  {
    try {
      ldap_add(
        $gateway->ds, 'ou=tokens' . ',' . $_ENV["LDAP_BASE"],
        [
          'ou'      => 'tokens',
          'objectClass' => 'organizationalUnit',
        ]
      );
    } catch (Exception $e) {

      echo json_encode(["Ldap Error - Impossible to create the token branch" => "$e"]); // string returned
      exit;
    }
  }

  /**
   * @param string $token
   * @param array $mailTemplateForm
   * @param string $taskDN
   * @return array
   */
  public static function generateTokenUrl (string $token, array $mailTemplateForm, string $taskDN): array
  {
    //Only take the cn of the main task name :
    preg_match('/cn=([^,]+),ou=/', $taskDN, $matches);
    $taskName = $matches[1];

    // Remove the API URI
    $cleanedUrl = preg_replace('#/rest\.php/v1$#', '', $_ENV['FUSION_DIRECTORY_API_URL']);
    $url    = $cleanedUrl . '/accountProlongation.php?token=' . $token . '&task=' . $taskName;

    $mailTemplateForm['body'] .= $url;

    return $mailTemplateForm;
  }

  /**
   * @param string $dn
   * @return bool
   * Note : Simply inspect if the branch for token is existing.
   */
  public static function tokenBranchExist (string $dn, TaskGateway $gateway): bool
  {
    $result = FALSE;

    try {
      $search = ldap_search($gateway->ds, $dn, "(objectClass=*)");
      // Check if the search was successful
      if ($search) {
        // Get the number of entries found
        $entries = ldap_get_entries($gateway->ds, $search);

        // If entries are found, set result to true
        if ($entries["count"] > 0) {
          $result = TRUE;
        }
      }
    } catch (Exception $e) {
      $result = FALSE;
    }

    return $result;
  }
}