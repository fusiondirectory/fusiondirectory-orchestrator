<?php

/*
 * Note : deleteExpired method from refresh_token_gateway must be developped.
 * No suppression is therefore handled for the moment : 18/11/22
 */

declare(strict_types=1);

require __DIR__ . "/vendor/autoload.php";

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

$ldap_connect = new Ldap($_ENV["LDAP_HOST"], $_ENV["LDAP_ADMIN"], $_ENV["LDAP_PWD"]);

$refresh_token_gateway = new RefreshTokenGateway($ldap_connect, $_ENV["SECRET_KEY"]);

echo $refresh_token_gateway->deleteExpired(), "\n";
