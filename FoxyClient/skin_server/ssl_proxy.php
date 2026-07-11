<?php
/**
 * SSL-to-HTTP proxy for the local skin server.
 * Listens on 9877 with HTTPS, forwards to the built-in server on 9876.
 */
$httpPort = (int)($argv[1] ?? 9876);
$sslPort  = (int)($argv[2] ?? 9877);
$certFile = $argv[3] ?? __DIR__ . '/ssl/server.crt';
$keyFile  = $argv[4] ?? __DIR__ . '/ssl/server.key';

if (!file_exists($certFile) || !file_exists($keyFile)) {
    fwrite(STDERR, "SSL cert/key not found\n");
    exit(1);
}

$ctx = stream_context_create([
    'ssl' => [
        'local_cert'     => $certFile,
        'local_pk'       => $keyFile,
        'verify_peer'    => false,
        'allow_self_signed' => true,
    ]
]);

$server = @stream_socket_server(
    "ssl://0.0.0.0:$sslPort", $errno, $errstr,
    STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $ctx
);

if (!$server) {
    fwrite(STDERR, "SSL proxy failed on port $sslPort: $errstr\n");
    exit(1);
}

fwrite(STDOUT, "READY\n");

while (true) {
    $client = @stream_socket_accept($server, -1);
    if (!$client) continue;

    stream_set_blocking($client, true);

    // Read request line and headers
    $req = '';
    while ($line = fgets($client)) {
        $req .= $line;
        if ($line === "\r\n") break;
        if (feof($client)) break;
    }
    if (empty($req)) { fclose($client); continue; }

    $lines = explode("\r\n", $req);
    $first = explode(' ', $lines[0] ?? '', 3);
    $method = $first[0] ?? 'GET';
    $path   = $first[1] ?? '/';

    $contentLength = 0;
    foreach ($lines as $l) {
        if (stripos($l, 'Content-Length:') === 0) {
            $contentLength = (int)trim(substr($l, 15));
        }
    }

    $body = '';
    if ($contentLength > 0) {
        $body = stream_get_contents($client, $contentLength);
    }

    // Build HTTP context for forwarding
    $opts = ['http' => [
        'method'  => $method,
        'header'  => "Host: localhost:$httpPort\r\nConnection: close\r\n",
        'content' => $body ?: null,
        'timeout' => 10,
        'ignore_errors' => true,
    ]];

    $response = @file_get_contents(
        "http://localhost:$httpPort$path", false,
        stream_context_create($opts)
    );

    if ($response === false) {
        fwrite($client, "HTTP/1.1 502 Bad Gateway\r\nContent-Length: 0\r\nConnection: close\r\n\r\n");
    } else {
        $respHeader = $http_response_header ?? [];
        $statusLine = $respHeader[0] ?? 'HTTP/1.1 200 OK';
        $forwardHeaders = '';
        $skip = ['Transfer-Encoding', 'Connection'];
        for ($i = 1; $i < count($respHeader); $i++) {
            $h = $respHeader[$i];
            $colonPos = strpos($h, ':');
            $hName = $colonPos !== false ? substr($h, 0, $colonPos) : '';
            if (!in_array($hName, $skip)) {
                $forwardHeaders .= $h . "\r\n";
            }
        }
        fwrite($client, "$statusLine\r\n{$forwardHeaders}Connection: close\r\nContent-Length: " . strlen($response) . "\r\n\r\n");
        fwrite($client, $response);
    }

    fclose($client);
}
