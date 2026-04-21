# Restart Server Skill

**Purpose**: Stop any running PHP development server on port 8081 and start it again.

**When to use**:
- After editing `index.php` and needing to reload the server
- When the server has crashed or stopped responding
- When port 8081 is occupied by a stale PHP process
- Any time a fresh server start is needed without manually killing processes

## What this does

1. Finds and kills any process currently listening on port 8081
2. Waits briefly to ensure the port is released
3. Starts a fresh PHP development server on `0.0.0.0:8081`

## Steps to restart the server

Run the following commands in the terminal from the `/workspaces/LocalTemporaryTalkChat` directory:

```bash
# Step 1: Kill any process on port 8081
fuser -k 8081/tcp 2>/dev/null || true

# Step 2: Start the server fresh
php -S 0.0.0.0:8081 -t /workspaces/LocalTemporaryTalkChat /workspaces/LocalTemporaryTalkChat/index.php
```

Or as a one-liner:

```bash
fuser -k 8081/tcp 2>/dev/null; sleep 0.5; php -S 0.0.0.0:8081 -t /workspaces/LocalTemporaryTalkChat /workspaces/LocalTemporaryTalkChat/index.php
```

## Using the VS Code Task

Press `Ctrl+Shift+P` → **Run Task** → **Restart PHP Server** to stop and restart in one step.

## Verify the server is running

After restart, confirm it's listening:

```bash
lsof -i :8081
```

Expected output includes a line like:
```
php  <PID>  ...  TCP *:tproxy (LISTEN)
```

## GitHub Codespaces — make port public

If running in GitHub Codespaces, ensure port 8081 is set to **Public** after (re)starting:

- Open the **Ports** tab in VS Code, right-click port **8081** → **Port Visibility** → **Public**
- Or run: `gh codespace ports visibility 8081:public -c $CODESPACE_NAME`

Then open `http://127.0.0.1:8081` locally or the forwarded `https://<name>-8081.app.github.dev` URL.

## Troubleshooting

- **`fuser` not found?** Use `kill $(lsof -t -i:8080)` instead:
  ```bash
  kill $(lsof -t -i:8081) 2>/dev/null; sleep 0.5; php -S 0.0.0.0:8081 -t /workspaces/LocalTemporaryTalkChat /workspaces/LocalTemporaryTalkChat/index.php
  ```
- **Permission denied killing process?** Try with `sudo`:
  ```bash
  sudo fuser -k 8081/tcp 2>/dev/null; sleep 0.5; php -S 0.0.0.0:8081 -t /workspaces/LocalTemporaryTalkChat /workspaces/LocalTemporaryTalkChat/index.php
  ```
- **PHP not found?** Ensure PHP 8+ is installed: `php -v`
