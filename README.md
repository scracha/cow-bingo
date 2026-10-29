# 🐄 Cow Pat Bingo (Multi-Event)

PHP-based **Cow Pat Bingo** visual game grid integrated with a self-hosted [hi.events](https://hi.events) server or run completely standalone/offline for fairs, galas, and school fundraisers.

Supports multiple simultaneous events, custom grid dimensions, visual square selection, Android & iPad mobile field selling, automated multi-ticket package discounting, treasurer cash/online reconciliation, and detailed audit trails.

---

## 📋 Features

- **Visual Live Grid:** Real-time paddock view showing sold, available, and winning squares with live name/ticket search and animated cow pats.
- **Dual Selling Channels:**
  - **Online:** Instant square assignment via Hi.Events webhooks on completed ticket purchases.
  - **In-Field POS (`pos.php`):** Responsive interface optimized for Android phones and iPads with zero horizontal scrolling. Supports tap-to-pick or quick random allocations (`+1`, `+3`, `+5`, `+10`) with bulk package discount calculations and collision protection.
- **Offline & Standalone Operation:** Create games without Hi.Events. Add custom event descriptions, Google Maps links, and direct bank transfer instructions for buyers.
- **Square Choice vs. Random Locking:** Toggle between open paddock square picking and locked random allocation at any time.
- **Treasurer Reconciliation & Auditing:**
  - High-level KPI cards for Physical Cash in Envelopes vs. Online Gateway Revenue.
  - Per-seller / per-channel cash envelope breakdown with variance checking.
  - Detailed bookings table showing multi-ticket batch discounts.
  - Excel-friendly UTF-8 BOM CSV export structured specifically for accountants.
- **Permanent Audit Trail:** Full activity log capturing who deleted squares, who marked tickets paid, prize awards, and privacy purges.
- **Official Print Sign-Off:** Print-ready judges' clipboard sheets for scrutinizing the drop on event day.
- **NZ Privacy Act Compliance:** One-click contact purging to erase customer emails and phone numbers post-event while retaining square coordinates and accounting totals.

---

## 👥 User Roles & Permissions

| Role | Access Level | Description |
|---|---|---|
| **Super Admin** | Full Access | Manages all games, creates and deletes administrators and sellers, accesses raw webhook logs, and oversees tenant settings. |
| **Event Admin** | Scoped Access | Creates and manages their own events, customizes event descriptions/locations, assigns prizes, manually allocates squares, triggers Hi.Events attendee syncs, creates iPad/phone sellers, and accesses their event audit trails. |
| **Field Seller** | POS Only (`pos.php`) | Field runners locked to the mobile cash selling interface. Cannot access admin settings, logs, or other events. |

---

## 🌐 Application URLs

* **Public Events Index:** `/cow-bingo/grid.php`
* **Public Visual Grid:** `/cow-bingo/grid.php?id=EVENT_ID`
* **Admin Panel:** `/cow-bingo/admin.php`
* **Mobile / iPad POS:** `/cow-bingo/pos.php`
* **Hi.Events Webhook Endpoint:** `/cow-bingo/webhook.php`

---

## 🚀 Installation & Setup

### 1. Requirements
- PHP 8.1+ with PDO, PDO_MySQL, and cURL extensions enabled
- MariaDB 10.3+ or MySQL 8.0+
- Web server (Apache, Nginx, or Caddy) with URL rewrite or standard directory support

### 2. Configuration
Copy the example configuration file and enter your database and Hi.Events credentials:
```bash
cp config.example.php config.php
```
Edit `config.php`:
```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'cow_bingo');
define('DB_USER', 'cowbingo');
define('DB_PASS', 'your_secure_password');

define('HIEVENTS_BASE_URL', 'https://events.yourdomain.com');
define('HIEVENTS_EMAIL', 'admin@yourdomain.com');
define('HIEVENTS_PASSWORD', 'your_api_password');

define('SITE_TITLE', '🐄 St Patrick\'s Cow Pat Bingo 🐄');
```

### 3. Database Migration
Import the database schema into your MariaDB instance:
```bash
mysql -u cowbingo -p cow_bingo < schema.sql
```
*(All future schema additions automatically self-migrate when logging into `admin.php`).*

### 4. Default Super Admin Setup
The initial admin credentials installed by `schema.sql` are:
* **Username:** `admin`
* **Password Hash:** Placeholder (must be reset before first login)

To set your super admin password, run this one-line command on your server:
```bash
php -r "echo password_hash('YourSecurePasswordHere', PASSWORD_DEFAULT);"
```
Then update MariaDB:
```sql
UPDATE cow_bingo_admins SET password_hash = 'OUTPUT_HASH_HERE' WHERE username = 'admin';
```

---

## 🔗 Hi.Events Webhook Configuration

In your self-hosted **hi.events** dashboard:
1. Navigate to **Event Settings > Webhooks > Add Webhook**.
2. **Payload URL:** `https://your-server.com/cow-bingo/webhook.php`
3. **Triggers:** Select:
   - `Order Completed` / `Order Created`
   - `Order Marked as Paid`
   - `Order Cancelled` / `Order Refunded`
   - `Attendee Created` / `Attendee Cancelled`

---

## 📱 Mobile & Tablet Field POS Setup

1. Log into `admin.php` and open the **👥 Admins & iPads** tab.
2. Create a seller account (e.g. Username: `phone1`, Display Name: `Phone 1 - Red`, Role: `iPad / Cash Seller`).
3. On the seller's Android phone or iPhone:
   - Open Chrome or Safari and go to `https://your-server.com/cow-bingo/admin.php`.
   - Log in. The screen will automatically lock to `pos.php`.
   - **Full-Screen Kiosk:** Tap **Add to Home Screen**.
   - **Locking:** Enable **Guided Access** (iOS) or **App Pinning** (Android) to keep volunteers locked to the sales screen.

---

## 🛡️ Security Architecture

* **Session Fixation Defense:** Full session ID rotation (`session_regenerate_id(true)`) executed immediately upon privilege escalation during authentication.
* **Cross-Site Request Forgery (CSRF):** Cryptographic token verification on every administrative and point-of-sale state change.
* **Strict Multi-Tenant Scoping:** Event Admins are strictly scoped to their assigned events (`canManageEvent`), preventing cross-tenant leakage of cell records, buyer contacts, or audit trails.
* **Brute-Force Lockout:** Exponential IP-based rate limiting on failed administrative login attempts.
* **No Public DDL Execution:** Public-facing visitors on `grid.php` execute standard read-only queries with zero structural database locking overhead.
