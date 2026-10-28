<?php
// views/logs_view.php

if (isset($_GET['select_event'])) {
    $_SESSION['selected_event_id'] = (int)$_GET['select_event'];
}

$selectedEventId = (int)($_SESSION['selected_event_id'] ?? 0);

// Load all events for the filter dropdown
$allEventsStmt = $db->query("SELECT id, event_name, hievents_event_id FROM cow_bingo_events ORDER BY id DESC");
$allEvents = $allEventsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$currentEventName = 'All Events';
$currentHiEventsId = null;
foreach ($allEvents as $e) {
    if ((int)$e['id'] === $selectedEventId) {
        $currentEventName = $e['event_name'];
        $currentHiEventsId = $e['hievents_event_id'] ?: null;
        break;
    }
}

// 1. Fetch Audit Log Records
if ($selectedEventId > 0) {
    $auditStmt = $db->prepare("
        SELECT a.*, e.event_name
        FROM cow_bingo_audit_log a
        LEFT JOIN cow_bingo_events e ON e.id = a.event_id
        WHERE a.event_id = ?
        ORDER BY a.id DESC
        LIMIT 250
    ");
    $auditStmt->execute([$selectedEventId]);
} else {
    $auditStmt = $db->query("
        SELECT a.*, e.event_name
        FROM cow_bingo_audit_log a
        LEFT JOIN cow_bingo_events e ON e.id = a.event_id
        ORDER BY a.id DESC
        LIMIT 250
    ");
}
$auditRows = $auditStmt ? $auditStmt->fetchAll(PDO::FETCH_ASSOC) : [];

// 2. Fetch Webhook Log Records (Strictly event-scoped if selected)
if ($selectedEventId > 0 && !empty($currentHiEventsId)) {
    $webhookStmt = $db->prepare("
        SELECT *
        FROM webhook_log
        WHERE hievents_event_id = ?
        ORDER BY id DESC
        LIMIT 100
    ");
    $webhookStmt->execute([(string)$currentHiEventsId]);
} elseif ($selectedEventId > 0 && empty($currentHiEventsId)) {
    $webhookStmt = null;
} else {
    $webhookStmt = $db->query("SELECT * FROM webhook_log ORDER BY id DESC LIMIT 100");
}
$webhookRows = $webhookStmt ? $webhookStmt->fetchAll(PDO::FETCH_ASSOC) : [];
?>
<div class="tab-content-header" style="display:flex; justify-content:space-between; align-items:center; margin-bottom:18px; flex-wrap:wrap; gap:12px;">
    <div>
        <h2 style="margin:0; font-size:1.4em; color:#2d5016;">📋 Activity &amp; Audit Trail</h2>
        <p style="margin:4px 0 0 0; color:#666; font-size:0.9em;">
            Live forensic ledger of square allocations, cash payments, winners, and settings changes.
        </p>
    </div>

    <!-- Scope Filter by Event -->
    <div style="display:flex; align-items:center; gap:8px;">
        <label for="auditEventSelect" style="font-size:0.85em; font-weight:bold; color:#333;">Filter Event:</label>
        <select id="auditEventSelect" onchange="window.location='admin.php?tab=logs&select_event=' + this.value;" style="padding:8px 12px; border:1.5px solid #cbd5e1; border-radius:6px; font-size:0.9em; background:#fff; font-weight:500;">
            <option value="0" <?= $selectedEventId === 0 ? 'selected' : '' ?>>🌐 All Events (Global)</option>
            <?php foreach ($allEvents as $evOption): ?>
                <option value="<?= (int)$evOption['id'] ?>" <?= (int)$evOption['id'] === $selectedEventId ? 'selected' : '' ?>>
                    🐮 <?= htmlspecialchars($evOption['event_name']) ?> (#<?= (int)$evOption['id'] ?>)
                </option>
            <?php endforeach; ?>
        </select>
    </div>
</div>

<!-- 6 Interactive Filter Category Pills -->
<div class="audit-category-pills" style="display:flex; gap:8px; margin-bottom:18px; flex-wrap:wrap;">
    <button type="button" class="audit-pill active" data-cat="all" onclick="setAuditCategory('all', this)" style="display:inline-flex; align-items:center; gap:6px; padding:8px 14px; border-radius:20px; border:1px solid #2d5016; background:#2d5016; color:#fff; font-size:0.85em; font-weight:bold; cursor:pointer;">
        📋 All Activity (<?= count($auditRows) ?>)
    </button>
    <button type="button" class="audit-pill" data-cat="squares" onclick="setAuditCategory('squares', this)" style="display:inline-flex; align-items:center; gap:6px; padding:8px 14px; border-radius:20px; border:1px solid #cbd5e1; background:#fff; color:#334155; font-size:0.85em; font-weight:bold; cursor:pointer;">
        🎟️  Squares
    </button>
    <button type="button" class="audit-pill" data-cat="payments" onclick="setAuditCategory('payments', this)" style="display:inline-flex; align-items:center; gap:6px; padding:8px 14px; border-radius:20px; border:1px solid #cbd5e1; background:#fff; color:#334155; font-size:0.85em; font-weight:bold; cursor:pointer;">
        💵 Payments
    </button>
    <button type="button" class="audit-pill" data-cat="prizes" onclick="setAuditCategory('prizes', this)" style="display:inline-flex; align-items:center; gap:6px; padding:8px 14px; border-radius:20px; border:1px solid #cbd5e1; background:#fff; color:#334155; font-size:0.85em; font-weight:bold; cursor:pointer;">
        🏆 Prizes
    </button>
    <button type="button" class="audit-pill" data-cat="settings" onclick="setAuditCategory('settings', this)" style="display:inline-flex; align-items:center; gap:6px; padding:8px 14px; border-radius:20px; border:1px solid #cbd5e1; background:#fff; color:#334155; font-size:0.85em; font-weight:bold; cursor:pointer;">
        🐮 Settings
    </button>
    <button type="button" class="audit-pill" data-cat="webhooks" onclick="setAuditCategory('webhooks', this)" style="display:inline-flex; align-items:center; gap:6px; padding:8px 14px; border-radius:20px; border:1px solid #cbd5e1; background:#fff; color:#334155; font-size:0.85em; font-weight:bold; cursor:pointer;">
        🔗 Webhooks (<?= count($webhookRows) ?>)
    </button>
</div>

<!-- AUDIT TABLE -->
<div id="auditTableContainer" style="background:#fff; border-radius:10px; box-shadow:0 2px 8px rgba(0,0,0,0.08); overflow-x:auto;">
    <?php if (empty($auditRows)): ?>
        <div style="padding:40px; text-align:center; color:#64748b;">
            <p style="font-size:1.1em; margin-bottom:4px;">📍 ­ No audit records found for <?= htmlspecialchars($currentEventName) ?>.</p>
            <small>Actions taken on squares, payments, or game settings will log here automatically.</small>
        </div>
    <?php else: ?>
        <table style="width:100%; border-collapse:collapse; text-align:left; font-size:0.88em;">
            <thead>
                <tr style="background:#f8fafc; border-bottom:1.5px solid #e2e8f0; color:#475569;">
                    <th style="padding:12px 14px;">Time</th>
                    <th style="padding:12px 14px;">Event</th>
                    <th style="padding:12px 14px;">Action</th>
                    <th style="padding:12px 14px;">Cell / Target</th>
                    <th style="padding:12px 14px;">Buyer / Ref</th>
                    <th style="padding:12px 14px;">Amount</th>
                    <th style="padding:12px 14px;">Details / Notes</th>
                    <th style="padding:12px 14px;">By</th>
                </tr>
            </thead>
            <tbody id="auditTableBody">
                <?php foreach ($auditRows as $row): 
                    $action = $row['action'] ?? '';
                    $cat = 'settings';
                    if (strpos($action, 'cell_') !== false || strpos($action, 'attendee') !== false) {
                        $cat = 'squares';
                    } elseif (strpos($action, 'paid') !== false || strpos($action, 'order_') !== false) {
                        $cat = 'payments';
                    } elseif (strpos($action, 'prize') !== false) {
                        $cat = 'prizes';
                    } elseif (strpos($action, 'webhook') !== false) {
                        $cat = 'webhooks';
                    }
                    $badgeColor = '#e2e8f0; color:#334155;';
                    if ($cat === 'squares') $badgeColor = '#dbeafe; color:#1e40af;';
                    if ($cat === 'payments') $badgeColor = '#dcfce7; color:#15803d;';
                    if ($cat === 'prizes') $badgeColor = '#fef3c7; color:#92400e;';
                    if ($cat === 'settings') $badgeColor = '#f3e8ff; color:#6b21a8;';
                    if ($cat === 'webhooks') $badgeColor = '#ffedd5; color:#9a3412;';
                ?>
                <tr class="audit-row" data-cat="<?= $cat ?>" style="border-bottom:1px solid #f1f5f9;">
                    <td style="padding:10px 14px; color:#64748b; white-space:nowrap;">
                        <?= !empty($row['created_at']) ? date('M d, g:ia', strtotime($row['created_at'])) : '-' ?>
                    </td>
                    <td style="padding:10px 14px; font-weight:600; color:#1e293b;">
                        <?= htmlspecialchars($row['event_name'] ?? ('Event #' . ($row['event_id'] ?? ''))) ?>
                    </td>
                    <td style="padding:10px 14px;">
                        <span style="display:inline-block; font-size:0.8em; font-weight:bold; padding:2px 8px; border-radius:10px; background:<?= $badgeColor ?>">
                            <?= htmlspecialchars(str_replace('_', ' ', $action)) ?>
                        </span>
                    </td>
                    <td style="padding:10px 14px; font-weight:bold; color:#0f172a;">
                        <?= htmlspecialchars($row['cell_reference'] ?? '-') ?>
                    </td>
                    <td style="padding:10px 14px; color:#334155;">
                        <?= htmlspecialchars($row['buyer_name'] ?? ($row['order_id'] ?? '-')) ?>
                    </td>
                    <td style="padding:10px 14px; font-weight:600; color:#059669;">
                        <?= ($row['amount'] !== null && (float)$row['amount'] > 0) ? ('$' . number_format((float)$row['amount'], 2)) : '-' ?>
                    </td>
                    <td style="padding:10px 14px; color:#475569;">
                        <?= htmlspecialchars($row['details'] ?? '') ?>
                    </td>
                    <td style="padding:10px 14px; color:#64748b; font-size:0.9em;">
                        👤 <?= htmlspecialchars($row['performed_by'] ?? 'System') ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<!-- WEBHOOKS TABLE -->
<div id="webhooksTableContainer" style="display:none; background:#fff; border-radius:10px; box-shadow:0 2px 8px rgba(0,0,0,0.08); overflow-x:auto;">
    <div style="padding:16px 20px; background:#fff7ed; border-bottom:1px solid #ffedd5;">
        <strong style="color:#9a3412;">🔗 Hi.Events Inbound Webhook Activity</strong>
        <p style="margin:2px 0 0; font-size:0.85em; color:#7c2d12;">
            <?php if ($selectedEventId > 0 && !empty($currentHiEventsId)):
                echo "Filtered strictly for <strong>" . htmlspecialchars($currentEventName) . "</strong> (Hi.Events ID: " . htmlspecialchars($currentHiEventsId) . ")";
            elseif ($selectedEventId > 0 && empty($currentHiEventsId)):
                echo "ℹ️  <strong>" . htmlspecialchars($currentEventName) . "</strong> is an Offline / Standalone event with no linked Hi.Events ID.";
            else:
                echo "Showing webhooks across all events.";
            endif; ?>
        </p>
    </div>
    <?php if (empty($webhookRows)): ?>
        <div style="padding:36px; text-align:center; color:#64748b;">
            <p>No webhooks recorded yet for this event.</p>
        </div>
    <?php else: ?>
        <table style="width:100%; border-collapse:collapse; text-align:left; font-size:0.88em;">
            <thead>
                <tr style="background:#f8fafc; border-bottom:1.5px solid #e2e8f0; color:#475569;">
                    <th style="padding:12px 14px;">Received</th>
                    <th style="padding:12px 14px;">Hi.Events ID</th>
                    <th style="padding:12px 14px;">Event Type</th>
                    <th style="padding:12px 14px;">IP Address</th>
                    <th style="padding:12px 14px;">Payload Preview</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($webhookRows as $w): ?>
                <tr style="border-bottom:1px solid #f1f5f9;">
                    <td style="padding:10px 14px; color:#64748b; white-space:nowrap;">
                        <?= !empty($w['created_at']) ? date('M d, g:ia', strtotime($w['created_at'])) : '-' ?>
                    </td>
                    <td style="padding:10px 14px; font-weight:bold;">
                        <?= htmlspecialchars($w['hievents_event_id'] ?? '-') ?>
                    </td>
                    <td style="padding:10px 14px;">
                        <span style="display:inline-block; font-size:0.8em; font-weight:bold; padding:2px 8px; border-radius:10px; background:#ffedd5; color:#9a3412;">
                            <?= htmlspecialchars($w['event_type'] ?? 'unknown') ?>
                        </span>
                    </td>
                    <td style="padding:10px 14px; color:#64748b; font-family:monospace;">
                        <?= htmlspecialchars($w['ip_address'] ?? '-') ?>
                    </td>
                    <td style="padding:10px 14px; color:#475569; font-family:monospace; font-size:0.8em;">
                        <?php 
                        $rawPayloadStr = $w['payload'] ?? '';
                        $previewStr = function_exists('mb_strimwidth') 
                            ? mb_strimwidth($rawPayloadStr, 0, 95, '...') 
                            : (strlen($rawPayloadStr) > 95 ? substr($rawPayloadStr, 0, 92) . '...' : $rawPayloadStr);
                        echo htmlspecialchars($previewStr);
                        ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<script>
function setAuditCategory(cat, btn) {
    document.querySelectorAll('.audit-pill').forEach(el => {
        el.style.background = '#fff';
        el.style.color = '#334155';
        el.style.borderColor = '#cbd5e1';
        el.classList.remove('active');
    });
    btn.style.background = '#2d5016';
    btn.style.color = '#fff';
    btn.style.borderColor = '#2d5016';
    btn.classList.add('active');

    const auditContainer = document.getElementById('auditTableContainer');
    const webhookContainer = document.getElementById('webhooksTableContainer');

    if (cat === 'webhooks') {
        if (auditContainer) auditContainer.style.display = 'none';
        if (webhookContainer) webhookContainer.style.display = 'block';
        return;
    } else {
        if (auditContainer) auditContainer.style.display = 'block';
        if (webhookContainer) webhookContainer.style.display = 'none';
    }

    const rows = document.querySelectorAll('.audit-row');
    rows.forEach(r => {
        if (cat === 'all' || r.dataset.cat === cat) {
            r.style.display = '';
        } else {
            r.style.display = 'none';
        }
    });
}
</script>
