<?php

echo "<h1>CompAcc POS - System Check</h1>";

echo "<p>PHP Version: " . PHP_VERSION . "</p>";

echo "<hr>";

if (extension_loaded('pdo')) {
    echo "<p>✅ PDO is enabled.</p>";
} else {
    echo "<p>❌ PDO is NOT enabled.</p>";
}

if (extension_loaded('pdo_sqlite')) {
    echo "<p>✅ PDO SQLite is enabled.</p>";
} else {
    echo "<p>❌ PDO SQLite is NOT enabled.</p>";
}

if (extension_loaded('sqlite3')) {
    echo "<p>✅ SQLite3 is enabled.</p>";
} else {
    echo "<p>❌ SQLite3 is NOT enabled.</p>";
}

echo "<hr>";

if (
    extension_loaded('pdo') &&
    extension_loaded('pdo_sqlite')
) {
    echo "<h2>✅ Ready for PHP + SQLite!</h2>";
} else {
    echo "<h2>❌ SQLite configuration needs to be fixed.</h2>";
}