<?php
declare(strict_types=1);

/**
 * Reverse proxy support.
 *
 * Behind HAProxy the web server sees the proxy's address and, if TLS is
 * terminated up front, a plain HTTP request. Left alone that produces three
 * concrete bugs: session cookies lose the Secure flag, the API hands mobile
 * clients http:// image URLs that iOS blocks, and every audit entry records the
 * proxy's IP instead of the client's.
 *
 * Forwarded headers are trivially spoofable, so they are only believed when the
 * request actually arrives from an address in trusted_proxies.
 */

/** Does an IP fall inside a CIDR range (or match exactly)? */
function ip_in_range(string $ip, string $range): bool
{
    $range = trim($range);
    if ($range === '') {
        return false;
    }
    if (!str_contains($range, '/')) {
        return inet_pton($ip) !== false && inet_pton($ip) === inet_pton($range);
    }

    [$subnet, $bits] = explode('/', $range, 2);
    $bits = (int) $bits;

    $ipBin     = @inet_pton($ip);
    $subnetBin = @inet_pton($subnet);
    if ($ipBin === false || $subnetBin === false || strlen($ipBin) !== strlen($subnetBin)) {
        return false;
    }

    $bytes = intdiv($bits, 8);
    $rem   = $bits % 8;

    if ($bytes > 0 && substr($ipBin, 0, $bytes) !== substr($subnetBin, 0, $bytes)) {
        return false;
    }
    if ($rem === 0) {
        return true;
    }
    $mask = chr((0xFF << (8 - $rem)) & 0xFF);
    return (($ipBin[$bytes] & $mask) === ($subnetBin[$bytes] & $mask));
}

/** Is the immediate peer a proxy we are willing to believe? */
function from_trusted_proxy(): bool
{
    $remote = $_SERVER['REMOTE_ADDR'] ?? '';
    if ($remote === '') {
        return false;
    }
    foreach ((array) config('trusted_proxies', []) as $range) {
        if (ip_in_range($remote, (string) $range)) {
            return true;
        }
    }
    return false;
}

/**
 * The client's real address.
 *
 * X-Forwarded-For is a chain: client, proxy1, proxy2. Walk it from the right,
 * discarding addresses that are themselves trusted proxies, and take the first
 * one that is not. That is the earliest address we can still vouch for.
 */
function client_ip(): string
{
    $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    if (!from_trusted_proxy()) {
        return $remote;
    }

    $forwarded = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
    if ($forwarded !== '') {
        $chain = array_map('trim', explode(',', (string) $forwarded));
        for ($i = count($chain) - 1; $i >= 0; $i--) {
            $candidate = preg_replace('/:\d+$/', '', $chain[$i]);   // strip :port
            $candidate = trim((string) $candidate, '[]');
            if ($candidate === '' || @inet_pton($candidate) === false) {
                continue;
            }
            $trusted = false;
            foreach ((array) config('trusted_proxies', []) as $range) {
                if (ip_in_range($candidate, (string) $range)) {
                    $trusted = true;
                    break;
                }
            }
            if (!$trusted) {
                return $candidate;
            }
        }
    }

    $real = $_SERVER['HTTP_X_REAL_IP'] ?? '';
    if ($real !== '' && @inet_pton((string) $real) !== false) {
        return (string) $real;
    }

    return $remote;
}

/** Was the original client request made over HTTPS? */
function request_is_https(): bool
{
    if (from_trusted_proxy()) {
        $proto = strtolower(trim((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')));
        if ($proto !== '') {
            return explode(',', $proto)[0] === 'https';
        }
        if (($_SERVER['HTTP_X_FORWARDED_SSL'] ?? '') === 'on') {
            return true;
        }
        // RFC 7239: Forwarded: for=...;proto=https
        $fwd = (string) ($_SERVER['HTTP_FORWARDED'] ?? '');
        if ($fwd !== '' && preg_match('/proto=(https?)/i', $fwd, $m)) {
            return strtolower($m[1]) === 'https';
        }
    }
    $https = $_SERVER['HTTPS'] ?? '';
    return $https !== '' && strtolower((string) $https) !== 'off';
}

/** The host the client asked for, which may differ from the one Apache saw. */
function request_host(): string
{
    if (from_trusted_proxy()) {
        $host = trim((string) ($_SERVER['HTTP_X_FORWARDED_HOST'] ?? ''));
        if ($host !== '') {
            $host = trim(explode(',', $host)[0]);
            // Never echo an arbitrary Host back into a URL unchecked.
            if (preg_match('/^[A-Za-z0-9._\-]+(:\d+)?$/', $host)) {
                return $host;
            }
        }
    }
    return (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
}

/**
 * Apply forwarded values to $_SERVER so anything reading them directly - PHP's
 * session cookie handling among them - sees the client's view of the request.
 */
function apply_proxy_headers(): void
{
    if (!from_trusted_proxy()) {
        return;
    }
    if (request_is_https()) {
        $_SERVER['HTTPS'] = 'on';
        $_SERVER['SERVER_PORT'] = $_SERVER['SERVER_PORT'] ?? 443;
    }
    $_SERVER['HTTP_HOST']   = request_host();
    $_SERVER['REMOTE_ADDR'] = client_ip();
}
