# Local Chat

A lightweight PHP private chat app using SQLite. It supports:

- user registration and login
- one-to-one text messaging with live updates (no manual refresh needed)
- typing indicator between private chat participants
- private voice note uploads
- automatic 24-hour retention for chat history

## Requirements

- PHP 8.1+
- SQLite extension enabled for PHP

## Run locally

```bash
php -S 127.0.0.1:8000 -t public
```

Then open <http://127.0.0.1:8000>.

## Storage

- SQLite database: `storage/db/chat.sqlite`
- Uploaded voice notes: `storage/uploads/`

Messages older than 24 hours are purged automatically whenever the app loads inbox or conversation pages, before new messages are saved, and while live polling requests keep the chat updated.
