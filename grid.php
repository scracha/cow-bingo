<?php
/**
 * Cow Bingo - Visual Grid Controller
 * 
 * Public read-only router. Directs to either the Event Selector or Visual Grid view.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/bingo_helpers.php';
require_once __DIR__ . '/includes/admin_helpers.php';

$isAdmin = !empty($_SESSION['admin_id']);
$db = getDbConnection();
ensureSchemaMigrated($db);
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
               COUNT(CASE WHEN c.is_paid = 1 THEN 1 END) as paid_count,
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

// Math Captcha Generation for public ticket purchase
if (empty($_SESSION['grid_captcha_num1']) || empty($_SESSION['grid_captcha_num2'])) {
    $n1 = random_int(2, 9);
    $n2 = random_int(2, 9);
    $_SESSION['grid_captcha_num1'] = $n1;
    $_SESSION['grid_captcha_num2'] = $n2;
    $_SESSION['grid_captcha_answer'] = $n1 + $n2;
}
$captchaNum1 = $_SESSION['grid_captcha_num1'];
$captchaNum2 = $_SESSION['grid_captcha_num2'];

// Handle Public Ticket Booking / Reservation for Standalone Events
$publicBookingSuccess = null;
$publicBookingError = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'public_reserve') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        $publicBookingError = 'Security session expired. Please refresh the page and try again.';
    } else {
        $userCaptcha = (int)($_POST['captcha_answer'] ?? -999);
        $expectedCaptcha = (int)($_SESSION['grid_captcha_answer'] ?? -998);

        if ($userCaptcha !== $expectedCaptcha) {
            $publicBookingError = 'Security verification failed. Please solve the math check correctly.';
        } else {
            $buyerName = trim($_POST['buyer_name'] ?? '');
            $buyerPhone = trim($_POST['buyer_phone'] ?? '');
            $buyerEmail = trim($_POST['buyer_email'] ?? '');

            if (empty($buyerName)) {
                $publicBookingError = 'Please enter your name.';
            } elseif (empty($buyerPhone) || !validateNzPhone($buyerPhone)) {
                $publicBookingError = 'Please enter a valid New Zealand phone number (e.g. 021 123 4567 or 03 123 4567).';
            } elseif ($buyerEmail !== '' && !filter_var($buyerEmail, FILTER_VALIDATE_EMAIL)) {
                $publicBookingError = 'Please enter a valid email address (or leave it blank).';
            } elseif (empty($event['is_active'])) {
                $publicBookingError = 'This event is currently closed or inactive.';
            } else {
                $cleanPhone = normalizeNzPhone($buyerPhone);
                $gridCols = (int)$event['grid_cols'];
                $gridRows = (int)$event['grid_rows'];
                $cellNaming = $event['cell_naming'] ?? 'alpha';
                $allowChoice = !empty($event['allow_cell_selection']);

                $rawSelected = array_filter(array_map('trim', explode(',', $_POST['selected_cells'] ?? '')));
                $chosenCells = [];

                if ($allowChoice && !empty($rawSelected)) {
                    foreach ($rawSelected as $cell) {
                        $canonical = normalizeCellRef($cell, $gridCols);
                        if (preg_match('/^([A-Z]+)(\d+)$/i', $canonical, $m)) {
                            $colLetters = strtoupper($m[1]);
                            $r = (int)$m[2];
                            $c = 0;
                            for ($i = 0; $i < strlen($colLetters); $i++) {
                                $c = $c * 26 + (ord($colLetters[$i]) - 64);
                            }
                            if ($c >= 1 && $c <= $gridCols && $r >= 1 && $r <= $gridRows) {
                                $chosenCells[] = $canonical;
                            }
                        }
                    } 
                }

                if (empty($chosenCells)) {
                    $qty = max(1, min(50, (int)($_POST['ticket_qty'] ?? 1)));
                    $available = getAvailableCells($db, $bingoEventId, $gridCols, $gridRows);
                    if (count($available) < $qty) {
                        $publicBookingError = 'Sorry, only ' . count($available) . ' square(s) are currently available in the paddock.';
                    } else {
                        shuffle($available);
                        $chosenCells = array_slice($available, 0, $qty);
                    }
                }

                if (empty($publicBookingError)) {
                    $pricingTiers = !empty($event['pricing_tiers']) ? (json_decode($event['pricing_tiers'], true) ?: []) : [];
                    if (empty($pricingTiers)) {
                        $pricingTiers = defined('DEFAULT_PRICING_TIERS') ? DEFAULT_PRICING_TIERS : [1 => 10.00, 3 => 25.00, 5 => 40.00, 10 => 70.00];
                    }
                    $cellCount = count($chosenCells);
                    $batchTotal = calculateBatchPrice($cellCount, $pricingTiers);
                    $batchId = 'BANK-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));

                    $db->beginTransaction();
                    $assignedList = [];
                    $hasCollision = false;
                    $collisionCell = '';

                    $hasPhoneCol = false;
                    try {
                        $colCheck = $db->query("SHOW COLUMNS FROM cow_bingo_cells LIKE 'buyer_phone'");
                        $hasPhoneCol = ($colCheck && $colCheck->fetch());
                    } catch (\Throwable $e) {}

                    if ($hasPhoneCol) {
                        $insertStmt = $db->prepare("
                            INSERT INTO cow_bingo_cells 
                            (event_id, ticket_id, order_id, buyer_name, buyer_email, buyer_phone, cell_reference, is_paid, order_amount, price_paid, payment_method, assigned_by)
                            VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?, 0.00, 'bank_transfer', 'Public Reservation')
                        ");
                    } else {
                        $insertStmt = $db->prepare("
                            INSERT INTO cow_bingo_cells 
                            (event_id, ticket_id, order_id, buyer_name, buyer_email, cell_reference, is_paid, order_amount, price_paid, payment_method, assigned_by)
                            VALUES (?, ?, ?, ?, ?, ?, 0, ?, 0.00, 'bank_transfer', 'Public Reservation')
                        ");
                    }

                    $idx = 1;
                    foreach ($chosenCells as $cell) {
                        $ticketId = $batchId . '-' . $idx++;
                        try {
                            if ($hasPhoneCol) {
                                $insertStmt->execute([
                                    $bingoEventId,
                                    $ticketId,
                                    $batchId,
                                    $buyerName,
                                    $buyerEmail ?: null,
                                    $cleanPhone,
                                    strtoupper($cell),
                                    $batchTotal
                                ]);
                            } else {
                                $contactField = $cleanPhone . ($buyerEmail ? " / $buyerEmail" : "");
                                $insertStmt->execute([
                                    $bingoEventId,
                                    $ticketId,
                                    $batchId,
                                    $buyerName,
                                    $contactField,
                                    strtoupper($cell),
                                    $batchTotal
                                ]);
                            }
                            $assignedList[] = strtoupper($cell);
                        } catch (PDOException $e) {
                            $hasCollision = true;
                            $collisionCell = formatCellRef($cell, $gridCols, $cellNaming);
                            break;
                        }
                    }

                    if ($hasCollision) {
                        $db->rollBack();
                        $publicBookingError = "Square {$collisionCell} was just reserved by someone else! Please choose another square.";
                    } else {
                        $db->commit();
                        $cellStr = implode(', ', array_map(fn($c) => formatCellRef($c, $gridCols, $cellNaming), $assignedList));
                        logAuditEvent($db, $bingoEventId, 'public_reservation', $cellStr, $batchId, $batchId, $buyerName, $batchTotal, "Public bank transfer booking ({$cellCount} sq): $cellStr", 'Public Reservation');

                        $publicBookingSuccess = [
                            'order_id' => $batchId,
                            'buyer_name' => $buyerName,
                            'buyer_phone' => $cleanPhone,
                            'cell_count' => $cellCount,
                            'cells' => array_map(fn($c) => formatCellRef($c, $gridCols, $cellNaming), $assignedList),
                            'total_amount' => $batchTotal,
                            'payment_instructions' => !empty($event['payment_instructions']) ? $event['payment_instructions'] : (!empty($event['event_description']) ? $event['event_description'] : "Please contact the event organisers for direct bank transfer details.")
                        ];

                        // Regenerate captcha
                        $n1 = random_int(2, 9);
                        $n2 = random_int(2, 9);
                        $_SESSION['grid_captcha_num1'] = $n1;
                        $_SESSION['grid_captcha_num2'] = $n2;
                        $_SESSION['grid_captcha_answer'] = $n1 + $n2;
                        $captchaNum1 = $n1;
                        $captchaNum2 = $n2;
                    }
                }
            }
        }
    }
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
$availableCount = max(0, $totalCells - $soldCount);

$paidBookings = array_filter($bookings, fn($b) => !empty($b['is_paid']));
$paidCount = count($paidBookings);
$unpaidCount = $soldCount - $paidCount;
$isRandomOnly = empty($event['allow_cell_selection']);

$eventPrizes = parseEventPrizes($event['event_prizes'] ?? '');

require __DIR__ . '/views/grid_view.php';
