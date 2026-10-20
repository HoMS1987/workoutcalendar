<?php
/**
 * Machbarkeitstest: Garmin-Zugriff mit reinem PHP (ext-curl), ohne Browser-TLS-Impersonation.
 *
 * Bildet die Abläufe von python-garminconnect 0.3.19 nach, damit die Ergebnisse vergleichbar sind.
 *
 * Aufruf:  php garmin_test.php <modus>
 *   api            Aktivitäten mit dem vorhandenen Python-Token abrufen (nur lesend)
 *   refresh        Token-Refresh mit dem Python-Refresh-Token, Ergebnis nach php_tokens.json
 *   login-mobile   Login wie die iOS-App (Strategie "mobile+requests")
 *   login-widget   Login über das SSO-Embed-Widget (wie "widget+cffi", aber ohne Impersonation)
 *   login-portal   Login über das Web-Portal (Strategie "portal+requests")
 *
 * Ausgegeben werden nur HTTP-Status und Ergebnis, nie Passwörter oder Tokens.
 */

declare(strict_types=1);

const PROJECT_DIR = __DIR__ . '/..';
const PYTHON_TOKEN_FILE = PROJECT_DIR . '/.garminconnect/garmin_tokens.json';
const PHP_TOKEN_FILE = PROJECT_DIR . '/.garminconnect/php_tokens.json';

const SSO = 'https://sso.garmin.com';
const CONNECTAPI = 'https://connectapi.garmin.com';
const DI_TOKEN_URL = 'https://diauth.garmin.com/di-oauth2-service/oauth/token';
const DI_GRANT_TYPE = 'https://connectapi.garmin.com/di-oauth2-service/oauth/grant/service_ticket';
const DI_CLIENT_IDS = [
    'GARMIN_CONNECT_MOBILE_ANDROID_DI_2025Q2',
    'GARMIN_CONNECT_MOBILE_ANDROID_DI_2024Q4',
    'GARMIN_CONNECT_MOBILE_ANDROID_DI',
    'GARMIN_CONNECT_MOBILE_IOS_DI',
];

const IOS_SSO_CLIENT_ID = 'GCM_IOS_DARK';
const IOS_SERVICE_URL = 'https://mobile.integration.garmin.com/gcm/ios';
const IOS_LOGIN_UA = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148';
const PORTAL_SSO_CLIENT_ID = 'GarminConnect';
const PORTAL_SERVICE_URL = 'https://connect.garmin.com/app';
const DESKTOP_UA = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36';

const NATIVE_HEADERS = [
    'User-Agent' => 'GCM-Android-5.23',
    'X-Garmin-User-Agent' => 'com.garmin.android.apps.connectmobile/5.23; ; Google/sdk_gphone64_arm64/google; Android/33; Dalvik/2.1.0',
    'X-Garmin-Paired-App-Version' => '10861',
    'X-Garmin-Client-Platform' => 'Android',
    'X-App-Ver' => '10861',
    'X-Lang' => 'en',
    'X-GCExperience' => 'GC5',
    'Accept-Language' => 'en-US,en;q=0.9',
];

final class Http
{
    private CurlShareHandle $share;

    public function __construct()
    {
        $this->share = curl_share_init();
        curl_share_setopt($this->share, CURLSHOPT_SHARE, CURL_LOCK_DATA_COOKIE);
    }

    /** @return array{status:int, body:string, url:string} */
    public function request(string $method, string $url, array $headers = [], ?string $body = null): array
    {
        $ch = curl_init($url);
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = "$name: $value";
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_ENCODING => '',
            CURLOPT_HTTPHEADER => $lines,
            CURLOPT_COOKIEFILE => '',
            CURLOPT_SHARE => $this->share,
            CURLOPT_CUSTOMREQUEST => $method,
        ]);
        if (PHP_OS_FAMILY === 'Windows') {
            // WHY: Windows-PHP bringt kein CA-Bundle mit, daher den Windows-Zertifikatsspeicher nutzen.
            curl_setopt($ch, CURLOPT_SSL_OPTIONS, CURLSSLOPT_NATIVE_CA);
        }
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $response = curl_exec($ch);
        if ($response === false) {
            throw new RuntimeException('curl: ' . curl_error($ch));
        }
        return [
            'status' => curl_getinfo($ch, CURLINFO_HTTP_CODE),
            'body' => $response,
            'url' => curl_getinfo($ch, CURLINFO_EFFECTIVE_URL),
        ];
    }
}

function loadEnv(string $path): array
{
    $env = [];
    if (!is_file($path)) {
        return $env;
    }
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $env[trim($key)] = trim(trim($value), "\"'");
    }
    return $env;
}

