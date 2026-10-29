<?php
// views/grid_view.php

if (!function_exists('makeClickableLinks')) {
    function makeClickableLinks(string $html): string {
        return formatRichText($html);
    }
}

$event = $event ?? [];
$eventName = $event['event_name'] ?? 'Cow Pat Bingo';
$gridCols = max(2, (int)($event['grid_cols'] ?? 16));
$gridRows = max(2, (int)($event['grid_rows'] ?? 16));
$totalCells = $gridCols * $gridRows;
$cellNaming = $event['cell_naming'] ?? 'alpha';
$eventDesc = $event['event_description'] ?? '';
$eventImage = $event['event_image'] ?? '';
$eventPaymentInstructions = !empty($event['payment_instructions']) ? $event['payment_instructions'] : (!empty($event['event_description']) ? $event['event_description'] : "Please contact the event organisers for direct bank transfer details.");
$eventVenue = $event['event_venue'] ?? '';
$eventStart = $event['event_start'] ?? null;
$startDisplay = !empty($eventStart) ? date('D, d M Y, g:ia', strtotime($eventStart)) : '';
$isActive = !empty($event['is_active']);
$isEntriesClosed = !empty($event['entries_closed']);
$isAdmin = !empty($_SESSION['admin_id']);
$allowCellSelection = !empty($event['allow_cell_selection']);
$isRandomOnly = empty($allowCellSelection);
$hasHiEvents = !empty($event['hievents_event_id']);
$activeEventsCount = $activeEventsCount ?? 1;

$pricingTiers = !empty($event['pricing_tiers']) ? (json_decode($event['pricing_tiers'], true) ?: []) : [];
if (empty($pricingTiers)) {
    $pricingTiers = defined('DEFAULT_PRICING_TIERS') ? DEFAULT_PRICING_TIERS : [1 => 10.00, 3 => 25.00, 5 => 40.00, 10 => 70.00];
}
krsort($pricingTiers);

if (empty($cols) || !is_array($cols)) {
    $cols = [];
    for ($c = 1; $c <= $gridCols; $c++) {
        $letters = '';
        $temp = $c;
        while ($temp > 0) {
            $rem = ($temp - 1) % 26;
            $letters = chr(65 + $rem) . $letters;
            $temp = (int)(($temp - $rem - 1) / 26);
        }
        $cols[] = $letters;
    }
}

$bookings = $bookings ?? [];
$soldCount = count($bookings);
$availableCount = max(0, $totalCells - $soldCount);

$paidBookings = array_filter($bookings, fn($b) => !empty($b['is_paid']));
$paidCount = count($paidBookings);
$unpaidCount = $soldCount - $paidCount;

$winners = array_filter($bookings, fn($b) => !empty($b['prize']));
$buyTicketsUrl = $buyTicketsUrl ?? '';
$eventPrizes = $eventPrizes ?? (function_exists('parseEventPrizes') ? parseEventPrizes($event['event_prizes'] ?? '') : []);

$gridCsrf = function_exists('getCsrfToken') ? getCsrfToken() : ($_SESSION['csrf_token'] ?? '');
$captchaNum1 = $captchaNum1 ?? ($_SESSION['grid_captcha_num1'] ?? 3);
$captchaNum2 = $captchaNum2 ?? ($_SESSION['grid_captcha_num2'] ?? 5);

