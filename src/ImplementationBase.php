<?php

require_once 'Utils.php';

abstract class ImplementationBase {

  const DEFAULT_LOG_PATH = __DIR__.'/../log';
  const DEFAULT_ERRLOG_PATH = __DIR__.'/../log';
  const DEFAULT_DEPLOY_ROOT = __DIR__.'/../deploy';

  const CFG = __DIR__.'/../config.yml';
  const USER_CFG = __DIR__.'/../config.user.yml';

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
        $this->runDeployScript();
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
    $this->deployRoot = self::DEFAULT_DEPLOY_ROOT;
    $this->deployScriptLogPath = self::DEFAULT_LOG_PATH."/{$this->projectId}-{$this->deliveryId}.log";
    $this->deployScriptErrLogPath = self::DEFAULT_ERRLOG_PATH."/{$this->projectId}-{$this->deliveryId}.err";
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
    // TODO load logPath, errLogPath
  }

  /**
   * @throws Exception
   */
  private function runDeployScript () {
    $this->deployScriptPath = __DIR__.'/../deploy.sh';
    $exitCode = 0;

    if(!is_file($this->deployScriptLogPath)) {
      # prepare descriptors, arguments, cwd, env variables and run process
      $descriptorspec = array(
        0 => array('pipe', 'r'),
        1 => array('pipe', 'w'),
      );
      $arg = escapeshellarg((new ReflectionClass($this))->getShortName());
      $cwd = $this->deployRoot;
      $env = $this->getEnv();
      $process = proc_open($this->deployScriptPath." $arg", $descriptorspec, $pipes, $cwd, $env);
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
   * @return array
   */
  private function getEnv () {
    $env = [];
    if (!array_key_exists('scriptEnv', $this->projectConfig)) {
      return $env;
    }
    foreach ($this->projectConfig['scriptEnv'] as $name => $value) {
      # true is converted into 1, false into ''
      if (!strlen($value)) {
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
