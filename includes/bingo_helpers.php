<?php
// includes/bingo_helpers.php
// Safe to include in BOTH public (grid.php) and private (admin.php, webhook.php, pos.php) files.

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Convert Alpha cell reference (e.g. 'D2', 'G4') to Numeric (e.g. 16, 43)
 * Row-major order: (row - 1) * cols + col
 */
function cellAlphaToNum(string $alphaRef, int $cols): int {
    $alphaRef = strtoupper(trim($alphaRef));
    if (!preg_match('/^([A-Z])([0-9]+)$/', $alphaRef, $m)) {
        return is_numeric($alphaRef) ? (int)$alphaRef : 0;
    }
    $col = ord($m[1]) - 64;
    $row = (int)$m[2];
    return (($row - 1) * $cols) + $col;
}

/**
 * Convert Numeric cell index (e.g. 16, 43) to Alpha reference (e.g. 'D2', 'G4')
 */
function cellNumToAlpha(int $num, int $cols): string {
    if ($num <= 0 || $cols <= 0) return '';
    $row = (int) floor(($num - 1) / $cols) + 1;
    $col = (($num - 1) % $cols) + 1;
    $colLetter = chr(64 + $col);
    return $colLetter . $row;
}

/**
 * Format a cell reference according to the event's numbering mode ('alpha' vs 'numeric')
 */
function formatCellRef(string $cellRef, int $cols, string $mode = 'alpha'): string {
    if ($mode === 'numeric') {
        if (is_numeric($cellRef)) return (string)$cellRef;
        $num = cellAlphaToNum($cellRef, $cols);
        return $num > 0 ? (string)$num : $cellRef;
    }
    if (is_numeric($cellRef)) {
        $alpha = cellNumToAlpha((int)$cellRef, $cols);
        return $alpha ?: $cellRef;
    }
    return strtoupper($cellRef);
}

/**
 * Normalize any input (either 'D2' or '16') into canonical Alpha reference ('D2')
 */
function normalizeCellRef(string $input, int $cols): string {
    $input = strtoupper(trim($input));
    if (is_numeric($input)) {
        $alpha = cellNumToAlpha((int)$input, $cols);
        return $alpha ?: $input;
    }
    return $input;
}

/**
 * Helper: Log an audit trail event for treasurers and admins
 */
function logAuditEvent(PDO $db, int $eventId, string $action, ?string $cellRef = null, ?string $ticketId = null, ?string $orderId = null, ?string $buyerName = null, ?float $amount = null, ?string $details = null, string $performedBy = 'System'): void {
    try {
        $stmt = $db->prepare("
            INSERT INTO cow_bingo_audit_log 
            (event_id, action, cell_reference, ticket_id, order_id, buyer_name, amount, details, performed_by) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$eventId, $action, $cellRef, $ticketId, $orderId, $buyerName, $amount, $details, $performedBy]);
    } catch (Exception $e) {
        error_log('Failed to write audit log: ' . $e->getMessage());
    }
}

/**
 * Backfill historical cells that lack financial records
 */
