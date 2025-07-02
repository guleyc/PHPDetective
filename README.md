# PHPDetective

A snapshot-based PHP script to monitor file and database changes and send detailed email notifications.

PHPDetective helps you keep an eye on your website's integrity by creating "snapshots" of your file system and database. It compares the current state with the previous snapshot to identify exactly what has been added, modified, or deleted, and sends you a detailed report via email.

This is ideal for security monitoring, unauthorized change detection, or simply keeping track of updates in a development environment.

## Features

- Snapshot-Based Monitoring: Uses MD5 hashes to create a fingerprint of your site's state, ensuring accurate change detection.
- Comprehensive Scanning: Monitors both the file system and MySQL/MariaDB database tables.
- Detailed Reports: Identifies changes as ✅ ADDED, 🔄 MODIFIED, or ❌ DELETED.
- Content Previews: The email report includes snippets of new or modified content for quick review.
- Email Notifications: Uses PHPMailer to send reliable alerts via SMTP.
- Configurable Exclusions: Easily exclude specific files, extensions, directories, or database tables from the scan.
- CLI-Friendly: Designed to be run from the command line, making it perfect for scheduling with cron jobs.

## Requirements

- PHP 7.4 or higher
- MySQLi Extension
- Composer for dependency management (specifically for PHPMailer)
- A MySQL or MariaDB database
- SMTP credentials for sending email notifications

## Installation

Clone the repository:

```bash
git clone https://github.com/your-username/phpdetective.git
cd phpdetective
```

Install dependencies: PHPDetective uses Composer to manage the PHPMailer library.

```bash
composer install
```

This will create a vendor directory and an autoload.php file.

## Configuration

All configuration is done within the scanner.php (or your chosen filename) script in the \$config array.

```php
$config = [
    // Database configuration
    'db_host' => 'localhost',
    'db_name' => 'YOUR_DB_NAME',
    'db_user' => 'YOUR_DB_USER',
    'db_pass' => 'YOUR_DB_PASSWORD',
    'db_charset' => 'utf8mb4',

    // SMTP configuration
    'smtp_host' => 'smtp.example.com',
    'smtp_user' => 'your_email@example.com',
    'smtp_pass' => 'YOUR_SMTP_PASSWORD',
    'smtp_port' => 465,
    'smtp_secure' => PHPMailer::ENCRYPTION_SMTPS,

    // Email settings
    'email_from' => 'scanner@example.com',
    'email_from_name' => 'Site Change Detective',
    'email_to' => 'your_alert_email@example.com',
    'email_subject' => 'Site Changes Detected (Detailed Report)',

    // Directory to monitor
    'monitor_dir' => '/path/to/your/website/htdocs'
];
```

You can also customize the exclusion arrays (\$excluded\_extensions, \$excluded\_directories, \$excluded\_tables) to fit your needs.

## Usage

The script is designed to be run from the command line (CLI).

```bash
php /path/to/your/phpdetective/detective.php
```

## Scheduling with Cron

For automated monitoring, it's highly recommended to run this script as a cron job. For example, to run the scanner every hour, edit your crontab (`crontab -e`) and add the following line:

```cron
0 * * * * /usr/bin/php /path/to/your/phpdetective/detective.php > /dev/null 2>&1
```

This command runs the script every hour and discards the standard output to prevent unnecessary emails from cron itself. The script will still log errors to `detective_error.log`.

## State File

The script will create a `scan_state.json` file in the same directory. Do not delete this file, as it contains the snapshot from the last run, which is essential for detecting changes.

## Example Email Report

```
Site scan completed at 2025-07-02 10:30:00
Comparing against state from 2025-07-02 09:30:00

--- CHANGE SUMMARY ---
--- File System Changes ---
  ✅ ADDED:
    - File: /path/to/your/website/htdocs/backdoor.php
    -- Content Start --
    <?php echo shell_exec($_GET['cmd']); ?>
    -- Content End --

  🔄 MODIFIED:
    - File: /path/to/your/website/htdocs/wp-config.php
    -- Content Start --
    define('DB_PASSWORD', 'new_hacked_password');
    ... (content truncated)
    -- Content End --

--- Database Changes ---
  ✅ ADDED:
    - Table: wp_users
      - ID: 123
        - user_login: admin_hacker
        - user_pass: ...
        - user_email: hacker@evil.com
        ...

  ❌ DELETED:
    - Database Row: Table: wp_options, ID: 1
```

## License

This project is licensed under the MIT License. See the LICENSE file for details.

