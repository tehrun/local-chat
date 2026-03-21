# Local Chat

A lightweight PHP private chat app using SQLite. It supports:

- user registration and login
- one-to-one text messaging with live updates (no manual refresh needed)
- mobile-first chat layout inspired by messaging apps, with the composer fixed below the conversation
- typing indicator between private chat participants
- hold-to-record private voice note uploads using the device microphone
- automatic 24-hour retention for chat history

## Requirements

- PHP 8.1+
- SQLite extension enabled for PHP

## Run locally

```bash
php -S 127.0.0.1:8000 -t public
```

Then open <http://127.0.0.1:8000>. On phones, install the app behind HTTPS or use a secure local tunnel so the browser can grant microphone access for hold-to-record voice notes.

## Storage

- SQLite database: `storage/db/chat.sqlite`
- Uploaded voice notes: `storage/uploads/`

Messages older than 24 hours are purged automatically whenever the app loads inbox or conversation pages, before new messages are saved, and while live updates wait for a conversation change before returning fresh data.
