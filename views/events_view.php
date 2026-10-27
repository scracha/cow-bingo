<?php
// views/events_view.php

if (isset($_GET['select_event'])) {
    $_SESSION['selected_event_id'] = (int)$_GET['select_event'];
}

$selectedEventId = (int)($_SESSION['selected_event_id'] ?? 0);

$csrfToken = function_exists('getCsrfToken')
    ? getCsrfToken()
    : (function_exists('generateCsrfToken') ? generateCsrfToken() : ($_SESSION['csrf_token'] ?? ''));

if (empty($csrfToken)) {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    $csrfToken = $_SESSION['csrf_token'];
}

$canCreate = $isSuperAdmin || ($_SESSION['admin_role'] ?? '') !== 'seller';

if (!function_exists('formatPricingTiersForDisplay')) {
    function formatPricingTiersForDisplay($tiers): string {
        if (empty($tiers)) {
            $def = defined('DEFAULT_PRICING_TIERS') ? DEFAULT_PRICING_TIERS : [1 => 10.00, 3 => 25.00, 5 => 40.00, 10 => 70.00];
            $parts = [];
            foreach ($def as $q => $p) {
                $parts[] = $q . ' for $' . number_format((float)$p, 2);
            }
            return 'Default (' . implode(', ', $parts) . ')';
        }
        if (is_string($tiers)) {
            $tiers = json_decode($tiers, true) ?: [];
        }
        if (!is_array($tiers) || empty($tiers)) return 'Default';
        $parts = [];
        foreach ($tiers as $qty => $price) {
            $parts[] = $qty . ' for $' . number_format((float)$price, 2);
        }
        return implode(', ', $parts);
    }
}

if (!function_exists('formatPricingTiersForInput')) {
    function formatPricingTiersForInput($tiers): string {
        if (empty($tiers)) return '';
        if (is_string($tiers)) {
            $tiers = json_decode($tiers, true) ?: [];
        }
        if (!is_array($tiers) || empty($tiers)) return '';
        $parts = [];
        foreach ($tiers as $qty => $price) {
            $parts[] = $qty . ':' . (is_float($price) || is_int($price) ? number_format((float)$price, 2, '.', '') : $price);
        }
        return implode(', ', $parts);
    }
}
?>
<div class="tab-content-header" style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
    <div>
        <h2 style="margin:0; font-size:1.4em; color:#2d5016;">🐮 Cow Pat Bingo Events</h2>
        <p style="margin:4px 0 0 0; color:#666; font-size:0.9em;">
            <?php if ($selectedEventId > 0): ?>
                Active in other tabs: <strong>Event #<?= $selectedEventId ?></strong> &bull; Click "Select Event" on any card below to switch.
            <?php else: ?>
                Select an event below to manage its squares, bookings, and audit records.
            <?php endif; ?>
        </p>
    </div>
    <?php if ($canCreate): ?>
        <button type="button" class="btn btn-primary" onclick="openCreateEventModal()" style="background:#2d5016; color:#fff; border:none; padding:10px 18px; border-radius:6px; font-weight:bold; cursor:pointer;">+ Create New Event</button>
    <?php endif; ?>
</div>

<?php if (empty($events)): ?>
    <div style="background:#fff; border-radius:8px; padding:30px; text-align:center; box-shadow:0 2px 8px rgba(0,0,0,0.08);">
        <p style="font-size:1.1em; color:#666;">No Cow Pat Bingo events configured yet.</p>
        <?php if ($canCreate): ?>
            <button type="button" class="btn btn-primary" onclick="openCreateEventModal()" style="background:#2d5016; color:#fff; border:none; padding:10px 20px; border-radius:6px; font-weight:bold; cursor:pointer; margin-top:10px;">Create Your First Game</button>
        <?php endif; ?>
    </div>
