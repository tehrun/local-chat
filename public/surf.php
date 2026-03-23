<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

$user = requireAuth();

const SURF_ALLOWED_SCHEMES = ['http', 'https'];
const SURF_RESERVED_QUERY_KEYS = ['url', 'asset', 'surf_target'];
const SURF_FETCH_TIMEOUT_SECONDS = 12;
const SURF_MAX_REDIRECTS = 5;
const SURF_USER_AGENT = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1 LocalChatSurf/1.0';

function surfNormalizeUrl(?string $candidate): ?string
{
    if (!is_string($candidate)) {
        return null;
    }

    $candidate = trim($candidate);
    if ($candidate === '') {
        return null;
    }

    if (!preg_match('~^[a-z][a-z0-9+.-]*://~i', $candidate)) {
        $candidate = 'https://' . ltrim($candidate, '/');
    }

    return $candidate;
}

function surfIsPrivateOrReservedIp(string $ip): bool
{
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }

    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
        if ($ip === '::1') {
            return true;
        }

        $normalized = strtolower($ip);
        return str_starts_with($normalized, 'fc')
            || str_starts_with($normalized, 'fd')
            || str_starts_with($normalized, 'fe8')
            || str_starts_with($normalized, 'fe9')
            || str_starts_with($normalized, 'fea')
            || str_starts_with($normalized, 'feb');
    }

    return true;
}

function surfResolveHostIps(string $host): array
{
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        return [$host];
    }

    $ips = gethostbynamel($host);
    if (is_array($ips) && $ips !== []) {
        return $ips;
    }

    if (function_exists('dns_get_record')) {
        $records = @dns_get_record($host, DNS_A + DNS_AAAA);
        if (is_array($records)) {
            $resolved = [];
            foreach ($records as $record) {
                if (isset($record['ip']) && is_string($record['ip'])) {
                    $resolved[] = $record['ip'];
                }
                if (isset($record['ipv6']) && is_string($record['ipv6'])) {
                    $resolved[] = $record['ipv6'];
                }
            }
            if ($resolved !== []) {
                return array_values(array_unique($resolved));
            }
        }
    }

    return [];
}

function surfAssertUrlIsAllowed(string $url): void
{
    $parts = parse_url($url);
    if (!is_array($parts)) {
        throw new RuntimeException('The URL is invalid.');
    }

    $scheme = strtolower((string) ($parts['scheme'] ?? ''));
    if (!in_array($scheme, SURF_ALLOWED_SCHEMES, true)) {
        throw new RuntimeException('Only http and https URLs are allowed.');
    }

    $host = strtolower((string) ($parts['host'] ?? ''));
    if ($host === '' || $host === 'localhost' || str_ends_with($host, '.localhost')) {
        throw new RuntimeException('Local addresses are blocked.');
    }

    $port = isset($parts['port']) ? (int) $parts['port'] : null;
    if ($port !== null && !in_array($port, [80, 443], true)) {
        throw new RuntimeException('Only ports 80 and 443 are allowed.');
    }

    $ips = surfResolveHostIps($host);
    if ($ips === []) {
        throw new RuntimeException('Could not resolve that host.');
    }

    foreach ($ips as $ip) {
        if (surfIsPrivateOrReservedIp($ip)) {
            throw new RuntimeException('Private or reserved network targets are blocked.');
        }
    }
}

