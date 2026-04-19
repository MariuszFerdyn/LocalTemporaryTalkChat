# Start Server Skill

**Purpose**: Start the PHP development server for the Local Temporary Talk Chat application.

**When to use**: 
- Starting local development on your machine
- Launching the server in GitHub Codespaces
- Running the application on any PHP 8+ environment

## What this does

Starts a PHP built-in development server on `127.0.0.1:8080` that serves the chat application. The server:
- Handles HTTP requests for the chat interface and API endpoints
- Stores encrypted messages in the `storage/` directory
- Does NOT use databases, WebSockets, or external services
- Supports both locally encrypted text messages and images (encrypted client-side)

## How to start the server

### Option 1: Direct Command
```bash
php -S 127.0.0.1:8080 index.php
```

### Option 2: VS Code Task (Codespaces)
1. Press `Ctrl+Shift+P` (or `Cmd+Shift+P` on Mac)
2. Select "Run Task"
3. Choose "Start PHP Server"
4. The terminal will show: `Listening on http://127.0.0.1:8080`

### Option 3: VS Code Task Terminal
In any integrated terminal in VS Code, run:
```bash
npm run start
```
(if npm start is configured in package.json)

## After starting

1. Open `http://127.0.0.1:8080` in your browser
2. Enter a room name (e.g., "MyChat")
3. Enter your name
4. (Optional) Set an encryption key for added security
5. Start chatting—all messages and images are encrypted end-to-end!

## Stopping the server

Press `Ctrl+C` in the terminal where the server is running.

## Troubleshooting

- **Port 8080 already in use?** Change the command to use another port:
  ```bash
  php -S 127.0.0.1:8081 index.php
  ```
- **Permission denied?** Ensure the app process has write permissions in the app directory for creating `storage/`
- **"Module not found"?** Ensure PHP 8+ is installed: `php -v`

## Security Notes

- Encryption keys are **never** sent to the server
- Messages and images are encrypted in your browser before being uploaded
- The server stores only encrypted payloads
- Room metadata (name, sender, timestamp) is visible on the server—only content is encrypted
- If you lose your encryption key, existing encrypted messages cannot be recovered
