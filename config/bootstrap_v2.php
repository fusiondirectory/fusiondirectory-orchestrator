<?php

function autoload ($class)
{
    $relative_class = str_replace('\\', '/', $class) . '.php';
    $base_dir = '/usr/share/php';
    $file = $base_dir . $relative_class;

  if (!file_exists($file)) {
      $dir = new RecursiveDirectoryIterator($base_dir);
      $iter = new RecursiveIteratorIterator($dir);

      $regex = new RegexIterator($iter, '/^.+\.php$/i', RecursiveRegexIterator::GET_MATCH);
      $files = [];

    foreach ($regex as $file) {
        $files[] = $file[0];
    }

    foreach ($files as $file) {
      if (strpos($file, $relative_class) !== FALSE) {

        require $file;
        break;
      }
    }
  } else {

    require $file;
  }
}

spl_autoload_register('autoload');

set_error_handler("ErrorHandler::handleError");
set_exception_handler("ErrorHandler::handleException");

//usable if dotenv 3.6
$dotenv = Dotenv\Dotenv::create('/etc/orchestrator', 'orchestrator.conf');
$dotenv->load();

header("Content-type: application/json; charset=UTF-8");
