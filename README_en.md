# RelayCat

RelayCat is a tiny Telegram relay bot that sits between users and an admin account:

- Users talk to the bot in private chat.
- The bot forwards messages to the admin.
- The admin replies to the forwarded message or an attached info card.
- RelayCat sends the reply back to the original user (non-forwarded).

On top of that, RelayCat adds:

- A simple Google reCAPTCHA + JWT one-time verification flow.
- Optional bad-word filtering and user bans.
- A file-based, database-free design that’s easy to inspect and hack on.

---

## Table of Contents

- [Features](#features)
- [How it works](#how-it-works)
- [Architecture](#architecture)
- [Requirements](#requirements)
- [Installation](#installation)
- [Configuration](#configuration)
  - [Telegram & webhook](#telegram--webhook)
  - [Verification & JWT](#verification--jwt)
  - [Data storage](#data-storage)
  - [Bad words filter](#bad-words-filter)
  - [Rate limiting](#rate-limiting)
- [Runtime behaviour](#runtime-behaviour)
  - [User perspective](#user-perspective)
  - [Admin perspective](#admin-perspective)
- [Security notes](#security-notes)
- [Troubleshooting](#troubleshooting)
- [Roadmap ideas](#roadmap-ideas)
- [License](#license)

---

## Features

- **Two-way relay**
  - All private messages to the bot are forwarded to a single admin.
  - Admin replies to the forwarded message (or the info card) to answer the user.
  - Media/files/stickers are re-sent as proper messages, not screenshots.

- **One-time human verification**
  - First-time users must pass a Google reCAPTCHA check via `verify.php`.
  - Verification state is stored in a JSON file and respected on all future messages.

- **Anti-spam / abuse controls**
  - Optional bad-word filter (substring / wildcard / regex).
  - File-based ban list: `/ban`, `/unban` (`/allow` alias), `/banlist`.
  - Banned users’ inbound messages are completely ignored (including `/start`).

- **Admin-friendly UX**
  - Every forwarded message comes with a compact “user card”:
    - `user_id` (as inline code)
    - `@username` (or “(none)”)
    - Name (with a ⭐ if the user is Premium)
  - Replying to either the forwarded message or the card sends a message back.

- **No database required**
  - All state stored in a few JSON files (users, routes, bans, bad words).
  - Easy to back up, inspect, and reset.

- **Security-aware**
  - Webhook secret token verification.
  - Verification page with strict CSP, HSTS (on HTTPS), no directory listing, etc.
  - JSON state files can live outside the web root.

---

## How it works

High-level flow:

1. **User → Bot (first message)**
   - User starts a private chat with the bot and sends `/start` or any text.
   - If the user is unknown and not verified, the bot replies with a verification link:
     - `https://yourdomain.com/verify.php?token=<JWT>`

2. **Verification page (`verify.php`)**
   - Decodes the “verify” JWT to get `user_id` and expiry.
   - Displays a reCAPTCHA v2 widget.
   - On success, generates a `success` JWT and shows a copy button for:
     - `/start <success_jwt>`

3. **User → Bot (`/start <success_jwt>`)**
   - Telegram sends `/start <token>` to the bot.
   - `webhook.php` validates the JWT (signature, expiry, user_id).
   - Adds the user to `verified_users.json` and confirms verification.

4. **Normal messaging**
   - Verified users’ messages are forwarded to the admin account.
   - The admin replies to the forwarded message or its info card.
   - The bot sends that reply back to the original user (non-forwarded).

---

## Architecture

The project consists of two main entrypoints:

- `webhook.php`  
  The Telegram webhook endpoint. Handles:
  - All incoming updates.
  - User verification state.
  - Message forwarding and reverse relaying.
  - Admin commands (ban, bad words, etc.).
  - Webhook secret validation.

- `verify.php`  
  The human verification page. Handles:
  - Decoding verification JWTs.
  - reCAPTCHA v2 display and backend verification.
  - Generating success JWTs and showing `/start <token>` for copy.

Shared configuration lives in:

- `config.php`  
  Central configuration: bot token, admin ID, JWT secret, reCAPTCHA keys, data directory, rate limiting, bad word settings, etc.

Dependencies (via Composer):

- `firebase/php-jwt`
- (Optional) Any PSR-4 autoloader via `vendor/autoload.php`.

---

## Requirements

- PHP 8.0+ (8.1+ recommended)
- Composer
- HTTPS-enabled web server (Apache, Nginx, etc.)
  - A valid (non-self-signed) TLS certificate
- A Telegram bot token (via @BotFather)
- Google reCAPTCHA v2 “I’m not a robot” keys

---

## Installation

1. **Clone or copy the project**

   ```bash
   git clone <your-repo-url> relaycat
   cd relaycat
   ```

2. **Install PHP dependencies**

   ```bash
   composer require firebase/php-jwt
   ```

   Ensure `vendor/autoload.php` exists and is readable by PHP.

3. **Create a data directory (outside web root if possible)**

   Example layout:

   ```text
   /var/www/html/relaycat      # web root (webhook.php, verify.php, config.php)
   /var/www/relaycat-data      # data directory (JSON files, bad_words.txt)
   ```

4. **Configure Apache / Nginx**

   * Point your HTTPS virtual host at the directory containing `webhook.php` and `verify.php`.
   * Disable directory listing and block direct access to `.json`/`.tmp` if they are under web root.
   * Make sure PHP is enabled for both scripts.

5. **Create and edit `config.php`**

   See the next section for detailed options.

6. **Set Telegram webhook**

   Example (with a secret token):

   ```bash
   curl "https://api.telegram.org/bot<YOUR_BOT_TOKEN>/setWebhook" \
     -d "url=https://yourdomain.com/webhook.php" \
     -d "secret_token=<YOUR_TG_WEBHOOK_SECRET>"
   ```

   The `secret_token` must match `TG_WEBHOOK_SECRET` in `config.php`.

7. **Verify webhook status**

   ```bash
   curl "https://api.telegram.org/bot<YOUR_BOT_TOKEN>/getWebhookInfo"
   ```

   Confirm:

   * `url` is your `webhook.php`.
   * `last_error_message` is empty.
   * `pending_update_count` looks reasonable.

---

## Configuration

All main options live in `config.php`. The important ones:

### Telegram & webhook

```php
const BOT_TOKEN    = 'YOUR_TELEGRAM_BOT_TOKEN';
const BOT_USERNAME = 'YourBotUserName';    // without @
const ADMIN_ID     = 123456789;            // your Telegram numeric ID

const ALLOW_BOT_INITIATED = false;         // ignore other bots DM’ing this bot
```

Webhook secret:

```php
const TG_WEBHOOK_SECRET      = 'change_to_a_long_random_secret';
const ENFORCE_WEBHOOK_SECRET = true; // require X-Telegram-Bot-Api-Secret-Token
```

When `ENFORCE_WEBHOOK_SECRET` is `true`, Telegram requests without the matching header will get HTTP 403 and be ignored.

### Verification & JWT

```php
const SHARED_JWT_SECRET      = 'another_long_random_secret';
const VERIFICATION_TOKEN_TTL = 600;  // seconds
const JWT_LEEWAY             = 300;  // seconds of clock skew

const RECAPTCHA_SITE_KEY   = 'your_recaptcha_site_key';
const RECAPTCHA_SECRET_KEY = 'your_recaptcha_secret_key';
const VERIFY_URL           = 'https://yourdomain.com/verify.php';
```

* `SHARED_JWT_SECRET` is used by both `webhook.php` and `verify.php`.
* `VERIFY_URL` must be the public HTTPS URL of `verify.php`.
* `VERIFICATION_TOKEN_TTL` + `JWT_LEEWAY` define how long verification tokens remain valid.

### Data storage

State is stored in JSON files under `DATA_DIR`:

```php
const DATA_DIR            = '/var/www/relaycat-data';
const VERIFIED_USERS_FILE = DATA_DIR . '/verified_users.json';
const ROUTE_MAP_FILE      = DATA_DIR . '/routes.json';       // admin message → user mapping
const BANNED_USERS_FILE   = DATA_DIR . '/banned_users.json';
const RATE_LIMIT_FILE     = DATA_DIR . '/rate_limit.json';
```

`webhook.php` will create the directory and files as needed (ensure the PHP user can read/write this directory).

### Bad words filter

```php
const BAD_WORDS_FILE            = DATA_DIR . '/bad_words.txt';
const BAD_WORDS_IGNORE_CASE     = true;
const BAD_WORDS_ENABLE_WILDCARD = true;
const BAD_WORDS_ENABLE_REGEX    = false;
```

* `bad_words.txt`: one entry per line.
* If `BAD_WORDS_ENABLE_REGEX` is `true`, each line is treated as a raw PCRE pattern (use with care).
* If wildcard mode is enabled, `*` and `?` are supported within patterns.
* When a message hits the filter:

  * The user is told their message contains blocked content.
  * The specific word is not echoed back.

### Rate limiting

Simple per-user sliding window in PHP:

```php
const RATE_LIMIT_ENABLED    = true;
const RATE_LIMIT_WINDOW_SEC = 10;   // window size
const RATE_LIMIT_MAX_EVENTS = 30;   // max messages per user per window
```

If a user sends more than `RATE_LIMIT_MAX_EVENTS` messages within `RATE_LIMIT_WINDOW_SEC`, incoming messages are ignored until they fall back under the threshold.

For large deployments, you should consider adding reverse-proxy-level rate limiting (e.g. Nginx `limit_req`) or a Redis-based solution.

---

## Runtime behaviour

### User perspective

* `/start`

  * If unverified:

    * Bot replies with a verification link (to `verify.php`).
  * If already verified:

    * Bot confirms that messages can be sent normally.

* `/help`

  * If unverified:

    * Short introduction + verification link.
  * If verified:

    * Explains that messages are forwarded to the admin and replies come back via the bot.

* Normal messages

  * If verified and not banned:

    * Message is forwarded to the admin.
  * If unverified:

    * Bot asks you to complete verification first.
  * If banned:

    * Message is silently ignored.

### Admin perspective

All admin behaviour is based on `ADMIN_ID`.

* `/help`

  * Shows an admin-specific help message with all available commands.

* When a user sends a message (and passes all checks)

  * Bot forwards the original message to the admin.
  * Bot then replies to that forwarded message with a compact user info card:

    * User ID (as inline code)
    * Username (or “(none)”)
    * Display name (+ ⭐ for Premium)
    * A hint to “reply here to answer the user”
  * Both the forwarded message and the card are mapped to the user for later replies.

* Replying to forwarded messages / info cards

  * Bot sends your reply as a real message to the target user:

    * For text: `sendMessage`
    * For media/files: `sendPhoto`, `sendDocument`, `sendVideo`, etc.
  * If the original message no longer exists (reply target gone), the bot tries again without `reply_to_message_id` and informs the admin if needed.

* Admin commands

  * **Ban / unban**

    ```text
    /ban <user_id>         # ban by ID
    /ban                   # as a reply to a forwarded message or card
    /unban <user_id>       # unban by ID
    /allow <user_id>       # alias of /unban
    ```

  * **List bans**

    ```text
    /banlist
    ```

  * **Bad words (basic management)**

    ```text
    /badadd <word_or_pattern>
    /baddel <word_or_pattern>
    ```

    These commands are intentionally simple; editing the `bad_words.txt` file directly is still the recommended way for bulk changes.

---

## Security notes

RelayCat was designed with a few extra security considerations:

* **Webhook origin check**

  * `webhook.php` optionally enforces a Telegram webhook secret (`X-Telegram-Bot-Api-Secret-Token`).
  * Requests without the correct secret are rejected with HTTP 403.

* **HTTPS only**

  * `VERIFY_URL` should always be HTTPS.
  * `verify.php` enables HSTS when served over HTTPS.

* **Verification page hardening**

  * Strict CSP (`default-src 'self'`, whitelisted Google reCAPTCHA domains).
  * `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`, `Referrer-Policy: no-referrer`.
  * No caching (`Cache-Control: no-store, no-cache, must-revalidate`).

* **State files**

  * Recommended: put `DATA_DIR` outside the web root.
  * If that’s not possible, add web server rules to deny direct access to JSON/TMP files.

* **Admin-only actions**

  * Management commands (`/ban`, `/badadd`, etc.) are only honoured for `ADMIN_ID`.
  * Banned users cannot trigger any commands or relays.

---

## Troubleshooting

* **Webhook set but bot is “silent”**

  1. Check webhook info:

     ```bash
     curl "https://api.telegram.org/bot<YOUR_BOT_TOKEN>/getWebhookInfo"
     ```

  2. Confirm:

     * `url` points to `webhook.php`, not `verify.php`.
     * `last_error_message` is empty.
       If it mentions `Forbidden`, double-check your `secret_token` vs `TG_WEBHOOK_SECRET`.

* **Verification link says token is invalid or expired**

  * Check server time and timezone.
  * In DEBUG mode, the pages print PHP time and token expiry in both Unix and ISO format.

* **Cannot write JSON files**

  * Ensure the PHP user has `rwx` permissions on `DATA_DIR`.
  * Check web server error logs for permission errors.

* **Bad words not applied**

  * Confirm `BAD_WORDS_FILE` path.
  * Ensure there’s at least one non-empty line.
  * If using regex mode, verify that the pattern is a valid PCRE.

---

## Roadmap ideas

Some possible directions if you want to extend RelayCat:

* Multiple admins / routing rules.
* Per-user notes/labels for the admin.
* Web dashboard for viewing routes and bans.
* Redis-based or DB-backed storage layer.
* Support for grouped media albums as a single logical unit.

---

## License

MIT License.
You are free to use, modify and redistribute RelayCat in your own projects.
If you publish improvements or forks, a short mention of the original project name is appreciated.