function surfBuildAbsoluteUrl(string $baseUrl, string $candidate): ?string
{
    $candidate = trim($candidate);
    if ($candidate === '' || str_starts_with($candidate, 'javascript:') || str_starts_with($candidate, 'data:') || str_starts_with($candidate, 'mailto:') || str_starts_with($candidate, 'tel:')) {
        return null;
    }

    if (preg_match('~^[a-z][a-z0-9+.-]*://~i', $candidate)) {
        return $candidate;
    }

    $base = parse_url($baseUrl);
    if (!is_array($base) || !isset($base['scheme'], $base['host'])) {
        return null;
    }

    $scheme = $base['scheme'];
    $host = $base['host'];
    $port = isset($base['port']) ? ':' . $base['port'] : '';

    if (str_starts_with($candidate, '//')) {
        return $scheme . ':' . $candidate;
    }

    if (str_starts_with($candidate, '#')) {
        return $baseUrl . $candidate;
    }

    $fragment = '';
    $fragmentPosition = strpos($candidate, '#');
    if ($fragmentPosition !== false) {
        $fragment = substr($candidate, $fragmentPosition);
        $candidate = substr($candidate, 0, $fragmentPosition);
    }

    $query = '';
    $queryPosition = strpos($candidate, '?');
    if ($queryPosition !== false) {
        $query = substr($candidate, $queryPosition);
        $candidate = substr($candidate, 0, $queryPosition);
    }

    $path = (string) ($base['path'] ?? '/');
    $path = preg_replace('~/[^/]*$~', '/', $path) ?? '/';

    if ($candidate === '') {
        $normalizedPath = (string) ($base['path'] ?? '/');
    } elseif (str_starts_with($candidate, '/')) {
        $normalizedPath = $candidate;
    } else {
        $resolvedPath = $path . $candidate;
        $segments = [];
        foreach (explode('/', $resolvedPath) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($segments);
                continue;
            }
            $segments[] = $segment;
        }

        $normalizedPath = '/' . implode('/', $segments);
        if (str_ends_with($resolvedPath, '/') && !str_ends_with($normalizedPath, '/')) {
            $normalizedPath .= '/';
        }
    }

    return sprintf('%s://%s%s%s%s%s', $scheme, $host, $port, $normalizedPath, $query, $fragment);
}

function surfIsGoogleHost(string $host): bool
{
    $host = strtolower($host);
    return $host === 'google.com'
        || $host === 'www.google.com'
        || str_starts_with($host, 'www.google.')
        || preg_match('/(^|\.)google\.[a-z.]+$/', $host) === 1;
}

function surfOptimizeTargetUrl(string $url): string
{
    $parts = parse_url($url);
    if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
        return $url;
    }

    if (!surfIsGoogleHost((string) $parts['host'])) {
        return $url;
    }

    $path = (string) ($parts['path'] ?? '/');
    $query = [];
    if (isset($parts['query'])) {
        parse_str((string) $parts['query'], $query);
    }

    if ($path === '/' || $path === '' || $path === '/webhp') {
        $query['igu'] = '1';
        if (isset($query['q']) && $query['q'] !== '') {
            $path = '/search';
        }
    }

    if ($path === '/search') {
        $query['gbv'] = '1';
        $query['igu'] = '1';
        if (!isset($query['num']) || (string) $query['num'] === '') {
            $query['num'] = '10';
        }
    }

    $authority = $parts['host'];
    if (isset($parts['port'])) {
        $authority .= ':' . $parts['port'];
    }

    $rebuilt = sprintf('%s://%s%s', $parts['scheme'], $authority, $path);
    if ($query !== []) {
        $rebuilt .= '?' . http_build_query($query);
    }
    if (isset($parts['fragment']) && $parts['fragment'] !== '') {
        $rebuilt .= '#' . $parts['fragment'];
    }

    return $rebuilt;
}

function surfEnsureHeadElement(DOMDocument $dom): DOMElement
{
    $heads = $dom->getElementsByTagName('head');
    if ($heads->length > 0) {
        return $heads->item(0);
    }

    $head = $dom->createElement('head');
    $html = $dom->getElementsByTagName('html')->item(0);
    if ($html instanceof DOMElement) {
        $html->insertBefore($head, $html->firstChild);
        return $head;
    }

    $dom->insertBefore($head, $dom->firstChild);
    return $head;
}

function surfEnsureResponsiveDocument(DOMDocument $dom, DOMXPath $xpath): void
{
    $head = surfEnsureHeadElement($dom);

    $hasViewport = $xpath->query('//meta[@name="viewport"]');
    if ($hasViewport === false || $hasViewport->length === 0) {
        $viewport = $dom->createElement('meta');
        $viewport->setAttribute('name', 'viewport');
        $viewport->setAttribute('content', 'width=device-width, initial-scale=1, viewport-fit=cover');
        $head->appendChild($viewport);
    }

    $style = $dom->createElement('style', <<<'CSS'
html, body {
    max-width: 100%;
    overflow-x: hidden;
    -webkit-text-size-adjust: 100%;
}
img, video, iframe, table, pre, code, input, textarea, select {
    max-width: 100%;
    box-sizing: border-box;
}
body {
    visibility: visible !important;
    opacity: 1 !important;
}
CSS);
    $style->setAttribute('data-local-chat-surf', 'responsive');
    $head->appendChild($style);
}

