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

class Backend
{
  public function __construct ()
  {
  }

  /**
   * Wrapper around FusionDirectory Integrator helper to read all attributes under
   * cn=config,ou=fusiondirectory,<baseDn>
   *
   * @param string $scope LDAP search scope: 'base'|'one'|'subtree'
   * @return array Returns entries array or an error array on failure
   */
  public function getFDConfigAttributes (string $scope = 'subtree'): array
  {
    // Instantiate FusionDirectory Integrator Link for advanced LDAP helpers
    try {
      $fdLink = new \FusionDirectory\Ldap\Link($_ENV["LDAP_URI"]);
      $fdLink->bind($_ENV["LDAP_BIND_DN"], $_ENV["LDAP_PASSWORD"]);
    } catch (\Throwable $e) {
      // Leave fdLink as NULL if initialization fails
      $fdLink = NULL;
    }

    if ($fdLink === NULL) {
      return ['error' => 'FD Link not initialized'];
    }

    try {
      return \FusionDirectory\FusionDirectory\Configuration::getFusionDirectoryConfigAttributes($fdLink, $_ENV["LDAP_BASE"], $scope);
    } catch (\Throwable $e) {
      return ['error' => (string)$e];
    }
  }
}