<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/routes.php';

$body = json_decode((string) file_get_contents('php://input'), true) ?? [];
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
[$status, $payload] = app()->dispatch($_SERVER['REQUEST_METHOD'] ?? 'GET', $path, $body);

http_response_code($status);
header('Content-Type: application/json');
echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
