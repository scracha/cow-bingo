<?php
// includes/admin_actions.php

function handleAdminActions(PDO $db, int $currentAdminId, bool $isSuperAdmin): string {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['action'])) {
        return '';
    }

    if (!verifyCsrfToken($_POST['csrf_token'] ?? null)) {
        return '❌ Security token expired or invalid request. Please refresh and try again.';
    }

    $adminName = $_SESSION['admin_name'] ?? 'Admin';

    switch ($_POST['action']) {
        case 'create_event':
            if (($_SESSION['admin_role'] ?? '') === 'seller') {
                return '❌ Access denied.';
            }

            $hieventsId = trim($_POST['hievents_event_id'] ?? '');
            $name = trim($_POST['event_name'] ?? '');
            $description = trim($_POST['event_description'] ?? '');
            $cols = (int) ($_POST['grid_cols'] ?? 16);
            $rows = (int) ($_POST['grid_rows'] ?? 16);
            $allowCellSelection = isset($_POST['allow_cell_selection']) ? 1 : 0;
            $cellNaming = ($_POST['cell_naming'] ?? 'alpha') === 'numeric' ? 'numeric' : 'alpha';
            $entriesClosed = isset($_POST['entries_closed']) ? 1 : 0;

            if (empty($name)) {
                return '❌ Event Name is required.';
            }

            $hieventsId = $hieventsId ?: null;
            $slug = null;
            $startDate = null;
            $venue = trim($_POST['event_venue'] ?? '') ?: null;

            $manualStart = trim($_POST['event_start'] ?? '');
            if (!empty($manualStart)) {
                try {
                    $dt = new DateTime($manualStart);
                    $startDate = $dt->format('Y-m-d H:i:s');
                } catch (Exception $e) {}
            }

            if ($hieventsId && (!defined('HIEVENTS_ENABLED') || HIEVENTS_ENABLED)) {
                $details = function_exists('fetchEventDetails') ? fetchEventDetails($hieventsId) : [];
                $slug = $details['slug'] ?? (function_exists('fetchEventSlug') ? fetchEventSlug($hieventsId) : null);
                
                if (empty($startDate) && !empty($details['start_date'])) {
                    try {
                        $dt = new DateTime($details['start_date']);
                        $dt->setTimezone(new DateTimeZone(date_default_timezone_get()));
                        $startDate = $dt->format('Y-m-d H:i:s');
                    } catch (Exception $e) {}
                }
                if (empty($venue)) {
                    $venue = $details['settings']['location_details']['venue_name'] ?? null;
                }
                if (empty($description) && !empty($details['description'])) {
                    $description = strip_tags($details['description']);
                }
            }

            if (empty($slug)) {
                $slug = trim(preg_replace('/[^a-z0-9]+/i', '-', strtolower($name)), '-');
            }

            // Event Image Upload Handling
            $eventImage = null;
            if (!empty($_FILES['event_image']) && $_FILES['event_image']['error'] === UPLOAD_ERR_OK) {
                $eventImage = handleEventImageUpload($_FILES['event_image']);
            }

            $paymentInstructions = trim($_POST['payment_instructions'] ?? '') ?: null;
            $rawPrizes = trim($_POST['event_prizes'] ?? '');
            $parsedPrizes = parseEventPrizes($rawPrizes);
            $prizesJson = !empty($parsedPrizes) ? json_encode($parsedPrizes) : null;

            $rawTiers = trim($_POST['pricing_tiers'] ?? '');
            $parsedTiers = [];
            if (!empty($rawTiers)) {
                if (strpos($rawTiers, '{') === 0) {
                    $decoded = json_decode($rawTiers, true);
                    if (is_array($decoded)) {
                        foreach ($decoded as $k => $v) {
                            if (is_numeric($k) && is_numeric($v)) {
                                $parsedTiers[(int)$k] = (float)$v;
                            }
                        }
                    }
                } else {
                    foreach (explode(',', $rawTiers) as $part) {
                        $pair = explode(':', trim($part));
                        if (count($pair) === 2 && is_numeric(trim($pair[0])) && is_numeric(trim($pair[1]))) {
                            $parsedTiers[(int)trim($pair[0])] = (float)trim($pair[1]);
                        }
                    }
                }
            }

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
                    (hievents_event_id, event_name, event_slug, event_start, event_venue, event_description, event_image, payment_instructions, grid_cols, grid_rows, pricing_tiers, event_prizes, allow_cell_selection, cell_naming, entries_closed)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([$hieventsId, $name, $slug, $startDate, $venue, $description ?: null, $eventImage, $paymentInstructions, $cols, $rows, $pricingJson, $prizesJson, $allowCellSelection, $cellNaming, $entriesClosed]);
                $newEventId = (int)$db->lastInsertId();

                if (!$isSuperAdmin) {
                    $assignStmt = $db->prepare("INSERT IGNORE INTO cow_bingo_admin_events (admin_id, event_id) VALUES (?, ?)");
                    $assignStmt->execute([$currentAdminId, $newEventId]);
                }

                $_SESSION['selected_event_id'] = $newEventId;
                logAuditEvent($db, $newEventId, 'event_created', null, null, null, null, null, "Created event '{$name}' (Numbering: $cellNaming)", $adminName);
                $safeName = htmlspecialchars($name);
                $modeText = $hieventsId ? " (Linked to Hi.Events ID $hieventsId)" : " (Standalone / Offline)";
                return "✅ Event '<strong>$safeName</strong>' created$modeText.";
            } catch (PDOException $e) {
                return (strpos($e->getMessage(), 'Duplicate entry') !== false)
                    ? "⚠️ A Cow Pat Bingo game is already configured for Hi.Events Event ID <strong>$hieventsId</strong>."
                    : "❌ Error: " . $e->getMessage();
            }

        case 'update_event_details':
            $eventDbId = (int)($_POST['event_db_id'] ?? 0);
            if (!$eventDbId) return '❌ Event not found.';

            if (!$isSuperAdmin && !canManageEvent($db, $currentAdminId, $eventDbId)) {
                return '❌ Access denied: You do not have permission to modify this event.';
            }

            $check = $db->prepare("SELECT id, event_image FROM cow_bingo_events WHERE id = ?");
            $check->execute([$eventDbId]);
            $existingRow = $check->fetch(PDO::FETCH_ASSOC);
            if (!$existingRow) {
                return '❌ Event not found or already deleted.';
            }

            $name = trim($_POST['event_name'] ?? '');
            $venue = trim($_POST['event_venue'] ?? '');
            $start = trim($_POST['event_start'] ?? '');
            $description = trim($_POST['event_description'] ?? '');
            $cellNaming = ($_POST['cell_naming'] ?? 'alpha') === 'numeric' ? 'numeric' : 'alpha';
            $allowCellSelection = isset($_POST['allow_cell_selection']) ? 1 : 0;
            $entriesClosed = isset($_POST['entries_closed']) ? 1 : 0;
            $paymentInstructions = trim($_POST['payment_instructions'] ?? '') ?: null;
            $rawPrizes = trim($_POST['event_prizes'] ?? '');
            $parsedPrizes = parseEventPrizes($rawPrizes);
            $prizesJson = !empty($parsedPrizes) ? json_encode($parsedPrizes) : null;
            $rawTiers = trim($_POST['pricing_tiers'] ?? '');

            if (empty($name)) return '❌ Event Name is required.';

            $startDate = null;
            if (!empty($start)) {
                try {
                    $dt = new DateTime($start);
                    $startDate = $dt->format('Y-m-d H:i:s');
                } catch (Exception $e) {
                    $startDate = null;
                }
            }

            // Event Image Management: upload new, keep existing, or remove
            $currentImage = $existingRow['event_image'] ?? null;
            $finalImage = $currentImage;

            if (!empty($_POST['remove_event_image'])) {
                deleteEventImageFile($currentImage);
                $finalImage = null;
            }

            if (!empty($_FILES['event_image']) && $_FILES['event_image']['error'] === UPLOAD_ERR_OK) {
                $uploaded = handleEventImageUpload($_FILES['event_image']);
                if ($uploaded) {
                    deleteEventImageFile($currentImage);
                    $finalImage = $uploaded;
                }
            }

            $parsedTiers = [];
            if (!empty($rawTiers)) {
                if (strpos($rawTiers, '{') === 0) {
                    $decoded = json_decode($rawTiers, true);
                    if (is_array($decoded)) {
                        foreach ($decoded as $k => $v) {
                            if (is_numeric($k) && is_numeric($v)) {
                                $parsedTiers[(int)$k] = (float)$v;
                            }
                        }
                    }
                } else {
                    foreach (explode(',', $rawTiers) as $part) {
                        $pair = explode(':', trim($part));
                        if (count($pair) === 2 && is_numeric(trim($pair[0])) && is_numeric(trim($pair[1]))) {
                            $parsedTiers[(int)trim($pair[0])] = (float)trim($pair[1]);
                        }
                    }
                }
            }
            $pricingJson = !empty($parsedTiers) ? json_encode($parsedTiers) : null;

            try {
                $stmt = $db->prepare("
                    UPDATE cow_bingo_events 
                    SET event_name = ?, 
                        event_venue = ?, 
                        event_start = ?, 
                        event_description = ?, 
                        event_image = ?, 
                        payment_instructions = ?, 
                        cell_naming = ?, 
                        allow_cell_selection = ?, 
                        entries_closed = ?, 
                        pricing_tiers = COALESCE(?, pricing_tiers), 
                        event_prizes = ? 
                    WHERE id = ?
                ");
                $stmt->execute([$name, $venue ?: null, $startDate, $description ?: null, $finalImage, $paymentInstructions, $cellNaming, $allowCellSelection, $entriesClosed, $pricingJson, $prizesJson, $eventDbId]);
            } catch (PDOException $e) {
                return "❌ Error updating event: " . htmlspecialchars($e->getMessage());
            }

            $_SESSION['selected_event_id'] = $eventDbId;
            logAuditEvent($db, $eventDbId, 'event_updated', null, null, null, null, null, "Updated event details (Numbering: $cellNaming, Entries Closed: $entriesClosed)", $adminName);
            return "✅ Event details for '<strong>" . htmlspecialchars($name) . "</strong>' updated successfully.";

        case 'toggle_entries_closed':
            $eventDbId = (int)($_POST['event_db_id'] ?? 0);
            if (!$eventDbId) return '❌ Event not found.';

            if (!$isSuperAdmin && !canManageEvent($db, $currentAdminId, $eventDbId)) {
                return '❌ Access denied.';
            }

            $db->prepare("UPDATE cow_bingo_events SET entries_closed = CASE WHEN entries_closed = 1 THEN 0 ELSE 1 END WHERE id = ?")->execute([$eventDbId]);
            $st = $db->prepare("SELECT entries_closed, event_name FROM cow_bingo_events WHERE id = ?");
            $st->execute([$eventDbId]);
            $evRow = $st->fetch(PDO::FETCH_ASSOC);
            $isClosed = (int)($evRow['entries_closed'] ?? 0) === 1;
            $_SESSION['selected_event_id'] = $eventDbId;

            $statusText = $isClosed ? 'Entries Closed (no new purchases allowed)' : 'Entries Reopened (ticket purchases active)';
            logAuditEvent($db, $eventDbId, 'toggle_entries_closed', null, null, null, null, null, $statusText, $adminName);
            return $isClosed 
                ? "🔒 Entries CLOSED for '<strong>" . htmlspecialchars($evRow['event_name']) . "</strong>'. No more squares can be purchased." 
                : "🎟️ Entries REOPENED for '<strong>" . htmlspecialchars($evRow['event_name']) . "</strong>'. Buyers can now purchase squares.";

        case 'delete_event':
            if (!$isSuperAdmin) {
                return '❌ Access denied: Only Super Administrators can permanently delete events.';
            }
            $eventDbId = (int)($_POST['event_db_id'] ?? 0);
            if (!$eventDbId) return '❌ Event not found.';

            $stmt = $db->prepare("SELECT event_name, hievents_event_id, event_image FROM cow_bingo_events WHERE id = ?");
            $stmt->execute([$eventDbId]);
            $ev = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$ev) return '❌ Event not found or already deleted.';

            $evName = $ev['event_name'];
            $hiId = $ev['hievents_event_id'];
            $evImage = $ev['event_image'] ?? null;

            $db->beginTransaction();
            try {
                $db->prepare("DELETE FROM cow_bingo_cells WHERE event_id = ?")->execute([$eventDbId]);
                $db->prepare("DELETE FROM cow_bingo_audit_log WHERE event_id = ?")->execute([$eventDbId]);
                $db->prepare("DELETE FROM cow_bingo_admin_events WHERE event_id = ?")->execute([$eventDbId]);
                if (!empty($hiId)) {
                    $db->prepare("DELETE FROM webhook_log WHERE hievents_event_id = ?")->execute([(string)$hiId]);
                }
                $db->prepare("DELETE FROM cow_bingo_events WHERE id = ?")->execute([$eventDbId]);
                $db->commit();

                if (!empty($evImage)) {
                    deleteEventImageFile($evImage);
                }

                if (isset($_SESSION['selected_event_id']) && (int)$_SESSION['selected_event_id'] === $eventDbId) {
                    unset($_SESSION['selected_event_id']);
                }

                return "✅ Event '<strong>" . htmlspecialchars($evName) . "</strong>' and all associated data permanently deleted.";
            } catch (Exception $e) {
                $db->rollBack();
                return "❌ Error deleting event: " . htmlspecialchars($e->getMessage());
            }

        case 'delete_all_unpaid':
            $eventDbId = (int)($_POST['event_db_id'] ?? 0);
            if (!$eventDbId) return '❌ Event not found.';

            if (!$isSuperAdmin && !canManageEvent($db, $currentAdminId, $eventDbId)) {
                return '❌ Access denied: You do not have permission to manage this event.';
            }

            $countStmt = $db->prepare("SELECT COUNT(*) FROM cow_bingo_cells WHERE event_id = ? AND (is_paid = 0 OR is_paid IS NULL)");
            $countStmt->execute([$eventDbId]);
            $unpaidCount = (int)$countStmt->fetchColumn();

            if ($unpaidCount === 0) {
                return "ℹ️ No unpaid squares found to remove for this event.";
            }

            $db->prepare("DELETE FROM cow_bingo_cells WHERE event_id = ? AND (is_paid = 0 OR is_paid IS NULL)")->execute([$eventDbId]);
            logAuditEvent($db, $eventDbId, 'unpaid_cells_purged', null, null, null, null, null, "Bulk removed $unpaidCount unpaid square(s) from grid", $adminName);
            return "✅ Removed $unpaidCount unpaid square" . ($unpaidCount === 1 ? '' : 's') . " from the grid. All returned to available pool.";

        case 'toggle_cell_naming':
            $id = (int) ($_POST['event_db_id'] ?? 0);
            if (!$id) return '❌ Event not found.';

            if (!$isSuperAdmin && !canManageEvent($db, $currentAdminId, $id)) {
                return '❌ Access denied: You do not have permission to manage this event.';
            }

            $db->prepare("UPDATE cow_bingo_events SET cell_naming = CASE WHEN cell_naming = 'numeric' THEN 'alpha' ELSE 'numeric' END WHERE id = ?")->execute([$id]);
            $st = $db->prepare("SELECT cell_naming FROM cow_bingo_events WHERE id = ?");
            $st->execute([$id]);
            $newMode = $st->fetchColumn() ?: 'alpha';
            $_SESSION['selected_event_id'] = $id;
            logAuditEvent($db, $id, 'toggle_cell_naming', null, null, null, null, null, "Switched numbering to " . ucfirst($newMode), $adminName);

            return $newMode === 'numeric'
                ? "✅ Numbering switched to Sequential Numbers (e.g. 1, 16, 43)."
                : "✅ Numbering switched to Grid References (e.g. A1, D2, G4).";

        case 'toggle_cell_selection':
            $id = (int) ($_POST['event_db_id'] ?? 0);
            if (!$id) return '❌ Event not found.';

            if (!$isSuperAdmin && !canManageEvent($db, $currentAdminId, $id)) {
                return '❌ Access denied.';
            }

            $db->prepare("UPDATE cow_bingo_events SET allow_cell_selection = CASE WHEN allow_cell_selection = 0 THEN 1 ELSE 0 END WHERE id = ?")->execute([$id]);
            $st = $db->prepare("SELECT allow_cell_selection FROM cow_bingo_events WHERE id = ?");
            $st->execute([$id]);
            $newMode = (int)$st->fetchColumn() === 1;
            $_SESSION['selected_event_id'] = $id;

            logAuditEvent($db, $id, 'toggle_square_choice', null, null, null, null, null, $newMode ? 'Square Choice Enabled' : 'Locked to Random Only', $adminName);
            return $newMode ? "✅ Square Choice ENABLED." : "✅ Square Choice LOCKED to Random Only.";

        case 'link_hievents':
            $eventDbId = (int) ($_POST['event_db_id'] ?? 0);
            $hieventsId = trim($_POST['hievents_event_id'] ?? '');

            if (!$eventDbId || empty($hieventsId)) {
                return '❌ Event and Hi.Events ID are required.';
            }

            if (!$isSuperAdmin && !canManageEvent($db, $currentAdminId, $eventDbId)) {
                return '❌ Access denied: You do not have permission to manage this event.';
            }

            $check = $db->prepare("SELECT id, event_name FROM cow_bingo_events WHERE hievents_event_id = ? AND id != ?");
            $check->execute([$hieventsId, $eventDbId]);
            if ($dup = $check->fetch(PDO::FETCH_ASSOC)) {
                return "⚠️ Hi.Events ID <strong>$hieventsId</strong> is already linked to '" . htmlspecialchars($dup['event_name']) . "'.";
            }

            $hiEventsOnline = (!defined('HIEVENTS_ENABLED') || HIEVENTS_ENABLED);
            $details = [];

            if ($hiEventsOnline && function_exists('fetchEventDetails')) {
                $details = fetchEventDetails($hieventsId);
                if (empty($details)) {
                    return "❌ Could not find Event ID <strong>$hieventsId</strong> on your Hi.Events server. Please verify the ID.";
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
            $pulledDesc = !empty($details['description']) ? strip_tags($details['description']) : null;

            $stmt = $db->prepare("
                UPDATE cow_bingo_events 
                SET hievents_event_id = ?, 
                    event_slug = COALESCE(event_slug, ?), 
                    event_start = COALESCE(?, event_start), 
                    event_venue = COALESCE(?, event_venue), 
                    event_description = COALESCE(event_description, ?)
                WHERE id = ?
            ");
            $stmt->execute([$hieventsId, $slug, $startDate, $venue, $pulledDesc, $eventDbId]);
            $_SESSION['selected_event_id'] = $eventDbId;
            logAuditEvent($db, $eventDbId, 'hievents_linked', null, null, null, null, null, "Linked to Hi.Events Event ID $hieventsId", $adminName);
            return "✅ Successfully linked to Hi.Events Event ID <strong>$hieventsId</strong>.";

        case 'toggle_event':
            $id = (int) ($_POST['event_db_id'] ?? 0);
            if (!$id) return '❌ Event not found.';

            if (!$isSuperAdmin && !canManageEvent($db, $currentAdminId, $id)) {
                return '❌ Access denied.';
            }

            $db->prepare("UPDATE cow_bingo_events SET is_active = NOT is_active WHERE id = ?")->execute([$id]);
            $st = $db->prepare("SELECT is_active FROM cow_bingo_events WHERE id = ?");
            $st->execute([$id]);
            $isActive = (int)$st->fetchColumn() === 1;
            $stateText = $isActive ? 'Event Activated' : 'Event Deactivated';
            $_SESSION['selected_event_id'] = $id;
            logAuditEvent($db, $id, 'toggle_event_active', null, null, null, null, null, $stateText, $adminName);
            return "✅ Event status toggled: $stateText.";

        case 'assign':
            $eventDbId = (int) ($_POST['event_db_id'] ?? 0);
            $buyerName = trim($_POST['buyer_name'] ?? '');
            $buyerEmail = trim($_POST['buyer_email'] ?? '');
            $rawTicketId = trim($_POST['ticket_id'] ?? '');
            $isPaid = isset($_POST['is_paid']) ? 1 : 0;
            $selectedCells = array_filter(array_map('trim', explode(',', $_POST['selected_cells'] ?? '')));

            if (!$eventDbId || empty($buyerName)) {
                return '❌ Event and Buyer Name are required.';
            }

            if (!$isSuperAdmin && !canManageEvent($db, $currentAdminId, $eventDbId)) {
                return '❌ Access denied: You do not have permission to manage this event.';
            }

            if (empty($selectedCells)) {
                return '❌ Please select at least one cell from the grid or use Quick Random.';
            }

            $evStmt = $db->prepare("SELECT grid_cols, grid_rows, cell_naming, pricing_tiers FROM cow_bingo_events WHERE id = ?");
            $evStmt->execute([$eventDbId]);
            $evRow = $evStmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $gridCols = (int)($evRow['grid_cols'] ?? 16);
            $gridRows = (int)($evRow['grid_rows'] ?? 16);
            $cellNaming = $evRow['cell_naming'] ?? 'alpha';
            $evTiersRaw = $evRow['pricing_tiers'] ?? '';
            $evTiers = !empty($evTiersRaw) ? (json_decode($evTiersRaw, true) ?: []) : [];

            $qty = count($selectedCells);
            $batchTotal = calculateBatchPrice($qty, $evTiers);
            $pricePerSquare = round($batchTotal / max(1, $qty), 2);
            $paidAmount = $isPaid ? $pricePerSquare : 0.00;

            $batchId = $rawTicketId ?: ('MANUAL-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6)));

            $db->beginTransaction();
            $assignedList = [];
            $hasCollision = false;
            $collisionCell = '';

            $insertStmt = $db->prepare("
                INSERT INTO cow_bingo_cells 
                (event_id, ticket_id, order_id, buyer_name, buyer_email, cell_reference, is_paid, order_amount, price_paid, payment_method, assigned_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'cash', ?)
            ");

            $idx = 1;
            $multi = count($selectedCells) > 1;

            foreach ($selectedCells as $cell) {
                $canonicalRef = normalizeCellRef($cell, $gridCols);

                if (preg_match('/^([A-Z]+)(\d+)$/i', $canonicalRef, $m)) {
                    $colLetters = strtoupper($m[1]);
                    $rowNum = (int)$m[2];
                    $colNum = 0;
                    for ($cIdx = 0; $cIdx < strlen($colLetters); $cIdx++) {
                        $colNum = $colNum * 26 + (ord($colLetters[$cIdx]) - 64);
                    }
                    if ($colNum < 1 || $colNum > $gridCols || $rowNum < 1 || $rowNum > $gridRows) {
                        $db->rollBack();
                        return "❌ Cell <strong>" . htmlspecialchars($cell) . "</strong> is outside the grid bounds ($gridCols x $gridRows).";
                    }
                } else {
                    $db->rollBack();
                    return "❌ Invalid cell coordinate format: <strong>" . htmlspecialchars($cell) . "</strong>.";
                }

                $ticketId = $multi ? ($batchId . '-' . $idx++) : ($rawTicketId ?: $batchId);

                try {
                    $insertStmt->execute([
                        $eventDbId,
                        $ticketId,
                        $batchId,
                        $buyerName,
                        $buyerEmail ?: null,
                        strtoupper($canonicalRef),
                        $isPaid,
                        $batchTotal,
                        $paidAmount,
                        $adminName
                    ]);
                    $assignedList[] = strtoupper($canonicalRef);
                } catch (PDOException $e) {
                    $hasCollision = true;
                    $collisionCell = formatCellRef($canonicalRef, $gridCols, $cellNaming);
                    break;
                }
            }

            if ($hasCollision) {
                $db->rollBack();
                return "❌ Cell <strong>$collisionCell</strong> was already taken! No cells were assigned.";
            }

            $db->commit();
            $_SESSION['selected_event_id'] = $eventDbId;
            $cellStr = implode(', ', array_map(fn($c) => formatCellRef($c, $gridCols, $cellNaming), $assignedList));
            logAuditEvent($db, $eventDbId, 'cell_assigned', $cellStr, $batchId, $batchId, $buyerName, $batchTotal, "Assigned $qty squares: $cellStr", $adminName);

            $count = count($assignedList);
            $paidStatus = $isPaid ? ' (Paid: $' . number_format($batchTotal, 2) . ')' : ' <span style="color:#856404;font-weight:bold;">(Unpaid - Expected: $' . number_format($batchTotal, 2) . ')</span>';
            return "✅ Successfully assigned $count cell" . ($count > 1 ? 's' : '') . " (<strong>$cellStr</strong>) to " . htmlspecialchars($buyerName) . "$paidStatus [Ref: $batchId].";

        case 'mark_paid':
            $cellId = (int) ($_POST['cell_id'] ?? 0);
            if (!$cellId) return '❌ Cell not found.';

            $stmt = $db->prepare("SELECT c.*, e.grid_cols, e.cell_naming, e.hievents_event_id, e.pricing_tiers FROM cow_bingo_cells c JOIN cow_bingo_events e ON e.id = c.event_id WHERE c.id = ?");
            $stmt->execute([$cellId]);
            $cell = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$cell) return '❌ Cell not found.';

            $cellEventId = (int)$cell['event_id'];
            if (!$isSuperAdmin && !canManageEvent($db, $currentAdminId, $cellEventId)) {
                return '❌ Access denied: You do not have permission to modify this event.';
            }

            $orderId = $cell['order_id'];
            $gridCols = (int)($cell['grid_cols'] ?? 16);
            $cellNaming = $cell['cell_naming'] ?? 'alpha';
            $pricingTiers = !empty($cell['pricing_tiers']) ? (json_decode($cell['pricing_tiers'], true) ?: []) : [];

            if (!empty($orderId)) {
                $batchStmt = $db->prepare("SELECT id, order_amount FROM cow_bingo_cells WHERE event_id = ? AND order_id = ?");
                $batchStmt->execute([$cellEventId, $orderId]);
                $batchRows = $batchStmt->fetchAll(PDO::FETCH_ASSOC);
                $batchCount = count($batchRows);

                $batchTotal = (float)($batchRows[0]['order_amount'] ?? 0);
                if ($batchTotal <= 0) {
                    $batchTotal = calculateBatchPrice($batchCount, $pricingTiers);
                }
                $perSquare = round($batchTotal / max(1, $batchCount), 2);

                $db->prepare("
                    UPDATE cow_bingo_cells 
                    SET is_paid = 1, 
                        order_amount = ?, 
                        price_paid = ? 
                    WHERE event_id = ? AND order_id = ?
                ")->execute([$batchTotal, $perSquare, $cellEventId, $orderId]);
                logAuditEvent($db, $cellEventId, 'marked_paid', $cell['cell_reference'], $cell['ticket_id'], $orderId, $cell['buyer_name'], $batchTotal, "Marked batch of $batchCount paid", $adminName);
            } else {
                $singleTotal = (float)($cell['order_amount'] ?? 0);
                if ($singleTotal <= 0) {
                    $singleTotal = calculateBatchPrice(1, $pricingTiers);
                }
                $db->prepare("
                    UPDATE cow_bingo_cells 
                    SET is_paid = 1, 
                        order_amount = ?, 
                        price_paid = ? 
                    WHERE id = ?
                ")->execute([$singleTotal, $singleTotal, $cellId]);
                logAuditEvent($db, $cellEventId, 'marked_paid', $cell['cell_reference'], $cell['ticket_id'], null, $cell['buyer_name'], $singleTotal, 'Single square marked paid', $adminName);
            }

            $syncMsg = '';
            if (!empty($cell['hievents_event_id']) && !empty($orderId) && strpos($orderId, 'MANUAL-') !== 0 && strpos($orderId, 'CASH-') !== 0 && strpos($orderId, 'BANK-') !== 0) {
                $hiSuccess = markHiEventsOrderAsPaid((string)$cell['hievents_event_id'], $orderId);
                $syncMsg = $hiSuccess 
                    ? " (Synchronized with Hi.Events order #$orderId)" 
                    : " (Recorded locally; Hi.Events unreachable)";
            }

            $_SESSION['selected_event_id'] = $cellEventId;
            $displayRef = formatCellRef($cell['cell_reference'], $gridCols, $cellNaming);
            return "✅ Square <strong>{$displayRef}</strong> (" . htmlspecialchars($cell['buyer_name']) . ") marked as Paid.$syncMsg";

        case 'delete_cell':
            $cellId = (int) ($_POST['cell_id'] ?? 0);
            if (!$cellId) return '❌ Cell not found.';

            $checkStmt = $db->prepare("SELECT * FROM cow_bingo_cells WHERE id = ?");
            $checkStmt->execute([$cellId]);
            $cell = $checkStmt->fetch(PDO::FETCH_ASSOC);

            if (!$cell) return '❌ Cell not found.';
            $cellEventId = (int)$cell['event_id'];

            if (!$isSuperAdmin && !canManageEvent($db, $currentAdminId, $cellEventId)) {
                return '❌ Access denied: You do not have permission to modify this event.';
            }

            $amount = (float)($cell['price_paid'] ?? $cell['order_amount'] ?? 0);
            $paidStatus = !empty($cell['is_paid']) ? 'Paid square removed' : 'Unpaid square removed';
            logAuditEvent($db, $cellEventId, 'cell_removed', $cell['cell_reference'], $cell['ticket_id'], $cell['order_id'], $cell['buyer_name'], $amount, $paidStatus, $adminName);

            $db->prepare("DELETE FROM cow_bingo_cells WHERE id = ?")->execute([$cellId]);
            $_SESSION['selected_event_id'] = $cellEventId;
            return "✅ Square assignment removed from grid.";

        case 'cancel_and_notify':
            $cellId = (int) ($_POST['cell_id'] ?? 0);
            if (!$cellId) return '❌ Cell not found.';

            $stmt = $db->prepare("
                SELECT c.*, e.hievents_event_id 
                FROM cow_bingo_cells c 
                JOIN cow_bingo_events e ON e.id = c.event_id 
                WHERE c.id = ?
            ");
            $stmt->execute([$cellId]);
            $cell = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$cell) return '❌ Cell not found.';

            $cellEventId = (int)$cell['event_id'];
            if (!$isSuperAdmin && !canManageEvent($db, $currentAdminId, $cellEventId)) {
                return '❌ Access denied: You do not have permission to modify this event.';
            }

            $buyer = htmlspecialchars($cell['buyer_name']);
            $ticket = htmlspecialchars($cell['ticket_id']);
            $orderId = $cell['order_id'] ?? '';
            $hiId = $cell['hievents_event_id'] ?? null;

            if (!empty($hiId) && !empty($orderId) && strpos($orderId, 'MANUAL-') !== 0 && strpos($orderId, 'CASH-') !== 0 && strpos($orderId, 'BANK-') !== 0) {
                if (function_exists('cancelHiEventsOrder') && (!defined('HIEVENTS_ENABLED') || HIEVENTS_ENABLED)) {
                    $subject = "Ticket Cancellation Notice - Payment Not Received";
                    $body = "Hi {$cell['buyer_name']},\n\nYour Cow Pat Bingo square reservation (Ticket: {$cell['ticket_id']}) has been cancelled as payment was not received.\n\nThank you.";
                    if (function_exists('messageHiEventsOrder')) {
                        messageHiEventsOrder((string)$hiId, $orderId, $subject, $body);
                    }
                    cancelHiEventsOrder((string)$hiId, $orderId);
                }
            }

            logAuditEvent($db, $cellEventId, 'cell_cancelled', $cell['cell_reference'], $cell['ticket_id'], $cell['order_id'], $cell['buyer_name'], 0.00, 'Cancelled reservation; square released', $adminName);
            $db->prepare("DELETE FROM cow_bingo_cells WHERE id = ?")->execute([$cellId]);
            $_SESSION['selected_event_id'] = $cellEventId;
            return "✅ Cancelled square for <strong>{$buyer}</strong> (Ticket: {$ticket}). Square returned to paddock.";

        case 'assign_prize_by_ref':
            $eventDbId = (int) ($_POST['event_db_id'] ?? 0);
            $rawRef = trim($_POST['cell_reference'] ?? '');
            $prize = trim($_POST['prize'] ?? '');

            if (!$eventDbId || !$rawRef || !$prize) {
                return '❌ Cell reference and prize text are required.';
            }

            if (!$isSuperAdmin && !canManageEvent($db, $currentAdminId, $eventDbId)) {
                return '❌ Access denied: You do not have permission to manage this event.';
            }

            $evStmt = $db->prepare("SELECT grid_cols, cell_naming FROM cow_bingo_events WHERE id = ?");
            $evStmt->execute([$eventDbId]);
            $evRow = $evStmt->fetch(PDO::FETCH_ASSOC);
            $gridCols = (int) ($evRow['grid_cols'] ?? 16);
            $cellNaming = $evRow['cell_naming'] ?? 'alpha';

            $canonicalRef = normalizeCellRef($rawRef, $gridCols);

            $stmt = $db->prepare("UPDATE cow_bingo_cells SET prize = ? WHERE event_id = ? AND cell_reference = ?");
            $stmt->execute([$prize, $eventDbId, $canonicalRef]);

            if ($stmt->rowCount() > 0) {
                $_SESSION['selected_event_id'] = $eventDbId;
                $displayRef = formatCellRef($canonicalRef, $gridCols, $cellNaming);
                $displayNote = ($cellNaming === 'numeric') ? " (Square #$displayRef / $canonicalRef)" : " ($canonicalRef)";
                logAuditEvent($db, $eventDbId, 'prize_assigned', $canonicalRef, null, null, null, null, "Prize awarded: $prize", $adminName);
                return "✅ Prize '<strong>" . htmlspecialchars($prize) . "</strong>' assigned to cell <strong>" . htmlspecialchars($displayRef) . "</strong>$displayNote.";
            }
            return "❌ Square <strong>" . htmlspecialchars($rawRef) . "</strong> not found or not sold.";

        case 'assign_prize':
            $cellId = (int) ($_POST['cell_id'] ?? 0);
            $prize = trim($_POST['prize'] ?? '');

            if (!$cellId) return '❌ Cell not found.';

            $checkStmt = $db->prepare("SELECT * FROM cow_bingo_cells WHERE id = ?");
            $checkStmt->execute([$cellId]);
            $cell = $checkStmt->fetch(PDO::FETCH_ASSOC);

            if (!$cell) return '❌ Cell not found.';
            $cellEventId = (int)$cell['event_id'];

            if (!$isSuperAdmin && !canManageEvent($db, $currentAdminId, $cellEventId)) {
                return '❌ Access denied: You do not have permission to modify this event.';
            }

            $db->prepare("UPDATE cow_bingo_cells SET prize = ? WHERE id = ?")->execute([$prize ?: null, $cellId]);
            $_SESSION['selected_event_id'] = $cellEventId;
            $actionLabel = $prize ? "Prize awarded: $prize" : "Prize removed";
            logAuditEvent($db, $cellEventId, 'prize_assigned', $cell['cell_reference'], $cell['ticket_id'], null, $cell['buyer_name'], null, $actionLabel, $adminName);
            return $prize ? "✅ Prize assigned." : "✅ Prize removed.";

        case 'sync_attendees':
            $eventDbId = (int) ($_POST['event_db_id'] ?? 0);
            if (!$isSuperAdmin && !canManageEvent($db, $currentAdminId, $eventDbId)) {
                return '❌ Access denied.';
            }

            $evStmt = $db->prepare("SELECT * FROM cow_bingo_events WHERE id = ?");
            $evStmt->execute([$eventDbId]);
            $ev = $evStmt->fetch(PDO::FETCH_ASSOC);

            if (!$ev) return '❌ Event not found.';
            if (empty($ev['hievents_event_id'])) {
                return '⚠️ This is a standalone event not yet linked to Hi.Events.';
            }

            $synced = syncAttendeesFromHiEvents($db, $ev);
            $_SESSION['selected_event_id'] = $eventDbId;
            logAuditEvent($db, $eventDbId, 'attendees_synced', null, null, null, null, null, "Synced $synced cells from Hi.Events", $adminName);
            return "✅ Sync complete. $synced new cells assigned.";

        case 'purge_contacts':
            $eventDbId = (int) ($_POST['event_db_id'] ?? 0);
            if (!$eventDbId) return '❌ Event not found.';

            if (!$isSuperAdmin && !canManageEvent($db, $currentAdminId, $eventDbId)) {
                return '❌ Access denied: You do not have permission to modify this event.';
            }

            $stmt = $db->prepare("UPDATE cow_bingo_cells SET buyer_email = NULL WHERE event_id = ? AND buyer_email IS NOT NULL AND buyer_email != ''");
            $stmt->execute([$eventDbId]);
            $count = $stmt->rowCount();
            $_SESSION['selected_event_id'] = $eventDbId;

            logAuditEvent($db, $eventDbId, 'contacts_purged', null, null, null, null, null, "Purged customer contacts for $count squares (NZ Privacy Act)", $adminName);
            return "✅ Privacy cleanup complete: Removed contact details from $count square" . ($count === 1 ? '' : 's') . ".";

        case 'create_admin':
            $newUser = trim($_POST['new_username'] ?? '');
            $newPass = $_POST['new_password'] ?? '';
            $newName = trim($_POST['new_display_name'] ?? '');
            $rawRole = $_POST['new_role'] ?? 'seller';
            $newEventIds = array_map('intval', $_POST['new_event_ids'] ?? []);

            if (!$isSuperAdmin) {
                $newRole = 'seller';
                $newSuper = 0;

                if (empty($newEventIds)) {
                    return '❌ Please select at least one event for this iPad seller.';
                }

                foreach ($newEventIds as $eid) {
                    if (!canManageEvent($db, $currentAdminId, $eid)) {
                        return '❌ Access denied: You cannot assign an iPad to an event you do not manage.';
                    }
                }
            } else {
                $newRole = in_array($rawRole, ['super_admin', 'event_admin', 'seller'], true) ? $rawRole : 'event_admin';
                $newSuper = ($newRole === 'super_admin') ? 1 : 0;
            }

            if ($newUser && $newPass && $newName) {
                if (strlen($newPass) < 8) {
                    return '❌ Password must be at least 8 characters long.';
                }

                try {
                    $stmt = $db->prepare("INSERT INTO cow_bingo_admins (username, password_hash, display_name, is_super_admin, role) VALUES (?, ?, ?, ?, ?)");
                    $stmt->execute([$newUser, password_hash($newPass, PASSWORD_DEFAULT), $newName, $newSuper, $newRole]);
                    $newAdminId = (int) $db->lastInsertId();

                    if (!$newSuper && !empty($newEventIds)) {
                        $assignStmt = $db->prepare("INSERT IGNORE INTO cow_bingo_admin_events (admin_id, event_id) VALUES (?, ?)");
                        foreach ($newEventIds as $eid) {
                            $assignStmt->execute([$newAdminId, $eid]);
                        }
                    }
                    $roleTitle = ($newRole === 'seller') ? 'iPad / Cash Seller' : $newRole;
                    return "✅ User '<strong>" . htmlspecialchars($newName) . "</strong>' created as " . htmlspecialchars($roleTitle) . ".";
                } catch (PDOException $e) {
                    return "❌ Error: " . $e->getMessage();
                }
            }
            return '❌ All fields required.';

        case 'update_admin':
            $targetAdminId = (int) ($_POST['admin_id'] ?? 0);
            if (!$targetAdminId) {
                return '❌ User not found.';
            }

            $checkStmt = $db->prepare("SELECT * FROM cow_bingo_admins WHERE id = ?");
            $checkStmt->execute([$targetAdminId]);
            $targetAdmin = $checkStmt->fetch(PDO::FETCH_ASSOC);
            if (!$targetAdmin) {
                return '❌ User not found or already deleted.';
            }

            // Permission check: Super Admin can update any admin/seller;
            // Non-super admin can only update sellers belonging to their events.
            if (!$isSuperAdmin) {
                if (($targetAdmin['role'] ?? '') !== 'seller' || !empty($targetAdmin['is_super_admin'])) {
                    return '❌ Access denied: You can only modify iPad sellers.';
                }

                $sharedStmt = $db->prepare("
                    SELECT 1 
                    FROM cow_bingo_admin_events ae
                    INNER JOIN cow_bingo_admin_events my_ae ON my_ae.event_id = ae.event_id AND my_ae.admin_id = ?
                    WHERE ae.admin_id = ? LIMIT 1
                ");
                $sharedStmt->execute([$currentAdminId, $targetAdminId]);
                if (!$sharedStmt->fetch()) {
                    return '❌ Access denied: You cannot edit this user.';
                }
            }

            $displayName = trim($_POST['display_name'] ?? '');
            if (empty($displayName)) {
                return '❌ Display Name is required.';
            }

            $newPassword = $_POST['new_password'] ?? '';
            if (!empty($newPassword) && strlen($newPassword) < 8) {
                return '❌ Password must be at least 8 characters long.';
            }

            if ($isSuperAdmin) {
                $rawRole = $_POST['role'] ?? $targetAdmin['role'];
                $newRole = in_array($rawRole, ['super_admin', 'event_admin', 'seller'], true) ? $rawRole : 'event_admin';
                $newSuper = ($newRole === 'super_admin') ? 1 : 0;
            } else {
                $newRole = 'seller';
                $newSuper = 0;
            }

            $newEventIds = array_map('intval', $_POST['event_ids'] ?? []);

            if (!$isSuperAdmin) {
                if (empty($newEventIds)) {
                    return '❌ Please select at least one event for this iPad seller.';
                }
                foreach ($newEventIds as $eid) {
                    if (!canManageEvent($db, $currentAdminId, $eid)) {
                        return '❌ Access denied: You cannot assign an event you do not manage.';
                    }
                }
            }

            $db->beginTransaction();
            try {
                if (!empty($newPassword)) {
                    $upStmt = $db->prepare("UPDATE cow_bingo_admins SET display_name = ?, password_hash = ?, role = ?, is_super_admin = ? WHERE id = ?");
                    $upStmt->execute([$displayName, password_hash($newPassword, PASSWORD_DEFAULT), $newRole, $newSuper, $targetAdminId]);
                } else {
                    $upStmt = $db->prepare("UPDATE cow_bingo_admins SET display_name = ?, role = ?, is_super_admin = ? WHERE id = ?");
                    $upStmt->execute([$displayName, $newRole, $newSuper, $targetAdminId]);
                }

                if (!$newSuper) {
                    if ($isSuperAdmin) {
                        $db->prepare("DELETE FROM cow_bingo_admin_events WHERE admin_id = ?")->execute([$targetAdminId]);
                        if (!empty($newEventIds)) {
                            $insAe = $db->prepare("INSERT IGNORE INTO cow_bingo_admin_events (admin_id, event_id) VALUES (?, ?)");
                            foreach ($newEventIds as $eid) {
                                if ($eid > 0) {
                                    $insAe->execute([$targetAdminId, $eid]);
                                }                            }
                        }
                    } else {
                        $myEventsStmt = $db->prepare("SELECT event_id FROM cow_bingo_admin_events WHERE admin_id = ?");
                        $myEventsStmt->execute([$currentAdminId]);
                        $myEventIds = $myEventsStmt->fetchAll(PDO::FETCH_COLUMN);

                        if (!empty($myEventIds)) {
                            $ph = implode(',', array_fill(0, count($myEventIds), '?'));
                            $db->prepare("DELETE FROM cow_bingo_admin_events WHERE admin_id = ? AND event_id IN ($ph)")
                               ->execute(array_merge([$targetAdminId], $myEventIds));

                            $insAe = $db->prepare("INSERT IGNORE INTO cow_bingo_admin_events (admin_id, event_id) VALUES (?, ?)");
                            foreach ($newEventIds as $eid) {
                                if (in_array($eid, $myEventIds, true)) {
                                    $insAe->execute([$targetAdminId, $eid]);
                                }
                            }
                        }
                    }
                } else {
                    $db->prepare("DELETE FROM cow_bingo_admin_events WHERE admin_id = ?")->execute([$targetAdminId]);
                }

                $db->commit();

                if ($targetAdminId === $currentAdminId) {
                    $_SESSION['admin_name'] = $displayName;
                    if ($isSuperAdmin) {
                        $_SESSION['admin_role'] = $newRole;
                        $_SESSION['is_super_admin'] = (bool)$newSuper;
                    }
                }

                $pwNote = !empty($newPassword) ? ' and password updated' : '';
                return "✅ User '<strong>" . htmlspecialchars($displayName) . "</strong>' updated successfully{$pwNote}.";
            } catch (Exception $e) {
                $db->rollBack();
                return "❌ Error updating user: " . htmlspecialchars($e->getMessage());
            }

        case 'delete_admin':
            $delId = (int) ($_POST['admin_id'] ?? 0);
            if ($delId === $currentAdminId) {
                return '❌ Cannot delete yourself.';
            }

            if ($isSuperAdmin) {
                $db->prepare("DELETE FROM cow_bingo_admins WHERE id = ?")->execute([$delId]);
                return '✅ User removed.';
            } else {
                $evStmt = $db->prepare("
                    SELECT ae.event_id 
                    FROM cow_bingo_admin_events ae
                    INNER JOIN cow_bingo_admin_events my_ae ON my_ae.event_id = ae.event_id AND my_ae.admin_id = ?
                    INNER JOIN cow_bingo_admins a ON a.id = ae.admin_id
                    WHERE a.id = ? AND a.role = 'seller' AND a.is_super_admin = 0
                ");
                $evStmt->execute([$currentAdminId, $delId]);
                $sharedEventIds = $evStmt->fetchAll(PDO::FETCH_COLUMN);

                if (empty($sharedEventIds)) {
                    return '❌ Access denied: You cannot delete this user.';
                }

                $ph = implode(',', array_fill(0, count($sharedEventIds), '?'));
                $db->prepare("DELETE FROM cow_bingo_admin_events WHERE admin_id = ? AND event_id IN ($ph)")
                   ->execute(array_merge([$delId], $sharedEventIds));

                $remStmt = $db->prepare("SELECT COUNT(*) FROM cow_bingo_admin_events WHERE admin_id = ?");
                $remStmt->execute([$delId]);
                if ((int)$remStmt->fetchColumn() === 0) {
                    $db->prepare("DELETE FROM cow_bingo_admins WHERE id = ? AND role = 'seller' AND is_super_admin = 0")->execute([$delId]);
                }

                return '✅ iPad seller removed from your event(s).';
            }
    }
    return '';
}
