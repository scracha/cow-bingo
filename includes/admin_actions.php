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

            if ($hieventsId && $name) {
                $details = function_exists('fetchEventDetails') ? fetchEventDetails($hieventsId) : [];
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

                try {
                    $stmt = $db->prepare("INSERT INTO cow_bingo_events (hievents_event_id, event_name, event_slug, event_start, event_venue, grid_cols, grid_rows) VALUES (?, ?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$hieventsId, $name, $slug, $startDate, $venue, $cols, $rows]);
                    return "✅ Event '<strong>$name</strong>' created.";
                } catch (PDOException $e) {
                    return (strpos($e->getMessage(), 'Duplicate entry') !== false)
                        ? "⚠️ A Cow Bingo game is already configured for Hi.Events Event ID <strong>$hieventsId</strong>."
                        : "❌ Error: " . $e->getMessage();
                }
            }
            return '❌ Event ID and Name are required.';

        case 'toggle_event':
            $id = (int) ($_POST['event_db_id'] ?? 0);
            $db->prepare("UPDATE cow_bingo_events SET is_active = NOT is_active WHERE id = ?")->execute([$id]);
            return '✅ Event status toggled.';

        case 'assign':
            $eventDbId = (int) ($_POST['event_db_id'] ?? 0);
            $buyerName = trim($_POST['buyer_name'] ?? '');
            $buyerEmail = trim($_POST['buyer_email'] ?? '');
            $rawTicketId = trim($_POST['ticket_id'] ?? '');
            
            // FIX 1: Correctly evaluate unchecked checkboxes as 0
            $isPaid = isset($_POST['is_paid']) ? 1 : 0;
            
            $assignedBy = $_SESSION['admin_name'] ?? 'Admin';
            $selectedCells = array_filter(array_map('trim', explode(',', $_POST['selected_cells'] ?? '')));

            if (!$eventDbId || empty($buyerName)) {
                return '❌ Event and Buyer Name are required.';
            }

            if (empty($selectedCells)) {
                return '❌ Please select at least one cell from the grid or use Quick Random.';
            }

            // Generate batch/order ID for grouping multi-cell purchases
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
                    // FIX 2: Distinguish duplicate ticket errors from cell collisions & DB errors
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

                    // Genuine cell collision (unique_cell_per_event)
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

                // FIX 3: Only ping hi.events if this was an actual external order (not a local MANUAL- or CASH- batch)
                $isExternalHiEventsOrder = !empty($orderId) 
                    && strpos($orderId, 'MANUAL-') !== 0 
                    && strpos($orderId, 'CASH-') !== 0;

                if ($isExternalHiEventsOrder && function_exists('cancelHiEventsOrder')) {
                    $subject = "Ticket Cancellation Notice - Payment Not Received";
                    $body = "Hi {$cell['buyer_name']},\n\nYour Cow Bingo square reservation (Ticket: {$cell['ticket_id']}) has been cancelled as payment was not received.\n\nThank you.";
                    if (function_exists('messageHiEventsOrder')) {
                        messageHiEventsOrder($cell['hievents_event_id'], $orderId, $subject, $body);
                    }
                    cancelHiEventsOrder($cell['hievents_event_id'], $orderId);
                }

                $db->prepare("DELETE FROM cow_bingo_cells WHERE id = ?")->execute([$cellId]);
                return "✅ Cancelled square for <strong>{$buyer}</strong> (Ticket: {$ticket}). Square returned to the paddock.";
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