function surfProxyUrl(string $absoluteUrl, bool $asset = false): string
{
    $absoluteUrl = surfOptimizeTargetUrl($absoluteUrl);
    $params = $asset ? ['asset' => $absoluteUrl] : ['url' => $absoluteUrl];
    return 'surf.php?' . http_build_query($params);
}

function surfFetch(string $url, bool $binary = false): array
{
    surfAssertUrlIsAllowed($url);

    $ch = curl_init($url);
    if ($ch === false) {
        throw new RuntimeException('Could not initialize the outbound request.');
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => SURF_MAX_REDIRECTS,
        CURLOPT_CONNECTTIMEOUT => SURF_FETCH_TIMEOUT_SECONDS,
        CURLOPT_TIMEOUT => SURF_FETCH_TIMEOUT_SECONDS,
        CURLOPT_USERAGENT => SURF_USER_AGENT,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_HEADER => true,
        CURLOPT_ENCODING => '',
    ]);

    $rawResponse = curl_exec($ch);
    if ($rawResponse === false) {
        $error = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException('Remote request failed: ' . $error);
    }

    $statusCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $contentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $finalUrl = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    curl_close($ch);

    surfAssertUrlIsAllowed($finalUrl);

    $headersText = substr($rawResponse, 0, $headerSize) ?: '';
    $body = substr($rawResponse, $headerSize);
    $headers = preg_split("/(?:\r\n|\n|\r){2,}/", trim($headersText)) ?: [];
    $lastHeaderBlock = trim((string) end($headers));

    return [
        'status' => $statusCode,
        'content_type' => $contentType,
        'final_url' => $finalUrl,
        'headers' => $lastHeaderBlock,
        'body' => $binary ? $body : (string) $body,
    ];
}

function surfPrepareTargetUrl(): ?string
{
    $directUrl = surfNormalizeUrl($_GET['url'] ?? null);
    if ($directUrl !== null) {
        return surfOptimizeTargetUrl($directUrl);
    }

    $formTarget = surfNormalizeUrl($_GET['surf_target'] ?? null);
    if ($formTarget === null) {
        return null;
    }

    $query = [];
    foreach ($_GET as $key => $value) {
        if (in_array($key, SURF_RESERVED_QUERY_KEYS, true)) {
            continue;
        }
        if (!is_string($key)) {
            continue;
        }
        $query[$key] = $value;
    }

    if ($query === []) {
        return surfOptimizeTargetUrl($formTarget);
    }

    $separator = str_contains($formTarget, '?') ? '&' : '?';
    return surfOptimizeTargetUrl($formTarget . $separator . http_build_query($query));
}

