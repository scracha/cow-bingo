<?php
/**
 * Cow Bingo - Visual Grid Controller
 * 
 * Public read-only router. Directs to either the Event Selector or Visual Grid view.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$isAdmin = !empty($_SESSION['admin_id']);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/bingo_helpers.php';

$db = getDbConnection();
$event = null;

// 1. Explicit primary key lookup (used by admin and event list links — 100% deterministic)
if (!empty($_GET['id']) && is_numeric($_GET['id'])) {
    $st = $db->prepare("SELECT * FROM cow_bingo_events WHERE id = ? AND is_active = 1 LIMIT 1");
    $st->execute([(int)$_GET['id']]);
    $event = $st->fetch(PDO::FETCH_ASSOC);
}

// 2. Legacy / Public ?event= parameter lookup (Priority: Hi.Events ID -> Slug -> Local ID)
if (!$event && !empty($_GET['event'])) {
    $param = trim($_GET['event']);

    // Priority A: Hi.Events ID
    $st = $db->prepare("SELECT * FROM cow_bingo_events WHERE hievents_event_id = ? AND is_active = 1 LIMIT 1");
    $st->execute([$param]);
    $event = $st->fetch(PDO::FETCH_ASSOC);

    // Priority B: Event Slug
    if (!$event) {
        $st = $db->prepare("SELECT * FROM cow_bingo_events WHERE event_slug = ? AND is_active = 1 LIMIT 1");
        $st->execute([$param]);
        $event = $st->fetch(PDO::FETCH_ASSOC);
    }

    // Priority C: Local Database ID fallback
    if (!$event && is_numeric($param)) {
        $st = $db->prepare("SELECT * FROM cow_bingo_events WHERE id = ? AND is_active = 1 LIMIT 1");
        $st->execute([(int)$param]);
        $event = $st->fetch(PDO::FETCH_ASSOC);
    }
}

// 3. No event specified -> Render Event List View
if (!$event && empty($_GET['id']) && empty($_GET['event'])) {
    $events = $db->query("
        SELECT e.*, COUNT(c.id) as sold_count 
        FROM cow_bingo_events e 
        LEFT JOIN cow_bingo_cells c ON c.event_id = e.id 
        WHERE e.is_active = 1 
        GROUP BY e.id 
        ORDER BY e.created_at DESC
    ")->fetchAll(PDO::FETCH_ASSOC);

    require __DIR__ . '/views/grid_events_list.php';
    exit;
}

if (!$event) {
    http_response_code(404);
    die('Event not found or not active.');
}

// 4. Data Preparation for Grid View
$gridCols = (int) $event['grid_cols'];
$gridRows = (int) $event['grid_rows'];
$eventName = $event['event_name'];
$eventSlug = $event['event_slug'] ?? '';
$eventStart = $event['event_start'] ?? null;
$eventVenue = $event['event_venue'] ?? '';
$bingoEventId = (int) $event['id'];

// Format start date/time
$startDisplay = '';
if ($eventStart) {
    try {
        $dt = new DateTime($eventStart);
        $dt->setTimezone(new DateTimeZone(date_default_timezone_get()));
        $startDisplay = $dt->format('l j M Y, g:ia');
    } catch (Exception $e) {}
}

// Online ticket purchase URL (if linked to hi.events)
$hasHiEvents = !empty($event['hievents_event_id']) && (!defined('HIEVENTS_ENABLED') || HIEVENTS_ENABLED);
$buyTicketsUrl = '';
if ($hasHiEvents) {
    $buyTicketsUrl = HIEVENTS_BASE_URL . '/event/' . $event['hievents_event_id'];
    if ($eventSlug) {
        $buyTicketsUrl .= '/' . $eventSlug;
    }
}

// Fetch all bookings using shared helper
$bookings = getEventBookings($db, $bingoEventId);

$cols = array_slice(range('A', 'Z'), 0, $gridCols);
$totalCells = $gridCols * $gridRows;
$soldCount = count($bookings);
$availableCount = $totalCells - $soldCount;

// 5. Render Main Visual Grid View
require __DIR__ . '/views/grid_view.php';