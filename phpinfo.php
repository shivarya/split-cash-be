<?php
header('Content-Type: text/html');
echo "<h2>PHP Info</h2>";
echo "<p><strong>PHP Version:</strong> " . phpversion() . "</p>";
echo "<p><strong>PDO Available:</strong> " . (extension_loaded('pdo') ? 'Yes ✅' : 'No ❌') . "</p>";
echo "<p><strong>PDO MySQL Available:</strong> " . (extension_loaded('pdo_mysql') ? 'Yes ✅' : 'No ❌') . "</p>";
echo "<p><strong>MySQLi Available:</strong> " . (extension_loaded('mysqli') ? 'Yes ✅' : 'No ❌') . "</p>";

echo "<h3>Loaded Extensions:</h3>";
echo "<pre>" . implode("\n", get_loaded_extensions()) . "</pre>";

echo "<h3>Full PHP Info:</h3>";
phpinfo();
