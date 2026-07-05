#!/usr/bin/php -q
<?php
// Runs every 5 minutes via cron (see megacmd.plg's cron install step). Restarts a crashed
// service (if enabled) and surfaces problems -- unexpected logout, sync errors, MEGA bandwidth
// quota -- as Unraid notifications (if enabled), instead of requiring someone to check the
// settings page manually.
require_once "/usr/local/emhttp/plugins/megacmd/include/common.php";

// Only act once the array is actually up -- avoids noisy failed-start attempts while the
// array is intentionally stopped.
exec("mountpoint -q /mnt/user 2>/dev/null", $o, $ret);
if ($ret !== 0 && !is_dir("/mnt/user0")) exit(0);

$cfg = getConfig();
$loggedInMarker = "$megaHome/.watchdog_loggedin";
$syncErrorsFile = "$megaHome/.watchdog_sync_errors";
$logPosFile = "$megaHome/.watchdog_logpos";

if (($cfg["WATCHDOG"] ?? "yes") === "yes" && !serviceRunning()) {
  exec("/etc/rc.d/rc.megacmd start >/dev/null 2>&1");
  logWatchdog("Service was not running -- restarted automatically.");
  notify(
    "MEGAcmd service restarted",
    "mega-cmd-server was not running and has been restarted automatically.",
    "NOTIFY_RESTART",
    "warning"
  );
}

if (!serviceRunning()) exit(0);

$whoami = megaExec("mega-whoami")["output"];
$loggedIn = stripos($whoami, "not logged in") === false && trim($whoami) !== "";

if ($loggedIn) {
  // Cheap and idempotent -- keeps Unraid's docker-safe permissions in place even if a logout/
  // login cycle (or an upgrade over an already-logged-in install) reset MEGAcmd's own defaults.
  applyPermissions();
  touch($loggedInMarker);
} elseif (file_exists($loggedInMarker)) {
  logWatchdog("Unexpectedly logged out.");
  notify(
    "MEGAcmd logged out",
    "MEGAcmd was logged in but is no longer -- syncs are paused until you log back in.",
    "NOTIFY_LOGOUT",
    "alert"
  );
  unlink($loggedInMarker);
}

if (!$loggedIn) exit(0);

// New sync errors (only notify once per distinct problem, not on every 5-minute check).
$syncs = megaListSyncsWithError();
$prevErrors = file_exists($syncErrorsFile)
  ? array_flip(array_filter(explode("\n", trim(file_get_contents($syncErrorsFile)))))
  : [];
$currentErrors = [];
foreach ($syncs as $s) {
  if ($s["error"] !== "" && strtoupper($s["error"]) !== "NO") {
    $currentErrors[] = $s["id"];
    if (!isset($prevErrors[$s["id"]])) {
      logWatchdog("Sync error: {$s['local']} -> {$s['remote']}: {$s['error']}");
      notify(
        "MEGAcmd sync error",
        "Sync {$s['local']} -> {$s['remote']} reports: {$s['error']}",
        "NOTIFY_SYNCERROR",
        "warning"
      );
    }
  }
}
file_put_contents($syncErrorsFile, implode("\n", $currentErrors) . "\n");

// MEGA bandwidth quota / other server warnings, scanned incrementally since the last check.
$logFile = "$megaHome/server.log";
if (file_exists($logFile)) {
  $size = filesize($logFile);
  $lastPos = file_exists($logPosFile) ? (int)file_get_contents($logPosFile) : $size;
  if ($lastPos > $size) $lastPos = 0; // log was rotated/truncated since last check
  $fh = fopen($logFile, "r");
  fseek($fh, $lastPos);
  $newContent = stream_get_contents($fh);
  fclose($fh);
  file_put_contents($logPosFile, (string)$size);
  if (stripos($newContent, "bandwidth quota") !== false) {
    logWatchdog("MEGA bandwidth quota reached.");
    notify(
      "MEGA bandwidth quota reached",
      "A transfer could not proceed because MEGA's free bandwidth allowance for this IP has been reached. It will resume automatically once quota is available, or consider a paid plan for more bandwidth.",
      "NOTIFY_QUOTA",
      "warning"
    );
  }
}
