# Local Chat

A lightweight PHP private chat app using SQLite by default, with optional MySQL compatibility. It supports:

- user registration and login
- one-to-one text messaging with live updates (no manual refresh needed)
- mobile-first chat layout inspired by messaging apps, with the composer fixed below the conversation
- typing indicator between private chat participants
- share files up to 10MB from the device file picker
- share images from the file picker or directly from the iPhone/Android camera flow
- hold-to-record private voice note uploads using the device microphone
- browser notifications and alert sounds for new messages and friend requests after the first user interaction
- background Web Push notifications for new messages, including when the installed app is closed (when the browser/platform supports Web Push)
- experimental Surf mode proof of concept that uses a server-side Playwright browser for signed-in users
- automatic 7-day retention for chat history, with uploaded photos, files, and voice notes expiring after 24 hours

## Requirements

- PHP 8.1+
- SQLite extension enabled for PHP
- OpenSSL and cURL extensions enabled for Web Push notifications
- PDO MySQL extension enabled for PHP if you want to switch to MySQL/MariaDB

## Run locally

Install the Node dependency for Surf mode before starting the PHP server:

```bash
npm install
php -S 127.0.0.1:8000 -t public
```

Then open <http://127.0.0.1:8000>. When running on PHP's built-in dev server, the chat UI falls back to short polling instead of Server-Sent Events so message sends do not get blocked by the single-worker server. Both the conversation page and the home/chat-list page now check lightweight signatures first, only fetch their full payloads when something actually changed, and back off toward slower polling while the UI is idle. On multi-worker hosting, the conversation and home pages keep SSE connections open and also use lightweight signatures to avoid unnecessary full payload reloads. On phones, install the app behind HTTPS or use a secure local tunnel so the browser can grant microphone access for hold-to-record voice notes.

For background Web Push notifications, serve the app over HTTPS (or a secure local tunnel), allow notifications in the browser, and keep the browser's push support enabled. A public `http://IP:port` URL is not a reliable Web Push or PWA-install target. The app automatically creates and stores a local VAPID key pair under `storage/webpush/` when OpenSSL is available. You can override that with:

- `CHAT_WEB_PUSH_VAPID_PRIVATE_KEY_PEM` — PEM-encoded EC private key on the `prime256v1` curve
- `CHAT_WEB_PUSH_SUBJECT` — contact value for VAPID claims, such as `mailto:admin@example.com`

## Storage

- SQLite database: `storage/db/chat.sqlite`
- Uploaded shared files and voice notes: `storage/uploads/`
- Uploaded chat images: `storage/tmp/`

Messages older than 7 days are purged automatically whenever the app loads inbox or conversation pages, before new messages are saved, and while live polling requests keep the chat updated. Uploaded photos, files, and voice notes are deleted from disk after 24 hours, and their message rows stay behind so the conversation still reads correctly.

## Database configuration

SQLite remains the default with no extra setup. To switch to MySQL or MariaDB, configure these environment variables before PHP starts:

- `CHAT_DB_DRIVER=mysql`
- `CHAT_DB_HOST=127.0.0.1`
- `CHAT_DB_PORT=3306`
- `CHAT_DB_NAME=your_database`
- `CHAT_DB_USER=your_username`
- `CHAT_DB_PASS=your_password`

If `CHAT_DB_DRIVER` is omitted, the app continues using SQLite at `storage/db/chat.sqlite`.

## cPanel deployment

To host the project from a subdirectory such as `https://towerco.land/chat`, upload the repository so the repository root is served from that `/chat` folder. The root now includes wrapper entry points (`index.php`, `chat.php`, API endpoints, `manifest.json`, `sw.js`, and `icons/`) that forward to the existing `public/` implementation while keeping URLs relative to the current directory.
