<?php

/*
 * Allows to autoload php system libraries required by the code.
 * Allows to load the 3 dependencies require by php-mailer.
 * Allows to iterate on parent directory for any required class.
 */
function autoload ($class)
{
  // Integrator is required
  require_once '/usr/share/php/FusionDirectory/autoloader.php';
  // avoid error handler requirements error, as it should be one of the first to load ?
  require_once dirname(__DIR__).'/library/ErrorHandler.php';

  if (strpos($class, 'PHPMailer') !== FALSE) {
    require_once("/usr/share/php/libphp-phpmailer/src/Exception.php");
    require_once("/usr/share/php/libphp-phpmailer/src/PHPMailer.php");
    require_once("/usr/share/php/libphp-phpmailer/src/SMTP.php");
  }

  $relative_class = str_replace('\\', '/', $class) . '.php';
  $base_dirs      = ['/usr/share/php', dirname(__DIR__)];
  $files          = [];
  foreach ($base_dirs as $base_dir) {
    $dir   = new RecursiveDirectoryIterator($base_dir);
    $iter  = new RecursiveIteratorIterator($dir);
    $regex = new RegexIterator($iter, '/^.+\.php$/i', RecursiveRegexIterator::GET_MATCH);

    foreach ($regex as $file) {
      $files[] = $file[0];
    }
  }

  foreach ($files as $file) {
    if (stripos(strtolower($file), strtolower($relative_class)) !== FALSE) {
      require_once $file;
      break;
    }
  }

}

spl_autoload_register('autoload');

set_error_handler(static function (int $errno, string $errstr, string $errfile, int $errline): bool {
  ErrorHandler::handleError($errno, $errstr, $errfile, $errline);
  return TRUE; // This statement is unreachable but required for satisfying phpstan callable return required...
});


set_exception_handler("ErrorHandler::handleException");

$dotenv = Dotenv\Dotenv::create('/etc/fusiondirectory-orchestrator', 'orchestrator.conf');
$dotenv->load();

header("Content-type: application/json; charset=UTF-8");
