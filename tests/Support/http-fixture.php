<?php

declare(strict_types=1);

/**
 * Router für `php -S` im CurlHttpClientTest.
 * Pfade: /set-cookie, /echo, /redirect (nach /echo), /status/503.
 */

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

switch ($path) {
    case '/set-cookie':
        setcookie('fixture', 'abc123', ['path' => '/']);
        header('Content-Type: text/plain');
        echo 'cookie set';
        break;
    case '/echo':
        header('Content-Type: application/json');
        header('X-Repeat: first');
        header('X-Repeat: second', false);
        echo json_encode([
            'method' => $_SERVER['REQUEST_METHOD'],
            'headers' => function_exists('getallheaders') ? getallheaders() : [],
            'body' => file_get_contents('php://input'),
        ], JSON_THROW_ON_ERROR);
        break;
    case '/redirect':
        header('Location: /echo', true, 302);
        break;
    case '/status/503':
        http_response_code(503);
        echo 'unavailable';
        break;
    default:
        http_response_code(404);
        echo 'not found';
}
