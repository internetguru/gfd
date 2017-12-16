<?php

require_once 'Utils.php';

class Deploy {

  const SECRET_PATH = __DIR__.'/../SECRET';
  const DEBUG_PATH = __DIR__.'/../DEBUG';

  private $headers;
  private $debug;

  /**
   * Deploy constructor.
   * @throws Exception
   */
  public function __construct () {
    # display all errors
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    error_reporting(E_ALL);

    # set debug
    $this->debug = is_file(self::DEBUG_PATH);

    # set error handlers
    set_error_handler(function($severity, $message, $file, $line) {
      throw new ErrorException($message, 0, $severity, $file, $line);
    });
    set_exception_handler(function(Exception $e) {
      if (!headers_sent()) {
        header('HTTP/1.1 500 Internal Server Error');
        header('Content-Type: text/plain');
      }
      echo htmlSpecialChars($e->getMessage());
      if ($this->debug) {
        echo "\n---\n";
        echo "Request Headers:\n";
        print_r($this->headers);
      }
      die();
    });

    $this->headers = Utils::getRequestHeaders();
    $impl = $this->setImplementation();
  }

  /**
   * Load secret from SECRET file
   * @throws Exception
   * @return string
   */
  private function getSecret () {
    if (!is_file(self::SECRET_PATH)) {
      throw new Exception('SECRET file is missing');
    }
    return trim(file_get_contents(self::SECRET_PATH));
  }

  /**
   * @return BitBucket|GitHub|GitLab
   * @throws Exception
   */
  private function setImplementation () {
    $ua = Utils::getHeader($this->headers, 'User-Agent');
    $secret = $this->getSecret();
    switch (true) {
      case preg_match('/^GitHub-Hookshot\/.*/', $ua):
        require_once ('GitHub.php');
        return new GitHub($this->headers, $secret);
      case preg_match('/^Bitbucket-Webhooks\/.*/', $ua):
        require_once ('BitBucket.php');
        return new BitBucket($this->headers, $secret);
      default:
        # https://gitlab.com/gitlab-org/gitlab-ce/issues/32912
        if (strlen(Utils::getHeader($this->headers, 'X-Gitlab-Event'))) {
          require_once ('GitLab.php');
          return new GitLab($this->headers, $secret);
        }
    }
    throw new Exception(sprintf('Unsupported User-Agent %s', $ua));
  }
}
