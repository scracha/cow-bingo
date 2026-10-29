<?php
// includes/admin_helpers.php

require_once __DIR__ . '/bingo_helpers.php';

function canManageEvent(PDO $db, int $adminId, int $eventId): bool {
    $stmt = $db->prepare("SELECT 1 FROM cow_bingo_admin_events WHERE admin_id = ? AND event_id = ?");
    $stmt->execute([$adminId, $eventId]);
    return (bool) $stmt->fetch();
}

/**
 * Helper: Securely handle event image uploads
 */
function handleEventImageUpload(array $file): ?string {
    if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
        return null;
    }
    if ($file['size'] > 6 * 1024 * 1024) { // 6MB maximum
        return null;
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);
    $allowedMimes = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'image/gif'  => 'gif',
    ];
    if (!isset($allowedMimes[$mime])) {
        return null;
    }
    $ext = $allowedMimes[$mime];
    $uploadDir = __DIR__ . '/../uploads/events';
    if (!is_dir($uploadDir)) {
        @mkdir($uploadDir, 0755, true);
    }
    $htaccess = $uploadDir . '/.htaccess';
    if (!file_exists($htaccess)) {
        @file_put_contents($htaccess, "Options -Indexes\n<FilesMatch \"\\.(php|phtml|php3|php4|php5|php7|php8|phps|cgi|pl|sh)$\">
Require all denied\n</FilesMatch>\n");
    }
    $filename = 'event_' . bin2hex(random_bytes(8)) . '.' . $ext;
    $targetPath = $uploadDir . '/' . $filename;
    if (move_uploaded_file($file['tmp_name'], $targetPath)) {
        return 'uploads/events/' . $filename;
    }
    return null;
}

/**
 * Helper: Safely delete an uploaded event image file from disk
 */
function deleteEventImageFile(?string $relPath): void {
    if (empty($relPath)) return;
    $fullPath = __DIR__ . '/../' . ltrim($relPath, '/\\');
    $realUploads = realpath(__DIR__ . '/../uploads');
    $realTarget = realpath($fullPath);
    if ($realTarget && $realUploads && str_starts_with($realTarget, $realUploads) && file_exists($realTarget)) {
        @unlink($realTarget);
    }
}

/**
 * Sync Attendees from Hi.Events (Safe: Never demotes locally paid cells back to unpaid)
 */
function syncAttendeesFromHiEvents(PDO $db, array $event): int {
    $token = getHiEventsToken();
    if (!$token) return 0;

    $hieventsEventId = $event['hievents_event_id'];
    $eventDbId = (int) $event['id'];
    $gridCols = (int) $event['grid_cols'];
    $gridRows = (int) $event['grid_rows'];
    $synced = 0;
    $page = 1;
    $unpaidStatuses = ['AWAITING_PAYMENT', 'PENDING', 'UNPAID', 'RESERVED'];

    do {
        $url = HIEVENTS_BASE_URL . "/api/events/$hieventsEventId/attendees?page=$page";
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
        ]);
        $response = curl_exec($ch);
        curl_close($ch);

        $data = json_decode($response, true);
        $attendees = $data['data'] ?? [];

        foreach ($attendees as $attendee) {
            $ticketId = $attendee['short_id'] ?? $attendee['public_id'] ?? (string)$attendee['id'];
            $status = strtoupper(trim($attendee['status'] ?? ''));
            if (in_array($status, ['CANCELLED', 'CANCELED', 'REFUNDED', 'DELETED'], true)) continue;
            $isPaid = in_array($status, $unpaidStatuses, true) ? 0 : 1;

            $candidates = array_values(array_unique(array_filter([
                $attendee['short_id'] ?? null,
                $attendee['public_id'] ?? null,
                isset($attendee['id']) ? (string)$attendee['id'] : null,
            ])));
            if (empty($candidates)) continue;

            $inPh = implode(',', array_fill(0, count($candidates), '?'));
            $checkStmt = $db->prepare("SELECT id, is_paid FROM cow_bingo_cells WHERE event_id = ? AND ticket_id IN ($inPh)");
            $checkStmt->execute(array_merge([$eventDbId], $candidates));
            $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);

            if ($existing) {
                if ((int)$existing['is_paid'] === 0 && $isPaid === 1) {
                    $db->prepare("UPDATE cow_bingo_cells SET is_paid = 1 WHERE id = ?")->execute([$existing['id']]);
                }
                continue;
            }

            $buyerName = trim(($attendee['first_name'] ?? '') . ' ' . ($attendee['last_name'] ?? ''));
            if (empty($buyerName)) $buyerName = $attendee['name'] ?? $attendee['email'] ?? 'Unknown';
            $buyerEmail = $attendee['email'] ?? null;
            $orderId = $attendee['order']['short_id'] ?? $attendee['order_id'] ?? null;

            $cell = assignRandomCell($db, $eventDbId, $gridCols, $gridRows);
            if (!$cell) break 2;

            $insertStmt = $db->prepare("INSERT INTO cow_bingo_cells (event_id, ticket_id, order_id, buyer_name, buyer_email, cell_reference, is_paid, assigned_by) VALUES (?, ?, ?, ?, ?, ?, ?, 'hi.events')");
            $insertStmt->execute([$eventDbId, $ticketId, $orderId, $buyerName, $buyerEmail, $cell, $isPaid]);
            $synced++;
        }
        $lastPage = $data['meta']['last_page'] ?? 1;
        $page++;
    } while ($page <= $lastPage);

    return $synced;
}

