<?php
/**
 * grapesjs-fonts — CORS proxy for font catalogue APIs.
 *
 * The catalogue (metadata) APIs of Google Fonts, Bunny Fonts, Fontsource and Fontshare send no CORS
 * headers, so a browser page cannot read them. This script fetches them server-side, caches the
 * answer on disk and returns it with CORS headers. Font files and stylesheets are NOT proxied:
 * the browser loads them straight from the CDNs.
 *
 * Routes are compatible with the unifont proxy (https://github.com/unjs/unifont/tree/main/proxy):
 *
 *   GET fonts-proxy.php/google/v1/fonts                       -> https://fonts.google.com/metadata/fonts
 *   GET fonts-proxy.php/bunny/v1/list                         -> https://fonts.bunny.net/list
 *   GET fonts-proxy.php/fontsource/v1/fonts                   -> https://api.fontsource.org/v1/fonts
 *   GET fonts-proxy.php/fontshare/v1/fonts?offset=0&limit=100 -> https://api.fontshare.com/v2/fonts
 *
 * If your server does not pass PATH_INFO, use the `path` query parameter instead:
 *   fonts-proxy.php?path=/bunny/v1/list
 *
 * Plugin option:  proxy: '/plugins/grapesjs-fonts/proxy/fonts-proxy.php'
 *
 * Settings can be changed below or through environment variables of the same name.
 * Requires PHP 7.2+ with the curl extension or allow_url_fopen + openssl.
 */

// Origin(s) allowed to read the answers: '*' or a comma-separated list, e.g. 'https://site.com,http://localhost'.
$ALLOWED_ORIGINS = getenv('FONTS_PROXY_ALLOWED_ORIGINS') ?: '*';
// Cache lifetime in seconds (catalogues change rarely).
$CACHE_TTL = (int) (getenv('FONTS_PROXY_CACHE_TTL') ?: 86400);
// Cache directory (must be writable). Default: system temp dir.
$CACHE_DIR = getenv('FONTS_PROXY_CACHE_DIR') ?: rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'grapesjs-fonts-proxy';
// Upstream request timeout in seconds.
$TIMEOUT = (int) (getenv('FONTS_PROXY_TIMEOUT') ?: 25);

// ---------------------------------------------------------------------------------------------

function fp_cors($allowed)
{
    $origin = isset($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : '';
    if (trim($allowed) === '*') {
        header('Access-Control-Allow-Origin: *');
    } else {
        $list = array_map('trim', explode(',', $allowed));
        if ($origin !== '' && in_array($origin, $list, true)) {
            header('Access-Control-Allow-Origin: ' . $origin);
            header('Vary: Origin');
        }
    }
    header('Access-Control-Allow-Methods: GET, HEAD, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    header('Access-Control-Max-Age: 86400');
}

function fp_fail($status, $message)
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array('error' => $message));
    exit;
}

/** @return array{0:int,1:string} [status, body] */
function fp_fetch($url, $timeout)
{
    $ua = 'grapesjs-fonts-proxy/1.0 (+https://github.com/shibisty)';
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_USERAGENT => $ua,
            CURLOPT_HTTPHEADER => array('Accept: application/json'),
            CURLOPT_ENCODING => '',
        ));
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($body === false) {
            return array(0, $err);
        }
        return array($status, $body);
    }
    $ctx = stream_context_create(array('http' => array(
        'method' => 'GET',
        'timeout' => $timeout,
        'header' => "User-Agent: $ua\r\nAccept: application/json\r\n",
        'ignore_errors' => true,
    )));
    $body = @file_get_contents($url, false, $ctx);
    $status = 0;
    if (isset($http_response_header) && is_array($http_response_header)) {
        foreach ($http_response_header as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
                $status = (int) $m[1];
            }
        }
    }
    return array($status, $body === false ? '' : $body);
}

// ---------------------------------------------------------------------------------------------

fp_cors($ALLOWED_ORIGINS);

$method = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET';
if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}
if ($method !== 'GET' && $method !== 'HEAD') {
    header('Allow: GET, HEAD, OPTIONS');
    fp_fail(405, 'Method not allowed');
}

$path = '';
if (!empty($_SERVER['PATH_INFO'])) {
    $path = $_SERVER['PATH_INFO'];
} elseif (isset($_GET['path'])) {
    $path = (string) $_GET['path'];
}
$path = '/' . trim($path, '/');

// Whitelist: nothing else can be requested through this script.
$upstream = null;
switch ($path) {
    case '/google/v1/fonts':
        $upstream = 'https://fonts.google.com/metadata/fonts';
        break;
    case '/bunny/v1/list':
        $upstream = 'https://fonts.bunny.net/list';
        break;
    case '/fontsource/v1/fonts':
        $upstream = 'https://api.fontsource.org/v1/fonts';
        break;
    case '/fontshare/v1/fonts':
        $offset = isset($_GET['offset']) ? $_GET['offset'] : '0';
        $limit = isset($_GET['limit']) ? $_GET['limit'] : '100';
        if (!preg_match('/^\d{1,5}$/', $offset) || !preg_match('/^\d{1,3}$/', $limit) || (int) $limit < 1) {
            fp_fail(400, 'Invalid offset/limit');
        }
        $upstream = 'https://api.fontshare.com/v2/fonts?offset=' . (int) $offset . '&limit=' . (int) $limit;
        break;
    case '/':
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(array(
            'service' => 'grapesjs-fonts catalogue proxy',
            'endpoints' => array('/google/v1/fonts', '/bunny/v1/list', '/fontsource/v1/fonts', '/fontshare/v1/fonts?offset=&limit='),
        ));
        exit;
    default:
        fp_fail(404, 'Unknown endpoint: ' . $path);
}

$cacheFile = $CACHE_DIR . DIRECTORY_SEPARATOR . sha1($upstream) . '.json';
$cached = is_file($cacheFile) ? @filemtime($cacheFile) : false;

header('Content-Type: application/json; charset=utf-8');

if ($cached !== false && (time() - $cached) < $CACHE_TTL) {
    header('X-Fonts-Proxy-Cache: HIT');
    header('Cache-Control: public, max-age=' . max(0, $CACHE_TTL - (time() - $cached)));
    if ($method !== 'HEAD') {
        readfile($cacheFile);
    }
    exit;
}

list($status, $body) = fp_fetch($upstream, $TIMEOUT);
// Google prefixes JSON with an XSSI guard.
if ($body !== '' && strpos($body, ")]}'") === 0) {
    $body = ltrim(substr($body, 4));
}
$valid = $status >= 200 && $status < 300 && $body !== '' && json_decode($body) !== null;

if (!$valid) {
    if ($cached !== false) {
        // Upstream is down: serve the stale copy rather than nothing.
        header('X-Fonts-Proxy-Cache: STALE');
        header('Cache-Control: no-cache');
        if ($method !== 'HEAD') {
            readfile($cacheFile);
        }
        exit;
    }
    fp_fail(502, 'Upstream request failed (' . ($status ?: 'network error') . ')');
}

if (!is_dir($CACHE_DIR)) {
    @mkdir($CACHE_DIR, 0775, true);
}
$tmp = $cacheFile . '.' . getmypid() . '.tmp';
if (@file_put_contents($tmp, $body) !== false) {
    @rename($tmp, $cacheFile);
}

header('X-Fonts-Proxy-Cache: MISS');
header('Cache-Control: public, max-age=' . $CACHE_TTL);
if ($method !== 'HEAD') {
    echo $body;
}
