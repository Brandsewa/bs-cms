<?php

declare(strict_types=1);

function testMcp(array $payload): array {
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $env = [
        'REQUEST_METHOD' => 'POST',
        'SYSTEMROOT' => getenv('SYSTEMROOT') ?: 'C:\WINDOWS',
        'PATH' => getenv('PATH') ?: '',
    ];

    $process = proc_open('php -r "$_SERVER[\'REQUEST_METHOD\']=\'POST\'; require \'spikes/mcp-spike/mcp.php\';"', $descriptors, $pipes, null, $env);
    if (!is_resource($process)) {
        throw new RuntimeException("Could not launch php process");
    }

    fwrite($pipes[0], json_encode($payload));
    fclose($pipes[0]);

    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    proc_close($process);

    if (!empty($stderr)) {
        echo "STDERR: {$stderr}\n";
    }

    return json_decode($stdout, true) ?: ['raw' => $stdout];
}

echo "Testing initialize...\n";
$initRes = testMcp(['jsonrpc' => '2.0', 'method' => 'initialize', 'id' => 1]);
print_r($initRes);

echo "\nTesting tools/list...\n";
$listRes = testMcp(['jsonrpc' => '2.0', 'method' => 'tools/list', 'id' => 2]);
print_r($listRes);

echo "\nTesting tools/call (ping)...\n";
$callRes = testMcp([
    'jsonrpc' => '2.0',
    'method' => 'tools/call',
    'params' => ['name' => 'ping', 'arguments' => ['message' => 'Hello from Phase 0']],
    'id' => 3,
]);
print_r($callRes);
