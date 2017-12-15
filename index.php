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
$secretPath = __DIR__.'/SECRET';
if (!is_file($secretPath)) {
  throw new Exception('SECRET file is missing');
}
$secret = trim(file_get_contents($secretPath));

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
# Payload structure depends on triggered event
# https://developer.github.com/v3/activity/events/types/
$payload = json_decode($json);
switch (strtolower($headers['X-Github-Event'])) {
  // case 'ping':
  //   echo 'pong';
  //   break;
  //	case 'push':
  //		break;
  //	case 'create':
  //		break;
  default:
    header('HTTP/1.0 404 Not Found');
    echo "Event:{$headers['X-Github-Event']} Payload:\n";
    print_r($payload);
    die();
}

# load config
#TODO

# define LOG and ERRLOG path
define('LOG', "log/{$headers['X-Request-Uuid']}.log");
define('ERRLOG', "log/{$headers['X-Request-Uuid']}.attempt{$headers['X-Attempt-Number']}.err");

$exit_code = 0;

if(!is_file(LOG)) {

  # read input
  $stdin = file_get_contents('php://input');
  if(!strlen($stdin)) {
    echo 'No input';
    exit;
  }

  # get process
  $descriptorspec = array(
     0 => array('pipe', 'r'),  // stdin is a pipe that the child will read from
     1 => array('pipe', 'w'),  // stdout is a pipe that the child will write to
  );
  $process = proc_open('/var/local/scripts/updategit', $descriptorspec, $pipes);
  if(!is_resource($process)) exit;

  fwrite($pipes[0], $stdin);
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
  echo 'Cached...';
}

if ($exit_code !== 0) {
  header('HTTP/1.1 500 Internal Server Error');
  http_response_code(500);
}

echo "\nExit status: $exit_code\n";
echo file_get_contents(LOG);

if ($exit_code !== 0) {
  rename(LOG, ERRLOG);
}
