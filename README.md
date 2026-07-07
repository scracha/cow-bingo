# 🐄 Cow Bingo (Multi-Event)

PHP-based "Cow Bingo" game grid integrated with a self-hosted hi.events server.

Supports multiple simultaneous cow bingo events, each with its own grid size and cell assignments.

## How It Works

1. You create a cow bingo event in the admin panel, linking it to a hi.events event ID
2. Someone buys a ticket on hi.events
3. hi.events fires a webhook to webhook.php
4. The webhook looks up which cow bingo game the event belongs to
5. A random available cell (e.g. C13) is assigned to that ticket
6. The public grid page shows sold/available cells with hover tooltips

## Files

| File | Purpose |
|------|---------|
| `config.php` | Database credentials and settings |
| `schema.sql` | MariaDB table definitions |
| `webhook.php` | Receives hi.events webhooks, assigns cells per event |
| `grid.php` | Public grid — shows event list or specific event grid |
| `admin.php` | Admin panel — create events, manage cells, view logs |
| `.htaccess` | Blocks direct access to config/schema files |

## URLs

- **Grid (event list):** `/cow-bingo/grid.php`
- **Grid (specific event):** `/cow-bingo/grid.php?event=HIEVENTS_EVENT_ID`
- **Admin:** `/cow-bingo/admin.php`
- **Webhook:** `/cow-bingo/webhook.php`

## Setup

1. Install MariaDB: `sudo apt install mariadb-server`
2. Run `schema.sql` to create the database and tables
3. Edit `config.php` with your DB credentials
4. Set file permissions: `sudo chown -R www-data:www-data /path/to/cow-bingo`
5. Set up webhook in hi.events pointing to your webhook.php URL
6. Create your first event via the admin panel
