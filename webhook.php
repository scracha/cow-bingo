<?php
/**
 * Cow Bingo - Webhook Receiver (Multi-Event)
 * 
 * Configure this URL in your hi.events webhook settings:
 * https://your-server.com/cow-bingo/webhook.php
 * 
 * Trigger on: Order Completed / Attendee Created
 */

require_once __DIR__ . '/config.php';

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

// Try to extract the hi.events event ID from the payload
$hieventsEventId = $payload['data']['event_id']
    ?? $payload['data']['order']['event_id']
    ?? $payload['event_id']
    ?? $payload['data']['event']['id']
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

// We want to process order/attendee creation events
$validEvents = [
    'order.completed',
    'order.created',
    'attendee.created',
    'ticket.created',
    'ORDER_COMPLETED',
    'ATTENDEE_CREATED',
];

if (!in_array($eventType, $validEvents, true)) {
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
    // This hi.events event doesn't have a cow bingo game set up - ignore
    $db->prepare("UPDATE webhook_log SET error_message = 'No cow bingo game for this event' WHERE id = ?")
       ->execute([$logId]);
    http_response_code(200);
    exit('No cow bingo game for event: ' . $hieventsEventId);
}

if (!$bingoEvent['is_active']) {
    http_response_code(200);
    exit('Game is not active');
}

$bingoEventId = (int) $bingoEvent['id'];
$gridCols = (int) $bingoEvent['grid_cols'];
$gridRows = (int) $bingoEvent['grid_rows'];

// Extract attendee data from the payload
$data = $payload['data'] ?? $payload['payload'] ?? $payload;

// Try to extract attendees (could be single or order with multiple)
$attendees = [];
if (isset($data['attendees']) && is_array($data['attendees'])) {
    $attendees = $data['attendees'];
} elseif (isset($data['order']['attendees']) && is_array($data['order']['attendees'])) {
    $attendees = $data['order']['attendees'];
} else {
    $attendees[] = $data;
}

$orderId = $data['order_id'] ?? $data['id'] ?? $data['order']['id'] ?? null;

foreach ($attendees as $attendee) {
    $ticketId = $attendee['ticket_id']
        ?? $attendee['id']
        ?? $attendee['attendee_id']
        ?? $attendee['short_id']
        ?? null;

    if (!$ticketId) {
        continue;
    }

    // Check if already assigned for this event
    $checkStmt = $db->prepare(
        "SELECT id FROM cow_bingo_cells WHERE event_id = ? AND ticket_id = ?"
    );
    $checkStmt->execute([$bingoEventId, $ticketId]);
    if ($checkStmt->fetch()) {
        continue;
    }

    // Extract buyer name
    $firstName = $attendee['first_name'] ?? $attendee['firstName'] ?? '';
    $lastName = $attendee['last_name'] ?? $attendee['lastName'] ?? '';
    $buyerName = trim($firstName . ' ' . $lastName);
    if (empty($buyerName)) {
        $buyerName = $attendee['name'] ?? $attendee['email'] ?? 'Unknown';
    }

    $buyerEmail = $attendee['email'] ?? $attendee['buyer_email'] ?? $data['email'] ?? null;

    // Assign a random available cell for this event
    $assignedCell = assignRandomCell($db, $bingoEventId, $gridCols, $gridRows);

    if (!$assignedCell) {
        $db->prepare("UPDATE webhook_log SET error_message = 'SOLD OUT' WHERE id = ?")
           ->execute([$logId]);
        http_response_code(200);
        exit('Sold out');
    }

    // Store the assignment
    $insertStmt = $db->prepare(
        "INSERT INTO cow_bingo_cells (event_id, ticket_id, order_id, buyer_name, buyer_email, cell_reference)
         VALUES (?, ?, ?, ?, ?, ?)"
    );
    $insertStmt->execute([$bingoEventId, $ticketId, $orderId, $buyerName, $buyerEmail, $assignedCell]);
}

// Mark log as processed
$db->prepare("UPDATE webhook_log SET processed = 1 WHERE id = ?")->execute([$logId]);

http_response_code(200);
echo 'OK';

/**
 * Picks a random unassigned cell from the grid for a specific event.
 */
function assignRandomCell(PDO $db, int $eventId, int $cols, int $rows): ?string
{
    $colLetters = array_slice(range('A', 'Z'), 0, $cols);
    $allCells = [];
    foreach ($colLetters as $col) {
        for ($row = 1; $row <= $rows; $row++) {
            $allCells[] = $col . $row;
        }
    }

    $stmt = $db->prepare("SELECT cell_reference FROM cow_bingo_cells WHERE event_id = ?");
    $stmt->execute([$eventId]);
    $assignedCells = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $available = array_values(array_diff($allCells, $assignedCells));

    if (empty($available)) {
        return null;
    }

    return $available[array_rand($available)];
}
