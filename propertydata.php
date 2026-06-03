<?php
/**
 * PropertyData Proxy for temporary shared environments.
 *
 * Secrets come from environment variables in Render:
 * - PROPERTYDATA_API_KEY (required)
 * - PROXY_SECRET (optional; if set, X-Proxy-Token must match)
 * - ALLOWED_ORIGINS (optional CSV of allowed origins)
 */

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

function get_env_or_default(string $name, string $default = ''): string {
    $value = getenv($name);
    return $value === false ? $default : trim((string)$value);
}

$propertyDataKey = get_env_or_default('PROPERTYDATA_API_KEY');
$proxySecret = get_env_or_default('PROXY_SECRET');
$allowedOriginsCsv = get_env_or_default('ALLOWED_ORIGINS');

if ($propertyDataKey === '') {
    http_response_code(500);
    echo json_encode(['error' => 'Server is missing PROPERTYDATA_API_KEY']);
    exit;
}

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$hostOrigin = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? '');
$allowedOrigins = [$hostOrigin, 'http://localhost:8000', 'http://127.0.0.1:8000'];
if ($allowedOriginsCsv !== '') {
    foreach (explode(',', $allowedOriginsCsv) as $item) {
        $item = trim($item);
        if ($item !== '') {
            $allowedOrigins[] = $item;
        }
    }
}

if ($origin !== '' && in_array($origin, $allowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Headers: X-Proxy-Token, Content-Type');
    header('Access-Control-Allow-Methods: GET, OPTIONS');
    header('Vary: Origin');
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($proxySecret !== '') {
    $token = $_SERVER['HTTP_X_PROXY_TOKEN'] ?? '';
    if (!hash_equals($proxySecret, (string)$token)) {
        http_response_code(403);
        echo json_encode(['error' => 'Forbidden']);
        exit;
    }
}

$endpoint = trim($_GET['endpoint'] ?? 'valuation-sale');
if (!preg_match('/^[a-z][a-z0-9\-]*$/', $endpoint)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid endpoint']);
    exit;
}

$allowedEndpoints = [
    'valuation-sale',
    'valuation-rent',
    'rental-valuation',
    'sold-prices',
    'averages',
    'national-hmo-register',
    'valuation-hmo',
    'build-cost',
    'rents-hmo'
];
if (!in_array($endpoint, $allowedEndpoints, true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Endpoint not permitted']);
    exit;
}

$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$rlKey = sys_get_temp_dir() . '/propdata_rl_' . md5($ip) . '.json';
$now = time();
$rl = file_exists($rlKey) ? json_decode((string)file_get_contents($rlKey), true) : ['ts' => $now, 'count' => 0];
if (!is_array($rl) || !isset($rl['ts'], $rl['count'])) {
    $rl = ['ts' => $now, 'count' => 0];
}
if ($now - (int)$rl['ts'] > 60) {
    $rl = ['ts' => $now, 'count' => 0];
}
$rl['count'] = (int)$rl['count'] + 1;
if ($rl['count'] > 30) {
    http_response_code(429);
    echo json_encode(['error' => 'Rate limit exceeded']);
    exit;
}
file_put_contents($rlKey, json_encode($rl), LOCK_EX);

$params = $_GET;
unset($params['endpoint'], $params['key']);
$params['key'] = $propertyDataKey;

$url = 'https://api.propertydata.co.uk/' . $endpoint . '?' . http_build_query($params);

if (!function_exists('curl_init')) {
    http_response_code(502);
    echo json_encode(['error' => 'cURL not available']);
    exit;
}

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => ['Accept: application/json'],
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_TIMEOUT => 20,
    CURLOPT_CONNECTTIMEOUT => 8,
]);

$response = curl_exec($ch);
$httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($curlError) {
    http_response_code(502);
    echo json_encode(['error' => 'Upstream connection error']);
    exit;
}

http_response_code($httpCode > 0 ? $httpCode : 502);
echo $response;
