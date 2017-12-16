<?php

require './src/Deploy.php';

$deploy = new Deploy();

die();

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

switch (strtolower($headers['X-Github-Event'])) {
  case 'ping':
    echo 'pong';
    break;
  case 'push':
    runDeploy($headers, $json);
    break;
  default:
    header('HTTP/1.0 404 Not Found');
    echo "Event: {$headers['X-Github-Event']} is not supported\n";
    die();
}

function runDeploy ($headers, $json) {
  # define LOG and ERRLOG path
  define('LOG', __DIR__."/log/{$headers['X-Github-Delivery']}.log");
  define('ERRLOG', __DIR__."/log/{$headers['X-Github-Delivery']}.err");
  define('DEPLOY_SCRIPT', __DIR__.'/deploy.sh');
  $exit_code = 0;

  if(!is_file(LOG)) {

    # stdin, stdout
    $descriptorspec = array(
      0 => array('pipe', 'r'),
      1 => array('pipe', 'w'),
    );
    $process = proc_open(DEPLOY_SCRIPT, $descriptorspec, $pipes);
    if(!is_resource($process)) exit;

    # write json to stdind
    fwrite($pipes[0], $json);
    fclose($pipes[0]);

    # log stdout
    $stdout = stream_get_contents($pipes[1]);
    file_put_contents(LOG, $stdout);
    fclose($pipes[1]);

    # wait to stop
    $status = proc_get_status($process);
    while ($status['running']) {
      sleep(1);
      $status = proc_get_status($process);
    }
    $exit_code = $status['exitcode'];

    proc_close($process);

  } else {
    # already processed
    echo "Cached log...\n";
  }

  if ($exit_code !== 0) {
    header('HTTP/1.1 500 Internal Server Error');
  }

  # print script output
  echo file_get_contents(LOG);

  if ($exit_code !== 0) {
    rename(LOG, ERRLOG);
    throw new Exception(sprintf('Non zero exit code %s', $exit_code));
  }
}
