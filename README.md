# 🐄 Cow Bingo (Multi-Event)

PHP-based "Cow Bingo" game grid integrated with a self-hosted hi.events server.

Supports multiple simultaneous cow bingo events, each with its own grid size, cell assignments, and per-event admin accounts.

## How It Works

1. You create a cow bingo event in the admin panel, linking it to a hi.events event ID (or run it offline)
2. Someone buys a ticket online or in-field at an iPad POS station
3. Webhooks or POS transactions assign a cell from the paddock
4. The public grid page displays sold/available cells with live search
5. On event day, assign prizes to winning cells via the admin panel

## Admin Roles

There are three roles:

- **Super Admin** — manages all events, creates/deletes admins and sellers, accesses webhook debug logs
- **Event Admin** — scoped to their assigned events. Can assign prizes, manually allocate cells, trigger attendee syncs, and create/manage iPad / Cash Sellers for their events
- **iPad / Cash Seller** — field devices locked to `pos.php` for selling squares on site with instant collision protection

## URLs

- **Grid (event list):** `/cow-bingo/grid.php`
- **Grid (specific event):** `/cow-bingo/grid.php?event=HIEVENTS_EVENT_ID` or `/cow-bingo/grid.php?id=LOCAL_ID`
- **Admin:** `/cow-bingo/admin.php`
- **iPad POS:** `/cow-bingo/pos.php`
- **Webhook:** `/cow-bingo/webhook.php`