function backfillMissingCellPrices(PDO $db, int $eventId, array $pricingTiers): void {
    try {
        $check = $db->prepare("SELECT COUNT(*) FROM cow_bingo_cells WHERE event_id = ? AND (order_amount IS NULL OR price_paid IS NULL)");
        $check->execute([$eventId]);
        if ((int)$check->fetchColumn() === 0) return;

        $stmt = $db->prepare("
            SELECT COALESCE(order_id, ticket_id) as batch_ref, COUNT(*) as qty, MAX(assigned_by) as assigned_by, MAX(is_paid) as is_paid
            FROM cow_bingo_cells
            WHERE event_id = ? AND (order_amount IS NULL OR price_paid IS NULL)
            GROUP BY batch_ref
        ");
        $stmt->execute([$eventId]);
        $batches = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $upd = $db->prepare("
            UPDATE cow_bingo_cells 
            SET order_amount = ?, price_paid = ?, payment_method = COALESCE(payment_method, ?)
            WHERE event_id = ? AND COALESCE(order_id, ticket_id) = ? AND (order_amount IS NULL OR price_paid IS NULL)
        ");

        foreach ($batches as $b) {
            $qty = (int)$b['qty'];
            $batchTotal = calculateBatchPrice($qty, $pricingTiers);
            $method = ($b['assigned_by'] === 'hi.events' || empty($b['assigned_by'])) ? 'online' : 'cash';
            $paidPerSquare = !empty($b['is_paid']) ? round($batchTotal / max(1, $qty), 2) : 0.00;
            $upd->execute([$batchTotal, $paidPerSquare, $method, $eventId, $b['batch_ref']]);
        }
    } catch (Exception $e) {
        error_log('Cow Pat Bingo backfill failed: ' . $e->getMessage());
    }
}

/**
 * CSRF Token Helpers
 */
function getCsrfToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verifyCsrfToken(?string $token): bool {
    if (empty($token) || empty($_SESSION['csrf_token'])) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Generates all cell coordinates for a grid (e.g. ['A1', 'A2', ... 'P16'])
 */
function generateGridCellReferences(int $cols, int $rows): array {
    $colLetters = array_slice(range('A', 'Z'), 0, $cols);
    $all = [];
    foreach ($colLetters as $col) {
        for ($row = 1; $row <= $rows; $row++) {
            $all[] = $col . $row;
        }
    }
    return $all;
}

/**
 * Gets all available (unsold) cells for an event.
 */
function getAvailableCells(PDO $db, int $eventId, int $cols, int $rows): array {
    $allCells = generateGridCellReferences($cols, $rows);
    $stmt = $db->prepare("SELECT cell_reference FROM cow_bingo_cells WHERE event_id = ?");
    $stmt->execute([$eventId]);
    $taken = $stmt->fetchAll(PDO::FETCH_COLUMN);
    return array_values(array_diff($allCells, $taken));
}

/**
 * Picks a random unassigned cell from the paddock.
 */
function assignRandomCell(PDO $db, int $eventId, int $cols, int $rows): ?string {
    $available = getAvailableCells($db, $eventId, $cols, $rows);
    return empty($available) ? null : $available[array_rand($available)];
}

/**
 * Loads all bookings for an event keyed by cell reference
 */
function getEventBookings(PDO $db, int $eventId): array {
    $stmt = $db->prepare("SELECT * FROM cow_bingo_cells WHERE event_id = ?");
    $stmt->execute([$eventId]);
    $bookings = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $bookings[$row['cell_reference']] = $row;
    }
    return $bookings;
}

/**
 * Calculates the bulk discounted price for a batch of cells based on pricing tiers
 */
function calculateBatchPrice(int $qty, array $pricingTiers): float {
    if ($qty <= 0) return 0.0;
    if (empty($pricingTiers)) return (float)$qty * 10.0;

    krsort($pricingTiers);
    $total = 0.0;
    $rem = $qty;
    foreach ($pricingTiers as $tierQty => $price) {
        if ($rem >= $tierQty) {
            $count = (int) floor($rem / $tierQty);
            $total += $count * $price;
            $rem %= $tierQty;
        }
    }
    return round($total, 2);
}

/**
 * Comprehensive Cash & Sales Reconciliation for Treasurers
 */
function calculateEventCashReconciliation(PDO $db, int $eventId, array $pricingTiers): array {
    $stmt = $db->prepare("
        SELECT 
            COALESCE(assigned_by, 'hi.events') as seller,
            COALESCE(payment_method, CASE WHEN assigned_by = 'hi.events' OR assigned_by IS NULL THEN 'online' ELSE 'cash' END) as payment_method,
            COALESCE(order_id, ticket_id) as batch_ref,
            buyer_name,
            buyer_email,
            COUNT(*) as cell_count,
            GROUP_CONCAT(cell_reference ORDER BY cell_reference ASC SEPARATOR ', ') as cells_list,
            MAX(is_paid) as is_paid,
            MAX(order_amount) as recorded_order_amount,
            SUM(COALESCE(price_paid, 0)) as recorded_price_paid
        FROM cow_bingo_cells 
        WHERE event_id = ?
        GROUP BY seller, payment_method, batch_ref, buyer_name, buyer_email
    ");
    $stmt->execute([$eventId]);
    $batches = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $baseSinglePrice = $pricingTiers[1] ?? 10.00;
    $summary = [];
    $buyerBatches = [];

    $totals = [
        'total_expected' => 0.0,
        'total_paid' => 0.0,
        'cash_expected' => 0.0,
        'cash_paid' => 0.0,
        'online_expected' => 0.0,
        'online_paid' => 0.0,
        'unpaid_total' => 0.0,
        'total_discount' => 0.0,
        'total_squares' => 0,
        'cash_squares' => 0,
        'online_squares' => 0
    ];

    foreach ($batches as $b) {
        $seller = $b['seller'];
        $method = $b['payment_method'];
        $isOnline = ($method === 'online' || $seller === 'hi.events');
        $qty = (int)$b['cell_count'];
        $isPaid = (int)$b['is_paid'] === 1;

        $expectedAmount = calculateBatchPrice($qty, $pricingTiers);
        $fullPriceNoDiscount = $qty * $baseSinglePrice;
        $discountApplied = max(0.0, $fullPriceNoDiscount - $expectedAmount);

        if ($isPaid) {
            if ($b['recorded_order_amount'] !== null && (float)$b['recorded_order_amount'] > 0) {
                $actualPaid = (float)$b['recorded_order_amount'];
            } elseif ((float)$b['recorded_price_paid'] > 0) {
                $actualPaid = (float)$b['recorded_price_paid'];
            } else {
                $actualPaid = $expectedAmount;
            }
        } else {
            $actualPaid = 0.00;
        }

        $buyerBatches[] = [
            'batch_ref' => $b['batch_ref'],
            'buyer_name' => $b['buyer_name'],
            'buyer_contact' => $b['buyer_email'],
            'seller' => $seller,
            'payment_method' => $method,
            'cell_count' => $qty,
            'cells' => $b['cells_list'],
            'expected_amount' => $expectedAmount,
            'discount_applied' => $discountApplied,
            'actual_paid' => $actualPaid,
            'is_paid' => $isPaid,
            'is_online' => $isOnline
        ];

        $sellerKey = $seller . '|' . ($isOnline ? 'online' : 'cash');
        if (!isset($summary[$sellerKey])) {
            $summary[$sellerKey] = [
                'seller' => $seller,
                'channel_label' => $isOnline ? ($seller . ' (Online)') : ($seller . ' (Field Cash)'),
                'is_online' => $isOnline,
                'payment_method' => $method,
                'total_squares' => 0,
                'batch_count' => 0,
                'expected_amount' => 0.0,
                'actual_paid' => 0.0,
                'unpaid_amount' => 0.0
            ];
        }

        $summary[$sellerKey]['total_squares'] += $qty;
        $summary[$sellerKey]['batch_count'] += 1;
        $summary[$sellerKey]['expected_amount'] += $expectedAmount;
        $summary[$sellerKey]['actual_paid'] += $actualPaid;
        if (!$isPaid) {
            $summary[$sellerKey]['unpaid_amount'] += $expectedAmount;
        }

        $totals['total_expected'] += $expectedAmount;
        $totals['total_paid'] += $actualPaid;
        $totals['total_discount'] += $discountApplied;
        $totals['total_squares'] += $qty;

        if ($isOnline) {
            $totals['online_squares'] += $qty;
            $totals['online_expected'] += $expectedAmount;
            $totals['online_paid'] += $actualPaid;
        } else {
            $totals['cash_squares'] += $qty;
            $totals['cash_expected'] += $expectedAmount;
            $totals['cash_paid'] += $actualPaid;
        }

        if (!$isPaid) {
            $totals['unpaid_total'] += $expectedAmount;
        }
    }

    return [
        'summary' => array_values($summary),
        'buyer_batches' => $buyerBatches,
        'totals' => $totals
    ];
}

/**
 * Exports Detailed Bookings to CSV (Formatted for chosen numbering mode)
 */
function exportEventBookingsCsv(PDO $db, int $eventId, string $eventName, array $pricingTiers = []): void {
    $evStmt = $db->prepare("SELECT grid_cols, cell_naming, pricing_tiers FROM cow_bingo_events WHERE id = ?");
    $evStmt->execute([$eventId]);
    $eventRow = $evStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $cols = (int)($eventRow['grid_cols'] ?? 16);
    $cellNaming = $eventRow['cell_naming'] ?? 'alpha';

    if (empty($pricingTiers)) {
        if (!empty($eventRow['pricing_tiers'])) {
            $pricingTiers = json_decode($eventRow['pricing_tiers'], true) ?: [];
        }
        if (empty($pricingTiers)) {
            $pricingTiers = defined('DEFAULT_PRICING_TIERS') ? DEFAULT_PRICING_TIERS : [1 => 10.00, 3 => 25.00, 5 => 40.00, 10 => 70.00];
        }
    }

    $stmt = $db->prepare("
        SELECT cell_reference, ticket_id, order_id, buyer_name, buyer_email, is_paid, 
               order_amount, price_paid, payment_method, assigned_by, prize, created_at 
        FROM cow_bingo_cells 
        WHERE event_id = ? 
        ORDER BY COALESCE(order_id, ticket_id) ASC, cell_reference ASC
    ");
    $stmt->execute([$eventId]);
    $cells = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $batchCounts = [];
    foreach ($cells as $c) {
        $bRef = $c['order_id'] ?: $c['ticket_id'];
        $batchCounts[$bRef] = ($batchCounts[$bRef] ?? 0) + 1;
    }

    $baseSinglePrice = $pricingTiers[1] ?? 10.00;

    $safeName = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $eventName);
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="cow-bingo-' . $safeName . '-treasurer-audit.csv"');
    header('Pragma: no-cache');
    header('Expires: 0');

    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");

    fputcsv($out, [
        $cellNaming === 'numeric' ? 'Square #' : 'Cell',
        'Ticket ID',
        'Order / Batch Ref',
        'Buyer Name',
        'Buyer Contact',
        'Payment Method',
        'Price per Ticket ($)',
        'Expected Batch Total ($)',
        'Multi-Ticket Discount ($)',
        'Actual Paid ($)',
        'Status',
        'Assigned By / Device',
        'Prize',
        'Assigned At'
    ]);

    $seenBatchesInCsv = [];

    foreach ($cells as $r) {
        $bRef = $r['order_id'] ?: $r['ticket_id'];
        $batchQty = $batchCounts[$bRef] ?? 1;
        $isPaid = (int)$r['is_paid'] === 1;
        $isFirstInBatch = !isset($seenBatchesInCsv[$bRef]);
        $seenBatchesInCsv[$bRef] = true;

        $expectedBatch = calculateBatchPrice($batchQty, $pricingTiers);
        $fullPriceNoDiscount = $batchQty * $baseSinglePrice;
        $discountAmount = max(0.0, $fullPriceNoDiscount - $expectedBatch);

        $actualBatchPaid = $isPaid 
            ? (($r['order_amount'] !== null && (float)$r['order_amount'] > 0) ? (float)$r['order_amount'] : $expectedBatch) 
            : 0.00;

        if ($r['price_paid'] !== null && (float)$r['price_paid'] > 0) {
            $ticketPrice = (float)$r['price_paid'];
        } else {
            $ticketPrice = round(($isPaid ? $actualBatchPaid : $expectedBatch) / max(1, $batchQty), 2);
        }

        $expectedCol = $isFirstInBatch ? number_format($expectedBatch, 2, '.', '') : '';
        $discountCol = ($isFirstInBatch && $discountAmount > 0) ? number_format($discountAmount, 2, '.', '') : ($isFirstInBatch ? '0.00' : '');
        $actualPaidCol = $isFirstInBatch ? number_format($actualBatchPaid, 2, '.', '') : '';

        $cellDisplay = formatCellRef($r['cell_reference'], $cols, $cellNaming);

        fputcsv($out, [
            $cellDisplay,
            $r['ticket_id'],
            $bRef,
            $r['buyer_name'],
            $r['buyer_email'] ?? '',
            ucfirst($r['payment_method'] ?? ($r['assigned_by'] === 'hi.events' ? 'Online' : 'Cash')),
            number_format($ticketPrice, 2, '.', ''),
            $expectedCol,
            $discountCol,
            $actualPaidCol,
            $isPaid ? 'Paid' : 'Unpaid',
            $r['assigned_by'] ?? 'hi.events',
            $r['prize'] ?? '',
            $r['created_at']
        ]);
    }
    fclose($out);
    exit;
}
