<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

$user = requireAuth();

const SURF_ALLOWED_SCHEMES = ['http', 'https'];
const SURF_RESERVED_QUERY_KEYS = ['url', 'asset', 'surf_target'];
const SURF_FETCH_TIMEOUT_SECONDS = 12;
const SURF_MAX_REDIRECTS = 5;

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

    $path = (string) ($base['path'] ?? '/');
    $path = preg_replace('~/[^/]*$~', '/', $path) ?? '/';

    if (str_starts_with($candidate, '/')) {
        $resolvedPath = $candidate;
    } else {
        $resolvedPath = $path . $candidate;
    }

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

    return sprintf('%s://%s%s%s', $scheme, $host, $port, $normalizedPath);
}

function surfProxyUrl(string $absoluteUrl, bool $asset = false): string
{
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
        CURLOPT_USERAGENT => 'LocalChat Surf Mode POC/1.0',
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
        return $directUrl;
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
        return $formTarget;
    }

    $separator = str_contains($formTarget, '?') ? '&' : '?';
    return $formTarget . $separator . http_build_query($query);
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

    foreach ($xpath->query('//script|//noscript|//meta[@http-equiv]') ?: [] as $node) {
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

$requestedUrl = surfPrepareTargetUrl();
$pageHtml = '';
$pageError = null;
$finalUrl = null;
$statusCode = null;
$contentType = null;
$defaultUrl = 'https://www.google.com/';

if ($requestedUrl !== null) {
    try {
        $response = surfFetch($requestedUrl);
        $finalUrl = $response['final_url'];
        $statusCode = $response['status'];
        $contentType = $response['content_type'];

        if (!str_contains(strtolower($contentType), 'text/html')) {
            $pageError = 'This proof of concept only renders HTML pages. The requested URL returned ' . $contentType . '.';
        } else {
            $pageHtml = surfRewriteHtml((string) $response['body'], $finalUrl);
        }
    } catch (Throwable $exception) {
        $pageError = $exception->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Surf Mode · Local Chat</title>
    <style>
        :root {
            color-scheme: light;
            --bg: #0b141a;
            --panel: #111b21;
            --card: #202c33;
            --ink: #e9edef;
            --muted: #8696a0;
            --accent: #25d366;
            --danger: #ff6b6b;
            --border: rgba(255, 255, 255, 0.1);
        }

        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: Inter, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            background: linear-gradient(180deg, #0b141a 0%, #111b21 100%);
            color: var(--ink);
        }

        .shell {
            max-width: 1200px;
            margin: 0 auto;
            min-height: 100vh;
            padding: 24px;
        }

        .topbar, .panel {
            background: rgba(17, 27, 33, 0.92);
            border: 1px solid var(--border);
            border-radius: 18px;
            backdrop-filter: blur(12px);
            box-shadow: 0 18px 45px rgba(0, 0, 0, 0.28);
        }

        .topbar {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            padding: 18px 20px;
            margin-bottom: 18px;
        }

        .topbar h1 { margin: 0 0 6px; font-size: 1.35rem; }
        .topbar p { margin: 0; color: var(--muted); }
        .actions { display: flex; gap: 12px; flex-wrap: wrap; }
        .button, .address-bar button {
            appearance: none;
            border: 0;
            border-radius: 12px;
            padding: 12px 16px;
            font: inherit;
            cursor: pointer;
            text-decoration: none;
        }

        .button { background: #233138; color: var(--ink); }
        .button.primary, .address-bar button { background: var(--accent); color: #06260f; font-weight: 700; }
        .panel { padding: 20px; }
        .address-bar { display: flex; gap: 12px; flex-wrap: wrap; margin-bottom: 16px; }
        .address-bar input {
            flex: 1 1 540px;
            min-width: 220px;
            border-radius: 12px;
            border: 1px solid var(--border);
            background: #0f171c;
            color: var(--ink);
            padding: 13px 14px;
            font: inherit;
        }

        .note, .error, .meta {
            border-radius: 14px;
            padding: 14px 16px;
            margin-bottom: 16px;
        }

        .note { background: rgba(37, 211, 102, 0.12); color: #c7f7d7; border: 1px solid rgba(37, 211, 102, 0.25); }
        .error { background: rgba(255, 107, 107, 0.12); color: #ffd7d7; border: 1px solid rgba(255, 107, 107, 0.25); }
        .meta { background: rgba(255, 255, 255, 0.04); color: var(--muted); border: 1px solid var(--border); }
        .browser {
            background: #fff;
            color: #111;
            border-radius: 18px;
            overflow: hidden;
            min-height: 70vh;
            border: 1px solid rgba(255,255,255,0.08);
        }

        .browser-inner {
            padding: 0;
            overflow: auto;
        }

        .browser-inner img, .browser-inner video, .browser-inner iframe { max-width: 100%; }
        .browser-inner form, .browser-inner table { max-width: 100%; }

        @media (max-width: 720px) {
            .shell { padding: 14px; }
            .topbar, .panel { border-radius: 14px; }
            .address-bar { flex-direction: column; }
            .address-bar button { width: 100%; }
        }
    </style>
</head>
<body>
<div class="shell">
    <div class="topbar">
        <div>
            <h1>Surf mode</h1>
            <p>Proof of concept server-side browsing for signed-in users. Signed in as <strong><?= e($user['username']) ?></strong>.</p>
        </div>
        <div class="actions">
            <a class="button" href="index.php">← Back to chat</a>
            <a class="button" href="surf.php?url=<?= e(rawurlencode($defaultUrl)) ?>">Open Google</a>
        </div>
    </div>

    <div class="panel">
        <div class="note">
            Remote pages are fetched by the server with cURL, then rewritten to stay inside this app. This proof of concept blocks local/private hosts, strips scripts, and only supports HTML pages and GET-based navigation.
        </div>

        <form class="address-bar" method="get" action="surf.php">
            <input
                type="text"
                name="url"
                value="<?= e($requestedUrl ?? $defaultUrl) ?>"
                placeholder="Enter a public URL, for example https://www.google.com/"
                autocomplete="off"
                spellcheck="false"
            >
            <button type="submit">Browse via server</button>
        </form>

        <?php if ($pageError !== null): ?>
            <div class="error">Could not open that page: <?= e($pageError) ?></div>
        <?php elseif ($finalUrl !== null): ?>
            <div class="meta">
                <strong>Final URL:</strong> <?= e($finalUrl) ?><br>
                <strong>Status:</strong> <?= e((string) $statusCode) ?><br>
                <strong>Content-Type:</strong> <?= e((string) $contentType) ?>
            </div>
        <?php endif; ?>

        <div class="browser">
            <div class="browser-inner">
                <?php if ($pageHtml !== ''): ?>
                    <?= $pageHtml ?>
                <?php else: ?>
                    <div style="padding: 28px;">
                        <h2 style="margin-top: 0;">Ready to browse</h2>
                        <p>Try <a href="surf.php?url=<?= e(rawurlencode($defaultUrl)) ?>">Google</a> or enter another public URL above.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
</body>
</html>
