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

class MailUtils
{
  public function __construct ()
  {
  }

  public function sendMail ($setFrom, $setBCC, $recipients, $body, $signature, $subject, $receipt, $attachments)
  {
    $mail_controller = new \FusionDirectory\Mail\MailLib($setFrom,
      $setBCC,
      $recipients,
      $body,
      $signature,
      $subject,
      $receipt,
      $attachments);

    return $mail_controller->sendMail();
  }

  /**
   * @return array
   * Note : A simple retrieval methods of the mail backend configuration set in FusionDirectory
   */
  public function getMailObjectConfiguration (TaskGateway $gateway): array
  {
    return $gateway->getLdapTasks(
      "(objectClass=fdTasksConf)",
      ["fdTasksConfLastExecTime", "fdTasksConfIntervalEmails", "fdTasksConfMaxEmails"]
    );
  }

  /**
   * @param array $fdTasksConf
   * @return int
   * Note : Allows a safety check in case mail configuration backed within FD has been missed. (50).
   */
  public function returnMaximumMailToBeSend (array $fdTasksConf): int
  {
    // set the maximum mails to be sent to the configured value or 50 if not set.
    return $fdTasksConf[0]["fdtasksconfmaxemails"][0] ?? 50;
  }

  /**
   * Resolve email from DN and clean the mail attribute if require
   * Fallback on mail attribute if the mailType doesn't exist
   *
   * @param TaskGateway $gateway TaskGateway object
   * @param string $dn user DN
   * @param string $mailType mail attribute that we will get from the user
   * @return string return email or empty string
   */
  public function resolveEmailFromDn (TaskGateway $gateway, string $dn, string $mailType): string
  {
    $objectClass = $this->getMailObjectForType($mailType);

    $email = $gateway->getLdapTasks(
      "(objectClass=$objectClass)",
      [$mailType],
      "",
      $dn
    );
    $gateway->unsetCountKeys($email);

    if (!empty($email[0][strtolower($mailType)][0])) {
      return $this->cleanSupannEmail($email[0][strtolower($mailType)][0], $mailType);
    }

    // Fallback on 'mail' if required attribute is empty
    if ($mailType !== 'mail') {
      $email = $gateway->getLdapTasks(
        "(objectClass=gosaMailAccount)",
        ["mail"],
        "",
        $dn
      );
      $gateway->unsetCountKeys($email);
      if (!empty($email[0]['mail'][0])) {
        return $email[0]['mail'][0];
      }
    }

    return '';
  }

  /**
   * Clean the "supann" emails by removing the prefixes
   *
   * @param string $email email of the user
   * @param string $mailType required mailType
   * @return string cleaned email
   */
  private function cleanSupannEmail (string $email, string $mailType): string
  {
    if (in_array($mailType, ['supannAutreMail', 'supannMailPerso', 'supannMailPrive'])) {
      $cleanMail = preg_replace('/.+?(?=supann)/', '', $email);
      return preg_replace('/\{.*?\}/', '', $cleanMail);
    }
    return $email;
  }

  /**
   * Return objectClass based on the mailType
   *
   * @param string $mailType required mailType
   * @return string objectclass related to mailType
   */
  private function getMailObjectForType (string $mailType): string
  {
    return match ($mailType) {
      'mail', 'gosaMailAlternateAddress', 'gosaMailForwardingAddress' => 'gosaMailAccount',
      'supannAutreMail', 'supannMailPerso', 'supannMailPrive' => 'supannPerson',
      default => 'gosaMailAccount',
    };
  }

  public function replaceMacros (TaskGateway $gateway, array|string $recipients, string $body, array $mailMacros): string
  {
    // Prepare hardcodedMacros array
    $hardcodedMacros = [];
    foreach ($mailMacros as $macro) {
      $pattern                   = explode("|", $macro)[0];
      $ldapValue                 = explode("|", $macro)[1];
      $hardcodedMacros[$pattern] = $ldapValue;
    }

    // Convert $recipients as array if it is a string
    if (is_string($recipients)) {
      $recipients = [$recipients];
    }

    // Replace each $macro with the attribute in $body
    foreach ($recipients as $recipient) {
      foreach ($hardcodedMacros as $pattern => $ldapValue) {
        $filter = "(&(objectClass=inetOrgPerson)(|(mail=$recipient)(gosaMailAlternateAddress=$recipient)(gosaMailForwardingAddress=$recipient)(supannAutreMail=$recipient)(supannMailPerso=$recipient)(supannMailPrive={*}$recipient)))";
        $ldapAttribute = $gateway->getLdapTasks("$filter", ["$ldapValue"]);
        if (isset($ldapAttribute[0][strtolower($ldapValue)][0])) {
          $body = preg_replace('/%' . $pattern . '%/', $ldapAttribute[0][strtolower($ldapValue)][0], $body);
        }
      }
    }

    return $body;
  }
}
