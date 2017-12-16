<?php

class ImplementationBase {

  protected $headers;
  protected $rawInput;

  /**
   * ImplementationBase constructor.
   * @param $headers
   */
  public function __construct ($headers) {
    $this->headers = $headers;
  }

  /**
   * Load raw input from request body
   */
  protected function loadRawInput () {
    $this->rawInput = file_get_contents('php://input');
  }

}
