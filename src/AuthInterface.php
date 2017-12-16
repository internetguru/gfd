<?php

interface AuthInterface {

  /**
   * @param $secret
   * @throws Exception
   */
  public function auth ($secret);

}
