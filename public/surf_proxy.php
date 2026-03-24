<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

requireAuth();

$rawUrl = trim((string) ($_GET['url'] ?? ''));
if ($rawUrl === '') {
    header('Location: surf.php');
    exit;
}

$targetUrl = surfValidateTargetUrl($rawUrl);
if ($targetUrl === null) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Invalid or blocked URL.';
    exit;
}

$response = surfFetchRemoteUrl($targetUrl);
if ($response === null) {
    http_response_code(502);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Could not load remote page via proxy.';
    exit;
}

http_response_code($response['status']);
header_remove('Content-Security-Policy');
header_remove('X-Frame-Options');

if ($response['content_type'] !== '') {
    header('Content-Type: ' . $response['content_type']);
}

foreach ($response['forward_headers'] as $headerLine) {
    header($headerLine, false);
}

if ($response['is_html']) {
    echo surfRewriteHtml((string) $response['body'], $response['final_url']);
    exit;
}

echo $response['body'];

function surfValidateTargetUrl(string $rawUrl): ?string
{
    $candidate = trim($rawUrl);
    if ($candidate === '') {
        return null;
    }

    if (!preg_match('/^[a-zA-Z][a-zA-Z\d+.-]*:\/\//', $candidate)) {
        $candidate = 'https://' . $candidate;
    }

    $parts = parse_url($candidate);
    if (!is_array($parts)) {
        return null;
    }

    $scheme = strtolower((string) ($parts['scheme'] ?? ''));
    $host = strtolower((string) ($parts['host'] ?? ''));
    if (($scheme !== 'http' && $scheme !== 'https') || $host === '') {
        return null;
    }

    if (surfIsBlockedHost($host)) {
        return null;
    }

    return $candidate;
}

function surfIsBlockedHost(string $host): bool
{
    if ($host === 'localhost' || str_ends_with($host, '.localhost')) {
        return true;
    }

    if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        return surfIsBlockedIpv4($host);
    }

    if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
        return surfIsBlockedIpv6($host);
    }

    $resolved = @gethostbynamel($host);
    if (is_array($resolved)) {
        foreach ($resolved as $ip) {
            if (surfIsBlockedIpv4($ip)) {
                return true;
            }
        }
    }

    return false;
}

function surfIsBlockedIpv4(string $ip): bool
{
    $long = ip2long($ip);
    if ($long === false) {
        return true;
    }

    $ranges = [
        ['0.0.0.0', '0.255.255.255'],
        ['10.0.0.0', '10.255.255.255'],
        ['127.0.0.0', '127.255.255.255'],
        ['169.254.0.0', '169.254.255.255'],
        ['172.16.0.0', '172.31.255.255'],
        ['192.168.0.0', '192.168.255.255'],
    ];

    foreach ($ranges as [$start, $end]) {
        $startLong = ip2long($start);
        $endLong = ip2long($end);
        if ($startLong !== false && $endLong !== false && $long >= $startLong && $long <= $endLong) {
            return true;
        }
    }

    return false;
}

function surfIsBlockedIpv6(string $ip): bool
{
    $normalized = strtolower($ip);
    return $normalized === '::1'
        || $normalized === '::'
        || str_starts_with($normalized, 'fe80:')
        || str_starts_with($normalized, 'fc')
        || str_starts_with($normalized, 'fd');
}

function surfFetchRemoteUrl(string $targetUrl): ?array
{
    $ch = curl_init();
    if ($ch === false) {
        return null;
    }

    curl_setopt_array($ch, [
        CURLOPT_URL => $targetUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HEADER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_USERAGENT => 'LocalChatSurfProxy/1.0',
    ]);

    $raw = curl_exec($ch);
    if (!is_string($raw)) {
        curl_close($ch);
        return null;
    }

    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $contentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $finalUrl = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);

    curl_close($ch);

    $rawHeaders = substr($raw, 0, $headerSize);
    $body = substr($raw, $headerSize);

    if (!is_string($rawHeaders) || !is_string($body)) {
        return null;
    }

    $forwardHeaders = [];
    foreach (preg_split('/\r\n|\n|\r/', trim($rawHeaders)) as $line) {
        if (!is_string($line) || $line === '' || !str_contains($line, ':')) {
            continue;
        }

        [$name, $value] = array_map('trim', explode(':', $line, 2));
        $lower = strtolower($name);
        if (in_array($lower, ['content-length', 'content-security-policy', 'x-frame-options', 'transfer-encoding', 'connection'], true)) {
            continue;
        }

        if (in_array($lower, ['set-cookie'], true)) {
            continue;
        }

        $forwardHeaders[] = $name . ': ' . $value;
    }

    $isHtml = str_contains(strtolower($contentType), 'text/html');

    return [
        'status' => $status > 0 ? $status : 200,
        'content_type' => $contentType,
        'forward_headers' => $forwardHeaders,
        'body' => $body,
        'is_html' => $isHtml,
        'final_url' => $finalUrl !== '' ? $finalUrl : $targetUrl,
    ];
}

function surfRewriteHtml(string $html, string $baseUrl): string
{
    $pattern = '/\b(href|src|action)=(["\'])(.*?)\2/i';

    $rewritten = preg_replace_callback($pattern, static function (array $match) use ($baseUrl): string {
        $attribute = $match[1];
        $quote = $match[2];
        $value = trim($match[3]);

        if ($value === '' || str_starts_with($value, '#') || str_starts_with(strtolower($value), 'javascript:') || str_starts_with(strtolower($value), 'data:')) {
            return $match[0];
        }

        $absolute = surfAbsolutizeUrl($baseUrl, $value);
        if ($absolute === null) {
            return $match[0];
        }

        $proxied = 'surf_proxy.php?url=' . rawurlencode($absolute);
        return sprintf('%s=%s%s%s', $attribute, $quote, htmlspecialchars($proxied, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), $quote);
    }, $html);

    return is_string($rewritten) ? $rewritten : $html;
}

function surfAbsolutizeUrl(string $base, string $value): ?string
{
    if (preg_match('/^[a-zA-Z][a-zA-Z\d+.-]*:\/\//', $value)) {
        return surfValidateTargetUrl($value);
    }

    $baseParts = parse_url($base);
    if (!is_array($baseParts)) {
        return null;
    }

    $scheme = (string) ($baseParts['scheme'] ?? 'https');
    $host = (string) ($baseParts['host'] ?? '');
    $port = isset($baseParts['port']) ? ':' . (int) $baseParts['port'] : '';
    $path = (string) ($baseParts['path'] ?? '/');

    if ($host === '') {
        return null;
    }

    if (str_starts_with($value, '//')) {
        return surfValidateTargetUrl($scheme . ':' . $value);
    }

    if (str_starts_with($value, '/')) {
        return surfValidateTargetUrl($scheme . '://' . $host . $port . $value);
    }

    $dir = preg_replace('#/[^/]*$#', '/', $path) ?: '/';
    $candidate = $scheme . '://' . $host . $port . $dir . $value;

    return surfValidateTargetUrl($candidate);
}
