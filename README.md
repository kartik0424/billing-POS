# QuickPOS — Modern Retail Point of Sale Progressive Web App

A lightweight, mobile-first Point of Sale (POS) Progressive Web App designed for retail shops, cafes, and small businesses. Built with vanilla HTML5, CSS3, Bootstrap 5, vanilla JavaScript (ES6+), Core PHP, and MySQL (PDO).

---

## Table of Contents
1. [Tech Stack & Architecture](#tech-stack--architecture)
2. [Folder Structure](#folder-structure)
3. [XAMPP & Local Server Setup](#xampp--local-server-setup)
4. [Database Import & Schema Migration](#database-import--schema-migration)
5. [Configuration & Default Credentials](#configuration--default-credentials)
6. [HTTPS & Security Requirements](#https--security-requirements)
7. [Thermal ESC/POS Printing System](#thermal-escpos-printing-system)
   - [A. Web Bluetooth (Direct BLE)](#a-web-bluetooth-direct-ble)
   - [B. WiFi / Network Socket (Port 9100)](#b-wifi--network-socket-port-9100)
   - [C. Browser Print Fallback & RawBT Integration](#c-browser-print-fallback--rawbt-integration)
8. [PWA Installation (Android & iOS)](#pwa-installation-android--ios)
9. [Offline Mode & IndexedDB Auto-Sync](#offline-mode--indexeddb-auto-sync)
10. [Security & Production Hardening Checklist](#security--production-hardening-checklist)
11. [Comprehensive End-to-End Testing Checklist](#comprehensive-end-to-end-testing-checklist)

---

## Tech Stack & Architecture

- **Frontend:** Vanilla JavaScript (ES6+ Fetch API), Bootstrap 5.3.3, Bootstrap Icons 1.11.3, Chart.js (CDN), SheetJS / xlsx 0.18.5 (CDN).
- **Backend:** Core PHP 7.4 through 8.4+ (No external frameworks, strictly PDO prepared statements).
- **Database:** MySQL / MariaDB (InnoDB, foreign keys, row-level locks, transactional integrity).
- **Offline Storage:** Service Worker Cache API + IndexedDB (`QuickPOS_OfflineDB`).
- **Hardware Integration:** Web Bluetooth API (BLE ESC/POS) + PHP raw TCP sockets (`fsockopen`).

---

## Folder Structure

```text
/billing
  ├── index.php                 # Landing page & role dispatcher
  ├── login.php                 # Authentication & brute-force protection
  ├── logout.php                # Session destruction & cookie cleanup
  ├── billing.php               # POS terminal (catalog, cart, checkout wizard, pending)
  ├── history.php               # Searchable sales history & reprint receipts
  ├── products.php              # Product catalog CRUD (admin only)
  ├── audit.php                 # Financial dashboard, Chart.js trends, Excel export
  ├── settings.php              # Business details & printer hardware setup (admin only)
  ├── offline.html              # Standalone offline fallback page
  ├── manifest.json             # PWA Web App Manifest
  ├── service-worker.js         # Offline caching & background updates
  ├── database.sql              # Schema, constraints, seed users & products
  ├── README.md                 # System manual & operational checklist
  │
  ├── /api
  │   ├── products.php          # Catalog list, create, update, delete, toggle
  │   ├── bills.php             # Transactional bill creation, idempotent client_uuid
  │   ├── pending.php           # Pending orders & balance clearance
  │   ├── history.php           # Server-side paginated sales records & totals
  │   ├── reports.php           # Audit metrics, time-series, top products, export dataset
  │   ├── settings.php          # Store preferences & hardware configuration
  │   └── print_network.php     # Direct TCP socket thermal printing (fsockopen)
  │
  ├── /includes
  │   ├── db.php                # PDO singleton connection (utf8mb4, +05:30)
  │   ├── auth.php              # Session security & role verification
  │   ├── csrf.php              # CSRF token generation & validation
  │   ├── helpers.php           # Sequential bill numbering, money formatters, sanitizers
  │   ├── header.php            # Shared HTML head, navigation, modals, toasts
  │   └── footer.php            # Bottom navigation bar & global scripts
  │
  └── /assets
      ├── /css
      │   ├── style.css         # Custom tokens, responsive grid, 44px+ touch targets
      │   └── print.css         # Thermal receipt stylesheet (58mm/80mm zero-margin)
      ├── /js
      │   ├── app.js            # API fetch wrapper, toasts, confirm modal, formatINR
      │   ├── billing.js        # Cart state, discount engine, checkout wizard
      │   ├── receipt.js        # Thermal receipt HTML builder
      │   ├── printer.js        # Window.PRINTER: Bluetooth BLE, WiFi socket, browser print
      │   ├── history.js        # Sales pagination, expandable rows, receipt reprint
      │   ├── products.js       # Admin catalog CRUD, search, and category chips
      │   ├── audit.js          # Chart.js visualization & SheetJS 3-sheet Excel export
      │   ├── settings.js       # Settings form handler & validation
      │   └── pwa.js            # Service worker registration, install banner, IndexedDB sync
      └── /icons
          ├── icon.svg          # Master vector PWA icon
          ├── icon-192.png      # 192x192 PNG icon (any and maskable)
          └── icon-512.png      # 512x512 PNG icon (any and maskable)
```

---

## XAMPP & Local Server Setup

### 1. Place Project in `htdocs`
Copy the project folder into your XAMPP web root:
```text
C:\xampp\htdocs\project\billing
```

### 2. Start Apache and MySQL
1. Launch the **XAMPP Control Panel**.
2. Click **Start** for **Apache**.
3. Click **Start** for **MySQL**.

### 3. Verify Database Credentials
Open [`includes/db.php`](file:///c:/xampp/htdocs/project/billing/includes/db.php). The default credentials match standard XAMPP installations:
```php
private const DB_HOST = '127.0.0.1';
private const DB_NAME = 'pos_db';
private const DB_USER = 'root';
private const DB_PASS = '';
private const DB_PORT = '3306';
```
*(If you have set a MySQL root password or are deploying to cPanel/shared hosting, update `DB_NAME`, `DB_USER`, and `DB_PASS` accordingly).*

---

## Database Import & Schema Migration

### 1. Import Initial Database
You can import [`database.sql`](file:///c:/xampp/htdocs/project/billing/database.sql) via **phpMyAdmin** or the command line:

**Via phpMyAdmin:**
1. Open [http://localhost/phpmyadmin](http://localhost/phpmyadmin) in your browser.
2. Click on the **Import** tab.
3. Choose the file `database.sql` from your project folder.
4. Click **Go** (it will create `pos_db` automatically).

**Via MySQL Command Line:**
```bash
mysql -u root -p < c:\xampp\htdocs\project\billing\database.sql
```

### 2. Schema Migration for Idempotency (`client_uuid`)
If upgrading from an earlier version, ensure the `client_uuid` column is present on the `bills` table to support offline sync deduplication:
```sql
USE `pos_db`;
ALTER TABLE `bills` 
ADD COLUMN `client_uuid` VARCHAR(36) NULL UNIQUE AFTER `bill_no`;
```

---

## Configuration & Default Credentials

### Default User Accounts
The seed data creates two default accounts with secure `password_hash` hashes:

| Role | Username | Password | Default Landing Page | Allowed Access |
| :--- | :--- | :--- | :--- | :--- |
| **Admin** | `admin` | `admin123` | `audit.php` | Billing, History, Products, Audit, Settings |
| **Cashier** | `cashier` | `cashier123` | `billing.php` | Billing, History **ONLY** |

> [!WARNING]
> Change these default passwords immediately upon production deployment in the `users` table or via phpMyAdmin using `PASSWORD_BCRYPT`.

---

## HTTPS & Security Requirements

1. **Why HTTPS is Required:**
   - **Service Workers & PWA:** Browsers require HTTPS to register service workers and trigger "Install App" banners (with an exception for `localhost`).
   - **Web Bluetooth API:** Chrome and Edge strictly block `navigator.bluetooth` on plain HTTP.
2. **Local Testing:**
   - On `http://localhost/project/billing/`, browsers treat `localhost` as a secure origin, allowing PWA and Bluetooth testing.
   - For testing on physical phones over local WiFi (e.g. `http://192.168.1.50/...`), you must generate a local SSL certificate using **mkcert** or route traffic through **ngrok** (`ngrok http 80`).
3. **Shared Hosting / Production:**
   - Enable a free SSL certificate (Let's Encrypt / AutoSSL) via your hosting control panel.
   - Enforce HTTPS redirection in `.htaccess`.

---

## Thermal ESC/POS Printing System

QuickPOS supports three independent printing methods configured per-device in **Settings** or via the **Printer** button:

```text
               ┌───────────────────────┐
               │    window.PRINTER     │
               └───────────┬───────────┘
         ┌─────────────────┼─────────────────┐
         ▼                 ▼                 ▼
 ┌───────────────┐ ┌───────────────┐ ┌───────────────┐
 │   Bluetooth   │ │  WiFi Socket  │ │    Browser    │
 │   (Web BLE)   │ │  (Port 9100)  │ │ (window.print)│
 └───────────────┘ └───────────────┘ └───────────────┘
```

### A. Web Bluetooth (Direct BLE)
- **Supported Hardware:** 58mm & 80mm portable Bluetooth thermal receipt printers (ESC/POS compatible).
- **Browser Support:** Google Chrome or Microsoft Edge on Android, Windows 10/11, or ChromeOS. *(Note: iOS Safari does not support the Web Bluetooth API).*
- **How to Pair & Print:**
  1. Turn on your Bluetooth thermal printer and enable Bluetooth on your phone/tablet.
  2. In QuickPOS, click the **Printer** button in the top navigation bar.
  3. Select **Bluetooth**, then tap **Connect Printer**.
  4. Select your printer name from the browser pairing modal (e.g., `MTP-2`, `RPP02N`, `POS-58`).
  5. Once connected, tap **Send Test Print**.
  6. Subsequent prints transmit data in chunked packets (120 bytes with 25ms delay) to prevent buffer overruns.

### B. WiFi / Network Socket (Port 9100)
- **Supported Hardware:** Epson, Star, TVS, Rongta, Xprinter, or POS-80 LAN/WiFi receipt printers with an RJ45 Ethernet port or WiFi module.
- **Network Requirement:** The web server running PHP and the printer must be on the same local subnet (e.g., `192.168.1.x`).
- **How to Configure:**
  1. Print your printer's self-test page (hold the FEED button while powering on) to obtain its assigned IP address (e.g., `192.168.1.100`).
  2. Navigate to **Settings** (`settings.php`) as an admin.
  3. Enter the IP address in **Printer Static IP Address** and set the port to `9100`.
  4. Click **Save Settings**.
  5. Click **Printer Setup** &rarr; select **WiFi / LAN** &rarr; click **Send Test Print**.
  6. Receipts are generated as raw ESC/POS binary bytes on the server and piped directly to the printer socket via `fsockopen()`.

### C. Browser Print Fallback & RawBT Integration
- **Universal Fallback:** Works on any desktop or mobile browser without drivers.
- **Print Stylesheet:** Uses [`assets/css/print.css`](file:///c:/xampp/htdocs/project/billing/assets/css/print.css) to isolate `#printableThermalReceipt`, hide all UI, set margins to 0, and match 58mm/80mm roll dimensions.
- **RawBT Thermal Driver (Android):**
  If using an OTG USB printer or Bluetooth printer without Web Bluetooth, install the free **RawBT Print Service** app from the Google Play Store:
  1. Install RawBT and pair your thermal printer inside RawBT.
  2. In QuickPOS, select **Browser** print method.
  3. When the Android print dialog opens, select **RawBT Virtual Printer** to output to your thermal paper roll.

---

## PWA Installation (Android & iOS)

### Installing on Android (Chrome / Edge)
1. Open the website URL in Google Chrome.
2. Tap the **Install** button that appears in the top navigation bar, or open the Chrome 3-dots menu &rarr; tap **Add to Home screen** / **Install app**.
3. QuickPOS installs as a standalone application without browser address bars, functioning full-screen.

### Installing on iOS (Apple Safari)
1. Open the website in **Safari** on your iPhone or iPad.
2. Tap the **Share** button (box with an arrow pointing up).
3. Scroll down and tap **Add to Home Screen**.
4. Tap **Add** in the top right corner. The app launches in standalone portrait mode.

---

## Offline Mode & IndexedDB Auto-Sync

When internet connectivity is lost, QuickPOS transitions into **Offline Queue Mode**:
1. An offline banner appears: `You are currently offline. Local changes will sync when connected.`
2. Cashiers can continue taking orders and tapping **Generate Bill**.
3. The bill payload is assigned a cryptographic UUIDv4 (`client_uuid`) and stored in the local browser IndexedDB (`QuickPOS_OfflineDB`).
4. An alert informs the cashier: `Saved to Offline Queue. This bill is stored safely on your device and will auto-sync when reconnected.`
5. The topbar displays a badge: `X bill(s) queued`.
6. When the device reconnects to WiFi or mobile data:
   - The `online` event fires automatically.
   - `window.PWA.syncQueue()` iterates through the queued bills and dispatches them to `api/bills.php?action=create`.
   - The server verifies the `client_uuid` so that repeated sync attempts never result in duplicate bills (idempotent).
   - Once confirmed, bills are cleared from IndexedDB and a success toast notification appears.

---

## Security & Production Hardening Checklist

- [x] **Server-side Role Protection:** Every PHP view and API endpoint calls `require_role('admin')` or `require_role(['admin', 'cashier'])`. Cashiers are physically blocked by the server with HTTP 403, not just hidden menus.
- [x] **Brute-Force Lockout:** `login.php` enforces a lockout after 5 failed login attempts per session/IP with an exponential delay.
- [x] **CSRF Token Verification:** All POST/mutating endpoints call `require_csrf()`, checking HTTP headers (`X-CSRF-Token`), form POST, and JSON payloads.
- [x] **XSS Output Escaping:** All dynamic output in PHP templates is escaped with `htmlspecialchars($str, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')` via the `e()` helper.
- [x] **Strict PDO Parameterization:** All queries use prepared statements with bound parameters (`:param`). No raw SQL interpolation.
- [x] **Financial Recalculation:** The server never trusts client-supplied product prices or line totals. Prices are looked up from the `products` table, discounts re-verified, and totals computed server-side.
- [x] **Concurrent Bill Numbering:** `generate_bill_no()` uses row-level locking (`SELECT ... FOR UPDATE`) inside database transactions to prevent duplicate bill numbers during high concurrency.
- [x] **Secure Sessions:** Sessions are configured with `cookie_httponly = true`, `cookie_samesite = 'Lax'`, and `use_strict_mode = true`.

---

## Comprehensive End-to-End Testing Checklist

Follow this systematic testing sequence to verify all POS modules:

### 1. Authentication & Role Enforcement
- [ ] Log in as `cashier` / `cashier123`.
- [ ] Verify cashier is automatically redirected to `billing.php`.
- [ ] Verify cashier sees only **Billing** and **History** in desktop nav and mobile bottom nav.
- [ ] Manually visit `http://localhost/project/billing/products.php` as cashier &rarr; Verify access is denied with HTTP 403.
- [ ] Manually visit `http://localhost/project/billing/audit.php` as cashier &rarr; Verify access is denied with HTTP 403.
- [ ] Manually visit `http://localhost/project/billing/settings.php` as cashier &rarr; Verify access is denied with HTTP 403.
- [ ] Log out and log in as `admin` / `admin123` &rarr; Verify redirection to `audit.php` and access to all 5 modules.

### 2. Product Management (Admin)
- [ ] In `products.php`, search for an item (e.g. "Cold Coffee"). Verify instant live filtering.
- [ ] Click category chips (Beverages, Snacks, Bakery) to filter products.
- [ ] Click **Add Product**, enter Name: `Hazelnut Latte`, Price: `180.00`, Category: `Beverages`. Save and verify toast.
- [ ] Click **Edit** on `Hazelnut Latte`, change price to `190.00`. Save and verify table updates.
- [ ] Click the **Active / Inactive** toggle switch &rarr; Verify product status toggles immediately.
- [ ] Click **Delete** &rarr; Confirm in modal dialog &rarr; Verify soft deletion (`is_deleted = 1`).

### 3. POS Terminal & Cart Calculations
- [ ] Navigate to `billing.php`.
- [ ] Tap a product card to add to cart. Tap multiple times to increment quantity.
- [ ] Use the `+` and `-` quantity steppers. Verify that decrementing at quantity 1 prompts or removes the item.
- [ ] Click **Add Custom Product**, enter Name: `Special Service`, Price: `250.00`, Qty: `1`. Verify it displays in the cart with a "Custom" badge.
- [ ] Apply **Flat Discount** of ₹50 &rarr; Verify subtotal, discount, and grand total recalculate immediately.
- [ ] Switch to **Percent Discount** of 10% &rarr; Verify discount is ₹(Subtotal * 0.10).
- [ ] Refresh the page &rarr; Verify cart state persists from `localStorage`.

### 4. Full Payment Checkout & Thermal Receipt
- [ ] In cart, click **Proceed to Checkout**.
- [ ] Leave customer name blank or enter `Rahul`, leave advance blank (full payment).
- [ ] Click **Next: Payment Mode** &rarr; Select **Cash**.
- [ ] Click **Generate Bill** &rarr; Verify receipt preview modal appears with bill number, items, and totals.
- [ ] Click **Print Receipt** &rarr; Verify `window.PRINTER.print()` is invoked.
- [ ] Verify the cart resets automatically for the next customer.

### 5. Advance Payment & Pending Balance Clearance
- [ ] Add items totaling ₹500 to cart &rarr; Proceed to Checkout.
- [ ] Enter Advance Amount: `200.00` while leaving Customer Name and Phone blank &rarr; Click **Next: Payment Mode** &rarr; Verify error prompt "Customer Name is compulsory when an advance amount is entered." and field highlights in red.
- [ ] Enter Customer Name: `Amit Kumar`, leave Phone blank &rarr; Click **Next: Payment Mode** &rarr; Verify error prompt "Customer Contact / Phone number is compulsory when an advance amount is entered."
- [ ] Enter valid Phone: `9876543210`.
- [ ] Click **Next: Payment Mode** &rarr; Select payment method: **UPI**.
- [ ] Click **Generate Bill** &rarr; Verify bill status is `pending` with `advance_paid = 200` and `balance_due = 300`.
- [ ] On desktop, check **Pending Bills** (Column 3) or mobile **Pending** tab &rarr; Verify Amit Kumar's card appears with `₹300.00 Due` highlighted in red along with customer name and contact number.
- [ ] Click **Paid** on the card &rarr; Open **Mark Paid** modal &rarr; Choose **Cash** &rarr; Confirm.
- [ ] Verify bill disappears from pending list and updates to `completed` with `balance_due = 0`.

### 6. Mixed Payment Split Validation
- [ ] Checkout a bill of ₹400 &rarr; Select payment method: **Mixed**.
- [ ] Enter UPI: `250` &rarr; Verify Cash automatically calculates to `150`.
- [ ] Intentionally enter UPI: `200` and Cash: `100` (Total 300 != 400) &rarr; Verify "Remaining to allocate: ₹100.00" appears in red and **Generate Bill** is blocked.

### 7. Sales History & Receipt Reprinting
- [ ] Navigate to `history.php`.
- [ ] Search for customer phone or bill number &rarr; Verify instant results.
- [ ] Click quick date chips: **Today**, **Yesterday**, **This Week**.
- [ ] Click the chevron down to expand desktop row details &rarr; Inspect individual item line totals and payment records.
- [ ] Click **Reprint** &rarr; Verify receipt preview opens and prints through `window.PRINTER.print()`.

### 8. Audit Dashboard & Excel Export
- [ ] Navigate to `audit.php` (Admin only).
- [ ] Verify summary cards display: Total Sales, Total Collected (Cash vs UPI), Total Bills, Total Discount, and Pending Receivables.
- [ ] Hover over the Chart.js trends chart &rarr; Verify tooltip values format in Indian Rupees (`₹`).
- [ ] Toggle chart datasets: **All**, **Sales**, **Cash**, **UPI**.
- [ ] Check **Top 10 Products** table &rarr; Verify top-selling items ranked by revenue.
- [ ] Click **Export to Excel** &rarr; Wait for SheetJS generation &rarr; Download `sales_report_YYYY-MM-DD_to_YYYY-MM-DD.xlsx`.
- [ ] Open the `.xlsx` file in Excel or Google Sheets &rarr; Verify 3 sheets:
  - **Summary:** Financial KPIs and audit metadata.
  - **Sales:** All bill transactions with frozen header, currency formatting (`#,##0.00`), and auto-fitted columns.
  - **Pending Customers:** Outstanding receivables ledger.

### 9. Offline PWA & Queue Synchronization
- [ ] Open Chrome DevTools &rarr; Network tab &rarr; Check **Offline** (or disconnect your WiFi).
- [ ] Notice the top status banner: `You are currently offline. Local changes will sync when connected.`
- [ ] Add items to cart and tap **Proceed to Checkout** &rarr; **Generate Bill**.
- [ ] Verify that QuickPOS does not fail; instead, it saves the transaction to IndexedDB with a client UUID.
- [ ] Notice the badge in the topbar: `1 bill queued`.
- [ ] Uncheck **Offline** in Chrome DevTools (reconnect internet).
- [ ] Verify toast appears: `Internet connection restored. Synchronizing...` followed by `Bill synced: INV-0000XX`.
- [ ] Check `history.php` &rarr; Verify the queued bill was recorded in the MySQL database without duplicates.

---

## License
MIT License. Open-source for personal and commercial point-of-sale deployments.