<?php else: ?>
    <div style="display:grid; grid-template-columns:repeat(auto-fill, minmax(360px, 1fr)); gap:20px;">
        <?php foreach ($events as $ev): 
            $evId = (int)$ev['id'];
            $isSelected = ($evId === $selectedEventId);
            $canManage = $isSuperAdmin || canManageEvent($db, $currentAdminId, $evId);
            $startDateFmt = !empty($ev['event_start']) ? date('D, d M Y, g:ia', strtotime($ev['event_start'])) : 'No date set';
            $isoStart = !empty($ev['event_start']) ? date('Y-m-d\TH:i', strtotime($ev['event_start'])) : '';
            $inputTiers = formatPricingTiersForInput($ev['pricing_tiers'] ?? '');
            $displayTiers = formatPricingTiersForDisplay($ev['pricing_tiers'] ?? '');
            $totalCells = ((int)($ev['grid_cols'] ?? 16)) * ((int)($ev['grid_rows'] ?? 16));
            $isLinked = !empty($ev['hievents_event_id']);
            $cellNamingVal = $ev['cell_naming'] ?? 'alpha';

            $eventDataJson = htmlspecialchars(json_encode([
                'id' => $evId,
                'name' => $ev['event_name'] ?? '',
                'venue' => $ev['event_venue'] ?? '',
                'start' => $isoStart,
                'description' => $ev['event_description'] ?? '',
                'cell_naming' => $cellNamingVal,
                'pricing_tiers' => $inputTiers,
                'allow_cell_selection' => (int)($ev['allow_cell_selection'] ?? 0),
                'hievents_event_id' => $ev['hievents_event_id'] ?? ''
            ]), ENT_QUOTES, 'UTF-8');
        ?>
        <div style="background:#fff; border-radius:10px; padding:20px; box-shadow:0 2px 8px rgba(0,0,0,0.08); display:flex; flex-direction:column; justify-content:space-between; border-left: 6px solid <?= $isSelected ? '#2d5016' : (!empty($ev['is_active']) ? '#65a30d' : '#a0aec0') ?>; <?= $isSelected ? 'outline: 2px solid #2d5016; background:#fafdf7;' : '' ?>">
            <div>
                <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:8px;">
                    <h3 style="margin:0; font-size:1.2em; color:#1a202c;"><?= htmlspecialchars($ev['event_name']) ?></h3>
                    <div style="display:flex; gap:6px; align-items:center;">
                        <?php if ($isSelected): ?>
                            <span style="font-size:0.75em; font-weight:bold; padding:3px 8px; border-radius:12px; background:#2d5016; color:#fff;">★ SELECTED</span>
                        <?php endif; ?>
                        <span style="font-size:0.75em; font-weight:bold; padding:3px 8px; border-radius:12px; background:<?= !empty($ev['is_active']) ? '#dcfce7; color:#15803d;' : '#f1f5f9; color:#64748b;' ?>">
                            <?= !empty($ev['is_active']) ? '● ACTIVE' : '○ DEACTIVATED' ?>
                        </span>
                    </div>
                </div>

                <p style="font-size:0.85em; color:#718096; margin-bottom:12px;">
                    📅 <?= htmlspecialchars($startDateFmt) ?><br>
                    <?php if (!empty($ev['event_venue'])): ?>📍 <?= htmlspecialchars($ev['event_venue']) ?><br><?php endif; ?>
                    🔢 Grid: <?= (int)$ev['grid_cols'] ?> &times; <?= (int)$ev['grid_rows'] ?> (<?= $totalCells ?> squares &bull; <?= $cellNamingVal === 'numeric' ? '1, 2, 3' : 'A1, B2' ?>)
                </p>

                <?php if (!empty($ev['event_description'])): 
                    $rawDesc = $ev['event_description'];
                    $shortDesc = function_exists('mb_strimwidth') ? mb_strimwidth($rawDesc, 0, 140, '...') : (strlen($rawDesc) > 140 ? substr($rawDesc, 0, 137) . '...' : $rawDesc);
                ?>
                    <div style="background:#f8fafc; border-radius:6px; padding:8px 12px; font-size:0.85em; color:#475569; margin-bottom:12px; line-height:1.4;">
                        <?= nl2br(htmlspecialchars($shortDesc)) ?>
                    </div>
                <?php endif; ?>

                <div style="font-size:0.82em; color:#4b5563; margin-bottom:12px; line-height:1.6;">
                    <div><strong>🎟️ Pricing:</strong> <?= htmlspecialchars($displayTiers) ?></div>
                    <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap; margin-top:4px;">
                        <strong>🔗 Hi.Events:</strong>
                        <?php if ($isLinked): ?>
                            <span>Linked (ID: <strong><?= htmlspecialchars($ev['hievents_event_id']) ?></strong>)</span>
                            <?php if ($canManage): ?>
                                <form method="POST" action="admin.php?tab=events" style="margin:0; display:inline;" onsubmit="return confirm('Sync attendees from Hi.Events now?');">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                    <input type="hidden" name="action" value="sync_attendees">
                                    <input type="hidden" name="event_db_id" value="<?= $evId ?>">
                                    <button type="submit" style="background:#eff6ff; border:1px solid #bfdbfe; color:#1e40af; border-radius:4px; padding:2px 7px; font-size:0.85em; font-weight:bold; cursor:pointer;">🔄 Sync</button>
                                </form>
                            <?php endif; ?>
                        <?php else: ?>
                            <span style="color:#64748b;">Standalone (Offline)</span>
                            <?php if ($canManage): ?>
                                <form method="POST" action="admin.php?tab=events" style="margin:0; display:inline-flex; gap:4px; align-items:center;">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                    <input type="hidden" name="action" value="link_hievents">
                                    <input type="hidden" name="event_db_id" value="<?= $evId ?>">
                                    <input type="text" name="hievents_event_id" placeholder="ID #" required style="padding:2px 6px; font-size:0.85em; width:65px; border:1px solid #ccc; border-radius:4px;">
                                    <button type="submit" style="background:#1e40af; color:#fff; border:none; border-radius:4px; padding:3px 8px; font-size:0.85em; font-weight:bold; cursor:pointer;">🔗 Link</button>
                                </form>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div style="border-top:1px solid #edf2f7; padding-top:12px; margin-top:8px; display:flex; flex-direction:column; gap:8px;">
                <div style="display:flex; gap:6px;">
                    <?php if (!$isSelected): ?>
                        <a href="admin.php?tab=events&select_event=<?= $evId ?>" style="flex:1; text-align:center; background:#f0fdf4; color:#166534; border:1.5px solid #86efac; padding:7px 8px; border-radius:6px; text-decoration:none; font-weight:bold; font-size:0.82em;">🎯 Select Event</a>
                    <?php else: ?>
                        <span style="flex:1; text-align:center; background:#2d5016; color:#fff; border:1px solid #2d5016; padding:7px 8px; border-radius:6px; font-weight:bold; font-size:0.82em;">✓ Active Event</span>
                    <?php endif; ?>
                    <a href="grid.php?id=<?= $evId ?>" target="_blank" style="flex:0.9; text-align:center; background:#f8fafc; color:#334155; border:1px solid #cbd5e1; padding:7px 8px; border-radius:6px; text-decoration:none; font-weight:bold; font-size:0.82em;">🌐 View Grid</a>
                    <a href="admin.php?tab=cells&view=<?= $evId ?>" style="flex:0.9; text-align:center; background:#eff6ff; color:#1e40af; border:1px solid #bfdbfe; padding:7px 8px; border-radius:6px; text-decoration:none; font-weight:bold; font-size:0.82em;">🎲 Cells</a>
                    <?php if ($canManage): ?>
                        <button type="button" class="btn-edit-event" data-event="<?= $eventDataJson ?>" style="flex:0.7; background:#f8fafc; border:1px solid #cbd5e1; color:#334155; padding:7px 8px; border-radius:6px; font-weight:bold; font-size:0.82em; cursor:pointer;">✏️ Edit</button>
                    <?php endif; ?>
                </div>

                <?php if ($canManage): ?>
                <div style="display:flex; gap:6px; flex-wrap:wrap; align-items:center; padding-top:2px;">
                    <form method="POST" action="admin.php?tab=events" style="margin:0;">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                        <input type="hidden" name="action" value="toggle_event">
                        <input type="hidden" name="event_db_id" value="<?= $evId ?>">
                        <button type="submit" style="background:none; border:none; padding:2px 4px; font-size:0.8em; color:<?= !empty($ev['is_active']) ? '#d97706' : '#15803d' ?>; cursor:pointer; text-decoration:underline;">
                            <?= !empty($ev['is_active']) ? 'Deactivate' : 'Activate' ?>
                        </button>
                    </form>
                    <span style="color:#cbd5e1;">&bull;</span>
                    <form method="POST" action="admin.php?tab=events" style="margin:0;">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                        <input type="hidden" name="action" value="toggle_cell_naming">
                        <input type="hidden" name="event_db_id" value="<?= $evId ?>">
                        <button type="submit" style="background:none; border:none; padding:2px 4px; font-size:0.8em; color:#475569; cursor:pointer; text-decoration:underline;">
                            <?= $cellNamingVal === 'numeric' ? 'Mode: 1..N' : 'Mode: A1' ?>
                        </button>
                    </form>
                    <span style="color:#cbd5e1;">&bull;</span>
                    <form method="POST" action="admin.php?tab=events" style="margin:0;">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                        <input type="hidden" name="action" value="toggle_cell_selection">
                        <input type="hidden" name="event_db_id" value="<?= $evId ?>">
                        <button type="submit" style="background:none; border:none; padding:2px 4px; font-size:0.8em; color:#475569; cursor:pointer; text-decoration:underline;">
                            <?= !empty($ev['allow_cell_selection']) ? 'Pick Square: ON' : 'Pick Square: OFF' ?>
                        </button>
                    </form>

                    <?php if ($isSuperAdmin): ?>
                    <span style="color:#cbd5e1;">&bull;</span>
                    <form method="POST" action="admin.php?tab=events" style="margin:0;" onsubmit="return confirm('🚨 PERMANENT EVENT DELETION 🚨\n\nAre you sure you want to completely delete event \'<?= htmlspecialchars(addslashes($ev['event_name'])) ?>\'?\n\nThis will permanently delete:\n- The event itself\n- All sold & unsold squares\n- All audit logs & activity history\n- All associated webhook records\n\nThis action CANNOT be undone!');">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                        <input type="hidden" name="action" value="delete_event">
                        <input type="hidden" name="event_db_id" value="<?= $evId ?>">
                        <button type="submit" style="background:none; border:none; padding:2px 4px; font-size:0.8em; color:#dc2626; cursor:pointer; text-decoration:underline;">
                            🗑️ Delete Event
                        </button>
                    </form>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<!-- MODAL: EDIT EVENT DETAILS -->
