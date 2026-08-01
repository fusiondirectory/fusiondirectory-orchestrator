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
   * Find matching keys between 2 lists.
   *
   * @param array|null $elements (1 dimension array)
   * @param array $keys (1 dimension array)
   * @return array
   */
  public function findMatchingKeys (?array $elements, array $keys): array
  {
    $matching = [];

    if (!empty($elements)) {
      foreach ($elements as $element) {
        foreach ($keys as $key) {
          if (!empty($element) && array_key_exists($key, $element)) {
            $matching[] = $key;
          }
        }
      }
    }

    return $matching;
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
}
