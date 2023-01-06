<?php

function autoload ($class)
{
  if (strpos($class, 'PHPMailer') !== FALSE) {
    require("/usr/share/php/libphp-phpmailer/src/Exception.php");
    require("/usr/share/php/libphp-phpmailer/src/PHPMailer.php");
    require("/usr/share/php/libphp-phpmailer/src/SMTP.php");
  }

    $relative_class = str_replace('\\', '/', $class) . '.php';
    $base_dirs = ['/usr/share/php', dirname(__DIR__)];
    $files = [];
  foreach ($base_dirs as $base_dir) {
      $dir = new RecursiveDirectoryIterator($base_dir);
      $iter = new RecursiveIteratorIterator($dir);
      $regex = new RegexIterator($iter, '/^.+\.php$/i', RecursiveRegexIterator::GET_MATCH);

    foreach ($regex as $file) {
      $files[] = $file[0];
    }
  }

  foreach ($files as $file) {
    if (strpos($file, $relative_class) !== FALSE) {

      require $file;
      break;
    }
  }
}

spl_autoload_register('autoload');

set_error_handler("ErrorHandler::handleError");
set_exception_handler("ErrorHandler::handleException");

$dotenv = Dotenv\Dotenv::create('/etc/orchestrator', 'orchestrator.conf');
$dotenv->load();

header("Content-type: application/json; charset=UTF-8");
