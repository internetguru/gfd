<?php

require_once 'Utils.php';
require_once 'ImplementationBase.php';

class GitHub extends ImplementationBase {

  /**
   * @param $secret
   * @throws Exception
   */
  public function auth ($secret) {
    $signature = Utils::getHeader($this->headers, 'X-Hub-Signature', true);
    if (!extension_loaded('hash')) {
      throw new Exception("Missing 'hash' extension to check the secret code validity.");
    }
    list($algo, $hash) = explode('=', $signature, 2);
    if (!in_array($algo, hash_algos(), true)) {
      throw new Exception("Hash algorithm '$algo' is not supported.");
    }
    $this->loadRawInput();
    if (!hash_equals($hash, hash_hmac($algo, $this->rawInput, $secret))) {
      throw new Exception('Hook secret does not match.');
    }
  }

  /**
   * @return string
   * @throws Exception
   */
  public function getEvent () {
    // TODO: Implement getEvent() method.
  }
}
