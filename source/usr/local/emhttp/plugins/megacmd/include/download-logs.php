<?php
require_once __DIR__ . "/common.php";
header('Content-Type: text/plain');
header('Content-Disposition: attachment; filename="megacmd-logs-' . date('Y-m-d_His') . '.txt"');
echo getRecentLogs(1000);
