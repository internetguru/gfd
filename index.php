<?php

# display all errors
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

# get request headers
$headers = getallheaders();

# load config


# define LOG and ERRLOG path
define('LOG', "log/${$headers['X-Request-Uuid']}.log");
define('ERRLOG', "log/${$headers['X-Request-Uuid']}.attempt${$headers['X-Attempt-Number']}.err");

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
