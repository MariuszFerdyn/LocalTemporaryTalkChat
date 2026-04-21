<?php
declare(strict_types=1);

// Let PHP built-in server serve static files (images, css, etc.) directly.
if (PHP_SAPI === 'cli-server') {
    $staticFile = __DIR__ . parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    if (is_file($staticFile)) {
        return false;
    }
}

const MAX_ROOM_LENGTH = 120;
const MAX_USER_LENGTH = 40;
const MAX_TEXT_LENGTH = 8000;           // encrypted text is larger than plaintext
const MAX_ENCRYPTED_IMAGE = 4_000_000; // ~3 MB plaintext image after base64+encryption overhead
const MAX_SIGNAL_DATA    = 32768;       // enough for SDP offers/answers and ICE candidates
const SIGNAL_TTL         = 120;         // seconds; stale signals are ignored on read

$storageDir = __DIR__ . '/storage';

if (!is_dir($storageDir)) {
    mkdir($storageDir, 0775, true);
}

$action = $_GET['action'] ?? null;
if ($action !== null) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
            exit;
        }

        $payload = json_decode(file_get_contents('php://input') ?: '{}', true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($payload)) {
            throw new RuntimeException('Invalid JSON payload');
        }

        $room = normalizeRoom((string)($payload['room'] ?? ''));

        if ($action === 'fetch') {
            $sinceId = max(0, (int)($payload['sinceId'] ?? 0));
            $messages = fetchMessages($room, $storageDir, $sinceId);
            echo json_encode(['ok' => true, 'messages' => $messages]);
            exit;
        }

        if ($action === 'send') {
            $user = normalizeUser((string)($payload['user'] ?? ''));
            $text = trim((string)($payload['text'] ?? ''));
            $encryptedImage = trim((string)($payload['encryptedImage'] ?? ''));

            if ($text === '' && $encryptedImage === '') {
                throw new RuntimeException('Message text or image is required.');
            }
            if (mb_strlen($text) > MAX_TEXT_LENGTH) {
                throw new RuntimeException('Message text is too long.');
            }
            if (mb_strlen($encryptedImage) > MAX_ENCRYPTED_IMAGE) {
                throw new RuntimeException('Encrypted image payload is too large.');
            }

            $message = [
                'id' => 0,
                'timestamp' => gmdate('c'),
                'user' => $user,
                'text' => $text,
                'encryptedImage' => $encryptedImage ?: null,
            ];

            $message = appendMessage($room, $storageDir, $message);
            echo json_encode(['ok' => true, 'message' => $message]);
            exit;
        }

        if ($action === 'signal') {
            $from  = validatePeerId((string)($payload['from'] ?? ''));
            $toRaw = (string)($payload['to'] ?? '');
            $to    = $toRaw === 'all' ? 'all' : validatePeerId($toRaw);
            $type  = (string)($payload['type'] ?? '');
            if (!in_array($type, ['screen-available', 'screen-stopped', 'join-request', 'offer', 'answer', 'ice', 'bye'], true)) {
                throw new RuntimeException('Invalid signal type.');
            }
            $data = (string)($payload['data'] ?? '');
            if (strlen($data) > MAX_SIGNAL_DATA) {
                throw new RuntimeException('Signal payload too large.');
            }
            appendSignal($room, $storageDir, [
                'from' => $from,
                'to'   => $to,
                'type' => $type,
                'data' => $data,
                'ts'   => time(),
            ]);
            echo json_encode(['ok' => true]);
            exit;
        }

        if ($action === 'fetch-signals') {
            $peerId  = validatePeerId((string)($payload['peerId'] ?? ''));
            $sinceId = max(0, (int)($payload['sinceId'] ?? 0));
            $signals = fetchSignals($room, $storageDir, $peerId, $sinceId);
            echo json_encode(['ok' => true, 'signals' => $signals]);
            exit;
        }

        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Unknown action']);
    } catch (Throwable $e) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

function normalizeRoom(string $room): string
{
    $room = trim($room);
    if ($room === '') {
        throw new RuntimeException('Room name is required.');
    }
    if (mb_strlen($room) > MAX_ROOM_LENGTH) {
        throw new RuntimeException('Room name is too long.');
    }
    return $room;
}

function normalizeUser(string $user): string
{
    $user = trim($user);
    if ($user === '') {
        $user = 'anon-' . bin2hex(random_bytes(3));
    }
    if (mb_strlen($user) > MAX_USER_LENGTH) {
        $user = mb_substr($user, 0, MAX_USER_LENGTH);
    }
    return $user;
}

