# Add Feature Skill

**Purpose**: Guide for adding new features to the LocalTemporaryTalkChat application while preserving its core security and architecture principles.

**When to use**:
- Adding a new message type, action, or API endpoint
- Extending the JavaScript client with new UI or behavior
- Adding server-side storage or processing logic

---

## CRITICAL SECURITY RULE — Read this first

> **The encryption key MUST NEVER leave the browser.**
> **All encryption and decryption MUST happen in JavaScript (client-side), never on the server.**

This is the fundamental security contract of this application. Every feature you add must honor it.

### What this means in practice

| ✅ Allowed | ❌ Never do this |
|---|---|
| Encrypt data with `crypto.subtle` before sending to server | Send the encryption key in any HTTP request field |
| Send only ciphertext (base64-encoded) to the server | Log, store, or echo back an encryption key server-side |
| Derive keys client-side via PBKDF2 from a user-typed secret | Accept an encryption key as a PHP `$payload` field |
| Store an opaque encrypted blob in `.jsonl` storage | Decrypt messages in PHP |
| Keep the key only in `state.cryptoKey` (an in-memory `CryptoKey`) | Persist the key in `localStorage`, cookies, or URL params |

### How the existing encryption works (reference)

```
User types secret  ──►  deriveKey(secret)  ──►  state.cryptoKey  (CryptoKey, non-extractable)
                             │
                         PBKDF2 / SHA-256 / 200 000 iterations
                         Salt: "LocalTalkChat-v1-salt"
                         Output: AES-GCM-256

Send message:    plaintext  ──► encryptText(state.cryptoKey, plaintext)  ──► base64 ciphertext  ──► POST /send
Receive message: base64 ciphertext  ──► decryptText(state.cryptoKey, b64)  ──► plaintext  ──► render in DOM
```

Key functions already available in `index.php` (JavaScript section):
- `deriveKey(secret)` — PBKDF2 key derivation from a string secret
- `encryptText(key, plaintext)` — AES-256-GCM encrypt, returns base64 string (IV prepended)
- `decryptText(key, b64)` — AES-256-GCM decrypt, returns plaintext string

---

## CRITICAL ARCHITECTURE RULE — Single file

> **Everything MUST live in `index.php`.**
> Do NOT create additional `.php`, `.js`, `.css`, or `.html` files unless the user explicitly asks for it.

The entire application — server logic, HTML, CSS, and JavaScript — is intentionally kept in one file. This makes the app trivially portable: copy one file, it works.

- **New PHP logic** → add functions/action blocks inside `index.php`
- **New JavaScript** → add inside the `<script>` block in `index.php`
- **New styles** → add inside the `<style>` block in `index.php`
- **New HTML** → add inside the relevant `<div>` in `index.php`

If a feature truly cannot fit in one file (e.g., the user wants a separate config), ask the user before creating a new file.

---

## Architecture rules

- **No external dependencies** — no npm packages, no CDN scripts, no Composer libraries
- **No database** — storage is append-only `.jsonl` files in `storage/`
- **No WebSockets** — real-time polling via `fetch` calls at a short interval
- **No framework** — plain PHP, plain Web Crypto API, plain `fetch`
- **Server run profile** — use `0.0.0.0:8081` and public port `8081` in Codespaces for stable internet access

---

## Adding a new API action (server-side)

1. Add a new `if ($action === 'your-action')` block inside the `$action !== null` dispatcher (around line 30).
2. Validate all inputs using existing patterns:
   - Use `normalizeRoom()` for the room name
   - Use `normalizeUser()` for the user name
   - Enforce length limits with constants (`MAX_TEXT_LENGTH`, etc.) or add a new `const` at the top
   - Never trust raw `$payload` values — always cast and trim
3. Storage: if the action reads/writes messages, use `fetchMessages()` / `appendMessage()`. For signals, use `fetchSignals()` / `appendSignal()`. Create a new pair of helpers only if the data structure is genuinely different.
4. Always return `json_encode(['ok' => true, ...])` on success and let the `catch (Throwable $e)` handler return errors — do not add redundant try/catch inside the action block.

```php
if ($action === 'your-action') {
    $room = normalizeRoom((string)($payload['room'] ?? ''));
    // validate other fields...
    // do work...
    echo json_encode(['ok' => true, 'result' => $result]);
    exit;
}
```

---

## Adding a new client-side message type

If you need to send a new kind of data (e.g., reactions, polls, file metadata):

1. **Encrypt the content** before posting, using `encryptText(state.cryptoKey, content)` or — for binary data — adapt `encryptText` / the image pipeline.
2. Add the encrypted field to the `send` payload (or define a new action if the shape is very different).
3. On receive, decrypt with `decryptText(state.cryptoKey, encryptedField)` before rendering.
4. Never include the plaintext or the key in the POST body.

```js
// Example: adding an encrypted "reaction" field
const encryptedReaction = await encryptText(state.cryptoKey, reactionEmoji);
await apiFetch('send', { room: state.room, user: state.user, text: '', encryptedReaction });
```

---

## Adding UI elements

- Add HTML inside the `<div id="chatView">` or `<div id="joinView">` sections.
- Add styles in the `<style>` block — use the existing CSS custom properties (`--bg`, `--surface`, `--accent`, etc.) for consistency.
- Wire up event listeners in the JavaScript section, near the existing `joinBtn`, `sendBtn` handlers.
- Keep accessibility in mind: use `<button>`, `<label>`, `aria-*` attributes where appropriate.

---

## Checklist before submitting a feature

- [ ] Encryption key is never present in any `fetch` request body or URL
- [ ] All user-supplied strings are validated/trimmed before use
- [ ] New storage fields have a defined maximum length
- [ ] Plaintext is only ever handled in the browser JavaScript
- [ ] No external scripts, stylesheets, or fonts added
- [ ] Everything is inside `index.php` — no new files created
- [ ] New PHP functions are added after the existing helper functions (after `fetchSignals`)