function credentials(): array
{
    $env = loadEnv(PROJECT_DIR . '/.env');
    $email = $env['GARMIN_EMAIL'] ?? '';
    $password = $env['GARMIN_PASSWORD'] ?? '';
    if ($email === '' || $password === '') {
        fail('GARMIN_EMAIL und GARMIN_PASSWORD fehlen in .env');
    }
    return [$email, $password];
}

function readTokens(string $file): array
{
    if (!is_file($file)) {
        fail("Token-Datei fehlt: $file");
    }
    return json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
}

function writePhpTokens(array $tokens): void
{
    file_put_contents(PHP_TOKEN_FILE, json_encode($tokens, JSON_PRETTY_PRINT));
    echo "Tokens gespeichert in .garminconnect/php_tokens.json\n";
}

function jwtClientId(string $jwt): ?string
{
    $parts = explode('.', $jwt);
    if (count($parts) < 2) {
        return null;
    }
    $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
    return is_array($payload) ? ($payload['client_id'] ?? null) : null;
}

function fail(string $message): never
{
    echo "FEHLER: $message\n";
    exit(1);
}

function pageTitle(string $html): string
{
    return preg_match('#<title>(.+?)</title>#', $html, $m) ? $m[1] : '';
}

function diTokenPost(Http $http, string $clientId, array $form): array
{
    $headers = NATIVE_HEADERS + [
        'Authorization' => 'Basic ' . base64_encode("$clientId:"),
        'Accept' => 'application/json',
        'Content-Type' => 'application/x-www-form-urlencoded',
        'Cache-Control' => 'no-cache',
    ];
    return $http->request('POST', DI_TOKEN_URL, $headers, http_build_query($form));
}

function exchangeTicket(Http $http, string $ticket, string $serviceUrl): array
{
    foreach (DI_CLIENT_IDS as $clientId) {
        $r = diTokenPost($http, $clientId, [
            'client_id' => $clientId,
            'service_ticket' => $ticket,
            'grant_type' => DI_GRANT_TYPE,
            'service_url' => $serviceUrl,
        ]);
        echo "  DI-Token-Tausch ($clientId): HTTP {$r['status']}\n";
        if ($r['status'] === 200) {
            $data = json_decode($r['body'], true);
            return [
                'di_token' => $data['access_token'],
                'di_refresh_token' => $data['refresh_token'] ?? null,
                'di_client_id' => jwtClientId($data['access_token']) ?? $clientId,
            ];
        }
    }
    fail('DI-Token-Tausch für alle Client-IDs fehlgeschlagen');
}

function postCredentials(Http $http, string $url, array $params, array $headers, string $serviceUrl): void
{
    [$email, $password] = credentials();
    $body = json_encode(['username' => $email, 'password' => $password, 'rememberMe' => true, 'captchaToken' => '']);
    $r = $http->request('POST', $url . '?' . http_build_query($params), $headers, $body);
    echo "  Credentials-POST: HTTP {$r['status']}\n";
    $res = json_decode($r['body'], true);
    $type = is_array($res) ? ($res['responseStatus']['type'] ?? 'unbekannt') : 'keine JSON-Antwort';
    echo "  responseStatus: $type\n";
    if ($type !== 'SUCCESSFUL') {
        fail('Login nicht erfolgreich');
    }
    writePhpTokens(exchangeTicket($http, $res['serviceTicketId'], $serviceUrl));
}

function loginMobile(Http $http): void
{
    postCredentials(
        $http,
        SSO . '/mobile/api/login',
        ['clientId' => IOS_SSO_CLIENT_ID, 'locale' => 'en-US', 'service' => IOS_SERVICE_URL],
        [
            'User-Agent' => IOS_LOGIN_UA,
            'Accept' => 'application/json, text/plain, */*',
            'Content-Type' => 'application/json',
            'Origin' => SSO,
        ],
        IOS_SERVICE_URL,
    );
}

function loginPortal(Http $http): void
{
    $signinUrl = SSO . '/portal/sso/en-US/sign-in';
    $query = http_build_query(['clientId' => PORTAL_SSO_CLIENT_ID, 'service' => PORTAL_SERVICE_URL]);
    $r = $http->request('GET', "$signinUrl?$query", [
        'User-Agent' => DESKTOP_UA,
        'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        'Accept-Language' => 'en-US,en;q=0.9',
    ]);
    echo "  Signin-GET: HTTP {$r['status']}\n";
    $delay = random_int(10, 20);
    echo "  Warte {$delay}s (wie die Python-Bibliothek)...\n";
    sleep($delay);
    postCredentials(
        $http,
        SSO . '/portal/api/login',
        ['clientId' => PORTAL_SSO_CLIENT_ID, 'locale' => 'en-US', 'service' => PORTAL_SERVICE_URL],
        [
            'User-Agent' => DESKTOP_UA,
            'Accept' => 'application/json, text/plain, */*',
            'Accept-Language' => 'en-US,en;q=0.9',
            'Content-Type' => 'application/json',
            'Origin' => SSO,
            'Referer' => "$signinUrl?$query",
        ],
        PORTAL_SERVICE_URL,
    );
}