function roomHash(string $room): string
{
    return hash('sha256', $room);
}

function roomFilePath(string $room, string $storageDir): string
{
    return $storageDir . '/' . roomHash($room) . '.jsonl';
}

function fetchMessages(string $room, string $storageDir, int $sinceId): array
{
    $path = roomFilePath($room, $storageDir);
    if (!file_exists($path)) {
        return [];
    }

    $handle = fopen($path, 'rb');
    if (!$handle) {
        throw new RuntimeException('Unable to open chat file.');
    }

    try {
        flock($handle, LOCK_SH);
        $messages = [];
        while (($line = fgets($handle)) !== false) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $msg = json_decode($line, true);
            if (!is_array($msg)) {
                continue;
            }
            $id = (int)($msg['id'] ?? 0);
            if ($id > $sinceId) {
                $messages[] = $msg;
            }
        }
        flock($handle, LOCK_UN);
        return $messages;
    } finally {
        fclose($handle);
    }
}

function appendMessage(string $room, string $storageDir, array $message): array
{
    $path = roomFilePath($room, $storageDir);
    $handle = fopen($path, 'c+b');
    if (!$handle) {
        throw new RuntimeException('Unable to open chat file for writing.');
    }

    try {
        if (!flock($handle, LOCK_EX)) {
            throw new RuntimeException('Unable to lock chat file.');
        }

        rewind($handle);
        $lastId = 0;
        while (($line = fgets($handle)) !== false) {
            $decoded = json_decode(trim($line), true);
            if (is_array($decoded)) {
                $lastId = max($lastId, (int)($decoded['id'] ?? 0));
            }
        }

        $message['id'] = $lastId + 1;

        fseek($handle, 0, SEEK_END);
        fwrite($handle, json_encode($message, JSON_UNESCAPED_SLASHES) . PHP_EOL);
        fflush($handle);
        flock($handle, LOCK_UN);

        return $message;
    } finally {
        fclose($handle);
    }
}

function validatePeerId(string $id): string
{
    if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $id)) {
        throw new RuntimeException('Invalid peer ID format.');
    }
    return $id;
}

function signalFilePath(string $room, string $storageDir): string
{
    return $storageDir . '/' . roomHash($room) . '.sig.jsonl';
}

function appendSignal(string $room, string $storageDir, array $signal): void
{
    $path   = signalFilePath($room, $storageDir);
    $handle = fopen($path, 'c+b');
    if (!$handle) {
        throw new RuntimeException('Unable to open signal file for writing.');
    }
    try {
        if (!flock($handle, LOCK_EX)) {
            throw new RuntimeException('Unable to lock signal file.');
        }
        rewind($handle);
        $lastId = 0;
        while (($line = fgets($handle)) !== false) {
            $decoded = json_decode(trim($line), true);
            if (is_array($decoded)) {
                $lastId = max($lastId, (int)($decoded['id'] ?? 0));
            }
        }
        $signal['id'] = $lastId + 1;
        fseek($handle, 0, SEEK_END);
        fwrite($handle, json_encode($signal, JSON_UNESCAPED_SLASHES) . PHP_EOL);
        fflush($handle);
        flock($handle, LOCK_UN);
    } finally {
        fclose($handle);
    }
}

function fetchSignals(string $room, string $storageDir, string $peerId, int $sinceId): array
{
    $path = signalFilePath($room, $storageDir);
    if (!file_exists($path)) {
        return [];
    }
    $handle = fopen($path, 'rb');
    if (!$handle) {
        return [];
    }
    try {
        flock($handle, LOCK_SH);
        $signals = [];
        $cutoff  = time() - SIGNAL_TTL;
        while (($line = fgets($handle)) !== false) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $sig = json_decode($line, true);
            if (!is_array($sig)) {
                continue;
            }
            if ((int)($sig['id'] ?? 0) <= $sinceId) {
                continue;
            }
            if ((int)($sig['ts'] ?? 0) < $cutoff) {
                continue;
            }
            if ($sig['to'] === $peerId || $sig['to'] === 'all') {
                $signals[] = $sig;
            }
        }
        flock($handle, LOCK_UN);
        return $signals;
    } finally {
        fclose($handle);
    }
}

