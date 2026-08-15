#!/usr/bin/php -q
<?php
// Runs every 5 minutes via cron (see megacmd.plg's cron install step). Restarts a crashed
// service (if enabled) and surfaces problems (unexpected logout, sync errors, MEGA bandwidth
// quota) as Unraid notifications (if enabled), instead of requiring someone to check the
// settings page manually.
require_once "/usr/local/emhttp/plugins/megacmd/include/common.php";

// Only act once the array is actually up, to avoid noisy failed-start attempts while the
// array is intentionally stopped.
exec("mountpoint -q /mnt/user 2>/dev/null", $o, $ret);
if ($ret !== 0 && !is_dir("/mnt/user0")) exit(0);

$cfg = getConfig();
$loggedInMarker = "$megaHome/.watchdog_loggedin";
$syncErrorsFile = "$megaHome/.watchdog_sync_errors";
$logPosFile = "$megaHome/.watchdog_logpos";

// A stop() (shutdown's stopping_svcs, or a manual Stop from the settings page) touches this
// marker. If it's present, the service being down is intentional, not a crash, so don't
// fight it. This matters most during a reboot/shutdown: crond keeps ticking independently of
// the array stopping, and without this check a watchdog run landing mid-shutdown would restart
// the service right as Unraid is trying to unmount shares, stalling the reboot.
if (($cfg["WATCHDOG"] ?? "yes") === "yes" && !file_exists("/var/run/megacmd.stopped") && !serviceRunning()) {
  exec("/etc/rc.d/rc.megacmd start >/dev/null 2>&1");
  logWatchdog("Service was not running. MEGAcmd restarted it automatically.");
  notify(
    "MEGAcmd service restarted",
    "mega-cmd-server was not running. MEGAcmd restarted it automatically.",
    "NOTIFY_RESTART",
    "warning"
  );
}

if (!serviceRunning()) exit(0);

$whoami = megaExec("mega-whoami")["output"];
$loggedIn = stripos($whoami, "not logged in") === false && trim($whoami) !== "";

if ($loggedIn) {
  // Cheap and idempotent: keeps Unraid's docker-safe permissions in place even if a logout/
  // login cycle (or an upgrade over an already-logged-in install) reset MEGAcmd's own defaults.
  applyPermissions();
  // Also cheap and idempotent: protects every sync root's physical disk copies from mover's
  // deletion-then-recreation cleanup, including any new copy mover created since the last tick.
  protectAllSyncRootsFromMoverCleanup();
  touch($loggedInMarker);
} elseif (file_exists($loggedInMarker)) {
  logWatchdog("Unexpectedly logged out.");
  notify(
    "MEGAcmd logged out",
    "MEGAcmd logged out unexpectedly. Your syncs stay paused until you log in again.",
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
      if (stripos($s["error"], "FSID") !== false) {
        logWatchdog("Sync disabled (FSID mismatch): {$s['local']} -> {$s['remote']}");
        notify(
          "MEGAcmd sync needs repair",
          "Sync {$s['local']} -> {$s['remote']} is disabled by a filesystem-identity mismatch. This can happen after an unclean reboot, or when Unraid's mover moves the sync folder between the cache pool and the array. Go to Settings > MEGAcmd to review it. Check the local folder, then self-heal the sync.",
          "NOTIFY_FSID",
          "alert"
        );
      } else {
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
      "A transfer stopped because this IP reached MEGA's free bandwidth allowance. It resumes automatically when quota is available again. For more bandwidth, MEGA also offers a paid plan.",
      "NOTIFY_QUOTA",
      "warning"
    );
  }
}
