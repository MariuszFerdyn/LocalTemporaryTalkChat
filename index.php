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
const MAX_TERMINAL_CHUNK = 24000;       // clipboard/terminal chunks are trimmed to this length
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
            if (!in_array($type, ['screen-available', 'screen-stopped', 'join-request', 'offer', 'answer', 'ice', 'bye', 'ai-share', 'ai-unshare', 'terminal-available', 'terminal-stopped', 'terminal-output', 'terminal-command'], true)) {
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
            white-space: normal;
            word-wrap: break-word;
            font-family: "Consolas", "Courier New", monospace;
            font-size: .92rem;
        }

        .text p {
            margin: 0 0 8px;
        }

        .text p:last-child {
            margin-bottom: 0;
        }

        .text pre {
            margin: 8px 0;
            padding: 10px;
            overflow-x: auto;
            border-radius: 8px;
            border: 1px solid #c8bda9;
            background: #f6efdf;
        }

        .text code {
            font-family: "Consolas", "Courier New", monospace;
            background: #f1e7d3;
            border-radius: 4px;
            padding: 1px 4px;
        }

        .text pre code {
            background: transparent;
            padding: 0;
        }

        .text a {
            color: #155b45;
            text-decoration: underline;
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
        #shareAIBtn      { background: #b56e2f; }
        #shareAIBtn:hover      { background: #8d531f; }
        #downloadTerminalHelperBtn { background: #5a7e28; }
        #downloadTerminalHelperBtn:hover { background: #46631f; }
        #copyTerminalHelperBtn { background: #6d8f33; }
        #copyTerminalHelperBtn:hover { background: #567127; }
        #shareTerminalBtn { background: #245f8f; }
        #shareTerminalBtn:hover { background: #1a4a72; }
        #stopTerminalBtn { background: #8e2f2f; font-size: .85rem; padding: 6px 10px; }
        #stopTerminalBtn:hover { background: #6e1f1f; }
        #closeTerminalSetupBtn { background: #6e6a61; }
        #closeTerminalSetupBtn:hover { background: #545149; }
        #stopScreenBtn   { background: #8e2f2f; font-size: .85rem; padding: 6px 10px; }
        #stopScreenBtn:hover   { background: #6e1f1f; }

        .ai-panel {
            display: none;
            border: 1px solid var(--border);
            border-radius: 10px;
            background: #f4ecdc;
            padding: 10px;
            gap: 8px;
        }

        .ai-panel.visible {
            display: grid;
        }

        .ai-panel input {
            width: 100%;
            padding: 10px;
            border-radius: 8px;
            border: 1px solid var(--border);
            font-size: .95rem;
        }

        .ai-panel-actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            align-items: center;
        }

        #saveAIShareBtn {
            background: #2f7b5f;
        }

        #cancelAIShareBtn {
            background: #6e6a61;
        }

        #cancelAIShareBtn:hover {
            background: #545149;
        }

        #aiShareStatus {
            color: #8e2f2f;
            font-size: .85rem;
            min-height: 1.1em;
        }

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

        .terminal-container {
            border-top: 1px solid var(--border);
            border-bottom: 1px solid var(--border);
            padding: 10px 16px;
            background: #101822;
            color: #c9d1d9;
            display: grid;
            gap: 10px;
        }

        .terminal-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: .88rem;
        }

        .terminal-output {
            margin: 0;
            background: #0a1118;
            border: 1px solid #2f3f52;
            border-radius: 8px;
            min-height: 180px;
            max-height: 360px;
            overflow: auto;
            padding: 10px;
            white-space: pre-wrap;
            word-break: break-word;
            font-family: "Consolas", "Courier New", monospace;
            font-size: .86rem;
            line-height: 1.4;
        }
        .terminal-controls {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            align-items: center;
        }

        .terminal-controls input {
            flex: 1 1 320px;
            min-width: 220px;
            padding: 10px;
            border-radius: 8px;
            border: 1px solid #3f536b;
            background: #0d1520;
            color: #d7e2f0;
        }

        #sendTerminalCommandBtn { background: #1f7b67; }
        #sendTerminalCommandBtn:hover { background: #185f4f; }
        #clearTerminalBtn { background: #6e6a61; }
        #clearTerminalBtn:hover { background: #545149; }

        .terminal-hint {
            color: #9cb2c7;
            font-size: .82rem;
        }

        .terminal-setup-panel {
            margin-top: 8px;
        }

        .terminal-manual {
            margin: 0;
            padding-left: 18px;
            color: var(--muted);
            font-size: .88rem;
            display: grid;
            gap: 3px;
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
                    <button id="shareAIBtn"     type="button">Share AI</button>
                    <button id="shareTerminalBtn" type="button">Share Terminal</button>
                    <button id="stopTerminalBtn" type="button" style="display:none">Stop Terminal Share</button>
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

            <section id="terminalContainer" class="terminal-container" style="display:none">
                <div class="terminal-header">
                    <span id="terminalLabel">Shared Terminal</span>
                </div>
                <pre id="terminalOutput" class="terminal-output">Terminal output will appear here.</pre>
                <div class="terminal-controls">
                    <input id="terminalCommandInput" type="text" maxlength="1000" placeholder="Type command to execute on helper terminal">
                    <button id="sendTerminalCommandBtn" type="button">Send Command</button>
                    <button id="clearTerminalBtn" type="button">Clear</button>
                </div>
                <div class="terminal-hint">Terminal chunks and command payloads are encrypted with the room key (AES-GCM).</div>
            </section>

            <section class="composer">
                <textarea id="messageInput" placeholder="Type a message or paste code. Press Ctrl+V to paste an image."></textarea>
                <img id="imagePreview" class="preview" alt="Pasted image preview">
                <section class="ai-panel" id="aiSharePanel">
                    <input id="aiNameInput" type="text" maxlength="40" placeholder="AI Name (example: CoderBot)">
                    <input id="aiModelInput" type="text" maxlength="160" placeholder="Model (example: qwen3-coder-next:q4_K_M)">
                    <input id="aiApiBaseInput" type="url" maxlength="300" placeholder="API Base (example: https://host/api)">
                    <input id="aiApiKeyInput" type="password" maxlength="400" placeholder="API Key">
                    <div class="ai-panel-actions">
                        <button id="saveAIShareBtn" type="button">Start Sharing This AI</button>
                        <button id="cancelAIShareBtn" type="button">Cancel</button>
                        <span class="hint">Only AI name, model and API base are announced to the room. API key stays local and encrypted prompts are processed only on your client.</span>
                    </div>
                    <div id="aiShareStatus"></div>
                </section>
                <section class="ai-panel terminal-setup-panel" id="terminalSharePanel">
                    <div class="hint"><strong>Terminal Share Setup</strong></div>
                    <ol class="terminal-manual">
                        <li>Download or copy the PowerShell helper script.</li>
                        <li>Run it on the terminal owner machine with the same room/key (PowerShell 7+ or Windows PowerShell 5.1 on Windows).</li>
                        <li>Terminal output copied to clipboard is published as encrypted chunks.</li>
                        <li>Incoming commands are executed automatically and output is posted back.</li>
                    </ol>
                    <div class="ai-panel-actions">
                        <button id="downloadTerminalHelperBtn" type="button">Download PowerShell Helper</button>
                        <button id="copyTerminalHelperBtn" type="button">Copy Helper Script</button>
                        <button id="closeTerminalSetupBtn" type="button">Close</button>
                    </div>
                </section>
                <div class="composer-actions">
                    <span class="hint">Auto-refresh every 3s. Ctrl+Enter to send. Markdown supported. Use AIName: prompt to request a shared AI.</span>
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
            return bytesToBase64(buf);
        }

        function bytesToBase64(bytes) {
            // Chunked conversion avoids "Maximum call stack size exceeded"
            // that occurs with String.fromCharCode(...bytes) on large payloads (e.g. images).
            let binary = '';
            const chunkSize = 0x8000;
            for (let i = 0; i < bytes.length; i += chunkSize) {
                binary += String.fromCharCode.apply(null, bytes.subarray(i, i + chunkSize));
            }
            return btoa(binary);
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
            channelSecret: '',
        };

        const TERMINAL_MAX_CHUNK = <?php echo MAX_TERMINAL_CHUNK; ?>;

        const aiState = {
            providers: {},
            localShares: {},
            processingMessageIds: new Set(),
            processedMessageIds: new Set(),
            advertiseTimer: null,
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

        const terminalState = {
            isSharing: false,
            helperPeerId: null,
            activePeerId: null,
            sharerName: null,
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
        const shareAIBtn = document.getElementById('shareAIBtn');
        const aiSharePanel = document.getElementById('aiSharePanel');
        const aiNameInput = document.getElementById('aiNameInput');
        const aiModelInput = document.getElementById('aiModelInput');
        const aiApiBaseInput = document.getElementById('aiApiBaseInput');
        const aiApiKeyInput = document.getElementById('aiApiKeyInput');
        const saveAIShareBtn = document.getElementById('saveAIShareBtn');
        const cancelAIShareBtn = document.getElementById('cancelAIShareBtn');
        const aiShareStatus = document.getElementById('aiShareStatus');
        const terminalSharePanel = document.getElementById('terminalSharePanel');
        const downloadTerminalHelperBtn = document.getElementById('downloadTerminalHelperBtn');
        const copyTerminalHelperBtn = document.getElementById('copyTerminalHelperBtn');
        const closeTerminalSetupBtn = document.getElementById('closeTerminalSetupBtn');
        const shareTerminalBtn = document.getElementById('shareTerminalBtn');
        const stopTerminalBtn = document.getElementById('stopTerminalBtn');
        const terminalContainer = document.getElementById('terminalContainer');
        const terminalLabel = document.getElementById('terminalLabel');
        const terminalOutput = document.getElementById('terminalOutput');
        const terminalCommandInput = document.getElementById('terminalCommandInput');
        const sendTerminalCommandBtn = document.getElementById('sendTerminalCommandBtn');
        const clearTerminalBtn = document.getElementById('clearTerminalBtn');

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

        const escapeHtml = (value) => String(value)
            .replaceAll('&', '&amp;')
            .replaceAll('<', '&lt;')
            .replaceAll('>', '&gt;')
            .replaceAll('"', '&quot;')
            .replaceAll("'", '&#39;');

        const renderMarkdown = (raw) => {
            let text = escapeHtml(raw).replace(/\r\n/g, '\n');

            const fenced = [];
            text = text.replace(/```([a-zA-Z0-9_-]+)?\n?([\s\S]*?)```/g, (_, lang, code) => {
                const index = fenced.length;
                fenced.push(`<pre><code${lang ? ` class="language-${lang}"` : ''}>${code.trimEnd()}</code></pre>`);
                return `@@FENCED_${index}@@`;
            });

            text = text.replace(/`([^`\n]+)`/g, '<code>$1</code>');
            text = text.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
            text = text.replace(/(^|[^*])\*([^*\n]+)\*(?=$|[^*])/g, '$1<em>$2</em>');
            text = text.replace(/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/g, '<a href="$2" target="_blank" rel="noopener noreferrer">$1</a>');

            const blocks = text
                .split(/\n{2,}/)
                .map((block) => block.trim())
                .filter(Boolean)
                .map((block) => {
                    if (block.startsWith('@@FENCED_') && block.endsWith('@@')) {
                        return block;
                    }
                    return `<p>${block.replace(/\n/g, '<br>')}</p>`;
                });

            let html = blocks.join('');
            html = html.replace(/@@FENCED_(\d+)@@/g, (_, idx) => fenced[Number(idx)] || '');
            return html || '<p></p>';
        };

        const normalizeAiName = (name) => name.trim().toLowerCase();

        const normalizeApiBase = (base) => base.trim().replace(/\/+$/, '');

        const isPromptForSharedAi = (text) => {
            const match = /^([A-Za-z0-9_.-]{1,40})\s*:\s*([\s\S]+)$/.exec(text.trim());
            if (!match) {
                return null;
            }
            const aiName = match[1].trim();
            const prompt = match[2].trim();
            if (!prompt) {
                return null;
            }
            return { aiName, prompt };
        };

        const setAiShareUi = (isSharing) => {
            shareAIBtn.textContent = isSharing ? 'Stop AI Share' : 'Share AI';
            aiNameInput.disabled = isSharing;
            aiModelInput.disabled = isSharing;
            aiApiBaseInput.disabled = isSharing;
            aiApiKeyInput.disabled = isSharing;
            saveAIShareBtn.textContent = isSharing ? 'AI Is Shared' : 'Start Sharing This AI';
            saveAIShareBtn.disabled = isSharing;
        };

        const hasAnyLocalAiShare = () => Object.keys(aiState.localShares).length > 0;

        const startAiAnnounceLoop = () => {
            clearInterval(aiState.advertiseTimer);
            aiState.advertiseTimer = setInterval(async () => {
                if (!state.room || !screenState.peerId) {
                    return;
                }
                const shares = Object.values(aiState.localShares);
                for (const share of shares) {
                    await postSignal('ai-share', 'all', {
                        aiName: share.aiName,
                        model: share.model,
                        apiBase: share.apiBase,
                        sharerName: state.user,
                    });
                }
            }, 20000);
        };

        const stopAiAnnounceLoop = () => {
            clearInterval(aiState.advertiseTimer);
            aiState.advertiseTimer = null;
        };

        const stopAllLocalAiShares = async () => {
            const shares = Object.values(aiState.localShares);
            aiState.localShares = {};
            setAiShareUi(false);
            for (const share of shares) {
                await postSignal('ai-unshare', 'all', { aiName: share.aiName });
            }
            stopAiAnnounceLoop();
        };

        const extractAiReplyText = (responseJson) => {
            const fromChoices = responseJson?.choices?.[0];
            const messageContent = fromChoices?.message?.content;
            if (typeof messageContent === 'string' && messageContent.trim()) {
                return messageContent.trim();
            }
            if (Array.isArray(messageContent)) {
                const combined = messageContent
                    .map((p) => (typeof p?.text === 'string' ? p.text : ''))
                    .join('\n')
                    .trim();
                if (combined) {
                    return combined;
                }
            }
            const textChoice = fromChoices?.text;
            if (typeof textChoice === 'string' && textChoice.trim()) {
                return textChoice.trim();
            }
            if (typeof responseJson?.message?.content === 'string' && responseJson.message.content.trim()) {
                return responseJson.message.content.trim();
            }
            if (typeof responseJson?.response === 'string' && responseJson.response.trim()) {
                return responseJson.response.trim();
            }
            if (typeof responseJson?.output_text === 'string' && responseJson.output_text.trim()) {
                return responseJson.output_text.trim();
            }
            throw new Error('AI API returned no usable text response.');
        };

        const fetchAiCompletion = async (share, prompt) => {
            const base = normalizeApiBase(share.apiBase);
            const endpointCandidates = [
                `${base}/chat/completions`,
                `${base}/v1/chat/completions`,
                `${base}/openai/v1/chat/completions`,
            ];
            const seen = new Set();
            const uniqueEndpoints = endpointCandidates.filter((url) => {
                if (seen.has(url)) {
                    return false;
                }
                seen.add(url);
                return true;
            });

            let lastError = 'Unknown AI API error';
            for (const endpoint of uniqueEndpoints) {
                const controller = new AbortController();
                const timeout = setTimeout(() => controller.abort(), 90000);
                try {
                    const headers = { 'Content-Type': 'application/json' };
                    if (share.apiKey) {
                        headers.Authorization = `Bearer ${share.apiKey}`;
                        headers['X-API-Key'] = share.apiKey;
                    }
                    const response = await fetch(endpoint, {
                        method: 'POST',
                        headers,
                        signal: controller.signal,
                        body: JSON.stringify({
                            model: share.model,
                            messages: [
                                {
                                    role: 'system',
                                    content: `You are ${share.aiName}. Keep responses concise unless asked for detail.`,
                                },
                                {
                                    role: 'user',
                                    content: prompt,
                                },
                            ],
                            stream: false,
                        }),
                    });

                    const textBody = await response.text();
                    if (!response.ok) {
                        lastError = `HTTP ${response.status} at ${endpoint}: ${textBody.slice(0, 220)}`;
                        continue;
                    }

                    let json;
                    try {
                        json = JSON.parse(textBody);
                    } catch {
                        throw new Error('AI API returned non-JSON response.');
                    }

                    return extractAiReplyText(json);
                } catch (err) {
                    lastError = `${endpoint} failed: ${err.message}`;
                } finally {
                    clearTimeout(timeout);
                }
            }

            throw new Error(lastError);
        };

        const sendAiAnswer = async (replyText, aiName, sourceMessageId) => {
            const output = `${aiName} reply to #${sourceMessageId}:\n${replyText}`;
            const encText = await encryptText(state.cryptoKey, output);
            const data = await callApi('send', {
                room: state.room,
                user: `${aiName} via ${state.user}`,
                text: encText,
                encryptedImage: '',
            });
            await appendMessage(data.message);
            state.sinceId = Math.max(state.sinceId, Number(data.message.id || 0));
        };

        const sendSystemChatMessage = async (plainText) => {
            const encText = await encryptText(state.cryptoKey, plainText);
            const data = await callApi('send', {
                room: state.room,
                user: 'System',
                text: encText,
                encryptedImage: '',
            });
            await appendMessage(data.message);
            state.sinceId = Math.max(state.sinceId, Number(data.message.id || 0));
        };

        const maybeProcessAiPrompt = async (message) => {
            if (!message?.text || !state.cryptoKey) {
                return;
            }
            if (aiState.processedMessageIds.has(message.id) || aiState.processingMessageIds.has(message.id)) {
                return;
            }

            let plainText = '';
            try {
                plainText = await decryptText(state.cryptoKey, message.text);
            } catch {
                return;
            }

            const parsed = isPromptForSharedAi(plainText);
            if (!parsed) {
                aiState.processedMessageIds.add(message.id);
                return;
            }

            const aiKey = normalizeAiName(parsed.aiName);
            const provider = aiState.providers[aiKey];
            const localShare = aiState.localShares[aiKey];
            if (!provider || !localShare) {
                aiState.processedMessageIds.add(message.id);
                return;
            }

            const msgTs = Date.parse(String(message.timestamp || ''));
            if (!Number.isNaN(msgTs) && msgTs < localShare.startedAt) {
                aiState.processedMessageIds.add(message.id);
                return;
            }

            if (provider.sharerPeerId !== screenState.peerId || localShare.sharerPeerId !== screenState.peerId) {
                aiState.processedMessageIds.add(message.id);
                return;
            }

            aiState.processingMessageIds.add(message.id);
            try {
                const answer = await fetchAiCompletion(localShare, parsed.prompt);
                await sendAiAnswer(answer, localShare.aiName, message.id);
            } catch (err) {
                const fallback = `${localShare.aiName} error for #${message.id}: ${err.message}`;
                const encText = await encryptText(state.cryptoKey, fallback);
                const data = await callApi('send', {
                    room: state.room,
                    user: `${localShare.aiName} via ${state.user}`,
                    text: encText,
                    encryptedImage: '',
                });
                await appendMessage(data.message);
                state.sinceId = Math.max(state.sinceId, Number(data.message.id || 0));
            } finally {
                aiState.processingMessageIds.delete(message.id);
                aiState.processedMessageIds.add(message.id);
            }
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
                    text.innerHTML = renderMarkdown(await decryptText(state.cryptoKey, message.text));
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
                    await maybeProcessAiPrompt(message);
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
            state.channelSecret = channelKey || room;
            state.sinceId = 0;
            aiState.providers = {};
            aiState.localShares = {};
            aiState.processedMessageIds.clear();
            aiState.processingMessageIds.clear();
            stopAiAnnounceLoop();
            aiShareStatus.textContent = '';
            aiSharePanel.classList.remove('visible');
            setAiShareUi(false);

            roomName.textContent = room;
            userName.textContent = user;
            joinView.style.display = 'none';
            chatView.classList.add('visible');

            messagesEl.innerHTML = '';
            await refreshMessages();

            clearInterval(state.pollTimer);
            screenState.peerId       = generatePeerId();
            screenState.lastSignalId = 0;
            terminalState.activePeerId = null;
            terminalState.sharerName = null;
            terminalState.helperPeerId = null;
            terminalState.isSharing = false;
            terminalState.warnedWrongKey = false;
            clearInterval(terminalState.keepAliveTimer);
            terminalState.keepAliveTimer = null;
            shareTerminalBtn.textContent = 'Share Terminal';
            shareTerminalBtn.disabled = false;
            shareTerminalBtn.style.display = '';
            stopTerminalBtn.style.display = 'none';
            terminalLabel.textContent = 'Shared Terminal';
            terminalOutput.textContent = 'Terminal output will appear here.';
            terminalSharePanel.classList.remove('visible');
            terminalContainer.style.display = 'none';
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

        shareAIBtn.addEventListener('click', async () => {
            if (hasAnyLocalAiShare()) {
                await stopAllLocalAiShares();
                aiShareStatus.textContent = 'AI sharing stopped.';
                return;
            }
            aiSharePanel.classList.toggle('visible');
            if (aiSharePanel.classList.contains('visible')) {
                aiNameInput.focus();
            }
        });

        cancelAIShareBtn.addEventListener('click', () => {
            aiSharePanel.classList.remove('visible');
            aiShareStatus.textContent = '';
        });

        saveAIShareBtn.addEventListener('click', async () => {
            if (!state.room || !screenState.peerId) {
                aiShareStatus.textContent = 'Join a room first.';
                return;
            }

            const aiName = aiNameInput.value.trim();
            const model = aiModelInput.value.trim();
            const apiBase = normalizeApiBase(aiApiBaseInput.value);
            const apiKey = aiApiKeyInput.value;

            if (!/^[A-Za-z0-9_.-]{1,40}$/.test(aiName)) {
                aiShareStatus.textContent = 'AI Name must use only letters, numbers, dot, underscore or dash.';
                return;
            }
            if (!model) {
                aiShareStatus.textContent = 'Model is required.';
                return;
            }
            if (!apiBase || !/^https?:\/\//i.test(apiBase)) {
                aiShareStatus.textContent = 'API Base must be a valid http(s) URL.';
                return;
            }

            const aiKey = normalizeAiName(aiName);
            aiState.localShares[aiKey] = {
                aiName,
                model,
                apiBase,
                apiKey,
                sharerPeerId: screenState.peerId,
                startedAt: Date.now(),
            };
            aiState.providers[aiKey] = {
                aiName,
                model,
                apiBase,
                sharerPeerId: screenState.peerId,
                sharerName: state.user,
                at: Date.now(),
            };

            setAiShareUi(true);
            aiSharePanel.classList.remove('visible');
            aiShareStatus.textContent = '';
            await postSignal('ai-share', 'all', {
                aiName,
                model,
                apiBase,
                sharerName: state.user,
            });
            startAiAnnounceLoop();
            await sendSystemChatMessage(`🤖 ${state.user} shared AI "${aiName}". Ask with: ${aiName}: your prompt`);
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

        const isValidPeerId = (value) => /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/.test(value);

        const escapePsSingleQuoted = (value) => String(value).replaceAll("'", "''");

        const buildPowerShellHelperScript = () => {
            const serverBase = `${window.location.origin}${window.location.pathname}`;
            const room = state.room;
            const secret = state.channelSecret || state.room;
            const userName = state.user;
            const helperPeerId = terminalState.helperPeerId || generatePeerId();

            return `# Local Temporary Talk Chat terminal helper (PowerShell 7+ or Windows PowerShell 5.1 on Windows)
$ErrorActionPreference = 'Stop'

$ServerBase = '${escapePsSingleQuoted(serverBase)}'
$Room = '${escapePsSingleQuoted(room)}'
$Secret = '${escapePsSingleQuoted(secret)}'
$UserName = '${escapePsSingleQuoted(userName)}'
$PeerId = '${escapePsSingleQuoted(helperPeerId)}'
$SinceId = 0
$LastClipboard = ''
$Salt = [System.Text.Encoding]::UTF8.GetBytes('LocalTalkChat-v1-salt')

$HasDotNetAesGcm = [bool]('System.Security.Cryptography.AesGcm' -as [type])
$UseWinCngAesGcm = $false

if (-not $HasDotNetAesGcm) {
    if ($env:OS -ne 'Windows_NT') {
        throw "AES-GCM runtime support is unavailable. Use PowerShell 7+ on this platform."
    }

    Add-Type -Language CSharp -TypeDefinition @"
using System;
using System.Runtime.InteropServices;
using System.Text;

public static class WinAesGcmCompat
{
    [StructLayout(LayoutKind.Sequential)]
    private struct BCRYPT_AUTHENTICATED_CIPHER_MODE_INFO
    {
        public int cbSize;
        public int dwInfoVersion;
        public IntPtr pbNonce;
        public int cbNonce;
        public IntPtr pbAuthData;
        public int cbAuthData;
        public IntPtr pbTag;
        public int cbTag;
        public IntPtr pbMacContext;
        public int cbMacContext;
        public int cbAAD;
        public long cbData;
        public int dwFlags;
    }

    private const string BCRYPT_AES_ALGORITHM = "AES";
    private const string BCRYPT_CHAINING_MODE = "ChainingMode";
    private const string BCRYPT_CHAIN_MODE_GCM = "ChainingModeGCM";

    [DllImport("bcrypt.dll", CharSet = CharSet.Unicode)]
    private static extern int BCryptOpenAlgorithmProvider(out IntPtr phAlgorithm, string pszAlgId, string pszImplementation, int dwFlags);

    [DllImport("bcrypt.dll")]
    private static extern int BCryptCloseAlgorithmProvider(IntPtr hAlgorithm, int dwFlags);

    [DllImport("bcrypt.dll", CharSet = CharSet.Unicode)]
    private static extern int BCryptSetProperty(IntPtr hObject, string pszProperty, byte[] pbInput, int cbInput, int dwFlags);

    [DllImport("bcrypt.dll")]
    private static extern int BCryptGenerateSymmetricKey(IntPtr hAlgorithm, out IntPtr phKey, IntPtr pbKeyObject, int cbKeyObject, byte[] pbSecret, int cbSecret, int dwFlags);

    [DllImport("bcrypt.dll")]
    private static extern int BCryptDestroyKey(IntPtr hKey);

    [DllImport("bcrypt.dll")]
    private static extern int BCryptEncrypt(IntPtr hKey, byte[] pbInput, int cbInput, ref BCRYPT_AUTHENTICATED_CIPHER_MODE_INFO pPaddingInfo, IntPtr pbIV, int cbIV, byte[] pbOutput, int cbOutput, out int pcbResult, int dwFlags);

    [DllImport("bcrypt.dll")]
    private static extern int BCryptDecrypt(IntPtr hKey, byte[] pbInput, int cbInput, ref BCRYPT_AUTHENTICATED_CIPHER_MODE_INFO pPaddingInfo, IntPtr pbIV, int cbIV, byte[] pbOutput, int cbOutput, out int pcbResult, int dwFlags);

    private static void Check(int status, string op)
    {
        if (status != 0)
        {
            throw new InvalidOperationException(op + " failed with NTSTATUS 0x" + status.ToString("X8"));
        }
    }

    private static IntPtr CreateAesGcmKey(byte[] key, out IntPtr hAlg)
    {
        Check(BCryptOpenAlgorithmProvider(out hAlg, BCRYPT_AES_ALGORITHM, null, 0), "BCryptOpenAlgorithmProvider");
        try
        {
            byte[] gcm = Encoding.Unicode.GetBytes(BCRYPT_CHAIN_MODE_GCM + "\0");
            Check(BCryptSetProperty(hAlg, BCRYPT_CHAINING_MODE, gcm, gcm.Length, 0), "BCryptSetProperty");

            IntPtr hKey;
            Check(BCryptGenerateSymmetricKey(hAlg, out hKey, IntPtr.Zero, 0, key, key.Length, 0), "BCryptGenerateSymmetricKey");
            return hKey;
        }
        catch
        {
            BCryptCloseAlgorithmProvider(hAlg, 0);
            throw;
        }
    }

    public static byte[] Encrypt(byte[] key, byte[] nonce, byte[] plaintext, out byte[] tag)
    {
        IntPtr hAlg = IntPtr.Zero;
        IntPtr hKey = IntPtr.Zero;
        GCHandle nonceHandle = default(GCHandle);
        GCHandle tagHandle = default(GCHandle);
        try
        {
            hKey = CreateAesGcmKey(key, out hAlg);
            byte[] ciphertext = new byte[plaintext.Length];
            tag = new byte[16];

            nonceHandle = GCHandle.Alloc(nonce, GCHandleType.Pinned);
            tagHandle = GCHandle.Alloc(tag, GCHandleType.Pinned);

            BCRYPT_AUTHENTICATED_CIPHER_MODE_INFO info = new BCRYPT_AUTHENTICATED_CIPHER_MODE_INFO();
            info.cbSize = Marshal.SizeOf(typeof(BCRYPT_AUTHENTICATED_CIPHER_MODE_INFO));
            info.dwInfoVersion = 1;
            info.pbNonce = nonceHandle.AddrOfPinnedObject();
            info.cbNonce = nonce.Length;
            info.pbAuthData = IntPtr.Zero;
            info.cbAuthData = 0;
            info.pbTag = tagHandle.AddrOfPinnedObject();
            info.cbTag = tag.Length;
            info.pbMacContext = IntPtr.Zero;
            info.cbMacContext = 0;
            info.cbAAD = 0;
            info.cbData = 0;
            info.dwFlags = 0;

            int result;
            Check(BCryptEncrypt(hKey, plaintext, plaintext.Length, ref info, IntPtr.Zero, 0, ciphertext, ciphertext.Length, out result, 0), "BCryptEncrypt");
            if (result != ciphertext.Length)
            {
                throw new InvalidOperationException("Unexpected ciphertext length from BCryptEncrypt");
            }
            return ciphertext;
        }
        finally
        {
            if (tagHandle.IsAllocated) tagHandle.Free();
            if (nonceHandle.IsAllocated) nonceHandle.Free();
            if (hKey != IntPtr.Zero) BCryptDestroyKey(hKey);
            if (hAlg != IntPtr.Zero) BCryptCloseAlgorithmProvider(hAlg, 0);
        }
    }

    public static byte[] Decrypt(byte[] key, byte[] nonce, byte[] ciphertext, byte[] tag)
    {
        IntPtr hAlg = IntPtr.Zero;
        IntPtr hKey = IntPtr.Zero;
        GCHandle nonceHandle = default(GCHandle);
        GCHandle tagHandle = default(GCHandle);
        try
        {
            hKey = CreateAesGcmKey(key, out hAlg);
            byte[] plaintext = new byte[ciphertext.Length];

            nonceHandle = GCHandle.Alloc(nonce, GCHandleType.Pinned);
            tagHandle = GCHandle.Alloc(tag, GCHandleType.Pinned);

            BCRYPT_AUTHENTICATED_CIPHER_MODE_INFO info = new BCRYPT_AUTHENTICATED_CIPHER_MODE_INFO();
            info.cbSize = Marshal.SizeOf(typeof(BCRYPT_AUTHENTICATED_CIPHER_MODE_INFO));
            info.dwInfoVersion = 1;
            info.pbNonce = nonceHandle.AddrOfPinnedObject();
            info.cbNonce = nonce.Length;
            info.pbAuthData = IntPtr.Zero;
            info.cbAuthData = 0;
            info.pbTag = tagHandle.AddrOfPinnedObject();
            info.cbTag = tag.Length;
            info.pbMacContext = IntPtr.Zero;
            info.cbMacContext = 0;
            info.cbAAD = 0;
            info.cbData = 0;
            info.dwFlags = 0;

            int result;
            Check(BCryptDecrypt(hKey, ciphertext, ciphertext.Length, ref info, IntPtr.Zero, 0, plaintext, plaintext.Length, out result, 0), "BCryptDecrypt");
            if (result != plaintext.Length)
            {
                throw new InvalidOperationException("Unexpected plaintext length from BCryptDecrypt");
            }
            return plaintext;
        }
        finally
        {
            if (tagHandle.IsAllocated) tagHandle.Free();
            if (nonceHandle.IsAllocated) nonceHandle.Free();
            if (hKey != IntPtr.Zero) BCryptDestroyKey(hKey);
            if (hAlg != IntPtr.Zero) BCryptCloseAlgorithmProvider(hAlg, 0);
        }
    }
}
"@

    $UseWinCngAesGcm = $true
}

function New-RoomKey([string]$SecretValue) {
    $derive = [System.Security.Cryptography.Rfc2898DeriveBytes]::new(
        [System.Text.Encoding]::UTF8.GetBytes($SecretValue),
        $Salt,
        200000,
        [System.Security.Cryptography.HashAlgorithmName]::SHA256
    )
    try {
        return $derive.GetBytes(32)
    } finally {
        $derive.Dispose()
    }
}

function Encrypt-Text([byte[]]$Key, [string]$PlainText) {
    $iv = New-Object byte[] 12
    $rng = [System.Security.Cryptography.RandomNumberGenerator]::Create()
    try {
        $rng.GetBytes($iv)
    } finally {
        $rng.Dispose()
    }
    $plainBytes = [System.Text.Encoding]::UTF8.GetBytes($PlainText)
    $cipherBytes = New-Object byte[] $plainBytes.Length
    $tag = New-Object byte[] 16
    if ($HasDotNetAesGcm) {
        $aes = [System.Security.Cryptography.AesGcm]::new($Key)
        try {
            $aes.Encrypt($iv, $plainBytes, $cipherBytes, $tag)
        } finally {
            $aes.Dispose()
        }
    } elseif ($UseWinCngAesGcm) {
        $cipherBytes = [WinAesGcmCompat]::Encrypt($Key, $iv, $plainBytes, [ref]$tag)
    } else {
        throw 'No AES-GCM implementation available in this runtime.'
    }
    $all = New-Object byte[] (12 + $cipherBytes.Length + 16)
    [Array]::Copy($iv, 0, $all, 0, 12)
    [Array]::Copy($cipherBytes, 0, $all, 12, $cipherBytes.Length)
    [Array]::Copy($tag, 0, $all, 12 + $cipherBytes.Length, 16)
    return [Convert]::ToBase64String($all)
}

function Decrypt-Text([byte[]]$Key, [string]$CipherB64) {
    $all = [Convert]::FromBase64String($CipherB64)
    if ($all.Length -lt 29) {
        throw 'Ciphertext too short.'
    }
    $iv = New-Object byte[] 12
    [Array]::Copy($all, 0, $iv, 0, 12)
    $tag = New-Object byte[] 16
    [Array]::Copy($all, $all.Length - 16, $tag, 0, 16)
    $cipherLen = $all.Length - 28
    $cipherBytes = New-Object byte[] $cipherLen
    [Array]::Copy($all, 12, $cipherBytes, 0, $cipherLen)
    $plainBytes = New-Object byte[] $cipherLen
    if ($HasDotNetAesGcm) {
        $aes = [System.Security.Cryptography.AesGcm]::new($Key)
        try {
            $aes.Decrypt($iv, $cipherBytes, $tag, $plainBytes)
        } finally {
            $aes.Dispose()
        }
    } elseif ($UseWinCngAesGcm) {
        $plainBytes = [WinAesGcmCompat]::Decrypt($Key, $iv, $cipherBytes, $tag)
    } else {
        throw 'No AES-GCM implementation available in this runtime.'
    }
    return [System.Text.Encoding]::UTF8.GetString($plainBytes)
}

function Resolve-BaseUri([string]$InputValue) {
    $candidate = [string]$InputValue
    $candidate = $candidate.Trim().Trim([char]39).Trim([char]34)
    if ([string]::IsNullOrWhiteSpace($candidate)) {
        throw 'Server base URL is empty.'
    }
    if ($candidate.StartsWith('//')) {
        $candidate = 'https:' + $candidate
    }
    if ($candidate -notmatch '^[A-Za-z][A-Za-z0-9+.-]*://') {
        $candidate = 'https://' + $candidate
    }

    try {
        $uri = [System.Uri]$candidate
    } catch {
        throw "Invalid server URL: '$InputValue'"
    }

    if (-not $uri.IsAbsoluteUri -or [string]::IsNullOrWhiteSpace($uri.Host)) {
        throw "Invalid server URL (hostname could not be parsed): '$InputValue'"
    }

    return $uri
}

$ServerBaseUri = Resolve-BaseUri $ServerBase

function Invoke-ChatApi([string]$Action, [hashtable]$Payload) {
    $builder = [System.UriBuilder]::new($ServerBaseUri)
    if ([string]::IsNullOrWhiteSpace($builder.Path)) {
        $builder.Path = '/'
    }
    $existingQuery = $builder.Query
    if ($existingQuery.StartsWith('?')) {
        $existingQuery = $existingQuery.Substring(1)
    }
    $actionQuery = 'action=' + [System.Uri]::EscapeDataString($Action)
    $builder.Query = if ([string]::IsNullOrWhiteSpace($existingQuery)) { $actionQuery } else { "$existingQuery&$actionQuery" }
    $uri = $builder.Uri.AbsoluteUri
    try {
        return Invoke-RestMethod -Method Post -Uri $uri -ContentType 'application/json' -Body ($Payload | ConvertTo-Json -Compress -Depth 8)
    } catch {
        $response = $_.Exception.Response
        $statusCode = if ($response) { [int]$response.StatusCode } else { 0 }
        $responseBody = ''
        if ($response) {
            try {
                $stream = $response.GetResponseStream()
                if ($stream) {
                    $reader = New-Object System.IO.StreamReader($stream)
                    try {
                        $responseBody = $reader.ReadToEnd()
                    } finally {
                        $reader.Dispose()
                    }
                }
            } catch {
                $responseBody = ''
            }
        }

        if ($statusCode -eq 401) {
            $hostName = $ServerBaseUri.Host
            throw "HTTP 401 Unauthorized for $hostName. If this is a GitHub Codespaces URL (*.app.github.dev), set forwarded port 8081 visibility to Public in the Ports panel, then download/copy a fresh helper script and run again."
        }

        if ($statusCode -gt 0) {
            if ([string]::IsNullOrWhiteSpace($responseBody)) {
                throw "HTTP $statusCode while calling $uri"
            }
            throw "HTTP $statusCode while calling $uri. Response: $responseBody"
        }

        throw ("Request failed while calling {0}: {1}" -f $uri, $_.Exception.Message)
    }
}

function Post-Signal([byte[]]$Key, [string]$Type, [string]$To, [hashtable]$Data) {
    $plain = ($Data | ConvertTo-Json -Compress -Depth 8)
    $enc = Encrypt-Text -Key $Key -PlainText $plain
    [void](Invoke-ChatApi -Action 'signal' -Payload @{
        room = $Room
        from = $PeerId
        to = $To
        type = $Type
        data = $enc
    })
}

$RoomKey = New-RoomKey -SecretValue $Secret

Write-Host "Terminal helper is running for room '$Room'."
Write-Host 'When a command arrives from a viewer, it will be executed and the output sent back automatically.'

[void](Invoke-ChatApi -Action 'fetch-signals' -Payload @{ room = $Room; peerId = $PeerId; sinceId = 0 })
Write-Host 'Connected to chat API successfully.'

$HelperEnabled = $true

Post-Signal -Key $RoomKey -Type 'terminal-available' -To 'all' -Data @{ name = $UserName; helperPeerId = $PeerId }

while ($true) {
    try {
        $signals = Invoke-ChatApi -Action 'fetch-signals' -Payload @{ room = $Room; peerId = $PeerId; sinceId = $SinceId }
        foreach ($sig in ($signals.signals | Sort-Object id)) {
            $SinceId = [Math]::Max($SinceId, [int]$sig.id)
            if (($sig.type -eq 'terminal-stopped' -or $sig.type -eq 'terminal-available') -and $sig.data) {
                try {
                    $plain = Decrypt-Text -Key $RoomKey -CipherB64 $sig.data
                    $payload = ConvertFrom-Json -InputObject $plain
                    $targetHelper = [string]$payload.helperPeerId
                    if ($targetHelper -and $targetHelper -eq $PeerId) {
                        if ($sig.type -eq 'terminal-stopped') {
                            if ($HelperEnabled) {
                                Write-Host 'Terminal sharing stopped by broadcaster. Helper is paused.'
                            }
                            $HelperEnabled = $false
                        } else {
                            if (-not $HelperEnabled) {
                                Write-Host 'Terminal sharing resumed by broadcaster.'
                            }
                            $HelperEnabled = $true
                        }
                    }
                } catch {
                    Write-Host "Failed to process helper lifecycle signal: $($_.Exception.Message)"
                }
                continue
            }
            if ($sig.type -eq 'terminal-command' -and $sig.data) {
                if (-not $HelperEnabled) {
                    continue
                }
                try {
                    $plain = Decrypt-Text -Key $RoomKey -CipherB64 $sig.data
                    $cmd = (ConvertFrom-Json -InputObject $plain).command
                    if ($cmd) {
                        Write-Host "Command received from viewer, executing: $cmd"
                        try {
                            $isCmdStyle = $cmd -match '^(?i)\s*(dir|copy|del|move|type|cd|chdir|cls|echo|set|where|find|findstr|tasklist|ipconfig|ping|systeminfo)\b'
                            if ($isCmdStyle) {
                                $output = & cmd.exe /d /s /c $cmd 2>&1 | Out-String
                            } else {
                                $output = Invoke-Expression $cmd 2>&1 | Out-String
                            }
                        } catch {
                            $output = "Execution failed: $($_.Exception.Message)"
                        }
                        $chunk = "> $cmd" + [Environment]::NewLine + $output
                        if ($chunk.Length -gt ${TERMINAL_MAX_CHUNK}) {
                            $chunk = $chunk.Substring($chunk.Length - ${TERMINAL_MAX_CHUNK})
                        }
                        Post-Signal -Key $RoomKey -Type 'terminal-output' -To 'all' -Data @{ name = $UserName; chunk = $chunk; source = 'exec' }
                        $LastClipboard = $chunk
                    }
                } catch {
                    Write-Host "Failed to process terminal command: $($_.Exception.Message)"
                }
            }
        }

        if ($HelperEnabled) {
            $clip = Get-Clipboard -Raw -ErrorAction SilentlyContinue
            if ($clip -and $clip -ne $LastClipboard) {
                $trimmedClip = $clip.TrimStart()
                if ($trimmedClip.StartsWith('# Local Temporary Talk Chat terminal helper')) {
                    $LastClipboard = $clip
                    continue
                }
                if ($trimmedClip.Contains("$ServerBase = '") -and $trimmedClip.Contains("$Room = '") -and $trimmedClip.Contains("$Secret = '")) {
                    $LastClipboard = $clip
                    continue
                }
                if ($clip.Length -gt ${TERMINAL_MAX_CHUNK}) {
                    $clip = $clip.Substring($clip.Length - ${TERMINAL_MAX_CHUNK})
                }
                Post-Signal -Key $RoomKey -Type 'terminal-output' -To 'all' -Data @{ name = $UserName; chunk = $clip; source = 'clipboard' }
                $LastClipboard = $clip
            }

            Post-Signal -Key $RoomKey -Type 'terminal-available' -To 'all' -Data @{ name = $UserName; helperPeerId = $PeerId }
        }
    } catch {
        Write-Host "Helper loop warning: $($_.Exception.Message)"
    }
    Start-Sleep -Milliseconds 1600
}
`;
        };

        const downloadPowerShellHelper = () => {
            if (!state.room || !state.cryptoKey) {
                chatStatus.textContent = 'Join a room before downloading helper.';
                return;
            }
            if (!terminalState.helperPeerId) {
                terminalState.helperPeerId = generatePeerId();
            }
            const content = buildPowerShellHelperScript();
            const blob = new Blob([content], { type: 'text/plain;charset=utf-8' });
            const url = URL.createObjectURL(blob);
            const link = document.createElement('a');
            link.href = url;
            const safeRoom = state.room.replace(/[^A-Za-z0-9._-]+/g, '-').slice(0, 40) || 'room';
            link.download = `terminal-helper-${safeRoom}.ps1`;
            document.body.appendChild(link);
            link.click();
            link.remove();
            URL.revokeObjectURL(url);
            chatStatus.textContent = 'PowerShell helper downloaded. Run it in PowerShell 7+ or Windows PowerShell 5.1 on Windows.';
        };

        const copyPowerShellHelper = async () => {
            if (!state.room || !state.cryptoKey) {
                chatStatus.textContent = 'Join a room before copying helper script.';
                return;
            }
            if (!terminalState.helperPeerId) {
                terminalState.helperPeerId = generatePeerId();
            }

            const content = buildPowerShellHelperScript();

            if (navigator.clipboard?.writeText) {
                await navigator.clipboard.writeText(content);
                chatStatus.textContent = 'PowerShell helper script copied. Paste into PowerShell 7+ or Windows PowerShell 5.1 on Windows.';
                return;
            }

            const fallback = document.createElement('textarea');
            fallback.value = content;
            fallback.setAttribute('readonly', 'readonly');
            fallback.style.position = 'fixed';
            fallback.style.left = '-9999px';
            document.body.appendChild(fallback);
            fallback.focus();
            fallback.select();
            const copied = document.execCommand('copy');
            document.body.removeChild(fallback);
            if (!copied) {
                throw new Error('Clipboard API unavailable in this browser context.');
            }
            chatStatus.textContent = 'PowerShell helper script copied. Paste into PowerShell 7+ or Windows PowerShell 5.1 on Windows.';
        };

        const appendTerminalChunk = (chunk, sharerName) => {
            const maxChars = 100000;
            const stamp = new Date().toLocaleTimeString();
            const normalized = String(chunk || '').replace(/\r\n/g, '\n').replace(/\r/g, '\n');
            const trimmed = normalized.trimStart();
            if (trimmed.startsWith('# Local Temporary Talk Chat terminal helper')) {
                return;
            }
            if (trimmed.includes("$ServerBase = '") && trimmed.includes("$Room = '") && trimmed.includes("$Secret = '")) {
                return;
            }
            const block = `[${stamp}] ${sharerName}:\n${normalized}\n\n`;
            const previous = terminalOutput.textContent === 'Terminal output will appear here.' ? '' : terminalOutput.textContent;
            const merged = previous + block;
            terminalOutput.textContent = merged.length > maxChars ? merged.slice(merged.length - maxChars) : merged;
            terminalOutput.scrollTop = terminalOutput.scrollHeight;
        };

        const startTerminalShare = async () => {
            if (!state.room || !screenState.peerId) {
                chatStatus.textContent = 'Join a room first.';
                return;
            }
            if (!terminalState.helperPeerId) {
                terminalState.helperPeerId = generatePeerId();
            }

            terminalState.isSharing = true;
            shareTerminalBtn.style.display = 'none';
            stopTerminalBtn.style.display = '';
            terminalSharePanel.classList.add('visible');
            terminalLabel.textContent = 'Shared Terminal - Waiting for helper output...';
            terminalContainer.style.display = '';

            await postSignal('terminal-available', 'all', {
                name: state.user,
                helperPeerId: terminalState.helperPeerId,
            });

            clearInterval(terminalState.keepAliveTimer);
            terminalState.keepAliveTimer = setInterval(async () => {
                if (!terminalState.isSharing || !terminalState.helperPeerId) {
                    return;
                }
                await postSignal('terminal-available', 'all', {
                    name: state.user,
                    helperPeerId: terminalState.helperPeerId,
                });
            }, 7000);

            chatStatus.textContent = 'Terminal share announced. Start the downloaded PowerShell helper on the owner computer.';
        };

        const stopTerminalShare = async () => {
            if (!terminalState.isSharing) {
                return;
            }
            if (terminalState.helperPeerId) {
                await postSignal('terminal-stopped', 'all', {
                    helperPeerId: terminalState.helperPeerId,
                    name: state.user,
                });
            }
            clearInterval(terminalState.keepAliveTimer);
            terminalState.keepAliveTimer = null;
            terminalState.isSharing = false;
            terminalState.activePeerId = null;
            terminalState.sharerName = null;
            shareTerminalBtn.textContent = 'Share Terminal';
            shareTerminalBtn.disabled = false;
            shareTerminalBtn.style.display = '';
            stopTerminalBtn.style.display = 'none';
            terminalContainer.style.display = 'none';
            terminalLabel.textContent = 'Shared Terminal';
            terminalOutput.textContent = 'Terminal output will appear here.';
        };

        const sendTerminalCommand = async () => {
            const command = terminalCommandInput.value.trim();
            if (!command) {
                chatStatus.textContent = 'Type a command to send.';
                return;
            }
            if (!terminalState.activePeerId) {
                chatStatus.textContent = 'No active terminal helper in this room.';
                return;
            }
            await postSignal('terminal-command', terminalState.activePeerId, {
                command,
                fromName: state.user,
            });
            terminalCommandInput.value = '';
            chatStatus.textContent = 'Command sent to helper.';
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
                    terminalState.warnedWrongKey = false;
                } catch (_) {
                    if (type.startsWith('terminal-')) {
                        if (!terminalState.warnedWrongKey) {
                            chatStatus.textContent = 'Terminal signal ignored: wrong encryption key for this room.';
                            terminalState.warnedWrongKey = true;
                        }
                        return;
                    }
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

                case 'ai-share': {
                    const aiName = (parsed.aiName || '').trim();
                    const model = (parsed.model || '').trim();
                    const apiBase = normalizeApiBase((parsed.apiBase || '').trim());
                    if (!/^[A-Za-z0-9_.-]{1,40}$/.test(aiName) || !model || !apiBase) {
                        break;
                    }
                    const key = normalizeAiName(aiName);
                    aiState.providers[key] = {
                        aiName,
                        model,
                        apiBase,
                        sharerPeerId: from,
                        sharerName: parsed.sharerName || 'Someone',
                        at: Date.now(),
                    };
                    break;
                }

                case 'ai-unshare': {
                    const aiName = (parsed.aiName || '').trim();
                    if (!aiName) {
                        break;
                    }
                    const key = normalizeAiName(aiName);
                    const provider = aiState.providers[key];
                    if (provider && provider.sharerPeerId === from) {
                        delete aiState.providers[key];
                    }
                    break;
                }

                case 'terminal-available': {
                    const helperPeerId = (parsed.helperPeerId || '').trim();
                    if (!isValidPeerId(helperPeerId)) {
                        break;
                    }
                    terminalState.activePeerId = helperPeerId;
                    terminalState.sharerName = (parsed.name || 'Someone').trim() || 'Someone';
                    terminalState.warnedWrongKey = false;
                    terminalLabel.textContent = `Shared Terminal - ${terminalState.sharerName}`;
                    terminalContainer.style.display = '';
                    break;
                }

                case 'terminal-stopped': {
                    const helperPeerId = (parsed.helperPeerId || '').trim();
                    if (helperPeerId && helperPeerId !== terminalState.activePeerId) {
                        break;
                    }
                    terminalState.activePeerId = null;
                    terminalState.sharerName = null;
                    terminalContainer.style.display = 'none';
                    terminalLabel.textContent = 'Shared Terminal';
                    terminalOutput.textContent = 'Terminal output will appear here.';
                    break;
                }

                case 'terminal-output': {
                    const chunk = typeof parsed.chunk === 'string' ? parsed.chunk : '';
                    if (!chunk) {
                        break;
                    }
                    if (terminalState.activePeerId && from !== terminalState.activePeerId) {
                        break;
                    }
                    if (!terminalState.activePeerId) {
                        terminalState.activePeerId = from;
                    }
                    const sharerName = (parsed.name || terminalState.sharerName || 'Terminal').trim() || 'Terminal';
                    terminalState.sharerName = sharerName;
                    terminalLabel.textContent = `Shared Terminal - ${sharerName}`;
                    terminalContainer.style.display = '';
                    appendTerminalChunk(chunk, sharerName);
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
                if (data.signals.length === 0 && (screenState.warnedWrongKey || terminalState.warnedWrongKey)) {
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
        downloadTerminalHelperBtn.addEventListener('click', downloadPowerShellHelper);
        copyTerminalHelperBtn.addEventListener('click', async () => {
            try {
                await copyPowerShellHelper();
            } catch (err) {
                chatStatus.textContent = 'Copy helper error: ' + err.message;
            }
        });
        closeTerminalSetupBtn.addEventListener('click', () => {
            terminalSharePanel.classList.remove('visible');
        });
        shareTerminalBtn.addEventListener('click', startTerminalShare);
        stopTerminalBtn.addEventListener('click', stopTerminalShare);
        sendTerminalCommandBtn.addEventListener('click', async () => {
            try {
                await sendTerminalCommand();
            } catch (err) {
                chatStatus.textContent = 'Terminal command error: ' + err.message;
            }
        });
        terminalCommandInput.addEventListener('keydown', async (e) => {
            if (e.key !== 'Enter') {
                return;
            }
            e.preventDefault();
            try {
                await sendTerminalCommand();
            } catch (err) {
                chatStatus.textContent = 'Terminal command error: ' + err.message;
            }
        });
        clearTerminalBtn.addEventListener('click', () => {
            terminalOutput.textContent = 'Terminal output will appear here.';
        });
    </script>
</body>
</html>
