<?php
// Handles all MEGAcmd WebGUI actions via AJAX (never via a page-navigating form
// POST) so revisiting/refreshing the Utilities > MEGAcmd tab can never replay
// the last action. CSRF is already enforced globally by webGui/include/local_prepend.php
// for every POST request, action.php included.
require_once __DIR__ . "/common.php";

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

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
    // MEGAcmd only remembers speed limits for the life of a login session, so re-apply ours.
    applySpeedlimit();
    break;
  case 'logout':
    $r = megaExec("mega-logout");
    $message = $r["output"];
    break;
  case 'addsync':
    $local = trim($_POST['localpath'] ?? '');
    $remote = trim($_POST['remotepath'] ?? '');
    $synctype = $_POST['synctype'] ?? 'sync';
    if ($local !== '' && $remote !== '') {
      if ($synctype === 'backup') {
        $period = trim($_POST['backupperiod'] ?? '') ?: '1d';
        $numBackups = max(1, (int)($_POST['numbackups'] ?? 7));
        $r = megaExec(
          "mega-backup " . escapeshellarg($local) . " " . escapeshellarg($remote) .
          " --period=" . escapeshellarg($period) . " --num-backups=" . $numBackups
        );
      } else {
        $r = megaExec("mega-sync " . escapeshellarg($local) . " " . escapeshellarg($remote));
      }
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
  case 'removebackup':
    $tag = trim($_POST['backuptag'] ?? '');
    if ($tag !== '') {
      $r = megaExec("mega-backup -d " . escapeshellarg($tag));
      $message = $r["output"];
    }
    break;
  case 'savesettings':
    $newCfg = [
      "NOTIFY" => ($_POST['notify'] ?? '') === 'yes' ? 'yes' : 'no',
      "WATCHDOG" => ($_POST['watchdog'] ?? '') === 'yes' ? 'yes' : 'no',
      "SPEEDLIMIT_UP" => trim($_POST['speedlimit_up'] ?? ''),
      "SPEEDLIMIT_DOWN" => trim($_POST['speedlimit_down'] ?? ''),
    ];
    saveConfig($newCfg);
    applySpeedlimit();
    $message = "Settings saved.";
    break;
  case 'checkmegacmdupdate':
    $codename = getPlgEntity('megacmd_repo_codename');
    $installed = getPlgEntity('megacmd_version');
    $latest = getLatestMegacmdVersion($codename);
    if ($latest === "") {
      $_SESSION['megacmd_latest_checked'] = null;
      $message = "Could not check for updates (network issue, or MEGA's repo listing format changed).";
    } else {
      $_SESSION['megacmd_latest_checked'] = $latest;
      $message = version_compare($latest, $installed, '>')
        ? "Update available: MEGAcmd $latest (installed: $installed)."
        : "MEGAcmd is up to date ($installed).";
    }
    break;
  case 'updatemegacmd':
    $codename = getPlgEntity('megacmd_repo_codename');
    $github = getPlgEntity('github');
    $branch = getPlgEntity('branch');
    $latest = getLatestMegacmdVersion($codename);
    if ($latest === "" || !preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/', $latest)) {
      $message = "Could not determine the latest MEGAcmd version -- update aborted.";
      break;
    }
    $rawbase = "https://raw.githubusercontent.com/$github/$branch";
    $tmp = "/tmp/megacmd-fetch-" . uniqid() . ".sh";
    exec("curl -fsSL -o " . escapeshellarg($tmp) . " " . escapeshellarg("$rawbase/scripts/fetch-megacmd.sh") . " 2>&1", $o1, $r1);
    if ($r1 !== 0) {
      $message = "Failed to fetch the update script:\n" . implode("\n", $o1);
      break;
    }
    exec("chmod +x " . escapeshellarg($tmp));
    exec("$rc stop 2>&1");
    exec(escapeshellarg($tmp) . " " . escapeshellarg($codename) . " " . escapeshellarg($latest) . " 2>&1", $o3, $r3);
    @unlink($tmp);
    if ($r3 !== 0) {
      exec("$rc start 2>&1");
      $message = "Update to $latest failed:\n" . implode("\n", $o3);
      break;
    }
    $message = setPlgEntity('megacmd_version', $latest)
      ? "Updated MEGAcmd to $latest."
      : "Installed MEGAcmd $latest but could not persist the version pin in megacmd.plg -- it will revert to the old version on next reboot.";
    $message .= "\n" . implode("\n", $o3);
    unset($_SESSION['megacmd_latest_checked']);
    exec("$rc start 2>&1"); sleep(1);
    break;
}

$_SESSION['megacmd_message'] = $message;
header('Content-Type: text/plain');
echo $message;
