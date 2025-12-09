DMARC-reports-analyzer
=================

A PHP application for analyzing DMARC aggregation reports using the [Nette](https://nette.org) framework.

* Import reports from IMAP mailbox.
* View reports summary data per domain, IP address...
* Filter the data by date, results, IP address...
* Easily see the source of your DMARC failures.

The application uses similar data structure to that created by [dmarcts-report-parser.pl](https://github.com/techsneeze/dmarcts-report-parser) 
script. So it should also parse data imported by that script.

Requirements
------------

- Webserver with PHP 8.2 or higher and MySQL/MariaDB server.
- Composer.
- Optional: IMAP access if you want to import directly from a mailbox. The php-imap extension is NOT required anymore; you can import from a local directory of DMARC attachments instead.


Installation
------------

Download the files:

    git clone https://github.com/petr22/DMARC-reports-analyzer.git

Use Composer to download Nette framework files. If you don't have Composer yet,
download it following [the instructions](https://getcomposer.org/). 
Then in the directory with composer.json run:

    composer install

Make directories `temp/` and `log/` writable:

    chmod -R a+rw temp log

Install needed php extensions:

    php-xml php-zip php-pdo php-json php-gd

Note: The importer can run without `php-imap` by using the local directory mode (see below). If you want to import directly from a mailbox using IMAP, you still need `php-imap`.

Web Server Setup
----------------

For Apache or Nginx, setup a virtual host to point to the `www/` directory of the project.

**It is CRITICAL that whole `app/`, `log/` and `temp/` directories are not accessible directly
via a web browser. See [security warning](https://nette.org/en/security-warning).**

Configuration
----------------

Copy local configuration file for the application `./app/config/config.local.neon.sample` to `./app/config/config.local.neon` 
and modify database/login settings.

Copy configuration file for the import script `./app/import.script/import.conf.php.sample` to `./app/import.script/import.conf.php`
and modify database settings. For importing:

- To import from an IMAP mailbox, set `$source = 'imap'` and configure `$host`, `$username`, `$password`, `$folderInbox`, `$folderProcessed`.
- To import from a local directory (no php-imap needed), set `$source = 'local'` and set `$localPath` to a directory containing DMARC report files (`.zip`, `.gz`, or `.xml`).

Usage
----------------

First you need to run the import script `./app/import.script/import.php`, which will create database tables and import data.
Depending on the configuration, it will:

- Fetch reports from your IMAP mailbox (IMAP mode), or
- Read report files from the configured local directory (Local mode).

Alternatively, you can create the tables manually; their structure is at the end of the import script.

Navigate your browser to the location of the ./www directory in the application.

Run the import script periodically.

Logging and diagnostics
----------------

The import script now has configurable logging with levels and a final summary:

- Levels: `error` (least verbose), `warn`, `info`, `debug` (most verbose)
- Configure in `app/import.script/import.conf.php`:
  - `$logLevel` (default: `info`)
  - `$logFile` (default: `app/import.script/import.log`)
  - `$logToStdout` (default: `true`)
- CLI overrides when running the script:
  - `-q` or `--quiet` → only errors
  - `-v` or `-vv` → debug verbosity
  - `--log-level=LEVEL` → set explicit level, e.g. `--log-level=debug`
  - `--log-file=/path/to/file.log` → change log file
  - `--no-stdout` → do not print to stdout, only log to file

Example:

```
php ./app/import.script/import.php --log-level=debug
```

On completion the script prints a one-line summary, for example:

```
done. summary: mode=imap messages=5 attachments=6 xml_parsed=5 xml_failed=0 reports_inserted=5 reports_skipped=0 records_inserted=123 messages_moved=5 errors=0
```