<?php
// includes/admin_actions.php

function handleAdminActions(PDO $db, int $currentAdminId, bool $isSuperAdmin): string {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['action'])) {
        return '';
    }

    switch ($_POST['action']) {
        case 'create_event':
            $hieventsId = trim($_POST['hievents_event_id'] ?? '') ?: trim($_POST['hievents_event_id_manual'] ?? '');
            $name = trim($_POST['event_name'] ?? '');
            $cols = (int) ($_POST['grid_cols'] ?? 16);
            $rows = (int) ($_POST['grid_rows'] ?? 16);

            if (empty($name)) {
                return '❌ Event Name is required.';
            }

            $hieventsId = $hieventsId ?: null;
            $slug = null;
            $startDate = null;
            $venue = null;

            // 1. Fetch metadata from hi.events if an ID is provided and the server is enabled
            if ($hieventsId && (!defined('HIEVENTS_ENABLED') || HIEVENTS_ENABLED)) {
                $details = function_exists('fetchEventDetails') ? fetchEventDetails($hieventsId) : [];
                $slug = $details['slug'] ?? (function_exists('fetchEventSlug') ? fetchEventSlug($hieventsId) : null);
                if (!empty($details['start_date'])) {
                    try {
                        $dt = new DateTime($details['start_date']);
                        $dt->setTimezone(new DateTimeZone(date_default_timezone_get()));
                        $startDate = $dt->format('Y-m-d H:i:s');
                    } catch (Exception $e) {}
                }
                $venue = $details['settings']['location_details']['venue_name'] ?? null;
            }

            // 2. Fallback slug generation from local event name
            if (empty($slug)) {
                $slug = trim(preg_replace('/[^a-z0-9]+/i', '-', strtolower($name)), '-');
            }

            // 3. Parse Pricing Tiers from GUI (e.g. "1:10, 3:25, 5:40, 10:70")
            $rawTiers = trim($_POST['pricing_tiers'] ?? '');
            $parsedTiers = [];
            if (!empty($rawTiers)) {
                foreach (explode(',', $rawTiers) as $part) {
                    $pair = explode(':', trim($part));
                    if (count($pair) === 2 && is_numeric(trim($pair[0])) && is_numeric(trim($pair[1]))) {
                        $parsedTiers[(int)trim($pair[0])] = (float)trim($pair[1]);
                    }
                }
            }

            // If empty, fallback to hi.events products if available, or system defaults
            if (empty($parsedTiers) && $hieventsId && (!defined('HIEVENTS_ENABLED') || HIEVENTS_ENABLED) && function_exists('fetchHiEventsProducts')) {
                $rawProducts = fetchHiEventsProducts($hieventsId);
                foreach ($rawProducts as $p) {
                    $title = strtolower($p['title'] ?? $p['name'] ?? '');
                    $price = (float)($p['price'] ?? 0);
                    if (preg_match('/(\d+)\s*(square|ticket|pack)/i', $title, $matches)) {
                        $qty = (int)$matches[1];
                    } elseif (strpos($title, 'single') !== false || strpos($title, '1') !== false) {
                        $qty = 1;
                    } else {
                        $qty = 1;
                    }
                    $parsedTiers[$qty] = $price;
                }
            }

            if (empty($parsedTiers)) {
                $parsedTiers = defined('DEFAULT_PRICING_TIERS') ? DEFAULT_PRICING_TIERS : [1 => 10.00, 3 => 25.00, 5 => 40.00, 10 => 70.00];
            }
            $pricingJson = json_encode($parsedTiers);

            try {
                $stmt = $db->prepare("
                    INSERT INTO cow_bingo_events 
                    (hievents_event_id, event_name, event_slug, event_start, event_venue, grid_cols, grid_rows, pricing_tiers) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([$hieventsId, $name, $slug, $startDate, $venue, $cols, $rows, $pricingJson]);
                $modeText = $hieventsId ? " (Linked to Hi.Events ID $hieventsId)" : " (Standalone / Offline)";
                return "✅ Event '<strong>$name</strong>' created$modeText.";
            } catch (PDOException $e) {
                return (strpos($e->getMessage(), 'Duplicate entry') !== false)
                    ? "⚠️ A Cow Bingo game is already configured for Hi.Events Event ID <strong>$hieventsId</strong>."
                    : "❌ Error: " . $e->getMessage();
            }

        case 'link_hievents':
            $eventDbId = (int) ($_POST['event_db_id'] ?? 0);
            $hieventsId = trim($_POST['hievents_event_id'] ?? '');

            if (!$eventDbId || empty($hieventsId)) {
                return '❌ Event and Hi.Events ID are required.';
            }

            // Check for duplicate claim on another local event
            $check = $db->prepare("SELECT id, event_name FROM cow_bingo_events WHERE hievents_event_id = ? AND id != ?");
            $check->execute([$hieventsId, $eventDbId]);
            if ($dup = $check->fetch(PDO::FETCH_ASSOC)) {
                return "⚠️ Hi.Events ID <strong>$hieventsId</strong> is already linked to '{$dup['event_name']}'.";
            }

            $hiEventsOnline = (!defined('HIEVENTS_ENABLED') || HIEVENTS_ENABLED);
            $details = [];

            // 1. Validate ID exists on hi.events if server is reachable
            if ($hiEventsOnline && function_exists('fetchEventDetails')) {
                $details = fetchEventDetails($hieventsId);
                if (empty($details)) {
                    return "❌ Could not find Event ID <strong>$hieventsId</strong> on your Hi.Events server. Please check the ID.";
                }
            }

            $slug = $details['slug'] ?? (function_exists('fetchEventSlug') ? fetchEventSlug($hieventsId) : null);
            $startDate = null;
            if (!empty($details['start_date'])) {
                try {
                    $dt = new DateTime($details['start_date']);
                    $dt->setTimezone(new DateTimeZone(date_default_timezone_get()));
                    $startDate = $dt->format('Y-m-d H:i:s');
                } catch (Exception $e) {}
            }
            $venue = $details['settings']['location_details']['venue_name'] ?? null;

            // 2. Fetch pricing tiers ONLY as a fallback if the local event doesn't have any configured yet
            $pulledTiersJson = null;
            if ($hiEventsOnline && function_exists('fetchHiEventsProducts')) {
                $rawProducts = fetchHiEventsProducts($hieventsId);
                $pulled = [];
                foreach ($rawProducts as $p) {
                    $title = strtolower($p['title'] ?? $p['name'] ?? '');
                    $price = (float)($p['price'] ?? 0);
                    if (preg_match('/(\d+)\s*(square|ticket|pack)/i', $title, $matches)) {
                        $qty = (int)$matches[1];
                    } elseif (strpos($title, 'single') !== false || strpos($title, '1') !== false) {
                        $qty = 1;
                    } else {
                        $qty = 1;
                    }
                    $pulled[$qty] = $price;
                }
                if (!empty($pulled)) {
                    $pulledTiersJson = json_encode($pulled);
                }
            }

            // 3. Update without clobbering existing local slug or custom pricing tiers
            $stmt = $db->prepare("
                UPDATE cow_bingo_events 
                SET hievents_event_id = ?, 
                    event_slug = COALESCE(event_slug, ?),
                    event_start = COALESCE(?, event_start),
                    event_venue = COALESCE(?, event_venue),
                    pricing_tiers = COALESCE(pricing_tiers, ?)
                WHERE id = ?
            ");
            $stmt->execute([$hieventsId, $slug, $startDate, $venue, $pulledTiersJson, $eventDbId]);

            return "✅ Successfully linked to Hi.Events Event ID <strong>$hieventsId</strong>. Existing local pricing and URLs preserved.";

        case 'toggle_event':
            $id = (int) ($_POST['event_db_id'] ?? 0);
            $db->prepare("UPDATE cow_bingo_events SET is_active = NOT is_active WHERE id = ?")->execute([$id]);
            return '✅ Event status toggled.';

        case 'assign':
            $eventDbId = (int) ($_POST['event_db_id'] ?? 0);
            $buyerName = trim($_POST['buyer_name'] ?? '');
            $buyerEmail = trim($_POST['buyer_email'] ?? '');
            $rawTicketId = trim($_POST['ticket_id'] ?? '');
            $isPaid = isset($_POST['is_paid']) ? 1 : 0;
            $assignedBy = $_SESSION['admin_name'] ?? 'Admin';
            $selectedCells = array_filter(array_map('trim', explode(',', $_POST['selected_cells'] ?? '')));

            if (!$eventDbId || empty($buyerName)) {
                return '❌ Event and Buyer Name are required.';
            }

            if (empty($selectedCells)) {
                return '❌ Please select at least one cell from the grid or use Quick Random.';
            }

            $batchId = $rawTicketId ?: ('MANUAL-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6)));

            $db->beginTransaction();
            $assignedList = [];
            $hasCollision = false;
            $collisionCell = '';

            $insertStmt = $db->prepare("
                INSERT INTO cow_bingo_cells 
                (event_id, ticket_id, order_id, buyer_name, buyer_email, cell_reference, is_paid, assigned_by) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");

            $idx = 1;
            $multi = count($selectedCells) > 1;

            foreach ($selectedCells as $cell) {
                $ticketId = $multi ? ($batchId . '-' . $idx++) : ($rawTicketId ?: $batchId);

                try {
                    $insertStmt->execute([
                        $eventDbId,
                        $ticketId,
                        $batchId,
                        $buyerName,
                        $buyerEmail ?: null,
                        strtoupper($cell),
                        $isPaid,
                        $assignedBy
                    ]);
                    $assignedList[] = strtoupper($cell);
                } catch (PDOException $e) {
                    $isDuplicate = ($e->errorInfo[1] ?? null) === 1062
                        || strpos($e->getMessage(), '1062') !== false
                        || strpos($e->getMessage(), 'Duplicate entry') !== false;

                    if (!$isDuplicate) {
                        $db->rollBack();
                        return "❌ Database error: " . htmlspecialchars($e->getMessage());
                    }

                    if (strpos($e->getMessage(), 'unique_ticket_per_event') !== false) {
                        $db->rollBack();
                        return "❌ Ticket/Ref <strong>" . htmlspecialchars($ticketId) . "</strong> is already assigned to a cell. Each square requires a unique ticket ID. To assign multiple squares to this buyer, select them together on the grid above, or leave the reference blank to auto-generate.";
                    }

                    $hasCollision = true;
                    $collisionCell = $cell;
                    break;
                }
            }

            if ($hasCollision) {
                $db->rollBack();
                return "❌ Cell <strong>$collisionCell</strong> was already taken! No cells were assigned.";
            }

            $db->commit();
            $cellStr = implode(', ', $assignedList);
            $count = count($assignedList);
            $paidStatus = $isPaid ? ' (Paid)' : ' <span style="color:#856404;font-weight:bold;">(Unpaid)</span>';
            return "✅ Successfully assigned $count cell" . ($count > 1 ? 's' : '') . " (<strong>$cellStr</strong>) to $buyerName$paidStatus [Ref: $batchId].";

        case 'delete_cell':
            $cellId = (int) ($_POST['cell_id'] ?? 0);
            $db->prepare("DELETE FROM cow_bingo_cells WHERE id = ?")->execute([$cellId]);
            return '✅ Cell assignment removed from grid.';

        case 'cancel_and_notify':
            $cellId = (int) ($_POST['cell_id'] ?? 0);
            $stmt = $db->prepare("SELECT c.*, e.hievents_event_id FROM cow_bingo_cells c JOIN cow_bingo_events e ON e.id = c.event_id WHERE c.id = ?");
            $stmt->execute([$cellId]);
            $cell = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($cell) {
                $buyer = htmlspecialchars($cell['buyer_name']);
                $ticket = htmlspecialchars($cell['ticket_id']);
                $orderId = $cell['order_id'] ?? '';

                $isExternalOrder = !empty($orderId) && strpos($orderId, 'MANUAL-') !== 0 && strpos($orderId, 'CASH-') !== 0;

                if ($isExternalOrder && function_exists('cancelHiEventsOrder') && (!defined('HIEVENTS_ENABLED') || HIEVENTS_ENABLED)) {
                    $subject = "Ticket Cancellation Notice - Payment Not Received";
                    $body = "Hi {$cell['buyer_name']},\n\nYour Cow Bingo square reservation (Ticket: {$cell['ticket_id']}) has been cancelled as payment was not received.\n\nThank you.";
                    if (function_exists('messageHiEventsOrder')) messageHiEventsOrder($cell['hievents_event_id'], $orderId, $subject, $body);
                    cancelHiEventsOrder($cell['hievents_event_id'], $orderId);
                }

                $db->prepare("DELETE FROM cow_bingo_cells WHERE id = ?")->execute([$cellId]);
                return "✅ Cancelled square for <strong>{$buyer}</strong> (Ticket: {$ticket}). Square returned to paddock.";
            }
            return '❌ Cell not found.';

        case 'assign_prize_by_ref':
            $eventDbId = (int) ($_POST['event_db_id'] ?? 0);
            $cellRef = strtoupper(trim($_POST['cell_reference'] ?? ''));
            $prize = trim($_POST['prize'] ?? '');
            if ($eventDbId && $cellRef && $prize) {
                $stmt = $db->prepare("UPDATE cow_bingo_cells SET prize = ? WHERE event_id = ? AND cell_reference = ?");
                $stmt->execute([$prize, $eventDbId, $cellRef]);
                return ($stmt->rowCount() > 0)
                    ? "✅ Prize '<strong>" . htmlspecialchars($prize) . "</strong>' assigned to cell <strong>$cellRef</strong>."
                    : "❌ Cell <strong>$cellRef</strong> not found or not sold.";
            }
            return '❌ Cell reference and prize text are required.';

        case 'assign_prize':
            $cellId = (int) ($_POST['cell_id'] ?? 0);
            $prize = trim($_POST['prize'] ?? '');
            $db->prepare("UPDATE cow_bingo_cells SET prize = ? WHERE id = ?")->execute([$prize ?: null, $cellId]);
            return $prize ? "✅ Prize assigned." : "✅ Prize removed.";

        case 'sync_attendees':
            $eventDbId = (int) ($_POST['event_db_id'] ?? 0);
            if (!$isSuperAdmin && !canManageEvent($db, $currentAdminId, $eventDbId)) return '❌ Access denied.';
            $evStmt = $db->prepare("SELECT * FROM cow_bingo_events WHERE id = ?");
            $evStmt->execute([$eventDbId]);
            $ev = $evStmt->fetch(PDO::FETCH_ASSOC);
            if (!$ev) return '❌ Event not found.';
            if (empty($ev['hievents_event_id'])) return '⚠️ This is a standalone event not yet linked to Hi.Events.';
            $synced = syncAttendeesFromHiEvents($db, $ev);
            return "✅ Sync complete. $synced new cells assigned.";

        case 'create_admin':
            if (!$isSuperAdmin) return '❌ Access denied.';
            $newUser = trim($_POST['new_username'] ?? '');
            $newPass = $_POST['new_password'] ?? '';
            $newName = trim($_POST['new_display_name'] ?? '');
            $newRole = $_POST['new_role'] ?? 'event_admin';
            $newSuper = ($newRole === 'super_admin') ? 1 : 0;
            $newEventIds = $_POST['new_event_ids'] ?? [];

            if ($newUser && $newPass && $newName) {
                try {
                    $stmt = $db->prepare("INSERT INTO cow_bingo_admins (username, password_hash, display_name, is_super_admin, role) VALUES (?, ?, ?, ?, ?)");
                    $stmt->execute([$newUser, password_hash($newPass, PASSWORD_DEFAULT), $newName, $newSuper, $newRole]);
                    $newAdminId = (int) $db->lastInsertId();
                    if (!$newSuper && !empty($newEventIds)) {
                        $assignStmt = $db->prepare("INSERT IGNORE INTO cow_bingo_admin_events (admin_id, event_id) VALUES (?, ?)");
                        foreach ($newEventIds as $eid) $assignStmt->execute([$newAdminId, (int)$eid]);
                    }
                    return "✅ User '<strong>$newName</strong>' created as " . htmlspecialchars($newRole) . ".";
                } catch (PDOException $e) {
                    return "❌ Error: " . $e->getMessage();
                }
            }
            return '❌ All fields required.';

        case 'delete_admin':
            if (!$isSuperAdmin) return '❌ Access denied.';
            $delId = (int) ($_POST['admin_id'] ?? 0);
            if ($delId === $currentAdminId) return '❌ Cannot delete yourself.';
            $db->prepare("DELETE FROM cow_bingo_admins WHERE id = ?")->execute([$delId]);
            return '✅ User removed.';
    }
    return '';
}