function surfRewriteHtml(string $html, string $baseUrl): string
{
    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $html = mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8');
    $loaded = $dom->loadHTML($html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    if ($loaded === false) {
        libxml_clear_errors();
        return '<pre>' . e($html) . '</pre>';
    }

    $xpath = new DOMXPath($dom);

    foreach ($xpath->query('//script|//noscript|//meta[@http-equiv="Content-Security-Policy"]') ?: [] as $node) {
        $node->parentNode?->removeChild($node);
    }

    foreach ($xpath->query('//a[@href]') ?: [] as $anchor) {
        $href = $anchor->getAttribute('href');
        $absolute = surfBuildAbsoluteUrl($baseUrl, $href);
        if ($absolute === null) {
            continue;
        }
        $anchor->setAttribute('href', surfProxyUrl($absolute));
        $anchor->setAttribute('rel', 'noopener noreferrer');
    }

    foreach ($xpath->query('//*[@src]') ?: [] as $node) {
        $src = $node->getAttribute('src');
        $absolute = surfBuildAbsoluteUrl($baseUrl, $src);
        if ($absolute === null) {
            $node->removeAttribute('src');
            continue;
        }
        $node->setAttribute('src', surfProxyUrl($absolute, true));
    }

    foreach ($xpath->query('//link[@href]') ?: [] as $node) {
        $href = $node->getAttribute('href');
        $absolute = surfBuildAbsoluteUrl($baseUrl, $href);
        if ($absolute === null) {
            $node->parentNode?->removeChild($node);
            continue;
        }
        $node->setAttribute('href', surfProxyUrl($absolute, true));
    }

    surfEnsureResponsiveDocument($dom, $xpath);

    foreach ($xpath->query('//form[@action] | //form[not(@action)]') ?: [] as $form) {
        $method = strtolower((string) $form->getAttribute('method'));
        if ($method !== '' && $method !== 'get') {
            $banner = $dom->createElement('p', 'POST forms are disabled in this proof of concept. Copy the target URL into the address bar instead.');
            $banner->setAttribute('style', 'padding:12px;background:#fff3cd;color:#6b4f00;border:1px solid #f0d98a;border-radius:10px;');
            $form->parentNode?->insertBefore($banner, $form);
            $form->setAttribute('onsubmit', 'return false;');
            continue;
        }

        $action = $form->getAttribute('action');
        $absolute = $action !== '' ? surfBuildAbsoluteUrl($baseUrl, $action) : $baseUrl;
        if ($absolute === null) {
            continue;
        }

        $form->setAttribute('action', 'surf.php');
        $form->setAttribute('method', 'get');

        if ($absolute !== $baseUrl) {
            $absolute = surfOptimizeTargetUrl($absolute);
        }

        $existing = $xpath->query('.//input[@type="hidden" and @name="surf_target"]', $form);
        if ($existing === false || $existing->length === 0) {
            $hidden = $dom->createElement('input');
            $hidden->setAttribute('type', 'hidden');
            $hidden->setAttribute('name', 'surf_target');
            $hidden->setAttribute('value', $absolute);
            $form->insertBefore($hidden, $form->firstChild);
        }
    }

    $bodyContent = $dom->saveHTML();
    libxml_clear_errors();

    return is_string($bodyContent) ? $bodyContent : '<pre>' . e($html) . '</pre>';
}

$assetUrl = surfNormalizeUrl($_GET['asset'] ?? null);
if ($assetUrl !== null) {
    try {
        $asset = surfFetch($assetUrl, true);
    } catch (Throwable $exception) {
        http_response_code(502);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Surf asset fetch failed: ' . $exception->getMessage();
        exit;
    }

    $contentType = $asset['content_type'] !== '' ? $asset['content_type'] : 'application/octet-stream';
    header('Content-Type: ' . $contentType);
    header('Cache-Control: private, max-age=300');
    echo $asset['body'];
    exit;
}

$defaultUrl = 'https://www.google.com/?igu=1';
$requestedUrl = surfOptimizeTargetUrl(surfPrepareTargetUrl() ?? $defaultUrl);
$pageHtml = '';
$pageError = null;
$finalUrl = null;
$statusCode = null;
$contentType = null;
$pageTitle = 'Surf Mode';

try {
    $response = surfFetch($requestedUrl);
    $finalUrl = $response['final_url'];
    $statusCode = $response['status'];
    $contentType = $response['content_type'];

    if (!str_contains(strtolower($contentType), 'text/html')) {
        $pageError = 'This proof of concept only renders HTML pages. The requested URL returned ' . $contentType . '.';
    } else {
        $pageHtml = surfRewriteHtml((string) $response['body'], $finalUrl);
        $titleHost = parse_url($finalUrl, PHP_URL_HOST);
        if (is_string($titleHost) && $titleHost !== '') {
            $pageTitle = $titleHost;
        }
    }
} catch (Throwable $exception) {
    $pageError = $exception->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle) ?></title>
    <style>
        html, body {
            margin: 0;
            min-height: 100%;
            background: #fff;
        }

        body {
            color: #111;
        }

        .surf-error {
            max-width: 760px;
            margin: 48px auto;
            padding: 20px 22px;
            border: 1px solid #f1b5b5;
            border-radius: 14px;
            background: #fff5f5;
            color: #6e2020;
            font: 16px/1.5 Inter, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        }

        .surf-error h1 {
            margin: 0 0 12px;
            font-size: 1.2rem;
        }

        .surf-error p {
            margin: 0 0 10px;
        }

        .surf-error a {
            color: inherit;
            font-weight: 600;
        }

        img, video, iframe, table {
            max-width: 100%;
        }
    </style>
</head>
<body>
<?php if ($pageError !== null): ?>
    <div class="surf-error">
        <h1>Could not open page</h1>
        <p><?= e($pageError) ?></p>
        <?php if ($finalUrl !== null): ?>
            <p>Final URL: <?= e($finalUrl) ?></p>
            <p>Status: <?= e((string) $statusCode) ?> · Content-Type: <?= e((string) $contentType) ?></p>
        <?php endif; ?>
        <p><a href="surf.php?url=<?= e(rawurlencode($defaultUrl)) ?>">Open Google</a></p>
        <p><a href="index.php">Back to chat</a></p>
    </div>
<?php else: ?>
    <?= $pageHtml ?>
<?php endif; ?>
</body>
</html>
