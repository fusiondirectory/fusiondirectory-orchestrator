<?php

/*
  This code is part of FusionDirectory\Ldap (https://www.fusiondirectory.org/)

  Copyright (C) 2025-2026  FusionDirectory

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

class CoreUtils
{
  public function __construct ()
  {
  }

  /**
   * @param array $array
   * @return array
   * Note : Recursively filters out empty values and arrays at any depth.
   */
  public function recursiveArrayFilter (array $array): array
  {
    return array_filter($array, function ($item) {
      if (is_array($item)) {
          $item = $this->recursiveArrayFilter($item);
      }
      return !empty($item);
    });
  }

  /**
   * @param array $array
   * @return array
   * Note : simply return all values of a multi-dimensional array.
   */
  public function getArrayValuesRecursive (array $array)
  {
    return array_reduce($array, function ($carry, $value) {
      return array_merge($carry, is_array($value) ? $this->getArrayValuesRecursive($value) : [$value]);
    }, []);
  }

  /**
   * @param string $path
   * @return bool
   * @throws Exception
   * Note: Create directory if it doesn't exist.
   */
  public function ensureDirectoryExists (string $path): bool
  {
    if (!is_dir($path)) {
      if (!mkdir($path, 0755, TRUE)) {
        throw new Exception("Failed to create directory: $path");
      }
    }
    return TRUE;
  }

  /**
   * Retrieve the supannAccountStatus of a user
   * @param string $userDn
   * @return array
   */
  public function getUserSupannAccountStatus (string $userDn, TaskGateway $gateway): array
  {
    return $gateway->getLdapTasks(
      '(objectClass=supannPerson)',
      ['supannRessourceEtatDate'],
      '',
      $userDn
    );
  }

  /**
   * Generature subtasks foreach DNs from the "group" DN
   * @param TaskGateway $gateway
   * @param array $maintask
   * @param string $maintaskMemberValue
   */
  public function generateSubtaskFromDN (TaskGateway $gateway, array $maintask,
    string $maintaskMemberValue = 'fdtasksgranulardn') : void
  {
    $maintaskCN       = $maintask['cn'][0];
    $maintaskMemberDN = $maintask[$maintaskMemberValue][0];

    // TODO: use our LDAP library
    $memberSearch = $gateway->getLdapTasks(
      '(|(objectClass=groupOfNames)(objectClass=organizationalRole)(objectClass=groupOfURLs))',
      ['objectClass', 'member', 'roleOccupant'],
      '',
      $maintaskMemberDN
    );

    // Remove the counts in $memberSearch
    $gateway->unsetCountKeys($memberSearch);

    // Check if $memberSearch[0]['member'] or $memberSearch[0]['roleOccupant'] isset
    // If one of them isset then return the DNs array
    // Else return the $dn that we get because it means that it is an user
    if (isset($memberSearch[0]['member'])) {
      $memberDNs   = $memberSearch[0]['member'];
    } else if (isset($memberSearch[0]['roleOccupant'])) {
      $memberDNs = $memberSearch[0]['roleOccupant'];
    } else {
      $memberDNs = [];
    }

    foreach ($memberDNs as $memberDN) {
      $memberID = explode("=", explode(',', $memberDN)[0])[1];

      // Get timestamp from maintask
      $maintaskTimestampCN = strrev(explode("-", strrev($maintaskCN))[0]);

      // Get CN without timestamp
      $maintaskWithoutTimestampCN = str_replace($maintaskTimestampCN, '', $maintaskCN);

      // Generate the new CN
      $newSubtaskCN = $maintaskWithoutTimestampCN . "extract-" . $memberID . "-" . $maintaskTimestampCN;

      // Generate the new DN
      $newSubtaskDN = str_replace(
        $maintask['cn'][0],
        $newSubtaskCN,
        $maintask['dn']
      );

      // Generate the new subtask attrs
      $newSubtaskAttrs    = [
        'objectClass'                 => 'fdTasksGranular',
        'cn'                          => $newSubtaskCN,
        'fdTasksGranularStatus'       => 1,
        'fdTasksGranularMaster'       => $maintask['fdtasksgranularmaster'][0],
        'fdTasksGranularType'         => $maintask['fdtasksgranulartype'][0],
        'fdTasksGranularSchedule'     => $maintask['fdtasksgranularschedule'][0],
        'fdTasksGranularCreationdate' => $maintask['fdtasksgranularcreationdate'][0],
        $maintaskMemberValue          => $memberDN
      ];

      // TODO: Use our LDAP library
      try {
        $result = ldap_add($gateway->ds, $newSubtaskDN, $newSubtaskAttrs);
        if (!$result) {
          echo "Error when creating subtask: " . $newSubtaskDN;
        }
      } catch (Exception $e) {
        echo "Error when doing ldap_add for " . $newSubtaskDN . ": " . print_r($e, TRUE);
        echo "Attrs: " . print_r($newSubtaskAttrs, TRUE);
      }
    }
  }
}
