<?php

declare(strict_types=1);

namespace Dotenv\Parser;

use Dotenv\Util\Regex;
use Dotenv\Util\Str;

final class Lines
{
    /**
     * This class is a singleton.
     *
     * @codeCoverageIgnore
     *
     * @return void
     */
  private function __construct()
  {
      //
  }

    /**
     * Process the array of lines of environment variables.
     *
     * This will produce an array of raw entries, one per variable.
     *
     * @param string[] $lines
     *
     * @return string[]
     */
  public static function process(array $lines)
  {
      $output = [];
      $multiline = FALSE;
      $multilineBuffer = [];

    foreach ($lines as $line) {
        [$multiline, $line, $multilineBuffer] = self::multilineProcess($multiline, $line, $multilineBuffer);

      if (!$multiline && !self::isCommentOrWhitespace($line)) {
        $output[] = $line;
      }
    }

      return $output;
  }

    /**
     * Used to make all multiline variable process.
     *
     * @param bool     $multiline
     * @param string   $line
     * @param string[] $buffer
     *
     * @return array{bool,string,string[]}
     */
  private static function multilineProcess(bool $multiline, string $line, array $buffer)
  {
      $startsOnCurrentLine = $multiline ? FALSE : self::looksLikeMultilineStart($line);

      // check if $line can be multiline variable
    if ($startsOnCurrentLine) {
        $multiline = TRUE;
    }

    if ($multiline) {
        \array_push($buffer, $line);

      if (self::looksLikeMultilineStop($line, $startsOnCurrentLine)) {
          $multiline = FALSE;
          $line = \implode("\n", $buffer);
          $buffer = [];
      }
    }

      return [$multiline, $line, $buffer];
  }

    /**
     * Determine if the given line can be the start of a multiline variable.
     *
     * @param string $line
     *
     * @return bool
     */
  private static function looksLikeMultilineStart(string $line)
  {
      return Str::pos($line, '="')->map(static function () use ($line) {
          return self::looksLikeMultilineStop($line, TRUE) === FALSE;
      })->getOrElse(FALSE);
  }

    /**
     * Determine if the given line can be the start of a multiline variable.
     *
     * @param string $line
     * @param bool   $started
     *
     * @return bool
     */
  private static function looksLikeMultilineStop(string $line, bool $started)
  {
    if ($line === '"') {
        return TRUE;
    }

      return Regex::occurrences('/(?=([^\\\\]"))/', \str_replace('\\\\', '', $line))->map(static function (int $count) use ($started) {
          return $started ? $count > 1 : $count >= 1;
      })->success()->getOrElse(FALSE);
  }

    /**
     * Determine if the line in the file is a comment or whitespace.
     *
     * @param string $line
     *
     * @return bool
     */
  private static function isCommentOrWhitespace(string $line)
  {
      $line = \trim($line);

      return $line === '' || (isset($line[0]) && $line[0] === '#');
  }
}