<div id="editEventModal" class="modal-backdrop" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.6); z-index:99999; align-items:center; justify-content:center;">
    <div class="modal-box" style="background:#fff; border-radius:12px; width:92%; max-width:540px; max-height:90vh; overflow-y:auto; padding:24px 28px; box-shadow:0 10px 30px rgba(0,0,0,0.4); font-family:-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;">
        <div style="display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid #e2e8f0; padding-bottom:12px; margin-bottom:16px;">
            <h3 style="margin:0; font-size:1.3em; color:#2d5016;">✏️ Edit Event Details</h3>
            <button type="button" onclick="closeEditEventModal()" style="background:none; border:none; font-size:1.5em; cursor:pointer; color:#718096;">&times;</button>
        </div>

        <form method="POST" action="admin.php?tab=events">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
            <input type="hidden" name="action" value="update_event_details">
            <input type="hidden" name="event_db_id" id="edit_event_db_id" value="">

            <div style="margin-bottom:14px;">
                <label style="display:block; font-size:0.85em; font-weight:bold; color:#333; margin-bottom:4px;">Event Name *</label>
                <input type="text" name="event_name" id="edit_event_name" required style="width:100%; padding:9px 12px; border:1.5px solid #ccc; border-radius:6px; font-size:0.95em;">
            </div>

            <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:14px;">
                <div>
                    <label style="display:block; font-size:0.85em; font-weight:bold; color:#333; margin-bottom:4px;">Venue / Location</label>
                    <input type="text" name="event_venue" id="edit_event_venue" style="width:100%; padding:9px 12px; border:1.5px solid #ccc; border-radius:6px; font-size:0.95em;">
                </div>
                <div>
                    <label style="display:block; font-size:0.85em; font-weight:bold; color:#333; margin-bottom:4px;">Start Date &amp; Time</label>
                    <input type="datetime-local" name="event_start" id="edit_event_start" style="width:100%; padding:9px 12px; border:1.5px solid #ccc; border-radius:6px; font-size:0.95em;">
                </div>
            </div>

            <div style="margin-bottom:14px;">
                <label style="display:block; font-size:0.85em; font-weight:bold; color:#333; margin-bottom:4px;">Event Description &amp; Public Notes</label>
                <textarea name="event_description" id="edit_event_description" rows="4" style="width:100%; padding:9px 12px; border:1.5px solid #ccc; border-radius:6px; font-size:0.92em; font-family:inherit; line-height:1.4;"></textarea>
            </div>

            <div style="margin-bottom:14px;">
                <label style="display:block; font-size:0.85em; font-weight:bold; color:#333; margin-bottom:4px;">Pricing Tiers (Qty:Price, comma-separated)</label>
                <input type="text" name="pricing_tiers" id="edit_pricing_tiers" placeholder="1:10, 3:25, 5:40, 10:70" style="width:100%; padding:9px 12px; border:1.5px solid #ccc; border-radius:6px; font-size:0.95em;">
                <small style="color:#64748b; font-size:0.8em;">Example: <code>1:10, 3:25, 5:40, 10:70</code></small>
            </div>

            <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:18px;">
                <div>
                    <label style="display:block; font-size:0.85em; font-weight:bold; color:#333; margin-bottom:4px;">Square Numbering</label>
                    <select name="cell_naming" id="edit_cell_naming" style="width:100%; padding:9px 12px; border:1.5px solid #ccc; border-radius:6px; font-size:0.92em;">
                        <option value="alpha">Grid References (A1, D2, G4)</option>
                        <option value="numeric">Sequential Numbers (1, 16, 43)</option>
                    </select>
                </div>
                <div style="display:flex; align-items:center; padding-top:20px;">
                    <label style="font-size:0.85em; font-weight:bold; color:#333; cursor:pointer;">
                        <input type="checkbox" name="allow_cell_selection" id="edit_allow_cell_selection" value="1" style="margin-right:6px;">
                        Allow Buyers to Pick Square
                    </label>
                </div>
            </div>

            <div style="text-align:right; border-top:1px solid #e2e8f0; padding-top:14px;">
                <button type="button" onclick="closeEditEventModal()" style="background:#e2e8f0; color:#4a5568; border:none; padding:9px 16px; border-radius:6px; font-weight:bold; cursor:pointer; margin-right:8px;">Cancel</button>
                <button type="submit" style="background:#2d5016; color:#fff; border:none; padding:9px 20px; border-radius:6px; font-weight:bold; cursor:pointer;">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<?php if ($canCreate): ?>
