<?php
// includes/admin_helpers.php

require_once __DIR__ . '/bingo_helpers.php';

function canManageEvent(PDO $db, int $adminId, int $eventId): bool {
    $stmt = $db->prepare("SELECT 1 FROM cow_bingo_admin_events WHERE admin_id = ? AND event_id = ?");
    $stmt->execute([$adminId, $eventId]);
    return (bool) $stmt->fetch();
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
                // Only upgrade 0 -> 1; never revert a locally paid square back to unpaid
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
function markHiEventsOrderAsPaid(string $hieventsEventId, string $orderId): bool {
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
