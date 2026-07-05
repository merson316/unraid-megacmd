#!/usr/bin/php -q
<?php
// Runs once a day via cron (see megacmd.plg's cron install step). Proactively checks whether a
// newer MEGAcmd release is available, so the admin doesn't have to remember to click "Check for
// updates" on the settings page themselves.
require_once "/usr/local/emhttp/plugins/megacmd/include/common.php";

$cfg = getConfig();
if (($cfg["NOTIFY"] ?? "yes") !== "yes") exit(0);

$codename = getPlgEntity("megacmd_repo_codename");
$installed = getPlgEntity("megacmd_version");
$latest = getLatestMegacmdVersion($codename);
if ($latest === "" || $installed === "") exit(0);

if (version_compare($latest, $installed, ">")) {
  notify(
    "MEGAcmd update available",
    "MEGAcmd $latest is available (installed: $installed). Update it from Settings > MEGAcmd.",
    "normal"
  );
}