<!-- MODAL: CREATE NEW EVENT -->
<div id="createEventModal" class="modal-backdrop" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.6); z-index:99999; align-items:center; justify-content:center;">
    <div class="modal-box" style="background:#fff; border-radius:12px; width:92%; max-width:540px; max-height:90vh; overflow-y:auto; padding:24px 28px; box-shadow:0 10px 30px rgba(0,0,0,0.4); font-family:-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;">
        <div style="display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid #e2e8f0; padding-bottom:12px; margin-bottom:16px;">
            <h3 style="margin:0; font-size:1.3em; color:#2d5016;">🐮 Create New Bingo Event</h3>
            <button type="button" onclick="closeCreateEventModal()" style="background:none; border:none; font-size:1.5em; cursor:pointer; color:#718096;">&times;</button>
        </div>

        <form method="POST" action="admin.php?tab=events">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
            <input type="hidden" name="action" value="create_event">

            <div style="margin-bottom:14px;">
                <label style="display:block; font-size:0.85em; font-weight:bold; color:#333; margin-bottom:4px;">Event Name *</label>
                <input type="text" name="event_name" required placeholder="e.g. 2026 Spring Fair Cow Pat Bingo" style="width:100%; padding:9px 12px; border:1.5px solid #ccc; border-radius:6px; font-size:0.95em;">
            </div>

            <div style="margin-bottom:14px;">
                <label style="display:block; font-size:0.85em; font-weight:bold; color:#333; margin-bottom:4px;">Hi.Events Event ID (Optional)</label>
                <input type="text" name="hievents_event_id" placeholder="e.g. 42 (leave blank for offline / cash-only game)" style="width:100%; padding:9px 12px; border:1.5px solid #ccc; border-radius:6px; font-size:0.95em;">
            </div>

            <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:14px;">
                <div>
                    <label style="display:block; font-size:0.85em; font-weight:bold; color:#333; margin-bottom:4px;">Venue</label>
                    <input type="text" name="event_venue" placeholder="e.g. Main Paddock" style="width:100%; padding:9px 12px; border:1.5px solid #ccc; border-radius:6px; font-size:0.95em;">
                </div>
                <div>
                    <label style="display:block; font-size:0.85em; font-weight:bold; color:#333; margin-bottom:4px;">Start Date</label>
                    <input type="datetime-local" name="event_start" style="width:100%; padding:9px 12px; border:1.5px solid #ccc; border-radius:6px; font-size:0.95em;">
                </div>
            </div>

            <div style="margin-bottom:14px;">
                <label style="display:block; font-size:0.85em; font-weight:bold; color:#333; margin-bottom:4px;">Description &amp; Payment Details</label>
                <textarea name="event_description" rows="3" placeholder="Location, map link, bank transfer instructions..." style="width:100%; padding:9px 12px; border:1.5px solid #ccc; border-radius:6px; font-size:0.92em; font-family:inherit;"></textarea>
            </div>

            <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:14px;">
                <div>
                    <label style="display:block; font-size:0.85em; font-weight:bold; color:#333; margin-bottom:4px;">Grid Columns</label>
                    <input type="number" name="grid_cols" value="16" min="2" max="50" required style="width:100%; padding:9px 12px; border:1.5px solid #ccc; border-radius:6px; font-size:0.95em;">
                </div>
                <div>
                    <label style="display:block; font-size:0.85em; font-weight:bold; color:#333; margin-bottom:4px;">Grid Rows</label>
                    <input type="number" name="grid_rows" value="16" min="2" max="50" required style="width:100%; padding:9px 12px; border:1.5px solid #ccc; border-radius:6px; font-size:0.95em;">
                </div>
            </div>

            <div style="margin-bottom:14px;">
                <label style="display:block; font-size:0.85em; font-weight:bold; color:#333; margin-bottom:4px;">Pricing Tiers (Qty:Price)</label>
                <input type="text" name="pricing_tiers" placeholder="1:10, 3:25, 5:40, 10:70" style="width:100%; padding:9px 12px; border:1.5px solid #ccc; border-radius:6px; font-size:0.95em;">
            </div>

            <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:18px;">
                <div>
                    <label style="display:block; font-size:0.85em; font-weight:bold; color:#333; margin-bottom:4px;">Numbering Format</label>
                    <select name="cell_naming" style="width:100%; padding:9px 12px; border:1.5px solid #ccc; border-radius:6px; font-size:0.92em;">
                        <option value="alpha">Grid References (A1..P16)</option>
                        <option value="numeric">Sequential Numbers (1..256)</option>
                    </select>
                </div>
                <div style="display:flex; align-items:center; padding-top:20px;">
                    <label style="font-size:0.85em; font-weight:bold; color:#333; cursor:pointer;">
                        <input type="checkbox" name="allow_cell_selection" value="1" checked style="margin-right:6px;">
                        Allow Square Choice
                    </label>
                </div>
            </div>

            <div style="text-align:right; border-top:1px solid #e2e8f0; padding-top:14px;">
                <button type="button" onclick="closeCreateEventModal()" style="background:#e2e8f0; color:#4a5568; border:none; padding:9px 16px; border-radius:6px; font-weight:bold; cursor:pointer; margin-right:8px;">Cancel</button>
                <button type="submit" style="background:#2d5016; color:#fff; border:none; padding:9px 20px; border-radius:6px; font-weight:bold; cursor:pointer;">Create Event</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<script>
