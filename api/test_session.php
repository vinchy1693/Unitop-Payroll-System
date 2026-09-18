<?php
session_start();

if (isset($_SESSION['counter'])) {
    $_SESSION['counter']++;
} else {
    $_SESSION['counter'] = 1;
}

echo "<h2>Session Test v2</h2>";
echo "<strong>Counter:</strong> " . $_SESSION['counter'] . "<br>";
echo "<strong>Session ID (server):</strong> " . session_id() . "<br>";
echo "<strong>Save Path:</strong> " . session_save_path() . "<br>";
echo "<strong>Is Writable:</strong> " . (is_writable(session_save_path()) ? 'Yes' : 'No') . "<br>";

echo "<hr><h3>What cookies did the SERVER receive?</h3>";
echo "<pre>";
print_r($_COOKIE);
echo "</pre>";

echo "<hr><h3>Session file on disk</h3>";
$file = session_save_path() . DIRECTORY_SEPARATOR . 'sess_' . session_id();
echo "Looking for: <code>" . htmlspecialchars($file) . "</code><br>";
echo "Exists: " . (file_exists($file) ? '<strong style="color:green;">YES</strong>' : '<strong style="color:red;">NO</strong>') . "<br>";

echo "<hr><h3>All session files in save path</h3>";
echo "<pre>";
$files = glob(session_save_path() . DIRECTORY_SEPARATOR . 'sess_*');
if ($files) {
    foreach ($files as $f) {
        echo basename($f) . " (" . filesize($f) . " bytes, " . date("H:i:s", filemtime($f)) . ")<br>";
    }
} else {
    echo "NO SESSION FILES FOUND";
}
echo "</pre>";

echo "<hr><h3>GC Settings</h3>";
echo "gc_maxlifetime: " . ini_get('session.gc_maxlifetime') . " seconds<br>";
echo "gc_probability: " . ini_get('session.gc_probability') . "<br>";
echo "gc_divisor: " . ini_get('session.gc_divisor') . "<br>";
echo "use_strict_mode: " . ini_get('session.use_strict_mode') . "<br>";
echo "use_only_cookies: " . ini_get('session.use_only_cookies') . "<br>";