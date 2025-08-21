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
}