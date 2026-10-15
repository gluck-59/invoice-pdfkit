<?php
declare(strict_types=1);
// Stub PHP application. Replaced by the real app later.
header('Content-Type: text/html; charset=utf-8');

function envv(string $name, string $default = ''): string {
    $v = getenv($name);
    return $v === false ? $default : $v;
}

$dbStatus = 'not checked';
try {
    $mysqli = new mysqli(
        envv('MYSQL_HOST', 'mysql'),
        envv('MYSQL_USER', 'invoices'),
        envv('MYSQL_PASSWORD', 'invoices'),
        envv('MYSQL_DATABASE', 'invoices')
    );
    if ($mysqli->connect_errno) {
        $dbStatus = 'MySQL ERROR: ' . $mysqli->connect_error;
    } else {
        $dbStatus = 'MySQL OK (server ' . $mysqli->server_info . ')';
        $mysqli->close();
    }
} catch (Throwable $e) {
    $dbStatus = 'MySQL ERROR: ' . $e->getMessage();
}
?>
<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><title>PHP 8.4 stub</title></head>
<body>
<h1>PHP <?= htmlspecialchars(PHP_VERSION) ?> stub</h1>
<p><?= htmlspecialchars($dbStatus) ?></p>
<p><a href="/preview/">PDF template preview</a></p>
</body>
</html>
