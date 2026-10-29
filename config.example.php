<?php
/**
 * Cow Bingo Configuration
 * 
 * Copy this file to config.php and update with your actual values.
 * cp config.example.php config.php
 */

// Database connection
define('DB_HOST', 'localhost');
define('DB_NAME', 'cow_bingo');
define('DB_USER', 'cowbingo');
define('DB_PASS', 'CHANGE_ME');

// Hi.Events configuration
define('HIEVENTS_BASE_URL', 'https://events.example.com');
define('HIEVENTS_EMAIL', 'admin@example.com');
define('HIEVENTS_PASSWORD', 'CHANGE_ME');
define('WEBHOOK_SECRET', '');     // Optional: webhook signing secret if configured

// Site branding
define('SITE_TITLE', '🐄 THIS-ORGANISATION Cow Bingo 🐄');

// Timezone
date_default_timezone_set('Pacific/Auckland');

/**
 * Helper: get a PDO database connection
 */
function getDbConnection(): PDO
{
    static $db = null;
    if ($db === null) {
        $db = new PDO(
            sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', DB_HOST, DB_NAME),
            DB_USER,
            DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    }
    return $db;
}

/**
 * Helper: get a JWT token from the hi.events API
 */
function getHiEventsToken(): ?string
{
    $url = HIEVENTS_BASE_URL . '/api/auth/login';
    $payload = json_encode([
        'email' => HIEVENTS_EMAIL,
        'password' => HIEVENTS_PASSWORD,
    ]);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
    ]);
    $response = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($response, true);
    return $data['token'] ?? null;
}

/**
 * Helper: fetch events list from hi.events API
 */
function fetchHiEventsEvents(): array
{
    $token = getHiEventsToken();
    if (!$token) {
        return [];
    }

    $url = HIEVENTS_BASE_URL . '/api/events';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
    ]);
    $response = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($response, true);
    return $data['data'] ?? [];
}

/**
 * Helper: fetch the slug for a specific hi.events event
 */
function fetchEventSlug(string $eventId): ?string
{
    $token = getHiEventsToken();
    if (!$token) return null;

    $url = HIEVENTS_BASE_URL . '/api/events/' . urlencode($eventId);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
    ]);
    $response = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($response, true);
    return $data['slug'] ?? $data['data']['slug'] ?? null;
}
