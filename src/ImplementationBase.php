<?php

require_once 'Utils.php';

abstract class ImplementationBase {

  protected $headers;
  protected $rawInput;
  protected $contentType;
  protected $event;

  /**
   * ImplementationBase constructor.
   * @param array $headers
   * @param string $secret
   * @throws Exception
   */
  public function __construct ($headers, $secret) {
    $this->headers = $headers;
    $this->contentType = Utils::getHeader($this->headers, 'Content-Type', true);
    $this->auth($secret);
    $this->event = $this->getEvent();
  }

  /**
   * Load raw input from request body
   */
  protected function loadRawInput () {
    $this->rawInput = file_get_contents('php://input');
  }

  /**
   * @param $secret
   * @throws Exception
   */
  abstract protected function auth ($secret);

  /**
   * @return string
   * @throws Exception
   */
  abstract protected function getEvent ();

}
