# Local Temporary Talk Chat

A lightweight, file-based chat application written in PHP 8 with **client-side encryption for secure communication**. No database, no WebSockets, and no external services.

## Features

- **Secure**: Text and image messages are encrypted locally in your browser using Web Crypto (AES-GCM). Encryption key never leaves your device.
- **No Sockets**: Chat refreshes automatically every 3 seconds—no WebSockets or long-polling overhead.
- **Encrypted Images**: Paste images via Ctrl+V; they are encrypted before being sent to server.
- **Encrypted Screen Sharing**: WebRTC screen-share signaling (SDP offers/answers, ICE candidates) is encrypted with the same room key. Clients with a wrong key cannot negotiate or view a screen share.
- File-based storage: Messages stored as newline-delimited JSON files in `storage/`.
- Runs on PHP 8 hosts, including Azure App Service and GitHub Codespaces.
- No database required. Encryption/decryption happens in the browser, not on server.
- If encryption key is empty, room name is used as fallback encryption key.
- If a wrong key is used, messages/images cannot be decrypted and an explicit warning is shown.
- Room name is visually hidden in toolbar and revealed on hover.
- Your name is required and shown with each message.

## Security Model

- The server stores encrypted payloads for message content and images.
- Screen-share signaling payloads (SDP, ICE) are also encrypted before being relayed through the server.
- Encryption/decryption happens in browser JavaScript.
- The encryption key is not transmitted to backend endpoints.
- If key is forgotten, existing encrypted messages cannot be recovered.
- A client using the wrong encryption key will not receive chat messages, images, or screen-share signals.
- Metadata is still visible to server/storage: room hash, timestamp, sender name, message id.

## Runtime Storage

- App auto-creates `storage/` at runtime if missing.
- You can deploy only `index.php`; no pre-created storage folders are required.
- Ensure the app process has write permission in app directory so `storage/` can be created and written.

## Local Run

1. Ensure PHP 8+ is installed.
2. Run:

```bash
php -S 127.0.0.1:8081 index.php
```

3. Open `http://127.0.0.1:8081`.

## GitHub Codespaces Run

1. Open the repository in GitHub Codespaces (Code → Codespaces → Create codespace on main).
2. Wait for the container to start.
3. Run in the terminal:

```bash
php -S 127.0.0.1:8081 index.php
```

4. Make port 8081 **public** so the forwarded URL is accessible:
	- Open the **Ports** tab in VS Code, right-click port **8081** → **Port Visibility** → **Public**
	- Or run: `gh codespace ports visibility 8081:public -c $CODESPACE_NAME`
5. Open the forwarded URL shown in the Ports tab (e.g. `https://<name>-8081.app.github.dev`).
6. Start chatting with end-to-end encryption!

Alternatively, use the provided VS Code task: Press `Ctrl+Shift+P` → Run Task → "Start PHP Server" to launch the server.

## Internet Access With Anonymous Users

If you want internet users to connect:

1. Start the public-bind server task in VS Code:
	- `Run Task` → `Restart PHP Server (Public)`
2. Expose port `8081` publicly in your host environment (for Codespaces: Ports tab → port `8081` → visibility `Public`).
3. Share the forwarded HTTPS URL.

Anonymous usage behavior in this app:

- Username is optional. If left empty, the app auto-generates a random alias such as `anon-a1b2c3d4`.
- Message content and images remain client-side encrypted.
- The server still sees transport metadata (for example network source data handled by your hosting platform). Full network-level anonymity is not guaranteed.

## Azure App Service Deployment (Azure CLI, index.php only)

The flow below creates a Linux PHP web app and deploys only `index.php`.

### 1) Clone repository first

```bash
git clone https://github.com/MariuszFerdyn/LocalTemporaryTalkChat.git
cd LocalTemporaryTalkChat
```

### 2) Log in and set defaults

```bash
az login
az account set --subscription "<SUBSCRIPTION_NAME_OR_ID>"
```

### 3) Variables

```bash
RG="rg-local-chat"
LOCATION="westeurope"
PLAN="asp-local-chat"
APP="local-chat-$RANDOM"
```

### 4) Create resource group and app service plan

```bash
az group create --name "$RG" --location "$LOCATION"
az appservice plan create --name "$PLAN" --resource-group "$RG" --is-linux --sku B1
```

### 5) Create PHP web app

```bash
az webapp create \
	--resource-group "$RG" \
	--plan "$PLAN" \
	--name "$APP" \
	--runtime "PHP|8.3"
```

### 6) Prepare deployment package (index.php only)

If you already have local `index.php`:

```bash
zip deploy.zip index.php
```

If you want to download `index.php` first (example from GitHub raw URL), then zip it:

Use this option if you do not clone the repository.

```bash
curl -L "https://raw.githubusercontent.com/<OWNER>/<REPO>/main/index.php" -o index.php
zip deploy.zip index.php
```

### 7) Deploy package

```bash
az webapp deploy \
	--resource-group "$RG" \
	--name "$APP" \
	--src-path deploy.zip \
	--type zip
```

### 8) Open app

```bash
az webapp browse --resource-group "$RG" --name "$APP"
```

## License

See [LICENSE](LICENSE) for details.

This project is Vibe Coding.