/**
 * Helper: Synchronize local cash payment to Hi.Events order
 */
function markHiEventsOrderAsPaid(?string $hieventsEventId, ?string $orderId): bool {
    if (empty($hieventsEventId) || empty($orderId)) return false;
    if (defined('HIEVENTS_ENABLED') && !HIEVENTS_ENABLED) return false;
    $token = getHiEventsToken();
    if (!$token) return false;

    $url = HIEVENTS_BASE_URL . "/api/events/$hieventsEventId/orders/$orderId/mark-as-paid";
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(['payment_method' => 'cash']),
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 5,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ($httpCode >= 200 && $httpCode < 300);
}

/**
 * Cancel an order in Hi.Events via API
 */
function cancelHiEventsOrder(?string $hieventsEventId, ?string $orderId): bool {
    if (empty($hieventsEventId) || empty($orderId)) return false;
    if (defined('HIEVENTS_ENABLED') && !HIEVENTS_ENABLED) return false;
    $token = getHiEventsToken();
    if (!$token) return false;

    $url = HIEVENTS_BASE_URL . "/api/events/$hieventsEventId/orders/$orderId/cancel";
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(['reason' => 'Payment not received']),
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 8,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ($httpCode >= 200 && $httpCode < 300);
}

/**
 * Send a message / notification to an order attendee in Hi.Events via API
 */
function messageHiEventsOrder(?string $hieventsEventId, ?string $orderId, string $subject, string $body): bool {
    if (empty($hieventsEventId) || empty($orderId)) return false;
    if (defined('HIEVENTS_ENABLED') && !HIEVENTS_ENABLED) return false;
    $token = getHiEventsToken();
    if (!$token) return false;

    $url = HIEVENTS_BASE_URL . "/api/events/$hieventsEventId/orders/$orderId/message";
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(['subject' => $subject, 'message' => $body]),
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 8,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ($httpCode >= 200 && $httpCode < 300);
}

/**
 * Auto-migrate database schema additions dynamically
 */
function ensureSchemaMigrated(PDO $db): void {
    static $done = false;
    if ($done) return;
    $done = true;

    $cacheFile = sys_get_temp_dir() . '/cow_bingo_schema_v5.lock';
    if (file_exists($cacheFile) && (time() - filemtime($cacheFile) < 86400)) {
        return;
    }

    try {
        $st = $db->query("SHOW COLUMNS FROM cow_bingo_events LIKE 'cell_naming'");
        if ($st && !$st->fetch()) {
            $db->exec("ALTER TABLE cow_bingo_events ADD COLUMN cell_naming VARCHAR(20) NOT NULL DEFAULT 'alpha' AFTER allow_cell_selection");
        }
    } catch (\Throwable $e) {}

    try {
        $st = $db->query("SHOW COLUMNS FROM cow_bingo_events LIKE 'payment_instructions'");
        if ($st && !$st->fetch()) {
            $db->exec("ALTER TABLE cow_bingo_events ADD COLUMN payment_instructions TEXT DEFAULT NULL AFTER event_description");
        }
    } catch (\Throwable $e) {}

    try {
        $st = $db->query("SHOW COLUMNS FROM cow_bingo_events LIKE 'event_image'");
        if ($st && !$st->fetch()) {
            $db->exec("ALTER TABLE cow_bingo_events ADD COLUMN event_image VARCHAR(255) DEFAULT NULL AFTER event_description");
        }
    } catch (\Throwable $e) {}

    try {
        $st = $db->query("SHOW COLUMNS FROM cow_bingo_events LIKE 'event_prizes'");
        if ($st && !$st->fetch()) {
            $db->exec("ALTER TABLE cow_bingo_events ADD COLUMN event_prizes TEXT DEFAULT NULL AFTER pricing_tiers");
        }
    } catch (\Throwable $e) {}

    try {
        $st = $db->query("SHOW COLUMNS FROM cow_bingo_events LIKE 'entries_closed'");
        if ($st && !$st->fetch()) {
            $db->exec("ALTER TABLE cow_bingo_events ADD COLUMN entries_closed TINYINT(1) NOT NULL DEFAULT 0 AFTER is_active");
        }
    } catch (\Throwable $e) {}

    try {
        $st = $db->query("SHOW COLUMNS FROM cow_bingo_cells LIKE 'buyer_phone'");
        if ($st && !$st->fetch()) {
            $db->exec("ALTER TABLE cow_bingo_cells ADD COLUMN buyer_phone VARCHAR(50) DEFAULT NULL AFTER buyer_email");
        }
    } catch (\Throwable $e) {}

    try {
        $st = $db->query("SHOW COLUMNS FROM cow_bingo_login_attempts LIKE 'username'");
        if ($st && !$st->fetch()) {
            $db->exec("ALTER TABLE cow_bingo_login_attempts ADD COLUMN username VARCHAR(100) DEFAULT NULL AFTER ip_address");
        }
    } catch (\Throwable $e) {}

    @touch($cacheFile);
}
