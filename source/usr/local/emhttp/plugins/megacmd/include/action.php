<?php
// Handles all MEGAcmd WebGUI actions via AJAX (never via a page-navigating form
// POST) so revisiting/refreshing the Settings > MEGAcmd tab can never replay
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
    // MEGAcmd only remembers speed limits and file/folder permissions for the life of a login
    // session, so both need to be re-applied after every fresh login.
    applySpeedlimit();
    applyPermissions();
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
  case 'selfhealsync':
    $syncId = trim($_POST['syncid'] ?? '');
    $message = $syncId !== '' ? selfHealSyncFsid($syncId) : "No sync selected.";
    break;
  case 'savesettings':
    $watchdogInterval = $_POST['watchdog_interval'] ?? '5';
    if (!in_array($watchdogInterval, ['1', '5', '10', '15', '30', '60'], true)) $watchdogInterval = '5';
    // MEGAcmd itself refuses anything below 600/700 and won't restrict the owner bits further,
    // so validate here too rather than silently letting an invalid value fall through to it.
    $permFiles = trim($_POST['perm_files'] ?? '666');
    if (!preg_match('/^[0-7]{3}$/', $permFiles) || (int)$permFiles < 600) $permFiles = '666';
    $permFolders = trim($_POST['perm_folders'] ?? '777');
    if (!preg_match('/^[0-7]{3}$/', $permFolders) || (int)$permFolders < 700) $permFolders = '777';
    $newCfg = [
      "NOTIFY_RESTART" => ($_POST['notify_restart'] ?? '') === 'yes' ? 'yes' : 'no',
      "NOTIFY_LOGOUT" => ($_POST['notify_logout'] ?? '') === 'yes' ? 'yes' : 'no',
      "NOTIFY_SYNCERROR" => ($_POST['notify_syncerror'] ?? '') === 'yes' ? 'yes' : 'no',
      "NOTIFY_FSID" => ($_POST['notify_fsid'] ?? '') === 'yes' ? 'yes' : 'no',
      "NOTIFY_QUOTA" => ($_POST['notify_quota'] ?? '') === 'yes' ? 'yes' : 'no',
      "NOTIFY_UPDATE" => ($_POST['notify_update'] ?? '') === 'yes' ? 'yes' : 'no',
      "WATCHDOG" => ($_POST['watchdog'] ?? '') === 'yes' ? 'yes' : 'no',
      "WATCHDOG_INTERVAL" => $watchdogInterval,
      "PERM_FILES" => $permFiles,
      "PERM_FOLDERS" => $permFolders,
      "SPEEDLIMIT_UP" => trim($_POST['speedlimit_up'] ?? ''),
      "SPEEDLIMIT_DOWN" => trim($_POST['speedlimit_down'] ?? ''),
    ];
    saveConfig($newCfg);
    applySpeedlimit();
    applyPermissions();
    regenerateCron();
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
