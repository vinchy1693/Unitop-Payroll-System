<?php
define("AUTH_ENDPOINT", true);
require __DIR__ . "/_bootstrap.php";

header("Content-Type: text/plain; charset=utf-8");

echo "=== DEBUG auth.php state ===\n\n";
echo "Session ID: " . session_id() . "\n\n";

echo "Session data (\$_SESSION):\n";
print_r($_SESSION);

echo "\nCookie received (\$_COOKIE):\n";
print_r($_COOKIE);

echo "\nSession file: " . session_save_path() . DIRECTORY_SEPARATOR . 'sess_' . session_id() . "\n";
echo "File exists: " . (file_exists(session_save_path() . DIRECTORY_SEPARATOR . 'sess_' . session_id()) ? "YES" : "NO") . "\n";
echo "File size: " . @filesize(session_save_path() . DIRECTORY_SEPARATOR . 'sess_' . session_id()) . " bytes\n";

echo "\n--- currentUser() result ---\n";
$u = currentUser();
var_dump($u);