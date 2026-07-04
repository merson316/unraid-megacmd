<?php
// Handles all MEGAcmd WebGUI actions via AJAX (never via a page-navigating form
// POST) so revisiting/refreshing the Utilities > MEGAcmd tab can never replay
// the last action. CSRF is already enforced globally by webGui/include/local_prepend.php
// for every POST request, action.php included.
require_once __DIR__ . "/common.php";

$rc = "/etc/rc.d/rc.megacmd";
$message = "";
$action = $_POST['action'] ?? '';

switch ($action) {
  case 'start':
    exec("$rc start 2>&1", $o); sleep(1);
    $message = implode("\n", $o);
    break;
  case 'stop':
    exec("$rc stop 2>&1", $o);
    $message = implode("\n", $o);
    break;
  case 'restart':
    exec("$rc restart 2>&1", $o); sleep(1);
    $message = implode("\n", $o);
    break;
  case 'login':
    $email = trim($_POST['email'] ?? '');
    $password = (string)($_POST['password'] ?? '');
    $authcode = trim($_POST['authcode'] ?? '');
    $args = "mega-login " . escapeshellarg($email) . " " . escapeshellarg($password);
    if ($authcode !== '') $args .= " --auth-code=" . escapeshellarg($authcode);
    $r = megaExec($args);
    $message = $r["output"];
    break;
  case 'logout':
    $r = megaExec("mega-logout");
    $message = $r["output"];
    break;
  case 'addsync':
    $local = trim($_POST['localpath'] ?? '');
    $remote = trim($_POST['remotepath'] ?? '');
    if ($local !== '' && $remote !== '') {
      $r = megaExec("mega-sync " . escapeshellarg($local) . " " . escapeshellarg($remote));
      $message = $r["output"];
    }
    break;
  case 'removesync':
    $id = trim($_POST['syncid'] ?? '');
    if ($id !== '') {
      $r = megaExec("mega-sync -d " . escapeshellarg($id));
      $message = $r["output"];
    }
    break;
}

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$_SESSION['megacmd_message'] = $message;
header('Content-Type: text/plain');
echo $message;
