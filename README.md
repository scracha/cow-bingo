# 🐄 Cow Bingo (Multi-Event)

PHP-based "Cow Bingo" game grid integrated with a self-hosted hi.events server.

Supports multiple simultaneous cow bingo events, each with its own grid size, cell assignments, and per-event admin accounts.

## How It Works

1. You create a cow bingo event in the admin panel, linking it to a hi.events event ID
2. Someone buys a ticket on hi.events
3. hi.events fires a webhook to webhook.php
4. The webhook looks up which cow bingo game the event belongs to
5. A random available cell (e.g. C13) is assigned to that ticket
6. The public grid page shows sold/available cells with hover tooltips
7. On event day, assign prizes to winning cells via the admin panel


![Alt text](/screenshots/admin_screenshot.png?raw=true "Screenshot")
![Alt text](/screenshots/grid_screenshot.png?raw=true "Screenshot")



## Files

| File | Purpose |
|------|---------|
| `config.php` | Database credentials and hi.events API settings (gitignored) |
| `config.example.php` | Template for config.php |
| `admin.php` | Admin panel with login, user management, events, prizes (gitignored) |
| `admin.example.php` | Template for admin.php |
| `schema.sql` | MariaDB table definitions |
| `webhook.php` | Receives hi.events webhooks, assigns cells per event |
| `grid.php` | Public grid — event list or specific event grid with search |
| `cow_poo_2.svg` | Animated cow pat SVG for winner cells |
| `.htaccess` | Blocks direct access to config/schema files |

## URLs

- **Grid (event list):** `/cow-bingo/grid.php`
- **Grid (specific event):** `/cow-bingo/grid.php?event=HIEVENTS_EVENT_ID`
- **Admin:** `/cow-bingo/admin.php`
- **Webhook:** `/cow-bingo/webhook.php`

## Setup

### 1. Install dependencies

```bash
sudo apt install mariadb-server php-mysql php-curl
sudo systemctl start mariadb
sudo systemctl enable mariadb
```

### 2. Create the database

```bash
sudo mariadb < schema.sql
```

Or paste the contents of `schema.sql` into your MariaDB client.

### 3. Create the DB user

```bash
sudo mariadb -e "
CREATE USER 'cowbingo'@'localhost' IDENTIFIED BY 'YOUR_PASSWORD';
GRANT ALL PRIVILEGES ON cow_bingo.* TO 'cowbingo'@'localhost';
FLUSH PRIVILEGES;"
```

### 4. Create your super admin account

```bash
sudo mariadb cow_bingo -e "INSERT INTO cow_bingo_admins (username, password_hash, display_name, is_super_admin) VALUES ('admin', '$(php -r "echo password_hash(\"YOUR_PASSWORD\", PASSWORD_DEFAULT);")', 'Your Name', 1);"
```

### 5. Configure

```bash
cp config.example.php config.php
nano config.php
```

Update DB credentials and hi.events API details.

### 6. Set permissions

```bash
sudo chown -R youruser:www-data /path/to/cow-bingo
sudo chmod 640 config.php
```

### 7. Set up the webhook on hi.events

Point it to `https://your-server.com/cow-bingo/webhook.php` with events:
- `order.created`
- `order.marked_as_paid`
- `attendee.created`

### 8. Create your first event

Log into the admin panel and create a cow bingo game linked to your hi.events event ID.

## Admin Accounts

There are two roles:

- **Super Admin** — can manage all events, create/delete admin users, view webhook logs, create events
- **Event Admin** — can only see and manage events they've been assigned to (assign prizes, manual cells, sync attendees)

Create event admins via the admin panel under "Manage Admins".

## Reset Super Admin Password

If you get locked out, reset from the command line:

```bash
sudo mariadb cow_bingo -e "DELETE FROM cow_bingo_admins WHERE username='admin'; INSERT INTO cow_bingo_admins (username, password_hash, display_name, is_super_admin) VALUES ('admin', '$(php -r "echo password_hash(\"NEW_PASSWORD\", PASSWORD_DEFAULT);")', 'Admin', 1);"
```

Replace `NEW_PASSWORD` with your desired password.

## Grid Features

- Colour-coded cells: green (available), orange (sold), gold (winner)
- Hover tooltips showing ticket ID, buyer name, and prize
- "Find my cells" search bar — highlights matching cells by name or ticket ID
- "Buy More Tickets" link back to hi.events
- Winners summary section at the bottom
- Animated cow poo SVG on winner cells
