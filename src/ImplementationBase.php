<?php

require_once 'Utils.php';

abstract class ImplementationBase {

  /**
   * @var array
   */
  protected $headers;
  /**
   * @var string
   */
  protected $rawInput;
  /**
   * @var string
   */
  protected $input;
  /**
   * @var string
   */
  protected $contentType;
  /**
   * @var string
   */
  protected $event;
  /**
   * @var string
   */
  protected $deliveryId;
  /**
   * @var string
   */
  protected $deployScriptPath;
  /**
   * @var string
   */
  protected $deployScriptLogPath;
  /**
   * @var string
   */
  protected $deployScriptErrLogPath;

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
    $this->loadInput();
    $this->deploy();
  }

  /**
   * @throws Exception
   */
  private function deploy () {
    $this->event = $this->getEvent();
    switch ($this->event) {
      case 'ping':
        echo 'pong';
        break;
      case $this->getPushEventName():
        $this->runDeployScript();
        break;
      default:
        throw new Exception(sprintf('Event: %s is not supported', $this->event));
    }
  }

  private function runDeployScript () {
    $this->deliveryId = $this->getDeliveryId();
    $this->deployScriptPath = __DIR__.'/../deploy.sh';
    $this->deployScriptLogPath = __DIR__."/../log/{$this->deliveryId}.log";
    $this->deployScriptErrLogPath = __DIR__."/../log/{$this->deliveryId}.err";
    $exitCode = 0;

    if(!is_file($this->deployScriptLogPath)) {
      # prepare arguments and run process
      $descriptorspec = array(
        0 => array('pipe', 'r'),
        1 => array('pipe', 'w'),
      );
      $arg = escapeshellarg((new ReflectionClass($this))->getShortName());
      $process = proc_open($this->deployScriptPath." $arg", $descriptorspec, $pipes);
      if(!is_resource($process)) exit;

      # write input to stdin
      fwrite($pipes[0], $this->input);
      fclose($pipes[0]);

      # log stdout
      $stdout = stream_get_contents($pipes[1]);
      file_put_contents($this->deployScriptLogPath, $stdout);
      fclose($pipes[1]);

      # wait until process exits
      $status = proc_get_status($process);
      while ($status['running']) {
        sleep(1);
        $status = proc_get_status($process);
      }

      # get exit code and close process
      $exitCode = $status['exitcode'];
      proc_close($process);
    } else {
      # already processed
      echo "Cached log...\n";
    }

    $log = file_get_contents($this->deployScriptLogPath);
    if ($exitCode !== 0) {
      rename($this->deployScriptLogPath, $this->deployScriptErrLogPath);
      throw new Exception(sprintf("Non zero exit code %s. Script output: \n\n%s:", $exitCode, $log));
    }
    echo $log;
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

  /**
   * @return string
   */
  abstract protected function getPushEventName();

  /**
   * @return string
   * @throws Exception
   */
  abstract protected function getDeliveryId ();

  /**
   * @throws exception
   */
  abstract protected function loadInput ();
}
