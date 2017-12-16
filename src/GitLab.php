<?php

require_once 'Utils.php';
require_once 'AuthInterface.php';
require_once 'ImplementationBase.php';

class GitLab extends ImplementationBase implements AuthInterface {

  /**
   * @param $secret
   * @throws Exception
   */
  public function auth ($secret) {
    // TODO: Implement auth() method.
  }
}
