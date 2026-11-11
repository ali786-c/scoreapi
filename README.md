# 🚀 60Score / STUMPS - 1-Step Automatic cPanel Web Setup Guide

With our **1-Step Web Installer (`install.php`)**, you do **NOT** need to open phpMyAdmin to run SQL queries, and you do **NOT** need to edit PHP files manually!

---

## ⚡ Super Easy 2-Minute Setup

### Step 1: Create a Database in cPanel
1. In cPanel, click **MySQL® Database Wizard**.
2. Enter a database name (e.g. `cpaneluser_60score`).
3. Enter a database user and password (save this password).
4. Click **All Privileges** and finish the wizard.

---

### Step 2: Upload Files & Open Web Installer
1. In cPanel **File Manager**, upload all files from `public_html/` to your website folder (e.g. `public_html/` or a subfolder like `public_html/scorer/`).
2. Open your website in your browser:
   👉 **`https://yourdomain.com/`** (or `https://yourdomain.com/install.php`)
3. The **60Score Setup Wizard** will automatically launch!
4. Enter:
   - **Database Name** (e.g. `cpaneluser_60score`)
   - **Database Username**
   - **Database Password**
   - **Your Admin Name, Email & Password**
5. Click **⚡ Run 1-Step Installation & Migrate Database**.

**Done!**
- The wizard tests the database connection.
- Automatically creates all **7 database tables** with indexes.
- Creates your **Master Admin Account**.
- Securely locks the installer (`installed.lock`).
- Automatically directs you to your **Admin Dashboard** (`https://yourdomain.com/admin/`)!

---

## 🔒 Security & Architecture
- **Creator-Only Scoring Enforcement:** Only the authenticated match creator can score or edit balls. Spectators receive read-only scorecards.
- **100% Offline-First:** Scorer works offline with 0ms delay; changes batch sync automatically to `/api/sync/batch` when online.
- **SQL Injection Prevention:** 100% PDO Prepared Statements.
- **Passwords:** Encrypted using industry-standard `bcrypt`.
