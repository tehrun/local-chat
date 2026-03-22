# Local Chat

A lightweight PHP private chat app using SQLite by default, with optional MySQL compatibility. It supports:

- user registration and login
- one-to-one text messaging with live updates (no manual refresh needed)
- mobile-first chat layout inspired by messaging apps, with the composer fixed below the conversation
- typing indicator between private chat participants
- share images from the file picker or directly from the iPhone/Android camera flow
- hold-to-record private voice note uploads using the device microphone
- browser notifications and alert sounds for new messages and friend requests after the first user interaction
- automatic 24-hour retention for chat history

## Requirements

- PHP 8.1+
- SQLite extension enabled for PHP
- PDO MySQL extension enabled for PHP if you want to switch to MySQL/MariaDB

## Run locally

```bash
php -S 127.0.0.1:8000 -t public
```

Then open <http://127.0.0.1:8000>. When running on PHP's built-in dev server, the chat UI falls back to short polling instead of Server-Sent Events so message sends do not get blocked by the single-worker server. Both the conversation page and the home/chat-list page now check lightweight signatures first, only fetch their full payloads when something actually changed, and back off toward slower polling while the UI is idle. On multi-worker hosting, the conversation and home pages keep SSE connections open and also use lightweight signatures to avoid unnecessary full payload reloads. On phones, install the app behind HTTPS or use a secure local tunnel so the browser can grant microphone access for hold-to-record voice notes.

## Run with Docker

```bash
docker compose up --build
```

The app will be available on <http://0.0.0.0:8080> (or `http://localhost:8080` from the same machine). The Compose stack starts two containers:

- `app`: Apache + PHP 8.3 serving the `public/` directory on port `8080`
- `mysql`: MySQL 8.0 with a persistent named volume

The app container is preconfigured to use MySQL through these environment variables in `docker-compose.yml`:

- `CHAT_DB_DRIVER=mysql`
- `CHAT_DB_HOST=mysql`
- `CHAT_DB_PORT=3306`
- `CHAT_DB_NAME=local_chat`
- `CHAT_DB_USER=local_chat`
- `CHAT_DB_PASS=local_chat`

Persistent data is stored in Docker named volumes:

- `mysql_data`: MySQL database files
- `app_storage`: uploaded files and runtime storage under `storage/`

To stop the stack:

```bash
docker compose down
```

To remove containers and volumes completely:

```bash
docker compose down -v
```

## Storage

- SQLite database: `storage/db/chat.sqlite`
- Uploaded voice notes: `storage/uploads/`
- Uploaded chat images: `storage/tmp/`

Messages older than 24 hours are purged automatically whenever the app loads inbox or conversation pages, before new messages are saved, and while live polling requests keep the chat updated.

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
