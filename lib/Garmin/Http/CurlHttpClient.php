<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\Garmin\Http;

/**
 * HTTP transport via curl. One handle per instance keeps the cookies in memory
 * (CURLOPT_COOKIEFILE = ''); a new instance is created per run so that cookies
 * are never shared between people.
 */
final class CurlHttpClient implements HttpClient
{
    private const MAX_REDIRECTS = 5;

    private ?\CurlHandle $handle = null;

    public function __construct(private readonly int $timeoutSeconds = 30)
    {
    }

    public function send(HttpRequest $request): HttpResponse
    {
        $handle = $this->handle();

        $headers = [];
        $headerLines = ['Expect:'];
        foreach ($request->headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }

        // curl_reset() would discard the cookie engine. Therefore all
        // request-specific options are set explicitly anew on every call.
        $method = strtoupper($request->method);
        curl_setopt($handle, CURLOPT_HTTPGET, true);
        curl_setopt($handle, CURLOPT_NOBODY, $method === 'HEAD');
        // With libcurl CUSTOMREQUEST applies to every redirect step; therefore only for special cases
        // (PUT, DELETE ...). GET/POST/HEAD use the built-in options so that a 302
        // after POST continues as GET, like in a browser.
        curl_setopt($handle, CURLOPT_CUSTOMREQUEST, in_array($method, ['GET', 'POST', 'HEAD'], true) ? null : $method);
        if ($method === 'POST') {
            curl_setopt($handle, CURLOPT_POST, true);
            curl_setopt($handle, CURLOPT_POSTFIELDS, $request->body ?? '');
        } elseif ($request->body !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $request->body);
        }
        curl_setopt($handle, CURLOPT_URL, $request->url);
        curl_setopt($handle, CURLOPT_HTTPHEADER, $headerLines);
        curl_setopt($handle, CURLOPT_HEADERFUNCTION, static function (\CurlHandle $ch, string $line) use (&$headers): int {
            $length = strlen($line);
            if (str_starts_with($line, 'HTTP/')) {
                // New status line (e.g. after a redirect): only the last response counts.
                $headers = [];
            } elseif (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $headers[strtolower(trim($name))] = trim($value);
            }

            return $length;
        });

        $body = curl_exec($handle);
        if ($body === false) {
            $error = curl_error($handle);
            throw new NetworkException($error !== '' ? $error : 'curl error ' . curl_errno($handle));
        }

        return new HttpResponse(
            (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE),
            $headers,
            (string) $body,
            (string) curl_getinfo($handle, CURLINFO_EFFECTIVE_URL),
        );
    }

    private function handle(): \CurlHandle
    {
        if ($this->handle !== null) {
            return $this->handle;
        }

        $handle = curl_init();
        if ($handle === false) {
            throw new NetworkException('curl could not be initialised');
        }
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_COOKIEFILE => '',
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => self::MAX_REDIRECTS,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => $this->timeoutSeconds,
            CURLOPT_ENCODING => '',
        ]);

        return $this->handle = $handle;
    }
}
