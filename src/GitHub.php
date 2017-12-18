<?php

require_once 'Utils.php';
require_once 'ImplementationBase.php';

class GitHub extends ImplementationBase {

  const PUSH_EVENT_NAME = 'push';

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
    return Utils::getHeader($this->headers, 'X-Github-Event', true);
  }

  /**
   * @return string
   */
  protected function getPushEventName () {
    return self::PUSH_EVENT_NAME;
  }

  /**
   * @return string
   * @throws Exception
   */
  public function getDeliveryId () {
    return Utils::getHeader($this->headers, 'X-Github-Delivery', true);
  }

  /**
   * TODO move to ImplementationBase (?)
   * @throws exception
   */
  protected function loadInput () {
    switch ($this->contentType) {
      case 'application/json':
        $this->input = $this->rawInput;
        return;
      case 'application/x-www-form-urlencoded':
        $this->input = $_POST['payload'];
        break;
      default:
        throw new Exception("Unsupported content type {$this->contentType}");
    }
  }
}
