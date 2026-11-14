# sumthin - Modern Tech Gadget Online Store

> **BMIT2013 Web-Based Integrated Systems Course Assignment**  
> Built strictly with vanilla PHP 8.2.12+, MySQL (PDO), HTML5, CSS3, and jQuery 3.7.1.  
> No external frameworks, no Composer, no template engines, and no inline JavaScript.

---

## 1. System Requirements & Environment

- **Operating System:** Windows 10/11
- **Web Stack:** XAMPP (Apache 2.4+ and MariaDB / MySQL 10.4+)
- **PHP Version:** PHP 8.2.12 or higher (Extensions required: `pdo_mysql`, `curl`, `openssl`, `mbstring`)
- **Default Port:** `80` (HTTP) / `3306` (MySQL)
- **Root Path:** `C:\xampp\htdocs\sumthin\`

---

## 2. Installation & Setup Guide

### Step 1: Place Project Files
Clone or place the project files directly in your XAMPP web root:
```text
C:\xampp\htdocs\sumthin\
```

### Step 2: Database Initialization
1. Start **Apache** and **MySQL** from the XAMPP Control Panel.
2. Open your web browser and navigate to **phpMyAdmin**: [http://localhost/phpmyadmin/](http://localhost/phpmyadmin/).
3. Click on the **Import** tab at the top.
4. Select the database script file located at:
   ```text
   C:\xampp\htdocs\sumthin\database\sumthin_db.sql
   ```
5. Click **Import** (or **Go**). The script will automatically drop and recreate the database `sumthin_db` with all relational tables and realistic seed data.

### Step 3: Application Configuration
Open `_config.php` in a code editor and verify database and URL settings:
```php
'app' => [
    'name'     => 'sumthin',
    'base_url' => 'http://localhost/sumthin/',
    'debug'    => false, // Set to true only during development
],
'db' => [
    'host'     => 'localhost',
    'database' => 'sumthin_db',
    'username' => 'root',
    'password' => '',
    'charset'  => 'utf8mb4',
],
```

---

## 3. Demo Evaluation Accounts

All accounts are pre-seeded in `database/sumthin_db.sql` with verified email addresses and bcrypt hashes.

| Role | Email Address | Password | Description / Status |
| :--- | :--- | :--- | :--- |
| **Admin** | `admin@sumthin.com` | `Admin123!` | Store Manager (Access to `/admin/`) |
| **Member** | `alice@gmail.com` | `Member123!` | Verified Customer (Has order history & review eligibility) |
| **Member** | `bob@gmail.com` | `Member123!` | Verified Customer |
| **Member** | `charlie@gmail.com` | `Member123!` | Verified Customer |
| **Member** | `oliver@gmail.com` | `Member123!` | **Blocked Account** (Testing immediate revocation) |

---

## 4. Gmail SMTP Setup (Real Email Sending)

The application uses **PHPMailer** (located in `/lib/phpmailer/`) via standard SMTP STARTTLS (port 587) with App Passwords.

### Generating a Google App Password:
1. Log in to your Google Account at [https://myaccount.google.com/](https://myaccount.google.com/).
2. Navigate to **Security** $\rightarrow$ **2-Step Verification** (must be enabled).
3. Scroll down to **App passwords** (or search "App passwords" in the top bar).
4. Enter an app name (e.g. `sumthin-store`) and click **Create**.
5. Copy the generated 16-character password (e.g. `abcd efgh ijkl mnop`).

### Configuring `_config.php`:
Update the `mail` section in `_config.php`:
```php
'mail' => [
    'enabled'      => true,
    'host'         => 'smtp.gmail.com',
    'port'         => 587,
    'username'     => 'your-gmail@gmail.com',
    'password'     => 'abcdefghijklmnop', // Your 16-character App Password (no spaces)
    'from_email'   => 'no-reply@sumthin.com',
    'from_name'    => 'sumthin Store',
    'debug'        => false,
],
```
*Note: If `mail.enabled` is `false` or SMTP credentials fail, the system automatically falls back to recording all outbound emails in the `email_log` database table so functionality remains testable offline.*

---

## 5. Security & Architectural Features

- **Authentication & Authorization:** Strict role-based access control (`auth('Admin')` / `auth('Member')`) with immediate block revocation on every request.
- **CSRF Defense:** Cryptographic CSRF token validation on every `POST` request (`csrf_field()` / `verify_csrf()`).
- **SQL Injection Defense:** 100% PDO prepared statements throughout all queries with strict parameter binding.
- **XSS Prevention:** Context-aware escaping using `encode()` / `e()` on all HTML outputs.
- **Inventory Concurrency Protection:** High-concurrency `SELECT ... FOR UPDATE` row-level locks during order placement and atomic inventory decrement.
- **Voucher Hardening:** Storing only code references in session, re-validating eligibility inside DB transactions, and atomic usage increments (`UPDATE voucher SET used_count = used_count + 1 WHERE used_count < usage_limit`).
- **Status Workflow:** Finite state machine for orders (`Pending` $\rightarrow$ `Processing` $\rightarrow$ `Shipped` $\rightarrow$ `Delivered` / `Cancelled`) with automatic inventory restoral and customer notifications.
- **Zero Information Leakage:** Production mode hides SQL and backend exceptions (`app.debug` guard), logging raw error details to PHP error logs while presenting user-friendly notices.

---

## 6. Before Submission Checklist

Ensure all items are verified prior to submitting the final project archive:

1. [ ] **Remove Real Secret Keys:** Ensure `_config.php` contains ONLY placeholder values (`sk_test_PLACEHOLDER_KEY`, `AIzaSy_GEMINI_API_KEY_PLACEHOLDER`, `YOUR_GMAIL_16_CHAR_APP_PASSWORD`). Do NOT include your local `_config.local.php` in the submission archive.
2. [ ] **Export Fresh Database Dump:** In phpMyAdmin, export database `sumthin_db` using **Custom - display all possible options**, check `DROP TABLE / VIEW / PROCEDURE / DATABASE`, and save to `database/sumthin_db.sql`. Verify all sample records and verified email flags exist.
3. [ ] **Generate Entity-Relationship Diagram (ERD):** In phpMyAdmin, click on database `sumthin_db`, open the **Designer** tab, arrange the 20 tables legibly showing all foreign keys, and export to PDF / PNG for report inclusion.
4. [ ] **Clean Disk & Cache:** Delete any temporary test files, temporary log files, and ensure `.gitignore` excludes custom uploads while keeping seed images intact.
5. [ ] **Archive Submission Package:** Zip the entire `sumthin` folder into `sumthin.zip` (verifying file size is compact and excludes unwanted large binaries or duplicate PDFs). Test importing and running from a fresh XAMPP folder.
