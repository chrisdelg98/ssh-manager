<?php
/**
 * SSH Connectivity Test
 *
 * Run from CLI: php test_connectivity.php <host> [port]
 * Tests if Hostinger allows outbound SSH connections to your VPS.
 *
 * DELETE THIS FILE after testing.
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("CLI only. Delete this file after use.\n");
}

$host = $argv[1] ?? null;
$port = (int)($argv[2] ?? 22);

if (!$host) {
    exit("Usage: php test_connectivity.php <host> [port]\n");
}

echo "Testing TCP connectivity to $host:$port ...\n";
$fp = @fsockopen($host, $port, $errno, $errstr, 5);
if ($fp) {
    fclose($fp);
    echo "✓ TCP port $port is REACHABLE on $host\n";
    echo "  Hostinger allows outbound connections on port $port.\n";
    echo "  phpseclib SSH should work.\n";
} else {
    echo "✗ CANNOT reach $host:$port — Error $errno: $errstr\n";
    echo "  Hostinger may be blocking outbound port $port.\n";
    echo "  Try a different SSH port on your VPS (e.g., 2222).\n";
}
