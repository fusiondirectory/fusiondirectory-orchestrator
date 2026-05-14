<?php

/*
  This code is part of FusionDirectory\Ldap (https://www.fusiondirectory.org/)

  Copyright (C) 2025  FusionDirectory

  SPDX-License-Identifier: GPL-2.0-or-later

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License as published by
  the Free Software Foundation; either version 2 of the License, or
  (at your option) any later version.

  This program is distributed in the hope that it will be useful,
  but WITHOUT ANY WARRANTY; without even the implied warranty of
  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
  GNU General Public License for more details.

  You should have received a copy of the GNU General Public License
  along with this program; if not, write to the Free Software
  Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA 02110-1301, USA.
*/

class ReminderTokenUtils
{
  private Backend $fdConfiguration;

  public function __construct ()
  {
    $this->fdConfiguration = new Backend();
  }

  /**
   * @param string $userDN
   * @param int $timeStamp
   * @return string
   * @throws Exception
   */
  public function generateToken (string $userDN, int $timeStamp, TaskGateway $gateway): string
  {
    $token = NULL;

    $fdConfigAttributes  = $this->fdConfiguration->getFDConfigAttributes();
    $salt                = $fdConfigAttributes[0]['fdReminderSalt'][0];

    $payload = json_encode($userDN . $salt);
    // This allows the token to be different every time.
    $time = time();

    // Create hmac with sha256 alg and the key provided for JWT token signature in ENV.
    $token_hmac = hash_hmac("sha256", $time . $payload, $_ENV["SECRET_KEY"], TRUE);

    // We need to have a token allowed to be used within an URL.
    $token = $this->base64urlEncode($token_hmac);

    // Save token within LDAP
    $this->saveTokenInLdap($userDN, $token, $timeStamp, $gateway);

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
  private function saveTokenInLdap (string $userDN, string $token, int $days, TaskGateway $gateway): bool
  {
    $result = FALSE;

    $currentTimestamp = time();
    // Calculate the future timestamp by adding days to the current timestamp (We actually adds number of seconds).
    $futureTimestamp = $currentTimestamp + ($days * 24 * 60 * 60);

    $fdConfigAttributes  = $this->fdConfiguration->getFDConfigAttributes();
    $tokenBranch         = $fdConfigAttributes[0]['fdReminderTokenRDN'][0];

    // set the dn for the token, only take what's between "uid=" and ",ou="
    preg_match('/uid=([^,]+),ou=/', $userDN, $matches);
    $uid = $matches[1];
    $dn  = 'cn=' . $uid . ',' . $tokenBranch . ',' . $_ENV["LDAP_BASE"];

    $ldap_entry["objectClass"]    = ['top', 'fdTokenEntry'];
    $ldap_entry["fdTokenUserDN"]  = $userDN;
    $ldap_entry["fdTokenType"]    = 'reminder';
    $ldap_entry["fdToken"]      = $token;
    $ldap_entry["fdTokenTimestamp"] = $futureTimestamp;
    $ldap_entry["cn"]         = $uid;

    // Verify if a token already exists for specified user and remove it to create new one correctly.
    if ($this->tokenBranchExist($dn, $gateway)) {
      // Remove the user token
      $this->removeUserToken($dn, $gateway);
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
  public function getTokenExpiration (int $subTaskCall, int $firstCall, int $secondCall): int
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
   * @param string $userTokenDN
   * @return void
   * Note : Simply remove the token for specific user DN
   */
  private function removeUserToken (string $userTokenDN, TaskGateway $gateway): void
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
   * @param string $token
   * @param array $mailTemplateForm
   * @param string $taskDN
   * @return array
   */
  public function generateTokenUrl (string $token, array $mailTemplateForm, string $taskDN): array
  {
    //Only take the cn of the main task name :
    preg_match('/cn=([^,]+),ou=/', $taskDN, $matches);
    $taskName = $matches[1];

    // Remove the API URI
    $cleanedUrl = preg_replace('#/rest\.php/v1$#', '', $_ENV['FUSIONDIRECTORY_WEBSERVICE_URL']);
    $url    = $cleanedUrl . '/accountProlongation.php?token=' . $token . '&task=' . $taskName;

    $mailTemplateForm['body'] .= PHP_EOL . PHP_EOL . $url;

    return $mailTemplateForm;
  }

  /**
   * @param string $dn
   * @return bool
   * Note : Simply inspect if the branch for token is existing.
   */
  private function tokenBranchExist (string $dn, TaskGateway $gateway): bool
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

  /**
   * @param string $text
   * @return string
   * Note : This come from jwtToken, as it is completely private - it is cloned here for now.
   */
  private function base64urlEncode (string $text): string
  {
    return str_replace(["+", "/", "="], ["A", "B", ""], base64_encode($text));
  }
}
