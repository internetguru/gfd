<?php

require_once 'Utils.php';

abstract class ImplementationBase {

  const DEFAULT_LOG_ROOT = __DIR__.'/../log';
  const DEFAULT_ERRLOG_ROOT = __DIR__.'/../log';
  const DEFAULT_DEPLOY_ROOT = __DIR__.'/../deploy';

  const CFG = __DIR__.'/../config.yml';
  const USER_CFG = __DIR__.'/../config.user.yml';

  const PUSH_EVENT_ID = 'push';

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
  protected $deployRoot;
  /**
   * @var string
   */
  protected $hooksRoot;
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
   * @var string
   */
  protected $projectId;
  /**
   * @var array
   */
  protected $projectConfig = [];

  /**
   * ImplementationBase constructor.
   * @param array $headers
   * @param string $secret
   * @throws Exception
   */
  public function __construct ($headers, $secret) {
    $this->headers = $headers;
    $this->contentType = Utils::getHeader($this->headers, 'Content-Type', true);
    $this->setProjectId();
    $this->deliveryId = $this->getDeliveryId();
    $this->auth($secret);
    $this->loadInput();
    $this->loadCfg();
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
        $this->runDeployScript(self::PUSH_EVENT_ID);
        break;
      default:
        throw new Exception(sprintf('Event: %s is not supported', $this->event));
    }
  }

  private function setProjectId () {
    if (!array_key_exists('projectid', $_GET) || !strlen(trim($_GET['projectid']))) {
      throw new Exception('projectid GET param is missing or empty');
    }
    $this->projectId = trim($_GET['projectid']);
  }

  /**
   * @throws Exception
   */
  private function loadCfg() {
    # defaults
    $this->deployRoot = realpath(self::DEFAULT_DEPLOY_ROOT);
    $logName = "{$this->projectId}-{$this->deliveryId}.log";
    $this->deployScriptLogPath = self::DEFAULT_LOG_ROOT."/$logName";
    $errLogName = "{$this->projectId}-{$this->deliveryId}.err";
    $this->deployScriptErrLogPath = self::DEFAULT_ERRLOG_ROOT."/$errLogName";
    if (!is_file(self::CFG)) {
      return;
    }
    if (!function_exists('yaml_parse_file')) {
      throw new Exception('PHP yaml extension is not loaded');
    }
    $cfg = yaml_parse_file(self::CFG);
    if (!array_key_exists($this->projectId, $cfg)) {
      return;
    }
    $this->projectConfig = $cfg[$this->projectId];

    # load paths
    if (!array_key_exists('paths', $this->projectConfig)) {
      return;
    }
    $paths = $this->projectConfig['paths'];
    foreach ($paths as $name => $value) {
      if (substr($value, 0, 1) !== '/') {
        throw new Exception('Configuration paths must be absolute');
      }
      switch ($name) {
        case 'log':
          $this->deployScriptLogPath = "$value/$logName";
          break;
        case 'errlog':
          $this->deployScriptErrLogPath = "$value/$errLogName";
          break;
        case 'hooks':
          $this->hooksRoot = $value;
          break;
        case 'deploy':
          $this->deployRoot = $value;
          break;
      }
    }
  }

  /**
   * @param string $event
   * @throws Exception
   */
  private function runDeployScript ($event) {
    $this->deployScriptPath = __DIR__.'/../deploy.sh';
    $exitCode = 0;

    if(!is_file($this->deployScriptLogPath)) {
      # prepare descriptors, arguments, cwd, env variables and run process
      $descriptorspec = array(
        0 => array('pipe', 'r'),
        1 => array('pipe', 'w'),
        2 => array('pipe', 'r'),
      );
      $arg1 = escapeshellarg($this->projectId);
      $arg2 = escapeshellarg($event);
      $arg3 = escapeshellarg((new ReflectionClass($this))->getShortName());
      $cwd = $this->deployRoot;
      $env = $this->getEnv();
      $process = proc_open($this->deployScriptPath." $arg1 $arg2 $arg3", $descriptorspec, $pipes, $cwd, $env);
      if(!is_resource($process)) exit;

      # write input to stdin
      fwrite($pipes[0], $this->input);
      fclose($pipes[0]);

      # log stdout
      $stdout = stream_get_contents($pipes[1]);
      file_put_contents($this->deployScriptLogPath, $stdout);
      fclose($pipes[1]);

      # log stderr
      $stdout = stream_get_contents($pipes[2]);
      file_put_contents($this->deployScriptLogPath, $stdout, FILE_APPEND);
      fclose($pipes[2]);

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
   * @return array
   */
  private function getEnv () {
    $env = [
      'GFD_HOOKSROOT' => $this->hooksRoot,
    ];
    if (!array_key_exists('scriptEnv', $this->projectConfig)) {
      return $env;
    }
    foreach ($this->projectConfig['scriptEnv'] as $name => $value) {
      if ($value === false) {
        $value = 0;
      }
      $env['GFD_'.strtoupper($name)] = $value;
    }
    return $env;
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
