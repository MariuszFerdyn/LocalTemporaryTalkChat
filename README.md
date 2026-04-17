# Local Temporary Talk Chat

A lightweight, file-based chat application written in PHP — no database, no WebSockets, no complex infrastructure required.

## Features

- **Runs on any PHP host** — deploy instantly on any PHP-capable environment, including Azure Web App, shared hosting, or a local server.
- **No database** — all messages are stored in plain files on the server; nothing to install or configure.
- **Fully private & controlled environment** — you host it, you own the data. No third-party services involved.
- **No sockets** — the chat works by simply refreshing the page; no persistent connections or special server support needed.
- **Paste code & images** — supports pasting code snippets and pictures directly with **Ctrl+V**.
- **Easy to join** — just share a chat name. The chat name can also serve as a secret passphrase, so only people who know it can join.

## Quick Start

1. Upload the PHP files to your web host (e.g., an Azure Web App with PHP runtime).
2. Open the URL in a browser.
3. Enter a **chat name** (this is also your private room key — keep it secret if you want a private chat).
4. Start chatting. Share the chat name with anyone you want to invite.

## How It Works

- Each chat room is identified by a unique name you choose.
- Messages are appended to a file on the server — no database required.
- To receive new messages, simply refresh the page (or let the page auto-refresh).
- Images and code can be pasted inline using **Ctrl+V**.

## Requirements

- PHP 7.4 or later
- Write permissions on the server for storing chat files

## Deployment on Azure Web App

1. Create an Azure Web App with a PHP runtime stack.
2. Deploy the application files (e.g., via ZIP deploy, FTP, or GitHub Actions).
3. Ensure the app has write access to the directory used for storing chat files.
4. Navigate to the app URL and start chatting.

## Privacy & Security

- There is no registration or login — access is controlled entirely by the chat name/secret.
- Choose a long, random chat name to prevent others from guessing it.
- All data resides on your own server; nothing is sent to external services.

## License

See [LICENSE](LICENSE) for details.
