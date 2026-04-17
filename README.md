# Local Temporary Talk Chat

A lightweight, file-based chat application written in PHP 8 with client-side encryption. No database, no WebSockets, and no external services.

## Features

- Runs on PHP 8 hosts, including Azure App Service.
- No database required. Messages are stored as newline-delimited JSON files in `storage/`.
- No sockets/WebSockets. Chat refreshes automatically every 3 seconds and can be refreshed manually.
- Supports text messages and pasted images via Ctrl+V.
- Client-side encryption for both text and images using Web Crypto (AES-GCM).
- Encryption key is entered in the browser and is never sent to the server.
- If encryption key is empty, room name is used as fallback encryption key.
- If a wrong key is used, messages/images cannot be decrypted and an explicit warning is shown.
- Room name is visually hidden in toolbar and revealed on hover.
- Your name is required and shown with each message.

## Security Model

- The server stores encrypted payloads for message content and images.
- Encryption/decryption happens in browser JavaScript.
- The encryption key is not transmitted to backend endpoints.
- If key is forgotten, existing encrypted messages cannot be recovered.
- Metadata is still visible to server/storage: room hash, timestamp, sender name, message id.

## Runtime Storage

- App auto-creates `storage/` at runtime if missing.
- You can deploy only `index.php`; no pre-created storage folders are required.
- Ensure the app process has write permission in app directory so `storage/` can be created and written.

## Local Run

1. Ensure PHP 8+ is installed.
2. Run:

```bash
php -S 127.0.0.1:8080 index.php
```

3. Open `http://127.0.0.1:8080`.

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
