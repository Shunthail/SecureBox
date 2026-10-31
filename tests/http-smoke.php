<?php

declare(strict_types=1);

// Run against a running server: php tests/http-smoke.php http://127.0.0.1:3030
$baseUrl = rtrim($argv[1] ?? 'http://127.0.0.1:3030', '/');
if (!in_array(parse_url($baseUrl, PHP_URL_HOST), ['localhost', '127.0.0.1'], true)) {
    throw new RuntimeException('Use a local test server.');
}
$cookieFile = tempnam(sys_get_temp_dir(), 'securebox-cookie-');
$sampleFile = tempnam(sys_get_temp_dir(), 'securebox-file-');
if (isset($argv[2])) {
    if (!is_file($argv[2]) || !copy($argv[2], $sampleFile)) {
        throw new RuntimeException('Test file unavailable.');
    }
} else {
    file_put_contents($sampleFile, 'SecureBox HTTP round-trip sample.');
}
$ownerToken = null;
$token = null;
$csrf = '';

function request(string $method, string $path, array|string|null $body = null, array $headers = []): array
{
    global $baseUrl, $cookieFile;
    $handle = curl_init($baseUrl . $path);
    curl_setopt_array($handle, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_COOKIEJAR => $cookieFile, CURLOPT_COOKIEFILE => $cookieFile, CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => 20]);
    if ($body !== null) {
        curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
    }
    $response = curl_exec($handle);
    if ($response === false) {
        throw new RuntimeException('Request failed: ' . curl_error($handle));
    }
    $status = curl_getinfo($handle, CURLINFO_HTTP_CODE);
    $headerLength = curl_getinfo($handle, CURLINFO_HEADER_SIZE);
    return ['status' => $status, 'headers' => substr($response, 0, $headerLength), 'body' => substr($response, $headerLength)];
}

function check(bool $condition, string $label): void
{
    if (!$condition) {
        throw new RuntimeException('FAILED: ' . $label);
    }
    echo 'OK: ' . $label . "\n";
}

try {
    $home = request('GET', '/');
    check($home['status'] === 200, 'homepage');
    preg_match('/name="csrf-token" content="([^"]+)"/', $home['body'], $matches);
    $csrf = html_entity_decode($matches[1] ?? '', ENT_QUOTES, 'UTF-8');
    check($csrf !== '', 'session CSRF token');
    check(request('POST', '/api/transfers')['status'] === 403, 'missing CSRF rejected');
    $headers = ['X-CSRF-Token: ' . $csrf];
    $created = request('POST', '/api/transfers', ['file' => new CURLFile($sampleFile, 'text/plain', 'securebox-check.txt'), 'hours' => '1', 'password' => 'test-password-only'], $headers);
    check($created['status'] === 201, 'multipart encrypted upload');
    $transfer = json_decode($created['body'], true, flags: JSON_THROW_ON_ERROR);
    $token = $transfer['token'];
    $ownerToken = $transfer['ownerToken'];
    $recipient = request('GET', '/t/' . $token);
    check($recipient['status'] === 200 && !str_contains($recipient['body'], 'securebox-check.txt'), 'protected metadata hidden');
    check(request('GET', '/api/transfers/' . $token)['status'] === 404, 'owner token required');
    $wrong = request('POST', '/api/transfers/' . $token . '/download', ['password' => 'incorrect'], $headers);
    check($wrong['status'] === 403, 'wrong password rejected');
    $download = request('POST', '/api/transfers/' . $token . '/download', ['password' => 'test-password-only'], $headers);
    check($download['status'] === 200 && $download['body'] === file_get_contents($sampleFile), 'download bytes match original');
    check(str_contains(strtolower($download['headers']), 'content-disposition: attachment') && str_contains(strtolower($download['headers']), 'x-content-type-options: nosniff'), 'attachment and response protections');
    $limited = null;
    for ($attempt = 0; $attempt < 9; $attempt++) {
        $limited = request('POST', '/api/transfers/' . $token . '/download', ['password' => 'incorrect'], $headers);
    }
    check($limited['status'] === 429, 'repeated password attempts rate limited');
    $ownerHeaders = [...$headers, 'X-Owner-Token: ' . $ownerToken];
    $status = json_decode(request('GET', '/api/transfers/' . $token, null, $ownerHeaders)['body'], true, flags: JSON_THROW_ON_ERROR);
    check($status['downloads'] === 1, 'only successful download counted');
    check(request('DELETE', '/api/transfers/' . $token, null, $ownerHeaders)['status'] === 204, 'owner revocation');
    check(request('GET', '/t/' . $token)['status'] === 404, 'revoked link unavailable');
    $token = null;
} finally {
    if ($token && $ownerToken) {
        request('DELETE', '/api/transfers/' . $token, null, ['X-CSRF-Token: ' . $csrf, 'X-Owner-Token: ' . $ownerToken]);
    }
    @unlink($sampleFile);
    @unlink($cookieFile);
}
