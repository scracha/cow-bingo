<?php
// webhook.php - Hi.Events Incoming Webhook Receiver

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/bingo_helpers.php';
require_once __DIR__ . '/includes/admin_helpers.php';

$db = getDbConnection();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method Not Allowed');
}

$clientIp = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
$rawPayload = file_get_contents('php://input');

// ----------------------------------------------------------------------------
// 1. Rate Limiting on Webhook Submissions
// ----------------------------------------------------------------------------
try {
    $rateStmt = $db->prepare("
        SELECT COUNT(*) 
        FROM webhook_log 
        WHERE created_at > DATE_SUB(NOW(), INTERVAL 1 MINUTE) 
          AND ip_address = ?
    ");
    $rateStmt->execute([$clientIp]);
    if ((int)$rateStmt->fetchColumn() > 100) {
        http_response_code(429);
        exit('Too Many Requests');
    }
} catch (\Throwable $e) {}

// ----------------------------------------------------------------------------
// 2. Authentication / Signature Verification
// ----------------------------------------------------------------------------
$secret = defined('WEBHOOK_SECRET') ? WEBHOOK_SECRET : '';
if ($secret !== '') {
    $signature = $_SERVER['HTTP_X_WEBHOOK_SIGNATURE'] ?? '';
    $querySecret = $_GET['secret'] ?? '';

    $verified = false;
    if ($signature !== '') {
        $expected = hash_hmac('sha256', $rawPayload, $secret);
        $verified = hash_equals($expected, $signature);
    } elseif ($querySecret !== '') {
        $verified = hash_equals($secret, $querySecret);
    }

    if (!$verified) {
        http_response_code(403);
        exit('Unauthorized: Invalid or missing webhook signature/secret');
    }
}

// ----------------------------------------------------------------------------
// 3. Payload Parsing & Unwrapping
// ----------------------------------------------------------------------------
$payload = json_decode($rawPayload, true);
if (!is_array($payload)) {
    http_response_code(400);
    exit('Invalid JSON payload');
}

$rawEventType = $payload['event_type'] ?? $payload['type'] ?? '';
$eventType = strtolower(trim($rawEventType));

$data = $payload['payload'] ?? $payload['data'] ?? $payload;
$hieventsEventId = $data['event_id']
    ?? $data['order']['event_id']
    ?? $data['event']['id']
    ?? $payload['event_id']
    ?? null;

// ----------------------------------------------------------------------------
// 4. Log Webhook Payload (With Event Metadata)
// ----------------------------------------------------------------------------
try {
    $logStmt = $db->prepare("INSERT INTO webhook_log (hievents_event_id, payload, event_type, ip_address, created_at) VALUES (?, ?, ?, ?, NOW())");
    $logStmt->execute([$hieventsEventId ? (string)$hieventsEventId : null, $rawPayload, $eventType ?: null, $clientIp]);
} catch (\Throwable $e) {
    try {
        $logStmt = $db->prepare("INSERT INTO webhook_log (hievents_event_id, payload, event_type, created_at) VALUES (?, ?, ?, NOW())");
        $logStmt->execute([$hieventsEventId ? (string)$hieventsEventId : null, $rawPayload, $eventType ?: null]);
    } catch (\Throwable $e2) {
        try {
            $logStmt = $db->prepare("INSERT INTO webhook_log (payload, created_at) VALUES (?, NOW())");
            $logStmt->execute([$rawPayload]);
        } catch (\Throwable $e3) {}
    }
}

if (empty($eventType) || empty($hieventsEventId)) {
    http_response_code(200);
    exit(json_encode(['status' => 'ignored', 'reason' => 'Missing event_type or event_id']));
}

$evStmt = $db->prepare("SELECT * FROM cow_bingo_events WHERE hievents_event_id = ? LIMIT 1");
$evStmt->execute([(string)$hieventsEventId]);
$event = $evStmt->fetch(PDO::FETCH_ASSOC);

if (!$event) {
    http_response_code(200);
    exit(json_encode(['status' => 'ignored', 'reason' => 'No matching Cow Bingo event linked to Hi.Events ID ' . $hieventsEventId]));
}

$eventDbId = (int)$event['id'];
$gridCols = (int)($event['grid_cols'] ?? 16);
$gridRows = (int)($event['grid_rows'] ?? 16);
$pricingTiers = !empty($event['pricing_tiers']) ? (json_decode($event['pricing_tiers'], true) ?: []) : [];

// ----------------------------------------------------------------------------
// 5. Event Categorization Arrays
// ----------------------------------------------------------------------------
$creationEvents = [
    'order.created', 'order.completed', 'attendee.created',
    'order_created', 'order_completed', 'attendee_created'
];

$paymentEvents = [
    'order.marked_as_paid', 'order.paid', 'order.completed',
    'order_marked_as_paid', 'order_paid', 'order_completed'
];

$cancellationEvents = [
    'order.cancelled', 'order.canceled', 'order.deleted', 'order.refunded', 'order.voided',
    'order_cancelled', 'order_canceled', 'order_deleted', 'order_refunded', 'order_voided'
];

$ticketCancellationEvents = [
    'attendee.cancelled', 'attendee.canceled', 'attendee.removed', 'attendee.deleted',
    'ticket.cancelled', 'ticket.canceled', 'ticket.deleted', 'ticket.voided',
    'attendee_cancelled', 'attendee_canceled', 'attendee_removed', 'attendee_deleted',
    'ticket_cancelled', 'ticket_canceled', 'ticket_deleted', 'ticket_voided'
];

$isCreation     = in_array($eventType, $creationEvents, true);
$isPayment      = in_array($eventType, $paymentEvents, true);
$isCancellation = in_array($eventType, $cancellationEvents, true) || in_array($eventType, $ticketCancellationEvents, true);

// ----------------------------------------------------------------------------
// 6. Inactive / Closed Game Guard: block new cells, allow payments/cancellations
// ----------------------------------------------------------------------------
$isBlockedForNew = empty($event['is_active']) || !empty($event['entries_closed']);
if ($isBlockedForNew && $isCreation) {
    // Pure creation events -> short-circuit entirely
    if (!$isPayment) {
        http_response_code(200);
        exit(json_encode([
            'status' => 'ignored',
            'reason' => "Event ID {$eventDbId} entries are closed or deactivated. New square allocations are blocked."
        ]));
    }
    // Dual-purpose events (order.completed) -> process payment in Branch (B), skip allocation in Branch (C)
    $isCreation = false;
}

// ----------------------------------------------------------------------------
// 7. Action Execution
// ----------------------------------------------------------------------------

// (A) Cancellation / Refund Handling
if (in_array($eventType, $cancellationEvents, true)) {
    $orderId = trim(
        $data['short_id']
        ?? $data['order_id']
        ?? $data['id']
        ?? $data['order']['short_id']
        ?? $data['order']['id']
        ?? $payload['order_id']
        ?? ''
    );
    $released = 0;
    if (!empty($orderId)) {
        $delStmt = $db->prepare("DELETE FROM cow_bingo_cells WHERE event_id = ? AND order_id = ?");
        $delStmt->execute([$eventDbId, $orderId]);
        $released = $delStmt->rowCount();
        if ($released > 0) {
            logAuditEvent($db, $eventDbId, 'webhook_order_cancelled', null, null, $orderId, null, 0.00, "Released $released square(s) for cancelled order #$orderId", 'Hi.Events Webhook');
        }
    }
    http_response_code(200);
    exit(json_encode(['status' => 'success', 'event' => $eventType, 'released' => $released]));
}

if (in_array($eventType, $ticketCancellationEvents, true)) {
    $ticketId = trim(
        $data['short_id']
        ?? $data['ticket_id']
        ?? $data['public_id']
        ?? $data['id']
        ?? $data['attendee']['short_id']
        ?? $data['attendee']['public_id']
        ?? $data['attendee']['id']
        ?? ''
    );
    $released = 0;
    if (!empty($ticketId)) {
        $delStmt = $db->prepare("DELETE FROM cow_bingo_cells WHERE event_id = ? AND ticket_id = ?");
        $delStmt->execute([$eventDbId, $ticketId]);
        $released = $delStmt->rowCount();
        if ($released > 0) {
            logAuditEvent($db, $eventDbId, 'webhook_ticket_cancelled', null, $ticketId, null, null, 0.00, "Released square for cancelled ticket #$ticketId", 'Hi.Events Webhook');
        }
    }
    http_response_code(200);
    exit(json_encode(['status' => 'success', 'event' => $eventType, 'released' => $released]));
}

// (B) Payment Status Updates (Permitted even when entries are closed)
if ($isPayment && !in_array($eventType, ['order.created', 'order_created'], true)) {
    $orderArray = is_array($data['order'] ?? null) ? $data['order'] : [];
    $orderId = trim(
        $orderArray['short_id']
        ?? $orderArray['public_id']
        ?? $orderArray['id']
        ?? $data['order_id']
        ?? $data['short_id']
        ?? $data['id']
        ?? ''
    );
    $gross = isset($orderArray['total_gross']) ? (float)$orderArray['total_gross'] : (isset($data['total_gross']) ? (float)$data['total_gross'] : null);

    if (!empty($orderId)) {
        $countStmt = $db->prepare("SELECT COUNT(*) FROM cow_bingo_cells WHERE event_id = ? AND order_id = ?");
        $countStmt->execute([$eventDbId, $orderId]);
        $cellCount = (int)$countStmt->fetchColumn();

        if ($cellCount > 0) {
            if ($gross !== null && $gross > 0) {
                $perSquare = round($gross / $cellCount, 2);
                $upStmt = $db->prepare("UPDATE cow_bingo_cells SET is_paid = 1, order_amount = ?, price_paid = ? WHERE event_id = ? AND order_id = ?");
                $upStmt->execute([$gross, $perSquare, $eventDbId, $orderId]);
            } else {
                $upStmt = $db->prepare("UPDATE cow_bingo_cells SET is_paid = 1 WHERE event_id = ? AND order_id = ?");
                $upStmt->execute([$eventDbId, $orderId]);
            }
            logAuditEvent($db, $eventDbId, 'webhook_marked_paid', null, null, $orderId, null, $gross, "Order #$orderId marked paid via webhook", 'Hi.Events Webhook');
        }
    }
}

// (C) Ticket Assignment / Order Creation
if ($isCreation) {
    $attendees = [];
    if (!empty($data['attendees']) && is_array($data['attendees'])) {
        $attendees = $data['attendees'];
    } elseif (!empty($data['order']['attendees']) && is_array($data['order']['attendees'])) {
        $attendees = $data['order']['attendees'];
    } elseif (!empty($data['attendee']) && is_array($data['attendee'])) {
        $attendees = [$data['attendee']];
    } elseif (!empty($data['id']) && (!empty($data['first_name']) || !empty($data['public_id']) || !empty($data['short_id']))) {
        $attendees = [$data];
    }

    if (empty($attendees)) {
        if (function_exists('syncAttendeesFromHiEvents')) {
            $synced = syncAttendeesFromHiEvents($db, $event);
            logAuditEvent($db, $eventDbId, 'webhook_attendee_sync', null, null, null, null, null, "Webhook synced $synced square(s) via API fallback", 'Hi.Events Webhook');
        }
        http_response_code(200);
        exit(json_encode(['status' => 'success', 'event' => $eventType, 'method' => 'api_fallback']));
    }

    $orderArray = is_array($data['order'] ?? null) ? $data['order'] : [];
    $orderId = (string)(
        $orderArray['short_id']
        ?? $orderArray['public_id']
        ?? $orderArray['id']
        ?? $data['order_id']
        ?? $data['short_id']
        ?? $data['id']
        ?? ''
    );
    $orderGross = isset($orderArray['total_gross']) ? (float)$orderArray['total_gross'] : (isset($data['total_gross']) ? (float)$data['total_gross'] : 0.0);
    $orderPaid = !empty($orderArray['is_paid']) || in_array($eventType, $paymentEvents, true);

    $qty = count($attendees);
    $batchTotal = ($orderGross > 0) ? $orderGross : calculateBatchPrice($qty, $pricingTiers);
    $perSquare = round($batchTotal / max(1, $qty), 2);
    $paidAmount = $orderPaid ? $perSquare : 0.00;

    $takenStmt = $db->prepare("SELECT cell_reference FROM cow_bingo_cells WHERE event_id = ?");
    $takenStmt->execute([$eventDbId]);
    $takenSet = array_flip($takenStmt->fetchAll(PDO::FETCH_COLUMN));

    $availableCells = [];
    for ($r = 1; $r <= $gridRows; $r++) {
        for ($c = 1; $c <= $gridCols; $c++) {
            $colLetters = '';
            $tempC = $c;
            while ($tempC > 0) {
                $rem = ($tempC - 1) % 26;
                $colLetters = chr(65 + $rem) . $colLetters;
                $tempC = (int)(($tempC - $rem - 1) / 26);
            }
            $ref = $colLetters . $r;
            if (!isset($takenSet[$ref])) {
                $availableCells[] = $ref;
            }
        }
    }
    shuffle($availableCells);

    $assignedRefs = [];
    $insertStmt = $db->prepare("
        INSERT INTO cow_bingo_cells 
        (event_id, ticket_id, order_id, buyer_name, buyer_email, cell_reference, is_paid, order_amount, price_paid, payment_method, assigned_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'online', 'Hi.Events Webhook')
    ");

    foreach ($attendees as $att) {
        $ticketId = (string)($att['short_id'] ?? $att['public_id'] ?? $att['id'] ?? '');
        if (empty($ticketId)) {
            continue;
        }

        $checkStmt = $db->prepare("SELECT id FROM cow_bingo_cells WHERE event_id = ? AND ticket_id = ? LIMIT 1");
        $checkStmt->execute([$eventDbId, $ticketId]);
        if ($checkStmt->fetch()) {
            continue;
        }

        $firstName = trim($att['first_name'] ?? '');
        $lastName = trim($att['last_name'] ?? '');
        $buyerName = trim("$firstName $lastName");
        if (empty($buyerName) && !empty($orderArray)) {
            $buyerName = trim(($orderArray['first_name'] ?? '') . ' ' . ($orderArray['last_name'] ?? ''));
        }
        if (empty($buyerName)) {
            $buyerName = 'Hi.Events Buyer';
        }

        $buyerEmail = trim($att['email'] ?? $orderArray['email'] ?? $data['email'] ?? '');

        $assigned = false;
        for ($attempt = 0; $attempt < 5; $attempt++) {
            if (empty($availableCells)) {
                break;
            }
            $cellRef = array_pop($availableCells);

            try {
                $insertStmt->execute([
                    $eventDbId,
                    $ticketId,
                    $orderId ?: null,
                    $buyerName,
                    $buyerEmail ?: null,
                    $cellRef,
                    $orderPaid ? 1 : 0,
                    $batchTotal,
                    $paidAmount
                ]);
                $assignedRefs[] = $cellRef;
                $takenSet[$cellRef] = true;
                $assigned = true;
                break;
            } catch (PDOException $e) {
                if (($e->errorInfo[1] ?? null) === 1062 || strpos($e->getMessage(), '1062') !== false) {
                    continue;
                }
                break;
            }
        }
    }

    if (!empty($assignedRefs)) {
        $assignedList = implode(', ', $assignedRefs);
        logAuditEvent($db, $eventDbId, 'webhook_cell_assigned', $assignedList, null, $orderId, null, $batchTotal, "Webhook assigned " . count($assignedRefs) . " square(s): $assignedList", 'Hi.Events Webhook');
    }
}

http_response_code(200);
echo json_encode(['status' => 'success', 'event' => $eventType]);
