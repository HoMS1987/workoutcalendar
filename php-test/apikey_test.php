<?php
/**
 * Checks the personal API key outside of Nextcloud with the app's own classes:
 * key exchange (ApiKeyTokenService) and the activity list (GarminClient), both over CurlHttpClient.
 *
 * Usage:   php apikey_test.php        (reads GARMIN_API_KEY from .env in the project root)
 *
 * Prints status, token expiry, claim names and activity count. Never prints the key or the token;
 * claim values other than exp are shortened.
 */

declare(strict_types=1);

use OCA\WorkoutCalendar\Garmin\Auth\ApiKeyTokenProvider;
use OCA\WorkoutCalendar\Garmin\Auth\ApiKeyTokenService;
use OCA\WorkoutCalendar\Garmin\Auth\GarminTokens;
use OCA\WorkoutCalendar\Garmin\Auth\TokenStore;
use OCA\WorkoutCalendar\Garmin\Diagnostics\Redactor;
use OCA\WorkoutCalendar\Garmin\GarminClient;
use OCA\WorkoutCalendar\Garmin\GarminConstants;
use OCA\WorkoutCalendar\Garmin\GarminException;
use OCA\WorkoutCalendar\Garmin\Http\CurlHttpClient;
use Psr\Log\NullLogger;

const PROJECT_DIR = __DIR__ . '/..';

require PROJECT_DIR . '/vendor/autoload.php';

/** @return array<string, string> */
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

function fail(GarminException $e): never
{
    fwrite(STDERR, sprintf("FAILED  step=%s  category=%s  status=%s\n  %s\n  excerpt: %s\n",
        $e->step, $e->category->value, $e->status ?? '-', $e->getMessage(), $e->excerpt));
    exit(1);
}

function shorten(mixed $value): string
{
    $text = is_scalar($value) ? (string)$value : json_encode($value);
    return strlen($text) <= 4 ? str_repeat('*', strlen($text)) : substr($text, 0, 2) . '…' . substr($text, -2) . ' (' . strlen($text) . ' chars)';
}

/** Keeps the token in memory only. */
final class MemoryTokenStore implements TokenStore
{
    private ?GarminTokens $tokens = null;

    public function load(): ?GarminTokens
    {
        return $this->tokens;
    }

    public function save(GarminTokens $tokens): void
    {
        $this->tokens = $tokens;
    }
}

$apiKey = loadEnv(PROJECT_DIR . '/.env')['GARMIN_API_KEY'] ?? '';
if ($apiKey === '') {
    fwrite(STDERR, "GARMIN_API_KEY is missing in .env\n");
    exit(1);
}

$redactor = new Redactor([$apiKey]);
$http = new CurlHttpClient();
$service = new ApiKeyTokenService($http, $redactor);

try {
    $tokens = $service->exchange($apiKey);
} catch (GarminException $e) {
    fail($e);
}

$expiresAt = $tokens->expiresAt();
echo "Exchange OK\n";
printf("  expires: %s (in %.1f h)\n", $expiresAt !== null ? gmdate('Y-m-d H:i:s', $expiresAt) . ' UTC' : '-', $expiresAt !== null ? ($expiresAt - time()) / 3600 : 0);
echo "  claims:\n";
foreach ($tokens->claims() as $name => $value) {
    printf("    %-24s %s\n", $name, $name === 'exp' || $name === 'iat' ? (string)$value : get_debug_type($value) . ' ' . shorten($value));
}
$garminId = ApiKeyTokenService::garminUserId($tokens);
printf("  Garmin ID for the account identity: %s\n", $garminId !== null ? shorten($garminId) : 'none (identity falls back to apikey:)');

$store = new MemoryTokenStore();
$store->save($tokens);
$client = new GarminClient($http, new ApiKeyTokenProvider($service, $store, $apiKey), new NullLogger(), GarminConstants::PERSONAL_API, [$apiKey]);

try {
    $activities = $client->activities(0, 5);
} catch (GarminException $e) {
    fail($e);
}

printf("Activities OK: %d returned\n", count($activities));
foreach ($activities as $activity) {
    printf("  %s  %s  %s\n", $activity['startTimeLocal'] ?? '-', $activity['activityType']['typeKey'] ?? '-', isset($activity['activityId']) ? 'id ' . shorten($activity['activityId']) : '');
}