$canBuyTickets = $isActive && !$isEntriesClosed && empty($winners) && $availableCount > 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes">
    <title><?= htmlspecialchars($eventName) ?> - Cow Pat Bingo</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #2d5016 0%, #4a7c23 50%, #2d5016 100%);
            min-height: 100vh;
            padding: 20px;
            color: #333;
        }
        .container { max-width: 1000px; margin: 0 auto; }
        header { text-align: center; margin-bottom: 20px; color: white; }
        header h1 {
            font-size: 2.2em;
            text-shadow: 2px 2px 4px rgba(0,0,0,0.3);
            margin-bottom: 5px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }
        header p { font-size: 1.1em; opacity: 0.9; }

        .header-poo-img {
            height: 1.25em;
            width: auto;
            vertical-align: middle;
            display: inline-block;
        }

        .event-details-card {
            background: rgba(255,255,255,0.96);
            border-radius: 10px;
            padding: 16px 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.15);
            line-height: 1.6;
            font-size: 0.95em;
            color: #2b3a1a;
        }
        .event-details-card a { color: #15803d; font-weight: bold; text-decoration: underline; }

        .top-action-bar {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 10px;
            margin-bottom: 20px;
        }
        .btn-top-buy {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            background: #2d5016;
            color: #ffffff;
            font-size: 1.15em;
            font-weight: 800;
            padding: 13px 30px;
            border-radius: 30px;
            border: 2px solid #86efac;
            text-decoration: none;
            box-shadow: 0 6px 20px rgba(0,0,0,0.25);
            cursor: pointer;
            transition: all 0.2s ease;
        }
        .btn-top-buy:hover {
            background: #3d6b20;
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.3);
        }
        .btn-bank-remind {
            background: rgba(255,255,255,0.92);
            color: #1e3a0f;
            border: 1px solid rgba(255,255,255,0.4);
            border-radius: 20px;
            padding: 6px 16px;
            font-size: 0.88em;
            font-weight: 700;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            box-shadow: 0 2px 6px rgba(0,0,0,0.15);
            transition: all 0.15s ease;
        }
        .btn-bank-remind:hover {
            background: #ffffff;
            color: #14532d;
        }
        .badge-entries-closed {
            display: inline-block;
            background: #fef2f2;
            color: #991b1b;
            border: 1.5px solid #fecaca;
            padding: 8px 18px;
            border-radius: 20px;
            font-weight: 700;
            font-size: 0.95em;
        }

        .stats { display: flex; justify-content: center; gap: 20px; margin-bottom: 20px; flex-wrap: wrap; }
        .stat-box {
            background: rgba(255,255,255,0.95); border-radius: 8px;
            padding: 12px 20px; text-align: center;
            box-shadow: 0 2px 8px rgba(0,0,0,0.15);
            min-width: 130px;
        }
        .stat-box .number { font-size: 1.8em; font-weight: bold; color: #2d5016; }
        .stat-box .label { font-size: 0.85em; color: #666; text-transform: uppercase; }
        .stat-box.awaiting-payment .number { color: #d97706; }
        
        .buy-link {
            background: #2d5016 !important; text-decoration: none;
            transition: background 0.2s, transform 0.2s; cursor: pointer; color: white;
            display: inline-flex; flex-direction: column; justify-content: center; align-items: center;
        }
        .buy-link .number { color: white; }
        .buy-link .label { color: rgba(255,255,255,0.95); font-weight: bold; }
        .buy-link:hover { background: #3d6b20 !important; transform: scale(1.05); }

        .btn-print-judges {
            background: #ffffff; border: 1.5px solid #2d5016; border-radius: 8px;
            padding: 10px 16px; font-weight: bold; color: #2d5016; cursor: pointer;
            display: inline-flex; align-items: center; gap: 6px; font-size: 0.9em;
        }
        .btn-print-judges:hover { background: #f0fdf4; }

        .prizes-banner {
            background: rgba(255, 255, 255, 0.97);
            border-radius: 12px;
            padding: 16px 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.15);
            border: 2px solid #fde047;
        }
        .prizes-banner h3 {
            margin: 0 0 10px;
            color: #713f12;
            font-size: 1.15em;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .prizes-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 10px;
        }
        .prize-item-box {
            background: #fffdf5;
            border: 1.5px solid #fef08a;
            border-radius: 8px;
            padding: 8px 12px;
            display: flex;
            flex-direction: column;
            gap: 3px;
        }
        .prize-rank-title {
            font-weight: 800;
            color: #854d0e;
            font-size: 0.95em;
        }
        .prize-desc-text {
            font-size: 0.88em;
            color: #1e293b;
            font-weight: 600;
        }
        .prize-status-badge {
            font-size: 0.78em;
            font-weight: bold;
            margin-top: 2px;
        }
        .prize-status-won { color: #15803d; }
        .prize-status-pending { color: #64748b; }

        .alert-banner {
            border-radius: 10px;
            padding: 16px 20px;
            margin-bottom: 20px;
            box-shadow: 0 3px 12px rgba(0,0,0,0.18);
            line-height: 1.5;
            animation: fadeIn 0.3s ease;
        }
        .alert-success {
            background: #f0fdf4;
            border: 2px solid #86efac;
            color: #166534;
        }
        .alert-error {
            background: #fef2f2;
            border: 2px solid #fecaca;
            color: #991b1b;
        }

        .grid-wrapper {
            background: rgba(255,255,255,0.95); border-radius: 12px;
            padding: 16px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.2);
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }
        .paddock-grid {
            display: grid;
            grid-template-columns: 32px repeat(<?= $gridCols ?>, minmax(36px, 1fr));
            gap: 3px;
            width: max-content;
            min-width: 100%;
        }
        .col-corner-cell { width: 32px; height: 26px; }
        .col-header-cell {
            display: flex; align-items: center; justify-content: center;
            font-weight: bold; font-size: 11px; color: #475569;
            height: 26px;
            user-select: none;
        }
        .row-header-cell {
            display: flex; align-items: center; justify-content: center;
            font-weight: bold; font-size: 11px; color: #475569;
            user-select: none;
        }
        .cell {
            aspect-ratio: 1;
            min-width: 36px;
            background-color: #7bc67b; border: 1px solid #5aa35a; border-radius: 4px;
            display: flex; align-items: center; justify-content: center;
            font-size: 12px; font-weight: bold; color: #2c5e2c;
            cursor: default; transition: all 0.15s ease; position: relative; user-select: none;
        }
        .cell.clickable {
            cursor: pointer;
        }
        .cell.taken {
            background-color: #e8a87c; border-color: #d4845a; color: #6b3a1a; cursor: pointer;
        }
        .cell.winner {
            background: radial-gradient(circle, #fffbeb 10%, #fde047 55%, #eab308 100%) !important;
            border: 2px solid #854d0e !important;
            position: relative;
            display: flex;
            align-items: center;
            justify-content: center;
            animation: winner-glow 2s ease-in-out infinite;
        }
        .cell.winner .winner-ref {
            position: absolute;
            top: 2px;
            left: 2px;
            font-size: 9px;
            font-weight: 900;
            color: #713f12;
            background: rgba(255, 255, 255, 0.85);
            padding: 0 3px;
            border-radius: 2px;
            line-height: 1.1;
            pointer-events: none;
            box-shadow: 0 1px 2px rgba(0,0,0,0.2);
        }
        .cell.winner .poo-icon {
            width: 78%;
            height: 78%;
            max-width: 32px;
            max-height: 32px;
            object-fit: contain;
            pointer-events: none;
            filter: drop-shadow(0 2px 3px rgba(0,0,0,0.4));
        }
        @keyframes winner-glow {
            0%, 100% { box-shadow: 0 0 6px rgba(202, 138, 4, 0.7); }
            50% { box-shadow: 0 0 16px rgba(234, 179, 8, 1); }
        }
        .cell:hover {
            transform: scale(1.1);
            z-index: 10;
            box-shadow: 0 2px 8px rgba(0,0,0,0.3);
        }
        .cell.taken:hover {
            background-color: #f0b88a;
        }
        .cell.winner:hover {
            background: radial-gradient(circle, #ffffff 10%, #fef08a 55%, #facc15 100%) !important;
        }

        .cell.selected {
            background: #ffc107 !important;
            border: 2px solid #d39e00 !important;
            color: #000 !important;
            transform: scale(1.08);
            z-index: 2;
            box-shadow: 0 0 8px rgba(255, 193, 7, 0.8);
        }

        .cell.taken.admin-unpaid {
            background-image: repeating-linear-gradient(
                45deg,
                rgba(220, 53, 69, 0.3),
                rgba(220, 53, 69, 0.3) 6px,
                transparent 6px,
                transparent 12px
            ) !important;
            border: 2px dashed #dc3545 !important;
        }

        .tooltip {
            display: none;
            position: fixed;
            background: #333;
            color: white;
            padding: 10px 14px;
            border-radius: 6px;
            font-size: 13px;
            line-height: 1.5;
            z-index: 9999;
            pointer-events: none;
            white-space: nowrap;
            box-shadow: 0 4px 12px rgba(0,0,0,0.3);
        }
        .tooltip::after {
            content: '';
            position: absolute;
            top: 100%;
            left: 50%;
            transform: translateX(-50%);
            border: 6px solid transparent;
            border-top-color: #333;
        }
        .legend { display: flex; justify-content: center; gap: 20px; margin-top: 15px; font-size: 0.9em; flex-wrap: wrap; }
        .legend-item { display: flex; align-items: center; gap: 6px; }
        .legend-swatch { width: 18px; height: 18px; border-radius: 3px; border: 1px solid #999; }
        .legend-swatch.available { background-color: #7bc67b; border-color: #5aa35a; }
        .legend-swatch.sold { background-color: #e8a87c; border-color: #d4845a; }
        .legend-swatch.winner { background: radial-gradient(circle, #fffbeb 10%, #fde047 55%, #eab308 100%); border-color: #854d0e; }
        .legend-swatch.highlight { background-color: #e8a87c; border: 3px solid #ffd700; }
        .legend-swatch.admin-unpaid {
            background-color: #e8a87c;
            background-image: repeating-linear-gradient(45deg, rgba(220, 53, 69, 0.4), rgba(220, 53, 69, 0.4) 3px, transparent 3px, transparent 6px);
            border: 2px dashed #dc3545;
        }

        .search-bar {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 20px;
            background: rgba(255,255,255,0.95);
            padding: 12px 20px;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.15);
            flex-wrap: wrap;
        }
        .search-bar input {
            flex: 1;
            min-width: 200px;
            padding: 10px 14px;
            border: 2px solid #ddd;
            border-radius: 6px;
            font-size: 0.95em;
            outline: none;
        }
        .search-bar button {
            padding: 10px 20px;
            border: none;
            border-radius: 6px;
            font-weight: bold;
            cursor: pointer;
            font-size: 0.9em;
        }
        #searchBtn { background: #2d5016; color: white; }
        #clearBtn { background: #dc3545; color: white; }
        #searchResult { font-size: 0.9em; color: #555; margin-left: 5px; }

        .cell.highlight {
            border: 3px solid #ffd700 !important;
            box-shadow: 0 0 12px rgba(255, 215, 0, 0.8) !important;
            transform: scale(1.15);
            z-index: 20;
        }
        .cell.dimmed { opacity: 0.3; }

        .winners-summary {
            background: rgba(255,255,255,0.95);
            border-radius: 12px;
            padding: 20px;
            margin-top: 20px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.2);
        }
        .winners-summary h2 { margin: 0 0 15px; color: #5c4a00; font-size: 1.3em; }
        .winners-list { display: flex; flex-wrap: wrap; gap: 12px; }
        .winner-card {
            display: flex;
            align-items: center;
            gap: 12px;
            background: linear-gradient(135deg, #fff8e1, #fffde7);
            border: 2px solid #ffd700;
            border-radius: 8px;
            padding: 12px 16px;
            min-width: 200px;
            box-shadow: 0 2px 6px rgba(255,215,0,0.3);
        }
        .winner-cell {
            background: #ffd700;
            color: #5c4a00;
            font-weight: bold;
            font-size: 1.1em;
            padding: 6px 10px;
            border-radius: 6px;
            min-width: 40px;
            text-align: center;
        }
        .winner-prize { font-weight: bold; color: #333; font-size: 1em; }
        .winner-name { color: #666; font-size: 0.9em; }

        .modal-backdrop {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.65);
            z-index: 99999;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 16px;
        }
        .modal-card {
            background: #ffffff;
            border-radius: 14px;
            width: 100%;
            max-width: 520px;
            max-height: 92vh;
            overflow-y: auto;
            padding: 24px 26px;
            box-shadow: 0 12px 36px rgba(0,0,0,0.4);
            color: #2d3748;
            position: relative;
            line-height: 1.5;
        }

        .print-only { display: none; }
        @media print {
            body { background: white !important; color: black !important; padding: 0 !important; }
            .container { max-width: 100% !important; margin: 0 !important; width: 100% !important; }
            header, .stats, .search-bar, .tooltip, .buy-link, .no-print, .event-details-card, .prizes-banner, .modal-backdrop, #publicPaddockTray, .top-action-bar { display: none !important; }
            .grid-wrapper { box-shadow: none !important; padding: 0 !important; border: none !important; }
            .cell {
                border: 1px solid #222 !important;
                background: transparent !important;
                color: #000 !important;
                box-shadow: none !important;
            }
            .cell.taken { background: #e5e5e5 !important; font-size: 9px !important; }
            .cell.winner { font-weight: 900 !important; text-decoration: underline !important; }
            .print-only { display: block !important; }
            .print-judges-box {
                margin-top: 25px;
                border: 2px solid #000;
                padding: 15px;
                page-break-inside: avoid;
            }
        }
    </style>
</head>
<body>
<div class="container">
    <div class="no-print" style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px;">
        <?php if ($activeEventsCount > 1): ?>
            <a href="grid.php?list=1" style="color:white; text-decoration:none; font-weight:bold; font-size:0.9em;">&larr; Back to Events</a>
        <?php else: ?>
            <div></div>
        <?php endif; ?>
        <?php if ($isAdmin): ?>
            <button type="button" class="btn-print-judges" onclick="window.print()">📋 Print Judges Clipboard Sheet</button>
        <?php endif; ?>
    </div>

    <header>
        <h1>
            <span>🐮</span>
            <img src="cow_poo_2.svg" alt="cow poo bingo" class="header-poo-img">
            <span>Bingo</span>
        </h1>
        <p style="font-size:1.3em; font-weight:bold; margin-top:4px;"><?= htmlspecialchars($eventName) ?></p>
        <?php if ($startDisplay || $eventVenue): ?>
            <p style="margin-top:5px; font-size:0.95em; opacity:0.9;">
                <?php if ($startDisplay): ?>📅 <?= htmlspecialchars($startDisplay) ?><?php endif; ?>
                <?php if ($startDisplay && $eventVenue): ?> &mdash; <?php endif; ?>
                <?php if ($eventVenue): ?>📍 <?= htmlspecialchars($eventVenue) ?><?php endif; ?>
            </p>
        <?php endif; ?>
        <?php if (!$isActive): ?>
            <div class="no-print" style="display:inline-block; margin-top:8px; background:#fef3c7; color:#92400e; padding:4px 12px; border-radius:12px; font-weight:bold; font-size:0.85em;">
                🏁 Event Concluded / Inactive
            </div>
        <?php endif; ?>
    </header>

    <div class="top-action-bar no-print">
        <?php if ($canBuyTickets): ?>
            <?php if ($hasHiEvents && $buyTicketsUrl): ?>
                <a href="<?= htmlspecialchars($buyTicketsUrl) ?>" target="_blank" class="btn-top-buy">
                    <span>🎟️</span> Buy Squares Online
                </a>
            <?php else: ?>
                <button type="button" class="btn-top-buy" onclick="openPublicBookingModal()">
                    <span>🎟️</span> Buy / Reserve Squares
                </button>
            <?php endif; ?>
        <?php elseif ($isEntriesClosed && empty($winners)): ?>
            <span class="badge-entries-closed">
                🔒 Entries are now closed for this event
            </span>
        <?php endif; ?>

        <?php if (!$hasHiEvents): ?>
            <button type="button" class="btn-bank-remind" onclick="openBankDetailsModal()">
                🏦 I've already reserved squares. Remind me of bank details
            </button>
        <?php endif; ?>
    </div>

    <?php if (!empty($publicBookingSuccess)): ?>
    <div class="alert-banner alert-success no-print" style="margin-bottom:20px; background:#f0fdf4; border:2px solid #22c55e;">
        <h3 style="margin-top:0; color:#15803d; font-size:1.25em;">🎉 Square Reservation Received!</h3>
        <p style="margin:6px 0;">Thank you, <strong><?= htmlspecialchars($publicBookingSuccess['buyer_name']) ?></strong>! Your reservation of <strong><?= $publicBookingSuccess['cell_count'] ?> square(s)</strong> has been logged.</p>
        <div style="background:#ffffff; border:1.5px solid #bbf7d0; border-radius:8px; padding:12px 16px; margin:12px 0;">
            <div><strong>Order Reference:</strong> <code style="font-size:1.15em; color:#15803d; font-weight:bold;"><?= htmlspecialchars($publicBookingSuccess['order_id']) ?></code></div>
            <div><strong>Total Due:</strong> <strong style="font-size:1.15em; color:#2d5016;">$<?= number_format($publicBookingSuccess['total_amount'], 2) ?></strong></div>
            <div style="margin-top:8px; border-top:1px dashed #d1fae5; padding-top:8px;">
                <strong>💳 Payment Instructions:</strong><br>
                <div style="margin-top:4px; font-family:inherit; line-height:1.4; color:#374151;">
                    <?= formatRichText($publicBookingSuccess['payment_instructions']) ?>
                </div>
            </div>
        </div>
        <div style="background:#fffbeb; border:1.5px solid #fde047; border-radius:8px; padding:10px 14px; margin-top:10px; color:#92400e; font-weight:600; font-size:0.9em;">
            ⚠️ <strong>Please Note:</strong> Squares are <u>not reserved until payment is received</u>. Once payment is confirmed, your square(s) will be updated on the website.
        </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($publicBookingError)): ?>
    <div class="alert-banner alert-error no-print">
        <strong>❌ Unable to complete reservation:</strong> <?= htmlspecialchars($publicBookingError) ?>
    </div>
    <?php endif; ?>

    <?php if (!empty($eventPrizes)): ?>
    <div class="prizes-banner no-print">
        <h3><span>🏆</span> Event Prizes to be Won</h3>
        <div class="prizes-grid">
            <?php foreach ($eventPrizes as $pz): 
                $rankText = trim($pz['rank'] ?? '');
                $prizeText = trim($pz['prize'] ?? '');
                
                $winningClaim = null;
                foreach ($winners as $ref => $w) {
                    $wPrize = strtolower(trim($w['prize']));
                    if (str_contains($wPrize, strtolower($rankText)) || ($prizeText !== '' && str_contains($wPrize, strtolower($prizeText)))) {
                        $winningClaim = [
                            'cell' => formatCellRef($ref, $gridCols, $cellNaming),
                            'buyer' => $w['buyer_name'],
                            'prize' => $w['prize']
                        ];
                        break;
                    }
                }
            ?>
            <div class="prize-item-box">
                <span class="prize-rank-title"><?= htmlspecialchars($rankText) ?></span>
                <span class="prize-desc-text"><?= htmlspecialchars($prizeText) ?></span>
                <?php if ($winningClaim): ?>
                    <span class="prize-status-badge prize-status-won">
                        ✅ Won by <?= htmlspecialchars($winningClaim['buyer']) ?> (Sq <?= htmlspecialchars($winningClaim['cell']) ?>)
                    </span>
                <?php else: ?>
                    <span class="prize-status-badge prize-status-pending">🎯 In play</span>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Event Info & Instructions -->
    <?php if (!empty($eventDesc) || (!empty($eventImage) && file_exists(__DIR__ . '/../' . ltrim($eventImage, '/\\')))): ?>
    <div class="event-details-card no-print">
        <?php if (!empty($eventDesc)): ?>
            <strong>📍 Event Info &amp; Instructions:</strong><br>
            <?= formatRichText($eventDesc) ?>
        <?php endif; ?>
        <?php if (!empty($eventImage) && file_exists(__DIR__ . '/../' . ltrim($eventImage, '/\\'))): ?>
            <div class="event-image-container" style="margin-top:<?= !empty($eventDesc) ? '14px' : '0' ?>; text-align:center;">
                <img src="<?= htmlspecialchars($eventImage) ?>" alt="<?= htmlspecialchars($eventName) ?> Poster" style="max-width:100%; height:auto; max-height:480px; object-fit:contain; border-radius:8px; box-shadow:0 3px 12px rgba(0,0,0,0.15); display:inline-block;">
            </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="stats no-print">
        <div class="stat-box">
            <div class="number"><?= $paidCount ?></div>
            <div class="label">Sold</div>
        </div>
        <div class="stat-box awaiting-payment">
            <div class="number"><?= $unpaidCount ?></div>
            <div class="label">Awaiting Payment</div>
        </div>
        <div class="stat-box">
            <div class="number"><?= $availableCount ?></div>
            <div class="label">Available</div>
        </div>
        <?php if ($canBuyTickets): ?>
            <?php if ($hasHiEvents && $buyTicketsUrl): ?>
                <a href="<?= htmlspecialchars($buyTicketsUrl) ?>" target="_blank" class="stat-box buy-link">
                    <div class="number">🎟️</div><div class="label">Buy Squares</div>
                </a>
            <?php else: ?>
                <button type="button" class="stat-box buy-link" onclick="openPublicBookingModal()" style="border:none;">
                    <div class="number">🎟️</div><div class="label">Buy / Reserve Squares</div>
                </button>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <div class="search-bar no-print">
        <input type="text" id="searchInput" placeholder="🔍 Find my cells (enter your name, ticket ID, or square <?= $cellNaming === 'numeric' ? '# 16' : 'D2' ?>)..." />
        <button id="searchBtn" onclick="findCells()">Find</button>
        <button id="clearBtn" onclick="clearSearch()" style="display:none;">Clear</button>
        <span id="searchResult"></span>
    </div>

    <div class="print-only" style="margin-bottom:12px;">
        <h2 style="margin:0;">🐮 <?= htmlspecialchars($eventName) ?> - Field Grid</h2>
        <small>Generated: <?= date('d M Y, g:ia') ?> &bull; Sold: <?= $paidCount ?> / <?= $totalCells ?> squares</small>
    </div>

    <div class="grid-wrapper">
        <div class="paddock-grid">
            <div class="col-corner-cell"></div>
            <?php foreach ($cols as $col): ?>
                <div class="col-header-cell"><?= $col ?></div>
            <?php endforeach; ?>

            <?php for ($row = 1; $row <= $gridRows; $row++): ?>
                <div class="row-header-cell"><?= $row ?></div>
                <?php foreach ($cols as $col): 
                    $cellRef = $col . $row;
                    $displayRef = formatCellRef($cellRef, $gridCols, $cellNaming);
                    $numericRef = formatCellRef($cellRef, $gridCols, 'numeric');
                    
                    $isTaken = isset($bookings[$cellRef]);
                    $booking = $isTaken ? $bookings[$cellRef] : null;
                    $isPaid = $booking ? !empty($booking['is_paid']) : false;
                    $isWinner = $isTaken && !empty($booking['prize']);

                    if ($isRandomOnly && !$isAdmin && $isTaken && !$isPaid) {
                        $isTaken = false;
                        $booking = null;
                    }

                    $dataAttrs = '';
                    $classes = 'cell';

                    if ($isTaken) {
                        $classes .= ' taken';
                        if ($isWinner) $classes .= ' winner';
                        if ($isAdmin && !$isPaid) $classes .= ' admin-unpaid';

                        $dataAttrs = sprintf(
                            'data-cell="%s" data-display="%s" data-numeric="%s" data-name="%s" data-ticket="%s" data-prize="%s" data-paid="%s"',
                            htmlspecialchars($cellRef, ENT_QUOTES, 'UTF-8'),
                            htmlspecialchars($displayRef, ENT_QUOTES, 'UTF-8'),
                            htmlspecialchars($numericRef, ENT_QUOTES, 'UTF-8'),
                            htmlspecialchars($booking['buyer_name'], ENT_QUOTES, 'UTF-8'),
                            htmlspecialchars($booking['ticket_id'], ENT_QUOTES, 'UTF-8'),
                            htmlspecialchars($booking['prize'] ?? '', ENT_QUOTES, 'UTF-8'),
                            $isPaid ? '1' : '0'
                        );
                    } else {
                        $isClickable = (!$hasHiEvents && $canBuyTickets && $allowCellSelection);
                        if ($isClickable) {
                            $classes .= ' clickable';
                        }
                        $dataAttrs = sprintf(
                            'data-cell="%s" data-display="%s" data-numeric="%s" onclick="%s"',
                            htmlspecialchars($cellRef, ENT_QUOTES, 'UTF-8'),
                            htmlspecialchars($displayRef, ENT_QUOTES, 'UTF-8'),
                            htmlspecialchars($numericRef, ENT_QUOTES, 'UTF-8'),
                            $isClickable ? 'publicSelectCell(this)' : ''
                        );
                    }
                ?>
                    <div class="<?= $classes ?>" <?= $dataAttrs ?>>
                        <?php if ($isWinner): ?>
                            <span class="winner-ref"><?= $displayRef ?></span>
                            <img src="cow_poo_2.svg" class="poo-icon" alt="Winner <?= $displayRef ?>">
                        <?php else: ?>
                            <?= $displayRef ?>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endfor; ?>
        </div>

        <div class="legend no-print">
            <div class="legend-item"><div class="legend-swatch available"></div><span>Available</span></div>
            <div class="legend-item"><div class="legend-swatch sold"></div><span>Sold</span></div>
            <div class="legend-item"><div class="legend-swatch winner"></div><span>Winner <img src="cow_poo_2.svg" style="width:32px;height:32px;vertical-align:middle;"></span></div>
            <div class="legend-item"><div class="legend-swatch highlight"></div><span>Your Squares</span></div>
            <?php if ($isAdmin): ?>
                <div class="legend-item"><div class="legend-swatch admin-unpaid"></div><span style="color:#dc2626;font-weight:bold;">Unpaid (Admin Only)</span></div>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($isAdmin): ?>
    <div class="print-only print-judges-box">
        <h3 style="margin-top:0; border-bottom:1px solid #000; padding-bottom:4px;">🐮 Official Paddock Judges Sign-Off</h3>
        <table style="width:100%; border:none; margin-top:10px; font-size:12px;">
            <tr>
                <td style="width:50%; padding:8px 0;"><strong>Lead Judge Signature:</strong> ____________________________________</td>
                <td style="width:50%; padding:8px 0;"><strong>Time of Drop:</strong> ___________________</td>
            </tr>
            <tr>
                <td style="padding:8px 0;"><strong>Scrutineer Signature:</strong> ___________________________________</td>
                <td style="padding:8px 0;"><strong>Official Winning Cell:</strong> [ &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp; ]</td>
            </tr>
            <tr>
                <td colspan="2" style="padding:8px 0;"><strong>Notes / Prize Awarded:</strong> __________________________________________________________________</td>
            </tr>
        </table>
    </div>
    <?php endif; ?>

    <?php if (!empty($winners)): ?>
    <div class="winners-summary no-print">
        <h2><img src="cow_poo_2.svg" style="width:56px;height:56px;vertical-align:middle;"> Winners</h2>
        <div class="winners-list">
            <?php foreach ($winners as $ref => $w): 
                $winDisplay = formatCellRef($ref, $gridCols, $cellNaming);
            ?>
            <div class="winner-card">
                <div class="winner-cell"><?= htmlspecialchars($winDisplay) ?></div>
                <div class="winner-details">
                    <div class="winner-prize"><?= htmlspecialchars($w['prize']) ?></div>
                    <div class="winner-name"><?= htmlspecialchars($w['buyer_name']) ?></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php if (!$hasHiEvents && $canBuyTickets && $allowCellSelection): ?>
<div id="publicPaddockTray" class="no-print" style="display:none; position:fixed; bottom:16px; left:50%; transform:translateX(-50%); background:#ffffff; color:#222; border-radius:30px; padding:10px 20px; box-shadow:0 8px 24px rgba(0,0,0,0.35); z-index:9000; align-items:center; gap:16px; border:2px solid #2d5016;">
    <div style="font-size:0.92em; font-weight:bold; color:#2d5016;">
        <span id="floatingSelectedCount">0</span> selected &bull; <span id="floatingTotalDisplay" style="font-size:1.15em; font-weight:900;">$0.00</span>
    </div>
    <button type="button" onclick="openPublicBookingModal()" style="background:#2d5016; color:#fff; border:none; padding:8px 16px; border-radius:20px; font-weight:bold; font-size:0.9em; cursor:pointer;">
        Proceed to Reservation &rarr;
    </button>
    <button type="button" onclick="clearPublicSelection()" style="background:none; border:none; color:#dc2626; font-weight:bold; font-size:0.85em; cursor:pointer;">
        Clear
    </button>
</div>
<?php endif; ?>

<?php if (!$hasHiEvents && $canBuyTickets): ?>
<div id="publicBookingModal" class="modal-backdrop no-print" style="display:none;">
    <div class="modal-card">
        <div style="display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid #e2e8f0; padding-bottom:10px; margin-bottom:14px;">
            <h3 style="margin:0; font-size:1.3em; color:#2d5016;">🎟️ Buy / Reserve Squares</h3>
            <button type="button" onclick="closePublicBookingModal()" style="background:none; border:none; font-size:1.6em; cursor:pointer; color:#718096; line-height:1;">&times;</button>
        </div>

        <form method="POST" id="publicBookingForm" action="grid.php?id=<?= $bingoEventId ?>" onsubmit="return validatePublicForm();">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($gridCsrf) ?>">
            <input type="hidden" name="action" value="public_reserve">
            <input type="hidden" name="selected_cells" id="public_selected_cells" value="">
            <input type="hidden" name="ticket_qty" id="public_ticket_qty" value="1">

            <?php if ($allowCellSelection): ?>
                <div style="background:#f8fafc; border:1px solid #cbd5e1; border-radius:8px; padding:10px 14px; margin-bottom:14px; font-size:0.88em;">
                    <strong>Square Selection:</strong>
                    <div id="publicSelectionPreview" style="margin-top:4px; font-weight:bold; color:#2d5016;">
                        <span id="publicSelectedSummary">1 random square</span>
                    </div>
                    <small style="color:#64748b;">(Tip: Tap available squares on the grid or use the increment buttons below)</small>
                </div>
            <?php endif; ?>

            <div style="margin-bottom:14px;">
                <label style="display:block; font-size:0.85em; font-weight:bold; color:#374151; margin-bottom:4px;">Add Squares:</label>
                <div style="display:flex; gap:6px; flex-wrap:wrap;">
                    <button type="button" class="quick-qty-btn" onclick="addPublicQty(1)" style="flex:1; min-width:50px; padding:7px 10px; border:1px solid #cbd5e1; border-radius:6px; background:#f1f5f9; font-weight:bold; cursor:pointer;">+1</button>
                    <button type="button" class="quick-qty-btn" onclick="addPublicQty(3)" style="flex:1; min-width:50px; padding:7px 10px; border:1px solid #cbd5e1; border-radius:6px; background:#f1f5f9; font-weight:bold; cursor:pointer;">+3</button>
                    <button type="button" class="quick-qty-btn" onclick="addPublicQty(5)" style="flex:1; min-width:50px; padding:7px 10px; border:1px solid #cbd5e1; border-radius:6px; background:#f1f5f9; font-weight:bold; cursor:pointer;">+5</button>
                    <button type="button" class="quick-qty-btn" onclick="addPublicQty(10)" style="flex:1; min-width:50px; padding:7px 10px; border:1px solid #cbd5e1; border-radius:6px; background:#f1f5f9; font-weight:bold; cursor:pointer;">+10</button>
                    <button type="button" class="quick-qty-btn" onclick="resetPublicQty()" style="padding:7px 12px; border:1px solid #fecaca; border-radius:6px; background:#fef2f2; color:#dc2626; font-weight:bold; cursor:pointer;">Reset</button>
                </div>
            </div>

            <div style="background:#ecfdf5; border:1.5px solid #a7f3d0; border-radius:8px; padding:12px 16px; margin-bottom:16px; display:flex; justify-content:space-between; align-items:center;">
                <div>
                    <span style="display:block; font-size:0.8em; text-transform:uppercase; font-weight:bold; color:#065f46;">Quantity</span>
                    <span id="publicQtyCountDisplay" style="font-size:1.25em; font-weight:800; color:#065f46;">1 square</span>
                </div>
                <div style="text-align:right;">
                    <span style="display:block; font-size:0.8em; text-transform:uppercase; font-weight:bold; color:#047857;">Total to Pay</span>
                    <span id="publicTotalDisplay" style="font-size:1.4em; font-weight:900; color:#047857;">$10.00</span>
                </div>
            </div>

            <div style="margin-bottom:12px;">
                <label style="display:block; font-size:0.85em; font-weight:bold; color:#374151; margin-bottom:4px;">Your Name *</label>
                <input type="text" name="buyer_name" id="public_buyer_name" required placeholder="Full Name (e.g. Sean O'Leary)" style="width:100%; padding:10px 12px; border:1.5px solid #cbd5e1; border-radius:6px; font-size:0.95em;">
            </div>

            <div style="margin-bottom:12px;">
                <label style="display:block; font-size:0.85em; font-weight:bold; color:#374151; margin-bottom:4px;">NZ Phone Number *</label>
                <input type="text" name="buyer_phone" id="public_buyer_phone" required placeholder="e.g. 021 123 4567 or 03 123 4567" style="width:100%; padding:10px 12px; border:1.5px solid #cbd5e1; border-radius:6px; font-size:0.95em;">
                <small style="color:#64748b; font-size:0.8em;">Must be a valid New Zealand mobile or landline number.</small>
            </div>

            <div style="margin-bottom:14px;">
                <label style="display:block; font-size:0.85em; font-weight:bold; color:#374151; margin-bottom:4px;">Email Address (Optional)</label>
                <input type="email" name="buyer_email" id="public_buyer_email" placeholder="you@example.co.nz" style="width:100%; padding:10px 12px; border:1.5px solid #cbd5e1; border-radius:6px; font-size:0.95em;">
            </div>

            <div style="background:#fffbeb; border:1.5px solid #fde047; border-radius:8px; padding:12px 14px; margin-bottom:16px;">
                <label style="display:block; font-size:0.88em; font-weight:bold; color:#854d0e; margin-bottom:6px;">
                    🛡️ Security Check: What is <?= $captchaNum1 ?> + <?= $captchaNum2 ?>? *
                </label>
                <input type="number" name="captcha_answer" id="public_captcha_answer" required placeholder="Enter sum" style="width:100%; padding:9px 12px; border:1.5px solid #cbd5e1; border-radius:6px; font-size:1em;">
            </div>

            <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:10px 14px; margin-bottom:16px; font-size:0.85em; color:#475569; line-height:1.4;">
                ℹ️ <strong>Reservation Policy:</strong> Squares are <u>not reserved until payment is received</u>. Payment instructions will be displayed immediately upon submission.
            </div>

            <div style="display:flex; justify-content:flex-end; gap:8px;">
                <button type="button" onclick="closePublicBookingModal()" style="background:#e2e8f0; color:#475569; border:none; padding:10px 16px; border-radius:6px; font-weight:bold; cursor:pointer;">Cancel</button>
                <button type="submit" id="publicSubmitBtn" style="background:#2d5016; color:#fff; border:none; padding:10px 20px; border-radius:6px; font-weight:bold; font-size:1em; cursor:pointer;">
                    Confirm &amp; Get Bank Details
                </button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<div id="bankDetailsModal" class="modal-backdrop no-print" style="display:none;">
    <div class="modal-card">
        <div style="display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid #e2e8f0; padding-bottom:10px; margin-bottom:14px;">
            <h3 style="margin:0; font-size:1.3em; color:#2d5016;">🏦 Bank Transfer Details</h3>
            <button type="button" onclick="closeBankDetailsModal()" style="background:none; border:none; font-size:1.6em; cursor:pointer; color:#718096; line-height:1;">&times;</button>
        </div>

        <p style="font-size:0.92em; color:#475569; margin-bottom:12px;">
            If you have already submitted a reservation, please use the details below to complete your direct bank payment:
        </p>

        <div style="background:#f8fafc; border:1.5px solid #cbd5e1; border-radius:8px; padding:14px 18px; margin-bottom:16px; font-size:0.95em; line-height:1.6; color:#1e293b;">
            <?= formatRichText($eventPaymentInstructions) ?>
        </div>

        <div style="text-align:right;">
            <button type="button" onclick="closeBankDetailsModal()" style="background:#2d5016; color:#fff; border:none; padding:9px 20px; border-radius:6px; font-weight:bold; cursor:pointer;">
                Close
            </button>
        </div>
    </div>
</div>

<div class="tooltip no-print" id="tooltip"></div>
<script>
    const tooltip = document.getElementById('tooltip');
    const allCells = document.querySelectorAll('.cell');
    const takenCells = document.querySelectorAll('.cell.taken');
    const cellNaming = '<?= $cellNaming ?>';
    const gridCols = <?= $gridCols ?>;
    const allowCellSelection = <?= $allowCellSelection ? 'true' : 'false' ?>;
    const pricingTiers = <?= json_encode($pricingTiers) ?>;
    const maxAvailable = <?= (int)$availableCount ?>;

    let publicSelectedSet = new Set();

    const escHtml = (s) => String(s || '').replace(/[&<>'"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

    takenCells.forEach(cell => {
        cell.addEventListener('mouseenter', (e) => {
            const disp = cell.dataset.display || cell.dataset.cell;
            let html = `<strong>Square: ${escHtml(disp)}${cellNaming === 'numeric' ? ' (' + escHtml(cell.dataset.cell) + ')' : ''}</strong><br>🎟️ Ticket: ${escHtml(cell.dataset.ticket)}<br>👤 ${escHtml(cell.dataset.name)}`;
            <?php if ($isAdmin): ?>
            if (cell.dataset.paid === "0") {
                html += `<br><span style="color:#ffc107;font-weight:bold;">⚠️ Awaiting Payment (Unpaid)</span>`;
            }
            <?php endif; ?>
            if (cell.dataset.prize) {
                html += `<br><img src="cow_poo_2.svg" style="width:16px;height:16px;vertical-align:middle;display:inline;"> <strong>${escHtml(cell.dataset.prize)}</strong>`;
            }
            tooltip.innerHTML = html;
            tooltip.style.display = 'block';
            positionTooltip(e);
        });
        cell.addEventListener('mousemove', positionTooltip);
        cell.addEventListener('mouseleave', () => { tooltip.style.display = 'none'; });
    });

    function positionTooltip(e) {
        const rect = tooltip.getBoundingClientRect();
        tooltip.style.left = (e.clientX - rect.width / 2) + 'px';
        tooltip.style.top = (e.clientY - rect.height - 15) + 'px';
    }

    const searchInput = document.getElementById('searchInput');
    searchInput.addEventListener('keyup', (e) => { if (e.key === 'Enter') findCells(); });

    function findCells() {
        const query = searchInput.value.trim().toLowerCase();
        if (!query) return;
        let matchCount = 0;
        allCells.forEach(cell => {
            cell.classList.remove('highlight', 'dimmed');
            const cellDisplay = (cell.dataset.display || '').toLowerCase();
            const cellAlpha = (cell.dataset.cell || '').toLowerCase();
            const cellNumeric = (cell.dataset.numeric || '').toLowerCase();
            const name = (cell.dataset.name || '').toLowerCase();
            const ticket = (cell.dataset.ticket || '').toLowerCase();

            const isMatch = (cellDisplay === query || cellAlpha === query || cellNumeric === query || (name && name.includes(query)) || (ticket && ticket.includes(query)));
            if (cell.classList.contains('taken')) {
                if (isMatch) {
                    cell.classList.add('highlight');
                    matchCount++;
                } else {
                    cell.classList.add('dimmed');
                }
            } else {
                if (cellDisplay === query || cellAlpha === query || cellNumeric === query) {
                    cell.classList.add('highlight');
                    matchCount++;
                } else {
                    cell.classList.add('dimmed');
                }
            }
        });
        const resultEl = document.getElementById('searchResult');
        resultEl.innerHTML = matchCount > 0 ? `✅ Found <strong>${matchCount}</strong> cell${matchCount > 1 ? 's' : ''}` : `❌ No cells found for "${searchInput.value.trim()}"`;
        document.getElementById('clearBtn').style.display = 'inline-block';
    }

    function clearSearch() {
        searchInput.value = '';
        allCells.forEach(cell => { cell.classList.remove('highlight', 'dimmed'); });
        document.getElementById('searchResult').innerHTML = '';
        document.getElementById('clearBtn').style.display = 'none';
    }

    function calculateBulkPrice(qty) {
        if (qty <= 0) return 0.00;
        let total = 0;
        let rem = qty;
        const sortedTiers = Object.keys(pricingTiers).map(Number).sort((a,b) => b - a);
        for (let tier of sortedTiers) {
            if (rem >= tier) {
                const countOfTier = Math.floor(rem / tier);
                total += countOfTier * pricingTiers[tier];
                rem %= tier;
            }
        }
        return total.toFixed(2);
    }

    function openPublicBookingModal() {
        const modal = document.getElementById('publicBookingModal');
        if (modal) {
            modal.style.display = 'flex';
            updatePublicTotals();
        }
    }

    function closePublicBookingModal() {
        const modal = document.getElementById('publicBookingModal');
        if (modal) {
            modal.style.display = 'none';
        }
    }

    function openBankDetailsModal() {
        const modal = document.getElementById('bankDetailsModal');
        if (modal) modal.style.display = 'flex';
    }

    function closeBankDetailsModal() {
        const modal = document.getElementById('bankDetailsModal');
        if (modal) modal.style.display = 'none';
    }

    function publicSelectCell(el) {
        if (!allowCellSelection) return;
        const ref = el.dataset.cell;
        if (publicSelectedSet.has(ref)) {
            publicSelectedSet.delete(ref);
            el.classList.remove('selected');
        } else {
            publicSelectedSet.add(ref);
            el.classList.add('selected');
        }
        updatePublicTotals();
    }

    function clearPublicSelection() {
        publicSelectedSet.forEach(ref => {
            const node = document.querySelector(`.cell[data-cell="${ref}"]`);
            if (node) node.classList.remove('selected');
        });
        publicSelectedSet.clear();
        updatePublicTotals();
    }

    function addPublicQty(delta) {
        const qtyInput = document.getElementById('public_ticket_qty');
        let current = parseInt(qtyInput ? qtyInput.value : 1, 10) || 1;
        if (publicSelectedSet.size > 0) {
            current = publicSelectedSet.size;
        }
        let target = current + delta;
        if (target > maxAvailable && maxAvailable > 0) {
            target = maxAvailable;
        }
        if (target < 1) target = 1;
        
        if (publicSelectedSet.size > 0) {
            clearPublicSelection();
        }
        if (qtyInput) qtyInput.value = target;
        updatePublicTotals();
    }

    function resetPublicQty() {
        clearPublicSelection();
        const qtyInput = document.getElementById('public_ticket_qty');
        if (qtyInput) qtyInput.value = 1;
        updatePublicTotals();
    }

    function updatePublicTotals() {
        const qtyInput = document.getElementById('public_ticket_qty');
        const selectedInput = document.getElementById('public_selected_cells');
        const summarySpan = document.getElementById('publicSelectedSummary');
        const totalDisplay = document.getElementById('publicTotalDisplay');
        const qtyCountDisplay = document.getElementById('publicQtyCountDisplay');

        let qty = 1;
        if (publicSelectedSet.size > 0) {
            qty = publicSelectedSet.size;
            if (selectedInput) selectedInput.value = Array.from(publicSelectedSet).join(',');
            if (qtyInput) qtyInput.value = qty;
            if (summarySpan) {
                const formatted = Array.from(publicSelectedSet).map(ref => {
                    const node = document.querySelector(`.cell[data-cell="${ref}"]`);
                    return node ? (node.dataset.display || ref) : ref;
                });
                summarySpan.textContent = `${qty} square(s): ` + formatted.join(', ');
            }
        } else {
            qty = parseInt(qtyInput ? qtyInput.value : 1, 10) || 1;
            if (selectedInput) selectedInput.value = '';
            if (summarySpan) summarySpan.textContent = `${qty} random square(s)`;
        }

        const total = calculateBulkPrice(qty);
        if (totalDisplay) totalDisplay.textContent = '$' + total;
        if (qtyCountDisplay) qtyCountDisplay.textContent = `${qty} square${qty > 1 ? 's' : ''}`;

        const tray = document.getElementById('publicPaddockTray');
        const floatCount = document.getElementById('floatingSelectedCount');
        const floatTotal = document.getElementById('floatingTotalDisplay');
        if (tray) {
            if (publicSelectedSet.size > 0) {
                tray.style.display = 'flex';
                if (floatCount) floatCount.textContent = publicSelectedSet.size;
                if (floatTotal) floatTotal.textContent = '$' + total;
            } else {
                tray.style.display = 'none';
            }
        }
    }

    function validateNzPhone(phone) {
        let cleaned = phone.replace(/[\s\-\(\)\.]+/g, '');
        if (cleaned.startsWith('+64')) {
            cleaned = '0' + cleaned.substring(3);
        } else if (cleaned.startsWith('0064')) {
            cleaned = '0' + cleaned.substring(4);
        } else if (cleaned.startsWith('64') && cleaned.length >= 10) {
            cleaned = '0' + cleaned.substring(2);
        }
        return /^(02[012789]\d{6,8}|0[34679]\d{7}|0[58]0[08]\d{5,7})$/.test(cleaned);
    }

    function validatePublicForm() {
        const name = document.getElementById('public_buyer_name').value.trim();
        const phone = document.getElementById('public_buyer_phone').value.trim();
        const captcha = document.getElementById('public_captcha_answer').value.trim();

        if (!name) {
            alert('Please enter your full name.');
            document.getElementById('public_buyer_name').focus();
            return false;
        }

        if (!phone || !validateNzPhone(phone)) {
            alert('Please enter a valid New Zealand phone number (e.g. 021 123 4567 or 03 123 4567).');
            document.getElementById('public_buyer_phone').focus();
            return false;
        }

        if (!captcha) {
            alert('Please answer the security math check.');
            document.getElementById('public_captcha_answer').focus();
            return false;
        }

        const btn = document.getElementById('publicSubmitBtn');
        if (btn) {
            btn.disabled = true;
            btn.textContent = 'Submitting Reservation... ⏳';
        }
        return true;
    }
</script>

<?php if ($isAdmin && !empty($_GET['print'])): ?>
<script>
    window.addEventListener('load', () => {
        setTimeout(() => window.print(), 250);
    });
</script>
<?php endif; ?>

</body>
</html>
