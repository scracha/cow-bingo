<?php
/**
 * Cow Bingo - Webhook Receiver (Multi-Event)
 * 
 * Configure this URL in your hi.events webhook settings:
 * https://your-server.com/cow-bingo/webhook.php
 * 
 * Trigger on: Order Completed / Created / Cancelled, Attendee Created / Cancelled
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/bingo_helpers.php'; // <-- ADD THIS

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method Not Allowed');
}

// Read the raw payload
$rawPayload = file_get_contents('php://input');
$payload = json_decode($rawPayload, true);

$db = getDbConnection();

// Determine event type from payload
$eventType = $payload['event_type'] ?? $payload['type'] ?? 'unknown';

// Extract the resource data (hi.events sends data inside 'payload'; fallback to 'data')
$data = $payload['payload'] ?? $payload['data'] ?? $payload;

// Extract the hi.events event ID
$hieventsEventId = $data['event_id']
    ?? $data['order']['event_id']
    ?? $data['event']['id']
    ?? $payload['event_id']
    ?? null;

// Log the raw webhook for debugging
$logStmt = $db->prepare(
    "INSERT INTO webhook_log (hievents_event_id, payload, event_type) VALUES (?, ?, ?)"
);
$logStmt->execute([$hieventsEventId, $rawPayload, $eventType]);
$logId = $db->lastInsertId();

// Validate payload
if (!$payload) {
    http_response_code(400);
    exit('Invalid JSON payload');
}

// Define relevant event types (including both US & UK spellings)
$creationEvents = [
    'order.completed', 'order.created', 'attendee.created', 'ticket.created',
    'ORDER_COMPLETED', 'ORDER_CREATED', 'ATTENDEE_CREATED', 'TICKET_CREATED'
];
$paymentEvents = [
    'order.marked_as_paid', 'order.paid', 'ORDER_MARKED_AS_PAID', 'ORDER_PAID'
];
$cancellationEvents = [
    'order.cancelled', 'order.canceled', 'order.refunded', 'order.voided', 'order.deleted',
    'attendee.cancelled', 'attendee.canceled', 'attendee.deleted', 'attendee.voided', 'attendee.removed',
    'ticket.cancelled', 'ticket.canceled', 'ticket.deleted', 'ticket.voided',
    'ORDER_CANCELLED', 'ORDER_CANCELED', 'ORDER_REFUNDED', 'ORDER_VOIDED', 'ORDER_DELETED',
    'ATTENDEE_CANCELLED', 'ATTENDEE_CANCELED', 'ATTENDEE_DELETED', 'ATTENDEE_VOIDED', 'ATTENDEE_REMOVED',
    'TICKET_CANCELLED', 'TICKET_CANCELED', 'TICKET_DELETED', 'TICKET_VOIDED'
];

$isCreation = in_array($eventType, $creationEvents, true);
$isPayment = in_array($eventType, $paymentEvents, true);
$isCancellation = in_array($eventType, $cancellationEvents, true);

if (!$isCreation && !$isCancellation && !$isPayment) {
    http_response_code(200);
    exit('Event type not relevant: ' . $eventType);
}

// Check if this event ID has a cow bingo game registered
if (!$hieventsEventId) {
    $db->prepare("UPDATE webhook_log SET error_message = 'No event_id found in payload' WHERE id = ?")
       ->execute([$logId]);
    http_response_code(200);
    exit('No event_id in payload');
}

// Look up the cow bingo event
$eventStmt = $db->prepare(
    "SELECT id, grid_cols, grid_rows, is_active FROM cow_bingo_events WHERE hievents_event_id = ?"
);
$eventStmt->execute([$hieventsEventId]);
$bingoEvent = $eventStmt->fetch(PDO::FETCH_ASSOC);

if (!$bingoEvent) {
    $db->prepare("UPDATE webhook_log SET error_message = 'No cow bingo game for this event' WHERE id = ?")
       ->execute([$logId]);
    http_response_code(200);
    exit('No cow bingo game for event: ' . $hieventsEventId);
}

// Allow cancellations and payments on inactive games
if (!$isCancellation && !$isPayment && !$bingoEvent['is_active']) {
    $db->prepare("UPDATE webhook_log SET processed = 1, error_message = 'Game is not active' WHERE id = ?")
       ->execute([$logId]);
    http_response_code(200);
    exit('Game is not active');
}

$bingoEventId = (int) $bingoEvent['id'];
$gridCols = (int) $bingoEvent['grid_cols'];
$gridRows = (int) $bingoEvent['grid_rows'];

$cancelledStatuses = ['CANCELLED', 'CANCELED', 'REFUNDED', 'VOIDED', 'DELETED'];
$unpaidStatuses = ['AWAITING_PAYMENT', 'PENDING', 'UNPAID', 'RESERVED'];
$isPartialOrderCancel = false;

// --- 1. HANDLE PAYMENT STATUS UPDATES (order.marked_as_paid) ---
if ($isPayment) {
    $orderCandidates = array_values(array_unique(array_filter([
        $data['short_id'] ?? null,
        isset($data['id']) ? (string)$data['id'] : null,
        $data['order_id'] ?? null,
        $data['order']['short_id'] ?? null,
        isset($data['order']['id']) ? (string)$data['order']['id'] : null,
    ], fn($v) => $v !== null && $v !== '')));

    if (!empty($orderCandidates)) {
        $inPlaceholders = implode(',', array_fill(0, count($orderCandidates), '?'));
        $db->prepare("UPDATE cow_bingo_cells SET is_paid = 1 WHERE event_id = ? AND order_id IN ($inPlaceholders)")
           ->execute(array_merge([$bingoEventId], $orderCandidates));
    }

    $db->prepare("UPDATE webhook_log SET processed = 1 WHERE id = ?")->execute([$logId]);
    http_response_code(200);
    exit('Marked as paid');
}

// --- 2. HANDLE ORDER-LEVEL CANCELLATIONS ---
if ($isCancellation && stripos($eventType, 'order') !== false) {
    $orderAttendees = $data['attendees'] ?? $data['order']['attendees'] ?? null;
    $isPartial = false;

    if (!empty($orderAttendees) && is_array($orderAttendees)) {
        $hasActive = false;
        $hasCancelled = false;
        foreach ($orderAttendees as $att) {
            $status = strtoupper(trim($att['status'] ?? ''));
            if (in_array($status, $cancelledStatuses, true)) {
                $hasCancelled = true;
            } else {
                $hasActive = true;
            }
        }
        $isPartial = ($hasActive && $hasCancelled);
    }

    if ($isPartial) {
        $isPartialOrderCancel = true;
    } else {
        $orderCandidates = array_values(array_unique(array_filter([
            $data['short_id'] ?? null,
            isset($data['id']) ? (string)$data['id'] : null,
            $data['order_id'] ?? null,
            $data['order']['short_id'] ?? null,
            isset($data['order']['id']) ? (string)$data['order']['id'] : null,
        ], fn($v) => $v !== null && $v !== '')));

        if (empty($orderCandidates)) {
            $db->prepare("UPDATE webhook_log SET processed = 1, error_message = 'Order cancellation missing order ID' WHERE id = ?")
               ->execute([$logId]);
            http_response_code(200);
            exit('No order ID');
        }

        $inPlaceholders = implode(',', array_fill(0, count($orderCandidates), '?'));
        $delStmt = $db->prepare("DELETE FROM cow_bingo_cells WHERE event_id = ? AND order_id IN ($inPlaceholders)");
        $delStmt->execute(array_merge([$bingoEventId], $orderCandidates));
        
        $db->prepare("UPDATE webhook_log SET processed = 1 WHERE id = ?")->execute([$logId]);
        http_response_code(200);
        exit('Order cells removed');
    }
}

// --- 2.5 PROMOTE UNPAID CELLS ON ORDER COMPLETION ---
if ($isCreation && stripos($eventType, 'order') !== false
    && in_array($eventType, ['order.completed', 'ORDER_COMPLETED', 'order.paid', 'ORDER_PAID'], true)
) {
    $completedOrderIds = array_values(array_unique(array_filter([
        $data['short_id'] ?? null,
        isset($data['id']) ? (string)$data['id'] : null,
        $data['order_id'] ?? null,
        $data['order']['short_id'] ?? null,
        isset($data['order']['id']) ? (string)$data['order']['id'] : null,
    ], fn($v) => $v !== null && $v !== '')));

    if (!empty($completedOrderIds)) {
        $ph = implode(',', array_fill(0, count($completedOrderIds), '?'));
        $db->prepare("UPDATE cow_bingo_cells SET is_paid = 1 WHERE event_id = ? AND order_id IN ($ph)")
           ->execute(array_merge([$bingoEventId], $completedOrderIds));
    }
}

// --- 3. ORDER CREATION WITHOUT EMBEDDED ATTENDEES -> ACKNOWLEDGE ONLY ---
if ($isCreation && stripos($eventType, 'order') !== false
    && empty($data['attendees'])
    && empty($data['order']['attendees'])
) {
    $db->prepare("UPDATE webhook_log SET processed = 1, error_message = 'Order acknowledged (tickets handled via attendee.created)' WHERE id = ?")
       ->execute([$logId]);
    http_response_code(200);
    exit('OK');
}

// --- 4. EXTRACT ATTENDEES FOR CREATION OR PER-TICKET CANCELLATION ---
$attendees = [];
if (!empty($data['attendees']) && is_array($data['attendees'])) {
    $attendees = $data['attendees'];
} elseif (!empty($data['order']['attendees']) && is_array($data['order']['attendees'])) {
    $attendees = $data['order']['attendees'];
} else {
    $attendees = [$data];
}

$defaultOrderId = (stripos($eventType, 'order') !== false)
    ? ($data['short_id'] ?? (isset($data['id']) ? (string)$data['id'] : null))
    : null;

$anyFailed = false;

foreach ($attendees as $attendee) {
    $status = strtoupper(trim($attendee['status'] ?? $data['status'] ?? ''));

    // Skip already-cancelled attendees on creation events
    if ($isCreation && in_array($status, $cancelledStatuses, true)) {
        continue;
    }

    // Determine Paid Status
    $isPaid = in_array($status, $unpaidStatuses, true) ? 0 : 1;

    // Collect all candidate identifiers for this attendee
    $candidates = array_values(array_unique(array_filter([
        $attendee['short_id'] ?? null,
        $attendee['public_id'] ?? null,
        isset($attendee['id']) ? (string)$attendee['id'] : null,
        $attendee['ticket_id'] ?? null,
        $attendee['attendee_id'] ?? null,
    ], fn($val) => $val !== null && $val !== '')));

    if (empty($candidates)) {
        continue;
    }

    $ticketId = $candidates[0];

    // --- ACTION: CANCELLATION ---
    if ($isCancellation) {
        if ($isPartialOrderCancel && !in_array($status, $cancelledStatuses, true)) {
            continue;
        }

        $inPlaceholders = implode(',', array_fill(0, count($candidates), '?'));
        $stmt = $db->prepare("DELETE FROM cow_bingo_cells WHERE event_id = ? AND ticket_id IN ($inPlaceholders)");
        $stmt->execute(array_merge([$bingoEventId], $candidates));
        continue;
    }

    // --- ACTION: CREATION ---
    $inPlaceholders = implode(',', array_fill(0, count($candidates), '?'));
    $checkStmt = $db->prepare(
        "SELECT id, is_paid FROM cow_bingo_cells WHERE event_id = ? AND ticket_id IN ($inPlaceholders)"
    );
    $checkStmt->execute(array_merge([$bingoEventId], $candidates));
    $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        // If the ticket was already assigned but its payment status changed, update it
        if ((int)$existing['is_paid'] !== $isPaid) {
            $db->prepare("UPDATE cow_bingo_cells SET is_paid = ? WHERE id = ?")->execute([$isPaid, $existing['id']]);
        }
        continue;
    }

    // Strict order_id resolution
    $orderArray = is_array($attendee['order'] ?? null) ? $attendee['order'] : [];
    $rawOrderId = $orderArray['short_id'] 
        ?? ($attendee['order_id'] ?? null)
        ?? ($orderArray['id'] ?? null)
        ?? $defaultOrderId;
    $orderId = ($rawOrderId !== null && $rawOrderId !== '') ? (string)$rawOrderId : null;

    // Buyer name & email
    $firstName = $attendee['first_name'] ?? $attendee['firstName'] ?? '';
    $lastName = $attendee['last_name'] ?? $attendee['lastName'] ?? '';
    $buyerName = trim($firstName . ' ' . $lastName);
    if (empty($buyerName)) {
        $buyerName = $attendee['name'] ?? $attendee['email'] ?? 'Unknown';
    }

    $buyerEmail = $attendee['email'] ?? $attendee['buyer_email'] ?? $data['email'] ?? null;

    // Collision-protected cell assignment
    $assigned = false;
    $maxRetries = 5;

    for ($i = 0; $i < $maxRetries; $i++) {
        $assignedCell = assignRandomCell($db, $bingoEventId, $gridCols, $gridRows);

        if (!$assignedCell) {
            $db->prepare("UPDATE webhook_log SET error_message = 'SOLD OUT' WHERE id = ?")
               ->execute([$logId]);
            http_response_code(200);
            exit('Sold out');
        }

        try {
            $insertStmt = $db->prepare(
                "INSERT INTO cow_bingo_cells (event_id, ticket_id, order_id, buyer_name, buyer_email, cell_reference, is_paid)
                 VALUES (?, ?, ?, ?, ?, ?, ?)"
            );
            $insertStmt->execute([$bingoEventId, $ticketId, $orderId, $buyerName, $buyerEmail, $assignedCell, $isPaid]);
            $assigned = true;
            break;
        } catch (PDOException $e) {
            $isDuplicate = ($e->errorInfo[1] ?? null) === 1062
                || strpos($e->getMessage(), '1062') !== false
                || strpos($e->getMessage(), 'Duplicate entry') !== false;

            if (!$isDuplicate) {
                throw $e;
            }

            if (strpos($e->getMessage(), 'unique_ticket_per_event') !== false) {
                $assigned = true;
                break;
            }

            continue;
        }
    }

    if (!$assigned) {
        $anyFailed = true;
        $db->prepare("UPDATE webhook_log SET error_message = 'Failed to assign cell after retries' WHERE id = ?")
           ->execute([$logId]);
    }
}

// Finalize log status
$db->prepare("UPDATE webhook_log SET processed = ? WHERE id = ?")
   ->execute([$anyFailed ? 0 : 1, $logId]);

// Signal hi.events to retry if assignment failed
if ($anyFailed) {
    http_response_code(500);
    exit('Partial failure - retry requested');
}

http_response_code(200);
echo 'OK';

