<?php

declare(strict_types=1);

namespace OCA\WorkoutCalendar\Tests\Unit\Garmin\Http;

use OCA\WorkoutCalendar\Garmin\Http\CurlHttpClient;
use OCA\WorkoutCalendar\Garmin\Http\HttpRequest;
use OCA\WorkoutCalendar\Garmin\Http\NetworkException;
use PHPUnit\Framework\TestCase;

final class CurlHttpClientTest extends TestCase
{
    /** @var resource|null */
    private static $process = null;
    private static int $port = 0;

    public static function setUpBeforeClass(): void
    {
        self::$port = self::freePort();
        $command = [PHP_BINARY, '-S', '127.0.0.1:' . self::$port, __DIR__ . '/../../../Support/http-fixture.php'];
        $descriptors = [0 => ['pipe', 'r'], 1 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'w'], 2 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'w']];
        $process = proc_open($command, $descriptors, $pipes);
        if (!is_resource($process)) {
            self::fail('Fixture server could not be started');
        }
        self::$process = $process;

        $deadline = microtime(true) + 5.0;
        while (microtime(true) < $deadline) {
            $socket = @fsockopen('127.0.0.1', self::$port, $errno, $errstr, 0.2);
            if ($socket !== false) {
                fclose($socket);

                return;
            }
            usleep(50_000);
        }
        self::fail('Fixture server does not answer on port ' . self::$port);
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$process !== null) {
            proc_terminate(self::$process);
            proc_close(self::$process);
            self::$process = null;
        }
    }

    private static function freePort(): int
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($server === false) {
            self::fail("No free port: $errstr");
        }
        $name = stream_socket_get_name($server, false);
        fclose($server);

        return (int) substr((string) $name, (int) strrpos((string) $name, ':') + 1);
    }

    private static function url(string $path): string
    {
        return 'http://127.0.0.1:' . self::$port . $path;
    }

    public function testPostSendsBodyAndHeaders(): void
    {
        $client = new CurlHttpClient(5);

        $response = $client->send(new HttpRequest('test.echo', 'POST', self::url('/echo'), ['X-Test' => 'hello'], 'a=1'));

        self::assertSame(200, $response->status);
        $data = $response->json();
        self::assertSame('POST', $data['method']);
        self::assertSame('a=1', $data['body']);
        self::assertSame('hello', $data['headers']['X-Test']);
        self::assertSame('application/json', $response->header('Content-Type'));
    }

    public function testRepeatedHeaderKeepsLastValue(): void
    {
        $response = (new CurlHttpClient(5))->send(new HttpRequest('test.echo', 'GET', self::url('/echo')));

        self::assertSame('second', $response->header('x-repeat'));
    }

    public function testGetAfterPostDoesNotResendBody(): void
    {
        $client = new CurlHttpClient(5);
        $client->send(new HttpRequest('test.post', 'POST', self::url('/echo'), [], 'a=1'));

        $data = $client->send(new HttpRequest('test.get', 'GET', self::url('/echo')))->json();

        self::assertSame('GET', $data['method']);
        self::assertSame('', $data['body']);
    }

    public function testCookiesAreKeptWithinInstance(): void
    {
        $client = new CurlHttpClient(5);
        $client->send(new HttpRequest('test.cookie', 'GET', self::url('/set-cookie')));

        $data = $client->send(new HttpRequest('test.echo', 'GET', self::url('/echo')))->json();

        self::assertStringContainsString('fixture=abc123', $data['headers']['Cookie'] ?? '');
    }

    public function testCookiesAreNotSharedBetweenInstances(): void
    {
        (new CurlHttpClient(5))->send(new HttpRequest('test.cookie', 'GET', self::url('/set-cookie')));

        $data = (new CurlHttpClient(5))->send(new HttpRequest('test.echo', 'GET', self::url('/echo')))->json();

        self::assertArrayNotHasKey('Cookie', $data['headers']);
    }

    public function testRedirectIsFollowed(): void
    {
        $response = (new CurlHttpClient(5))->send(new HttpRequest('test.redirect', 'GET', self::url('/redirect')));

        self::assertSame(200, $response->status);
        self::assertStringEndsWith('/echo', $response->effectiveUrl);
        self::assertNull($response->header('location'));
    }

    public function testPostRedirectIsFollowedAsGet(): void
    {
        $response = (new CurlHttpClient(5))->send(new HttpRequest('test.redirect', 'POST', self::url('/redirect'), [], 'a=1'));

        $data = $response->json();
        self::assertStringEndsWith('/echo', $response->effectiveUrl);
        self::assertSame('GET', $data['method']);
        self::assertSame('', $data['body']);
    }

    public function testStatusIsReturnedNotThrown(): void
    {
        $response = (new CurlHttpClient(5))->send(new HttpRequest('test.status', 'GET', self::url('/status/503')));

        self::assertSame(503, $response->status);
        self::assertSame('unavailable', $response->body);
    }

    public function testConnectionErrorThrowsNetworkException(): void
    {
        $client = new CurlHttpClient(2);

        $this->expectException(NetworkException::class);
        $client->send(new HttpRequest('test.down', 'GET', 'http://127.0.0.1:' . self::freePort() . '/'));
    }
}
