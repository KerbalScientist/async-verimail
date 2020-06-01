# AsyncVeryMail

Asynchronous email account existence checker based on SMTP "RCPT TO" command response.

## Requirements

- PHP 7.4
- JSON PHP extention
- PCNTL PHP extention
- [`ev` PECL extension](https://pecl.php.net/package/ev) (optional, but recommended)


## Installation

- Clone repository.
- Run `composer install`.
- Edit `.env` file to set up your DB credentials.
- Run `composer app:install` to install DB schema. 

## Usage

*Running verify command may lead to ban your IP or your proxy IP by email spam protection systems like Spamhaus.*

Current settings work well with most of the popular email domains like gmail.com, outlook.com, yandex.ru, mail.ru,
 but some very restrictive MX servers may ban by IP.
 
Empirically from many runs, I may say it's safe to run any amount of checks on email domains:
 gmail.com, outlook.com, yandex.ru, mail.ru.

### 1. Import emails from CSV file

```bash
bin/async-verimail import your-emails.csv
```

First row must contain column name - email.

Alternatively, for testing you can generate random emails by running generate-fixtures command.

```bash
bin/async-verimail generate-fixtures 50000
```

This will generate 50000 random emails.

### 2. Run verify command
  
```bash
bin/async-verimail verify
```

*Run `bin/async-verimail help verify` for options.*

### 3. Export results

```bash
bin/async-verimail export results.csv
```

Or
```bash
bin/async-verimail show
```

*Run `bin/async-verimail help export` or `bin/async-verimail show` for options.*

*Run `bin/async-verimail status-list` to see `status` field values meaning.*

## Configuration

Environment configuration variables can be found in `.env.example` file and set in `.env` file or by shell.

Host-specific settings can be found in `config/hosts.yaml` and paramethers reference reside in `config/hosts.reference.yaml`.

## Uninstallation

Run to drop DB schema:

```bash
bin/async-verimail uninstall
```