?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Local Temporary Talk Chat</title>
    <style>
        :root {
            --bg: #f5efe4;
            --panel: #fffaf0;
            --ink: #161513;
            --muted: #645e53;
            --accent: #2f7b5f;
            --accent-strong: #1f5842;
            --bubble-a: #e7f4ee;
            --bubble-b: #f3ede1;
            --border: #d6cdbd;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            font-family: "Trebuchet MS", "Segoe UI", sans-serif;
            color: var(--ink);
            min-height: 100vh;
            background: radial-gradient(circle at top right, #d9efe5 0%, rgba(217, 239, 229, 0) 45%),
                        linear-gradient(145deg, var(--bg), #efe5d4);
            display: grid;
            place-items: center;
            padding: 24px;
        }

        .app {
            width: min(100%, 900px);
            background: var(--panel);
            border: 1px solid var(--border);
            border-radius: 18px;
            overflow: hidden;
            box-shadow: 0 18px 50px rgba(22, 21, 19, 0.14);
        }

        .hero {
            padding: 20px;
            border-bottom: 1px solid var(--border);
            background: linear-gradient(120deg, #f4f2ea, #f8f1e2);
        }

        .hero h1 {
            margin: 0;
            font-size: clamp(1.4rem, 2.2vw, 2rem);
        }

        .hero p {
            margin: 8px 0 0;
            color: var(--muted);
        }

        .join {
            display: grid;
            gap: 12px;
            padding: 20px;
        }

        .join input {
            width: 100%;
            padding: 12px;
            border-radius: 10px;
            border: 1px solid var(--border);
            font-size: 1rem;
        }

        button {
            border: none;
            background: var(--accent);
            color: #fff;
            padding: 10px 14px;
            border-radius: 10px;
            font-weight: 700;
            cursor: pointer;
            transition: transform .15s ease, background-color .15s ease;
        }

        button:hover { background: var(--accent-strong); }
        button:active { transform: translateY(1px); }

        .chat {
            display: none;
            grid-template-rows: auto 1fr auto;
            min-height: 540px;
        }

        .chat.visible { display: grid; }

        .toolbar {
            padding: 12px 16px;
            border-bottom: 1px solid var(--border);
            display: flex;
            gap: 12px;
            align-items: center;
            justify-content: space-between;
            font-size: .95rem;

                .room-secret {
                    filter: blur(5px);
                    transition: filter .25s ease;
                    cursor: pointer;
                    user-select: none;
                }
                .room-secret:hover,
                .room-secret:focus {
                    filter: none;
                }
        }

        .messages {
            padding: 16px;
            display: grid;
            gap: 10px;
            overflow-y: auto;
            background:
                linear-gradient(90deg, rgba(47, 123, 95, 0.05) 1px, transparent 1px) 0 0 / 20px 20px,
                linear-gradient(var(--panel), #fdf7ec);
        }

        .msg {
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 10px;
            animation: pop .2s ease;
            background: var(--bubble-a);
        }

        .msg:nth-child(even) {
            background: var(--bubble-b);
        }

        .meta {
            color: var(--muted);
            font-size: .82rem;
            margin-bottom: 6px;
        }

        .text {
            white-space: pre-wrap;
            word-wrap: break-word;
            font-family: "Consolas", "Courier New", monospace;
            font-size: .92rem;
        }

        .image {
            margin-top: 8px;
            max-width: min(100%, 360px);
            border-radius: 8px;
            border: 1px solid var(--border);
        }

        .composer {
            border-top: 1px solid var(--border);
            padding: 14px;
            display: grid;
            gap: 10px;
            background: #f9f4e9;
        }

        textarea {
            width: 100%;
            min-height: 90px;
            max-height: 220px;
            resize: vertical;
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 10px;
            font-family: "Consolas", "Courier New", monospace;
            font-size: .95rem;
        }

        .composer-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            align-items: center;
            justify-content: space-between;
        }

        .hint {
            color: var(--muted);
            font-size: .85rem;
        }

        .status {
            min-height: 1.2em;
            color: #8e2f2f;
            font-size: .9rem;
        }

        .preview {
            max-width: 180px;
            border-radius: 8px;
            border: 1px solid var(--border);
            display: none;
        }

        .preview.visible { display: block; }

        @keyframes pop {
            from { opacity: 0; transform: translateY(4px); }
            to { opacity: 1; transform: translateY(0); }
        }

        @media (max-width: 700px) {
            body { padding: 10px; }
            .app { border-radius: 14px; }
            .toolbar { flex-direction: column; align-items: flex-start; }
            .chat { min-height: 75vh; }
        }

        /* ── Screen Sharing ── */
        .toolbar-actions {
            display: flex;
            gap: 8px;
            align-items: center;
            flex-wrap: wrap;
        }

        #shareScreenBtn  { background: #5b6abf; }
        #shareScreenBtn:hover  { background: #3d4a9e; }
        #watchScreenBtn  { background: #7a5baf; }
        #watchScreenBtn:hover  { background: #5a3d8e; }
        #stopScreenBtn   { background: #8e2f2f; font-size: .85rem; padding: 6px 10px; }
        #stopScreenBtn:hover   { background: #6e1f1f; }

        .screen-container {
            border-top: 1px solid var(--border);
            border-bottom: 1px solid var(--border);
            padding: 10px 16px;
            background: #0d1117;
        }

        .screen-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 8px;
            color: #c9d1d9;
            font-size: .88rem;
        }

        #screenVideo {
            width: 100%;
            max-height: 420px;
            background: #000;
            border-radius: 8px;
            display: block;
            object-fit: contain;
        }
    </style>
</head>
<body>
    <main class="app">
        <section class="hero">
            <h1>Local Temporary Talk Chat</h1>
            <p>File-based PHP chat. Join with a room key, paste code or images, and refresh/poll for updates.</p>
        </section>

        <section class="join" id="joinView">
            <input id="roomInput" type="text" maxlength="120" placeholder="Channel name">
            <input id="channelKeyInput" type="password" maxlength="200" placeholder="Encryption key (optional)">
            <input id="userInput" type="text" maxlength="40" placeholder="Name (optional, leave blank for anonymous)">
            <button id="joinBtn" type="button">Join Chat</button>
            <div class="hint">Use the same channel name and encryption key on all clients to read the same chat.</div>
            <div class="hint">If encryption key is empty, the room name is used as the encryption key.</div>
            <div class="hint">Encryption key is used only in your browser for encryption/decryption and is never sent to the server.</div>
            <div class="hint">Important: if you forget the encryption key, existing messages cannot be decrypted.</div>
            <div class="status" id="joinStatus"></div>
        </section>

        <section class="chat" id="chatView">
            <header class="toolbar">
                <div>
                    Room: <strong id="roomName" class="room-secret" title="Hover to reveal room name" tabindex="0"></strong> &middot; You: <strong id="userName"></strong>
                </div>
                <div class="toolbar-actions">
                    <button id="shareScreenBtn" type="button">Share Screen</button>
                    <button id="watchScreenBtn" type="button" style="display:none">Watch Screen</button>
                    <button id="stopScreenBtn"  type="button" style="display:none">Stop Sharing</button>
                    <button id="refreshBtn"     type="button">Refresh Now</button>
                </div>
            </header>

            <section class="messages" id="messages"></section>

            <section id="screenContainer" class="screen-container" style="display:none">
                <div class="screen-header">
                    <span id="screenLabel">Screen Share</span>
                </div>
                <video id="screenVideo" autoplay playsinline muted></video>
            </section>

            <section class="composer">
                <textarea id="messageInput" placeholder="Type a message or paste code. Press Ctrl+V to paste an image."></textarea>
                <img id="imagePreview" class="preview" alt="Pasted image preview">
                <div class="composer-actions">
                    <span class="hint">Auto-refresh every 3s. Ctrl+Enter to send.</span>
                    <button id="sendBtn" type="button">Send</button>
                </div>
                <div class="status" id="chatStatus"></div>
            </section>
        </section>
    </main>

    <script>
        // ── Web Crypto helpers (AES-256-GCM, key from user-provided secret via PBKDF2) ──
        const CRYPTO_SALT = new TextEncoder().encode('LocalTalkChat-v1-salt');

        async function deriveKey(secret) {
            const keyMaterial = await crypto.subtle.importKey(
                'raw',
            new TextEncoder().encode(secret),
                'PBKDF2',
                false,
                ['deriveKey']
            );
            return crypto.subtle.deriveKey(
                { name: 'PBKDF2', salt: CRYPTO_SALT, iterations: 200_000, hash: 'SHA-256' },
                keyMaterial,
                { name: 'AES-GCM', length: 256 },
                false,
                ['encrypt', 'decrypt']
            );
        }

        async function encryptText(key, plaintext) {
            const iv = crypto.getRandomValues(new Uint8Array(12));
            const cipher = await crypto.subtle.encrypt(
                { name: 'AES-GCM', iv },
                key,
                new TextEncoder().encode(plaintext)
            );
            const buf = new Uint8Array(12 + cipher.byteLength);
            buf.set(iv, 0);
            buf.set(new Uint8Array(cipher), 12);
            return btoa(String.fromCharCode(...buf));
        }

        async function decryptText(key, b64) {
            const buf = Uint8Array.from(atob(b64), c => c.charCodeAt(0));
            const plain = await crypto.subtle.decrypt(
                { name: 'AES-GCM', iv: buf.slice(0, 12) },
                key,
                buf.slice(12)
            );
            return new TextDecoder().decode(plain);
        }
        // ────────────────────────────────────────────────────────────────────────

        const state = {
            room: '',
            user: 'Guest',
            sinceId: 0,
            pendingImage: '',
            pollTimer: null,
            cryptoKey: null,
        };

        function generatePeerId() {
            const buf = crypto.getRandomValues(new Uint8Array(16));
            buf[6] = (buf[6] & 0x0f) | 0x40;
            buf[8] = (buf[8] & 0x3f) | 0x80;
            const hex = Array.from(buf, b => b.toString(16).padStart(2, '0')).join('');
            return `${hex.slice(0,8)}-${hex.slice(8,12)}-${hex.slice(12,16)}-${hex.slice(16,20)}-${hex.slice(20)}`;
        }

        const screenState = {
            peerId:         null,
            isSharing:      false,
            isWatching:     false,
            stream:         null,
            sharerPeerId:   null,
            sharerName:     null,
            peerConns:      {},
            viewerConn:     null,
            lastSignalId:   0,
            keepAliveTimer: null,
            warnedWrongKey: false,
        };

        const joinView = document.getElementById('joinView');
        const chatView = document.getElementById('chatView');
        const roomInput = document.getElementById('roomInput');
        const channelKeyInput = document.getElementById('channelKeyInput');
        const userInput = document.getElementById('userInput');
        const joinBtn = document.getElementById('joinBtn');
        const joinStatus = document.getElementById('joinStatus');

        const roomName = document.getElementById('roomName');
        const userName = document.getElementById('userName');
        const messagesEl = document.getElementById('messages');
        const refreshBtn = document.getElementById('refreshBtn');
        const sendBtn = document.getElementById('sendBtn');
        const messageInput = document.getElementById('messageInput');
        const chatStatus = document.getElementById('chatStatus');
        const imagePreview = document.getElementById('imagePreview');

        const callApi = async (action, payload) => {
            const response = await fetch(`?action=${encodeURIComponent(action)}`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload),
            });
            const data = await response.json();
            if (!data.ok) {
                throw new Error(data.error || 'Unknown API error');
            }
            return data;
        };

        const fmtTime = (iso) => {
            const d = new Date(iso);
            if (Number.isNaN(d.getTime())) {
                return iso;
            }
            return d.toLocaleString();
        };

        const appendMessage = async (message) => {
            const item = document.createElement('article');
            item.className = 'msg';

            const meta = document.createElement('div');
            meta.className = 'meta';
            meta.textContent = `${message.user} • ${fmtTime(message.timestamp)} • #${message.id}`;
            item.appendChild(meta);

            if (message.text) {
                const text = document.createElement('div');
                text.className = 'text';
                try {
                    text.textContent = await decryptText(state.cryptoKey, message.text);
                } catch {
                    text.textContent = '[could not decrypt message - probably wrong encryption key]';
                    text.style.color = '#8e2f2f';
                }
                item.appendChild(text);
            }

            if (message.encryptedImage) {
                const img = document.createElement('img');
                img.className = 'image';
                img.alt = 'pasted image';
                try {
                    img.src = await decryptText(state.cryptoKey, message.encryptedImage);
                } catch {
                    img.alt = '[could not decrypt image - probably wrong encryption key]';
                }
                item.appendChild(img);
            }

            messagesEl.appendChild(item);
            messagesEl.scrollTop = messagesEl.scrollHeight;
        };

        const refreshMessages = async () => {
            if (!state.room) {
                return;
            }
            try {
                const data = await callApi('fetch', {
                    room: state.room,
                    sinceId: state.sinceId,
                });

                for (const message of data.messages) {
                    await appendMessage(message);
                    state.sinceId = Math.max(state.sinceId, Number(message.id || 0));
                }
                chatStatus.textContent = '';
            } catch (err) {
                chatStatus.textContent = err.message;
            }
        };

        const sendMessage = async () => {
            if (!state.room) {
                return;
            }

            const rawText = messageInput.value;
            const rawImage = state.pendingImage;
            if (!rawText.trim() && !rawImage) {
                chatStatus.textContent = 'Type a message or paste an image first.';
                return;
            }

            sendBtn.disabled = true;
            try {
                const encText  = rawText.trim() ? await encryptText(state.cryptoKey, rawText) : '';
                const encImage = rawImage       ? await encryptText(state.cryptoKey, rawImage) : '';

                const data = await callApi('send', {
                    room: state.room,
                    user: state.user,
                    text: encText,
                    encryptedImage: encImage,
                });

                await appendMessage(data.message);
                state.sinceId = Math.max(state.sinceId, Number(data.message.id || 0));
                messageInput.value = '';
                state.pendingImage = '';
                imagePreview.src = '';
                imagePreview.classList.remove('visible');
                chatStatus.textContent = '';
            } catch (err) {
                chatStatus.textContent = err.message;
            } finally {
                sendBtn.disabled = false;
                messageInput.focus();
            }
        };

        joinBtn.addEventListener('click', async () => {
            const room = roomInput.value.trim();
            const channelKey = channelKeyInput.value;
            const user = userInput.value.trim() || `anon-${crypto.randomUUID().slice(0, 8)}`;

            if (!room) {
                joinStatus.textContent = 'Room name is required.';
                return;
            }
            joinBtn.disabled = true;
            joinStatus.textContent = 'Deriving encryption key…';
            try {
                state.cryptoKey = await deriveKey(channelKey || room);
            } catch (err) {
                joinStatus.textContent = 'Crypto error: ' + err.message;
                joinBtn.disabled = false;
                return;
            }
            joinBtn.disabled = false;
            joinStatus.textContent = '';
            state.room = room;
            state.user = user;
            state.sinceId = 0;

            roomName.textContent = room;
            userName.textContent = user;
            joinView.style.display = 'none';
            chatView.classList.add('visible');

            messagesEl.innerHTML = '';
            await refreshMessages();

            clearInterval(state.pollTimer);
            screenState.peerId       = generatePeerId();
            screenState.lastSignalId = 0;
            state.pollTimer = setInterval(async () => {
                await refreshMessages();
                await fetchAndHandleSignals();
            }, 3000);
            await fetchAndHandleSignals();
            messageInput.focus();
        });

        refreshBtn.addEventListener('click', refreshMessages);
        sendBtn.addEventListener('click', sendMessage);

        messageInput.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' && e.ctrlKey) {
                e.preventDefault();
                sendMessage();
            }
        });

        messageInput.addEventListener('paste', (event) => {
            const items = event.clipboardData?.items || [];
            for (const item of items) {
                if (item.type.startsWith('image/')) {
                    const file = item.getAsFile();
                    if (!file) {
                        continue;
                    }

                    const reader = new FileReader();
                    reader.onload = () => {
                        const result = typeof reader.result === 'string' ? reader.result : '';
                        if (!result.startsWith('data:image/')) {
                            chatStatus.textContent = 'Unable to read pasted image.';
                            return;
                        }

                        state.pendingImage = result;
                        imagePreview.src = result;
                        imagePreview.classList.add('visible');
                        chatStatus.textContent = 'Image pasted. Send to upload.';
                    };
                    reader.readAsDataURL(file);
                    return;
                }
            }
        });

        // ── Screen Sharing via WebRTC (signaling relayed through HTTP polling) ──
        const RTC_CONFIG = {
            iceServers: [
                { urls: 'stun:stun.l.google.com:19302' },
                { urls: 'stun:stun1.l.google.com:19302' },
            ],
        };

        const screenContainer = document.getElementById('screenContainer');
        const screenVideo     = document.getElementById('screenVideo');
        const screenLabel     = document.getElementById('screenLabel');
        const shareScreenBtn  = document.getElementById('shareScreenBtn');
        const watchScreenBtn  = document.getElementById('watchScreenBtn');
        const stopScreenBtn   = document.getElementById('stopScreenBtn');

        const postSignal = async (type, to, data) => {
            try {
                const plainData = typeof data === 'string' ? data : JSON.stringify(data);
                const encryptedData = await encryptText(state.cryptoKey, plainData);
                await callApi('signal', {
                    room: state.room,
                    from: screenState.peerId,
                    to,
                    type,
                    data: encryptedData,
                });
            } catch (err) {
                console.warn('Signal send failed:', err.message);
            }
        };

        const showSystemNotice = (html) => {
            const notice = document.createElement('article');
            notice.className = 'msg';
            notice.innerHTML = html;
            messagesEl.appendChild(notice);
            messagesEl.scrollTop = messagesEl.scrollHeight;
        };

        const closeViewerConn = () => {
            if (screenState.viewerConn) {
                screenState.viewerConn.close();
                screenState.viewerConn = null;
            }
            screenState.isWatching = false;
            screenVideo.srcObject = null;
            screenContainer.style.display = 'none';
            watchScreenBtn.textContent = 'Watch Screen';
            watchScreenBtn.disabled = false;
        };

        const closeSharerConns = () => {
            clearInterval(screenState.keepAliveTimer);
            screenState.keepAliveTimer = null;
            for (const pc of Object.values(screenState.peerConns)) {
                try { pc.close(); } catch (_) {}
            }
            screenState.peerConns = {};
            if (screenState.stream) {
                screenState.stream.getTracks().forEach(t => t.stop());
                screenState.stream = null;
            }
            screenState.isSharing = false;
            screenVideo.srcObject = null;
            screenContainer.style.display = 'none';
            shareScreenBtn.textContent = 'Share Screen';
            shareScreenBtn.disabled = false;
            stopScreenBtn.style.display = 'none';
        };

        // Sharer creates a dedicated RTCPeerConnection for each viewer
        const createOfferForViewer = async (viewerPeerId) => {
            if (screenState.peerConns[viewerPeerId]) return;
            if (!screenState.stream) return;

            const pc = new RTCPeerConnection(RTC_CONFIG);
            screenState.peerConns[viewerPeerId] = pc;

            screenState.stream.getTracks().forEach(track => {
                pc.addTrack(track, screenState.stream);
            });

            pc.onicecandidate = async ({ candidate }) => {
                if (candidate) {
                    await postSignal('ice', viewerPeerId, candidate.toJSON());
                }
            };

            pc.onconnectionstatechange = () => {
                if (['disconnected', 'failed', 'closed'].includes(pc.connectionState)) {
                    try { pc.close(); } catch (_) {}
                    delete screenState.peerConns[viewerPeerId];
                }
            };

            try {
                const offer = await pc.createOffer();
                await pc.setLocalDescription(offer);
                await postSignal('offer', viewerPeerId, { type: offer.type, sdp: offer.sdp });
            } catch (err) {
                console.warn('Failed to create offer:', err);
                delete screenState.peerConns[viewerPeerId];
            }
        };

        const handleSignal = async (sig) => {
            const { from, type, data } = sig;
            let parsed = {};
            if (data) {
                try {
                    const decrypted = await decryptText(state.cryptoKey, data);
                    parsed = decrypted ? JSON.parse(decrypted) : {};
                    screenState.warnedWrongKey = false;
                } catch (_) {
                    if (!screenState.warnedWrongKey) {
                        chatStatus.textContent = 'Screen-share signal ignored: wrong encryption key for this room.';
                        screenState.warnedWrongKey = true;
                    }
                    return;
                }
            }

            switch (type) {
                case 'screen-available': {
                    if (screenState.isSharing) break;
                    const isNewSharer = from !== screenState.sharerPeerId;
                    screenState.sharerPeerId = from;
                    screenState.sharerName   = parsed.name || 'Someone';
                    watchScreenBtn.style.display = '';
                    watchScreenBtn.textContent = `Watch ${screenState.sharerName}'s Screen`;
                    if (isNewSharer) {
                        showSystemNotice(`<div class="meta">System</div><div class="text" style="color:var(--accent)">📺 ${screenState.sharerName} started sharing their screen — click the Watch button in the toolbar to view.</div>`);
                    }
                    break;
                }

                case 'screen-stopped': {
                    if (from !== screenState.sharerPeerId) break;
                    const stoppedName = screenState.sharerName || 'Someone';
                    watchScreenBtn.style.display = 'none';
                    watchScreenBtn.textContent = 'Watch Screen';
                    screenState.sharerPeerId = null;
                    screenState.sharerName   = null;
                    closeViewerConn();
                    showSystemNotice(`<div class="meta">System</div><div class="text" style="color:var(--muted)">📺 ${stoppedName} stopped screen sharing.</div>`);
                    break;
                }

                case 'join-request': {
                    if (screenState.isSharing) {
                        await createOfferForViewer(from);
                    }
                    break;
                }

                case 'offer': {
                    if (!screenState.isWatching) break;
                    if (screenState.viewerConn) {
                        screenState.viewerConn.close();
                        screenState.viewerConn = null;
                    }
                    const sharerFrom = from;
                    const pc = new RTCPeerConnection(RTC_CONFIG);
                    screenState.viewerConn = pc;

                    pc.onicecandidate = async ({ candidate }) => {
                        if (candidate) {
                            await postSignal('ice', sharerFrom, candidate.toJSON());
                        }
                    };

                    pc.ontrack = ({ streams }) => {
                        if (streams && streams[0]) {
                            screenVideo.srcObject = streams[0];
                            screenContainer.style.display = '';
                            screenLabel.textContent = `📺 ${screenState.sharerName || 'Screen'} — Live`;
                            watchScreenBtn.textContent = 'Watching…';
                        }
                    };

                    pc.onconnectionstatechange = () => {
                        if (['disconnected', 'failed', 'closed'].includes(pc.connectionState)) {
                            closeViewerConn();
                        }
                    };

                    try {
                        await pc.setRemoteDescription(new RTCSessionDescription({ type: parsed.type, sdp: parsed.sdp }));
                        const answer = await pc.createAnswer();
                        await pc.setLocalDescription(answer);
                        await postSignal('answer', sharerFrom, { type: answer.type, sdp: answer.sdp });
                    } catch (err) {
                        console.warn('Failed to handle offer:', err);
                        closeViewerConn();
                    }
                    break;
                }

                case 'answer': {
                    if (!screenState.isSharing) break;
                    const apc = screenState.peerConns[from];
                    if (apc && apc.signalingState === 'have-local-offer') {
                        try {
                            await apc.setRemoteDescription(new RTCSessionDescription({ type: parsed.type, sdp: parsed.sdp }));
                        } catch (err) {
                            console.warn('Failed to set remote answer:', err);
                        }
                    }
                    break;
                }

                case 'ice': {
                    const candidate = new RTCIceCandidate(parsed);
                    if (screenState.isSharing && screenState.peerConns[from]) {
                        await screenState.peerConns[from].addIceCandidate(candidate).catch(console.warn);
                    } else if (screenState.isWatching && screenState.viewerConn) {
                        await screenState.viewerConn.addIceCandidate(candidate).catch(console.warn);
                    }
                    break;
                }
            }
        };

        const fetchAndHandleSignals = async () => {
            if (!state.room || !screenState.peerId) return;
            try {
                const data = await callApi('fetch-signals', {
                    room: state.room,
                    peerId: screenState.peerId,
                    sinceId: screenState.lastSignalId,
                });
                for (const sig of data.signals) {
                    screenState.lastSignalId = Math.max(screenState.lastSignalId, Number(sig.id || 0));
                    await handleSignal(sig);
                }
                if (data.signals.length === 0 && screenState.warnedWrongKey) {
                    chatStatus.textContent = '';
                }
            } catch (err) {
                console.warn('Signal fetch failed:', err.message);
            }
        };

        const startScreenShare = async () => {
            if (screenState.isSharing) return;
            try {
                const stream = await navigator.mediaDevices.getDisplayMedia({ video: true, audio: false });
                screenState.stream    = stream;
                screenState.isSharing = true;

                screenVideo.srcObject = stream;
                screenContainer.style.display = '';
                screenLabel.textContent = '📺 Your screen (you are sharing)';
                shareScreenBtn.textContent = 'Sharing…';
                shareScreenBtn.disabled    = true;
                stopScreenBtn.style.display = '';

                await postSignal('screen-available', 'all', { name: state.user });

                // Keep advertising every 5 s so peers that join later discover the share
                screenState.keepAliveTimer = setInterval(async () => {
                    if (screenState.isSharing) {
                        await postSignal('screen-available', 'all', { name: state.user });
                    }
                }, 5000);

                // Handle user stopping share from the browser's built-in "Stop sharing" button
                stream.getVideoTracks()[0].addEventListener('ended', () => stopScreenShare());
            } catch (err) {
                if (err.name !== 'NotAllowedError') {
                    chatStatus.textContent = 'Screen share failed: ' + err.message;
                }
            }
        };

        const stopScreenShare = async () => {
            if (!screenState.isSharing) return;
            await postSignal('screen-stopped', 'all', {});
            closeSharerConns();
        };

        const requestWatchScreen = async () => {
            if (screenState.isWatching) return;
            if (!screenState.sharerPeerId) {
                chatStatus.textContent = 'No active screen share in this room.';
                return;
            }
            screenState.isWatching = true;
            watchScreenBtn.textContent = 'Connecting…';
            watchScreenBtn.disabled    = true;
            screenLabel.textContent = `📺 Connecting to ${screenState.sharerName || 'screen'}…`;
            screenContainer.style.display = '';
            await postSignal('join-request', screenState.sharerPeerId, {});
        };

        shareScreenBtn.addEventListener('click', startScreenShare);
        stopScreenBtn.addEventListener('click', stopScreenShare);
        watchScreenBtn.addEventListener('click', requestWatchScreen);
    </script>
</body>
</html>
