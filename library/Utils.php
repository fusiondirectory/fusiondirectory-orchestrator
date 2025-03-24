<?php

class Utils
{
    private function __construct() {}

    /**
     * @param array $array
     * @return array
     * Note : Recursively filters out empty values and arrays at any depth.
     */
    public static function recursiveArrayFilter (array $array): array
    {
        // First filter the array for non-empty elements
        $filtered = array_filter($array, function ($item) {
            if (is_array($item)) {
                // Recursively filter the sub-array
                $item = $this->recursiveArrayFilter($item);
                // Only retain non-empty arrays
                return !empty($item);
            } else {
                // Retain non-empty scalar values
                return !empty($item);
            }
        });

        return $filtered;
    }

    /**
     * Find matching keys between 2 lists.
     *
     * @param array|null $elements
     * @param array $keys
     * @return array
     */
    public static function findMatchingKeys (?array $elements, array $keys): array
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
     * @param $array
     * @return array
     * Note : simply return all values of a multi-dimensional array.
     */
    public static function getArrayValuesRecursive ($array)
    {
        $values = [];
        foreach ($array as $value) {
            if (is_array($value)) {
                // If value is an array, merge its values recursively
                $values = array_merge($values, self::getArrayValuesRecursive($value));
            } else {
                // If value is not an array, add it to the result
                $values[] = $value;
            }
        }
        return $values;
    }

    /**
     * @param string $text
     * @return string
     * Note : This come from jwtToken, as it is completely private - it is cloned here for now.
     */
    public static function base64urlEncode (string $text): string
    {
        return str_replace(["+", "/", "="], ["A", "B", ""], base64_encode($text));
    }
}