function openCreateEventModal() {
    const el = document.getElementById('createEventModal');
    if (el) el.style.display = 'flex';
}
function closeCreateEventModal() {
    const el = document.getElementById('createEventModal');
    if (el) el.style.display = 'none';
}
function closeEditEventModal() {
    const el = document.getElementById('editEventModal');
    if (el) el.style.display = 'none';
}

document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.btn-edit-event').forEach(btn => {
        btn.addEventListener('click', function() {
            try {
                const ev = JSON.parse(this.dataset.event);
                document.getElementById('edit_event_db_id').value = ev.id || '';
                document.getElementById('edit_event_name').value = ev.name || '';
                document.getElementById('edit_event_venue').value = ev.venue || '';
                document.getElementById('edit_event_start').value = ev.start || '';
                document.getElementById('edit_event_description').value = ev.description || '';
                document.getElementById('edit_pricing_tiers').value = ev.pricing_tiers || '';
                
                const namingSelect = document.getElementById('edit_cell_naming');
                if (namingSelect) namingSelect.value = ev.cell_naming || 'alpha';
                
                const pickCheck = document.getElementById('edit_allow_cell_selection');
                if (pickCheck) pickCheck.checked = (parseInt(ev.allow_cell_selection, 10) === 1);

                const modal = document.getElementById('editEventModal');
                if (modal) modal.style.display = 'flex';
            } catch (err) {
                console.error('Error parsing event data for edit modal:', err);
            }
        });
    });
});
</script>
