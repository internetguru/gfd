<?php

class Utils {
  /**
   * getallheaders() polyfill
   * @return array|false
   */
  public static function getRequestHeaders () {
    if (function_exists('getallheaders')) {
      return getallheaders();
    }
    $headers = [];
    foreach ($_SERVER as $name => $value) {
      if (substr($name, 0, 5) !== 'HTTP_') {
        continue;
      }
      $headers[str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))))] = $value;
    }
    return $headers;
  }

  /**
   * @param array $headers
   * @param string $header
   * @param bool $required
   * @throws Exception
   * @return string
   */
  public static function getHeader ($headers, $header, $required=false) {
    if (!isset($headers[$header])) {
      if (!$required) {
        return '';
      }
      throw new Exception(sprintf("Missing HTTP '%s' header.", $header));
    }
    return $headers[$header];
  }

  public static function getParam ($param, $required=false) {
    if (!isset($_GET[$param])) {
      if (!$required) {
        return '';
      }
      throw new Exception(sprintf("Missing GET '%s' parameter.", $param));
    }
    return $_GET[$param];
  }
}
