<?php
/**
 * Cow Bingo - Visual Grid Controller
 * 
 * Public read-only router. Directs to either the Event Selector or Visual Grid view.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/bingo_helpers.php';

$isAdmin = !empty($_SESSION['admin_id']);
$db = getDbConnection();
$event = null;

// 1. Explicit primary key lookup (Admins can preview inactive events; public requires is_active = 1)
if (!empty($_GET['id']) && is_numeric($_GET['id'])) {
    $sql = $isAdmin 
        ? "SELECT * FROM cow_bingo_events WHERE id = ? LIMIT 1"
        : "SELECT * FROM cow_bingo_events WHERE id = ? AND is_active = 1 LIMIT 1";
    $st = $db->prepare($sql);
    $st->execute([(int)$_GET['id']]);
    $event = $st->fetch(PDO::FETCH_ASSOC);
}

// 2. Legacy / Public ?event= parameter lookup (Hi.Events ID -> Slug -> Local ID)
if (!$event && !empty($_GET['event'])) {
    $param = trim($_GET['event']);
    $activeSql = $isAdmin ? "" : "AND is_active = 1";

    $st = $db->prepare("SELECT * FROM cow_bingo_events WHERE hievents_event_id = ? $activeSql LIMIT 1");
    $st->execute([$param]);
    $event = $st->fetch(PDO::FETCH_ASSOC);

    if (!$event) {
        $st = $db->prepare("SELECT * FROM cow_bingo_events WHERE event_slug = ? $activeSql LIMIT 1");
        $st->execute([$param]);
        $event = $st->fetch(PDO::FETCH_ASSOC);
    }

    if (!$event && is_numeric($param)) {
        $st = $db->prepare("SELECT * FROM cow_bingo_events WHERE id = ? $activeSql LIMIT 1");
        $st->execute([(int)$param]);
        $event = $st->fetch(PDO::FETCH_ASSOC);
    }
}

// 3. No event specified -> Render Public Event List View (Strictly active events; drafts hidden)
if (!$event && empty($_GET['id']) && empty($_GET['event'])) {
    $rawEvents = $db->query("
        SELECT e.*, 
               COUNT(c.id) as sold_count,
               COUNT(CASE WHEN c.prize IS NOT NULL AND c.prize != '' THEN 1 END) as winner_count
        FROM cow_bingo_events e 
        LEFT JOIN cow_bingo_cells c ON c.event_id = e.id 
        WHERE e.is_active = 1
        GROUP BY e.id
    ")->fetchAll(PDO::FETCH_ASSOC);

    $todayDate = date('Y-m-d');
    $liveEvents = [];
    $finishedEvents = [];

    foreach ($rawEvents as $ev) {
        $hasWinners = ((int)($ev['winner_count'] ?? 0)) > 0;
        $isPastDate = false;
        $isToday = false;
        $formattedDate = '';

        if (!empty($ev['event_start'])) {
            try {
                $dt = new DateTime($ev['event_start']);
                $dt->setTimezone(new DateTimeZone(date_default_timezone_get()));
                $dateStr = $dt->format('Y-m-d');
                $formattedDate = $dt->format('l, j M Y, g:ia');

                if ($dateStr < $todayDate) {
                    $isPastDate = true;
                } elseif ($dateStr === $todayDate) {
                    $isToday = true;
                }
            } catch (Exception $e) {}
        }

        // Active events are finished only if prizes were awarded or the scheduled date has passed
        $isFinished = $hasWinners || $isPastDate;
        $ev['is_finished'] = $isFinished;
        $ev['is_today'] = $isToday;
        $ev['formatted_date'] = $formattedDate;

        if ($isFinished) {
            $finishedEvents[] = $ev;
        } else {
            $liveEvents[] = $ev;
        }
    }

    // Sort Live/Upcoming: Events today first, then closest upcoming (ASC), then events without dates
    usort($liveEvents, function($a, $b) {
        if ($a['is_today'] && !$b['is_today']) return -1;
        if (!$a['is_today'] && $b['is_today']) return 1;
        if (empty($a['event_start']) && !empty($b['event_start'])) return 1;
        if (!empty($a['event_start']) && empty($b['event_start'])) return -1;
        if (!empty($a['event_start']) && !empty($b['event_start'])) {
            return strcmp($a['event_start'], $b['event_start']);
        }
        return $b['id'] - $a['id'];
    });

    // Sort Finished: Most recent first
    usort($finishedEvents, function($a, $b) {
        if (!empty($a['event_start']) && !empty($b['event_start'])) {
            return strcmp($b['event_start'], $a['event_start']);
        }
        return $b['id'] - $a['id'];
    });

    $events = array_merge($liveEvents, $finishedEvents);
    require __DIR__ . '/views/grid_events_list.php';
    exit;
}

if (!$event) {
    http_response_code(404);
    die('Event not found or not currently active.');
}

// 4. Data Preparation for Grid View
$gridCols = (int) $event['grid_cols'];
$gridRows = (int) $event['grid_rows'];
$eventName = $event['event_name'];
$eventSlug = $event['event_slug'] ?? '';
$eventStart = $event['event_start'] ?? null;
$eventVenue = $event['event_venue'] ?? '';
$bingoEventId = (int) $event['id'];

$startDisplay = '';
$isPastDate = false;
if ($eventStart) {
    try {
        $dt = new DateTime($eventStart);
        $dt->setTimezone(new DateTimeZone(date_default_timezone_get()));
        $startDisplay = $dt->format('l j M Y, g:ia');
        if ($dt->format('Y-m-d') < date('Y-m-d')) {
            $isPastDate = true;
        }
    } catch (Exception $e) {}
}

$bookings = getEventBookings($db, $bingoEventId);
$winners = array_filter($bookings, fn($b) => !empty($b['prize']));
$hasWinners = !empty($winners);

// Event is considered concluded if prizes were awarded, date has passed, or admin deactivated it
$isFinished = empty($event['is_active']) || $hasWinners || $isPastDate;

// Online ticket purchase URL
$hasHiEvents = !empty($event['hievents_event_id']) && (!defined('HIEVENTS_ENABLED') || HIEVENTS_ENABLED);
$buyTicketsUrl = '';
if ($hasHiEvents) {
    $buyTicketsUrl = HIEVENTS_BASE_URL . '/event/' . $event['hievents_event_id'];
    if ($eventSlug) {
        $buyTicketsUrl .= '/' . $eventSlug;
    }
}

$cols = array_slice(range('A', 'Z'), 0, $gridCols);
$totalCells = $gridCols * $gridRows;
$soldCount = count($bookings);
$availableCount = $totalCells - $soldCount;

require __DIR__ . '/views/grid_view.php';
