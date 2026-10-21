<?php
// includes/bingo_helpers.php
// Safe to include in BOTH public (grid.php) and private (admin.php, webhook.php, pos.php) files.

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
 * Replaces duplicate functions in webhook.php, pos.php, and admin.php!
 */
function assignRandomCell(PDO $db, int $eventId, int $cols, int $rows): ?string {
    $available = getAvailableCells($db, $eventId, $cols, $rows);
    return empty($available) ? null : $available[array_rand($available)];
}

/**
 * Loads all bookings for an event keyed by cell reference (['A1' => [...], 'B3' => [...]])
 * Usable by BOTH grid.php and admin.php!
 */
function getEventBookings(PDO $db, int $eventId): array {
    $stmt = $db->prepare("SELECT cell_reference, ticket_id, buyer_name, buyer_email, prize, is_paid, assigned_by, created_at FROM cow_bingo_cells WHERE event_id = ?");
    $stmt->execute([$eventId]);
    $bookings = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $bookings[$row['cell_reference']] = $row;
    }
    return $bookings;
}