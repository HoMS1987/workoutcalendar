<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\Garmin\Http;

/**
 * HTTP-Transport über curl. Ein Handle pro Instanz hält die Cookies im Speicher
 * (CURLOPT_COOKIEFILE = ''); pro Lauf wird eine neue Instanz erzeugt, damit Cookies
 * nie zwischen Personen geteilt werden.
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

        // curl_reset() würde die Cookie-Engine verwerfen. Deshalb werden alle
        // anfragespezifischen Optionen bei jedem Aufruf explizit neu gesetzt.
        $method = strtoupper($request->method);
        curl_setopt($handle, CURLOPT_HTTPGET, true);
        curl_setopt($handle, CURLOPT_NOBODY, $method === 'HEAD');
        // CUSTOMREQUEST gilt bei libcurl für jeden Redirect-Schritt; deshalb nur für Sonderfälle
        // (PUT, DELETE ...). GET/POST/HEAD nutzen die eingebauten Optionen, damit ein 302
        // nach POST wie im Browser als GET weiterläuft.
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
                // Neue Statuszeile (z. B. nach einem Redirect): nur die letzte Antwort zählt.
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