function loginWidget(Http $http): void
{
    [$email, $password] = credentials();
    $ssoBase = SSO . '/sso';
    $embed = "$ssoBase/embed";
    $browser = ['User-Agent' => DESKTOP_UA, 'Accept-Language' => 'en-US,en;q=0.9'];
    $embedParams = ['id' => 'gauth-widget', 'embedWidget' => 'true', 'gauthHost' => $ssoBase];
    $signinParams = [
        ...$embedParams,
        'gauthHost' => $embed,
        'service' => $embed,
        'source' => $embed,
        'redirectAfterAccountLoginUrl' => $embed,
        'redirectAfterAccountCreationUrl' => $embed,
    ];

    $r = $http->request('GET', "$embed?" . http_build_query($embedParams), $browser);
    echo "  Embed-GET: HTTP {$r['status']}\n";
    $r = $http->request('GET', "$ssoBase/signin?" . http_build_query($signinParams), $browser + ['Referer' => $embed]);
    echo "  Signin-GET: HTTP {$r['status']}, Titel: '" . pageTitle($r['body']) . "'\n";
    if (!preg_match('/name="_csrf"\s+value="(.+?)"/', $r['body'], $csrf)) {
        fail('Kein CSRF-Token gefunden');
    }

    $delay = random_int(3, 8);
    echo "  Warte {$delay}s (wie die Python-Bibliothek)...\n";
    sleep($delay);

    $form = http_build_query(['username' => $email, 'password' => $password, 'embed' => 'true', '_csrf' => $csrf[1]]);
    $r = $http->request(
        'POST',
        "$ssoBase/signin?" . http_build_query($signinParams),
        $browser + ['Referer' => $r['url'], 'Content-Type' => 'application/x-www-form-urlencoded'],
        $form,
    );
    $title = pageTitle($r['body']);
    echo "  Signin-POST: HTTP {$r['status']}, Titel: '$title'\n";
    if ($title !== 'Success') {
        fail('Login nicht erfolgreich');
    }
    if (!preg_match('/\?ticket=(ST-[^"&\s]+)/', $r['body'], $ticket)) {
        fail('Kein Service-Ticket gefunden');
    }
    writePhpTokens(exchangeTicket($http, $ticket[1], $embed));
}

function refresh(Http $http): void
{
    $tokens = readTokens(PYTHON_TOKEN_FILE);
    $r = diTokenPost($http, $tokens['di_client_id'], [
        'grant_type' => 'refresh_token',
        'client_id' => $tokens['di_client_id'],
        'refresh_token' => $tokens['di_refresh_token'],
    ]);
    echo "  Token-Refresh: HTTP {$r['status']}\n";
    if ($r['status'] !== 200) {
        fail('Refresh fehlgeschlagen');
    }
    $data = json_decode($r['body'], true);
    writePhpTokens([
        'di_token' => $data['access_token'],
        'di_refresh_token' => $data['refresh_token'] ?? $tokens['di_refresh_token'],
        'di_client_id' => jwtClientId($data['access_token']) ?? $tokens['di_client_id'],
    ]);
}

function activities(Http $http): void
{
    $file = is_file(PHP_TOKEN_FILE) && in_array('--php-token', $GLOBALS['argv'], true) ? PHP_TOKEN_FILE : PYTHON_TOKEN_FILE;
    echo '  Token aus ' . basename($file) . "\n";
    $tokens = readTokens($file);
    $headers = NATIVE_HEADERS + ['Authorization' => 'Bearer ' . $tokens['di_token'], 'Accept' => 'application/json'];
    $r = $http->request('GET', CONNECTAPI . '/activitylist-service/activities/search/activities?start=0&limit=3', $headers);
    echo "  Aktivitäten-GET: HTTP {$r['status']}\n";
    if ($r['status'] !== 200) {
        fail('Abruf fehlgeschlagen');
    }
    foreach (json_decode($r['body'], true) as $a) {
        printf("  %s  %-14s  %s\n", $a['startTimeLocal'], $a['activityType']['typeKey'], $a['activityName']);
    }
}

$mode = $argv[1] ?? '';
$v = curl_version();
echo "PHP " . PHP_VERSION . ", curl {$v['version']}, {$v['ssl_version']}, Modus: $mode\n";

$http = new Http();
match ($mode) {
    'api' => activities($http),
    'refresh' => refresh($http),
    'login-mobile' => loginMobile($http),
    'login-widget' => loginWidget($http),
    'login-portal' => loginPortal($http),
    default => fail('Modus fehlt: api | refresh | login-mobile | login-widget | login-portal'),
};
echo "OK\n";
