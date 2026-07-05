<?php
require_once __DIR__ . "/common.php";
header('Content-Type: text/plain');
echo getRecentLogs(200);
