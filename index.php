<?php

# display all errors
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

# set error handlers
set_error_handler(function($severity, $message, $file, $line) {
  throw new \ErrorException($message, 0, $severity, $file, $line);
});
set_exception_handler(function(Exception $e) {
  header('HTTP/1.1 500 Internal Server Error');
  echo "Error on line {$e->getLine()}: " . htmlSpecialChars($e->getMessage());
  die();
});

# helpers
if (!function_exists('getallheaders'))  {
  function getallheaders() {
    if (!is_array($_SERVER)) {
      return array();
    }
    $headers = array();
    foreach ($_SERVER as $name => $value) {
      if (substr($name, 0, 5) == 'HTTP_') {
        $headers[str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))))] = $value;
      }
    }
    return $headers;
  }
}

# load secret, SECRET is mandatory
define('SECRET_PATH', __DIR__.'/SECRET');
if (!is_file(SECRET_PATH)) {
  throw new Exception('SECRET file is missing');
}
$secret = trim(file_get_contents(SECRET_PATH));

# get request headers
$headers = getallheaders();

# validate X-Hub-Signature
if (!isset($headers['X-Hub-Signature'])) {
  throw new Exception("HTTP header 'X-Hub-Signature' is missing.");
}
if (!extension_loaded('hash')) {
  throw new Exception("Missing 'hash' extension to check the secret code validity.");
}
list($algo, $hash) = explode('=', $headers['X-Hub-Signature'], 2);
if (!in_array($algo, hash_algos(), true)) {
  throw new Exception("Hash algorithm '$algo' is not supported.");
}
$rawPost = file_get_contents('php://input');
if (!hash_equals($hash, hash_hmac($algo, $rawPost, $secret))) {
  throw new Exception('Hook secret does not match.');
}

# load content
if (!isset($headers['Content-Type'])) {
  throw new Exception("Missing HTTP 'Content-Type' header.");
}
if (!isset($headers['X-Github-Event'])) {
  throw new Exception("Missing HTTP 'X-Github-Event' header.");
}
switch ($headers['Content-Type']) {
  case 'application/json':
    $json = $rawPost;
    break;
  case 'application/x-www-form-urlencoded':
    $json = $_POST['payload'];
    break;
  default:
    throw new Exception("Unsupported content type: {$headers['Content-Type']}");
}

$payload = json_decode($json);
switch (strtolower($headers['X-Github-Event'])) {
  case 'ping':
    echo 'pong';
    break;
  case 'push':
    runDeploy($headers, $payload);
    break;
  default:
    header('HTTP/1.0 404 Not Found');
    echo "Event: {$headers['X-Github-Event']} is not supported\n";
    die();
}

function runDeploy ($headers, $payload) {
  # define LOG and ERRLOG path
  define('LOG', __DIR__."/log/{$headers['X-GitHub-Delivery']}.log");
  define('ERRLOG', __DIR__."/log/{$headers['X-GitHub-Delivery']}.err");
  define('DEPLOY_SCRIPT', __DIR__.'/deploy.sh');
  $exit_code = 0;

  if(!is_file(LOG)) {

    # get process
    $descriptorspec = array(
      0 => array('pipe', 'r'),  // stdin is a pipe that the child will read from
      1 => array('pipe', 'w'),  // stdout is a pipe that the child will write to
    );
    $process = proc_open(DEPLOY_SCRIPT, $descriptorspec, $pipes);
    if(!is_resource($process)) exit;

    fwrite($pipes[0], $payload);
    fclose($pipes[0]);

    $stdout = stream_get_contents($pipes[1]);
    if(strlen($stdout)) file_put_contents(LOG, $stdout);
    fclose($pipes[1]);

    $status = proc_get_status($process);
    while ($status['running']) {
      sleep(1);
      $status = proc_get_status($process);
    }
    $exit_code = $status['exitcode'];

    proc_close($process);

  } else {
    echo "Cached log...\n";
  }

  echo file_get_contents(LOG);

  if ($exit_code !== 0) {
    rename(LOG, ERRLOG);
    throw new Exception(sprintf('Non zero exit code %s', $exit_code));
  }
}
