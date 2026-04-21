---
mode: agent
description: "Start LocalTemporaryTalkChat server with the stable internet-access settings (0.0.0.0:8081 and public port 8081)."
---
Start the LocalTemporaryTalkChat server using the known-good settings.

Requirements:
1. Run from /workspaces/LocalTemporaryTalkChat.
2. Start PHP with:
   php -S 0.0.0.0:8081 -t /workspaces/LocalTemporaryTalkChat /workspaces/LocalTemporaryTalkChat/index.php
3. Ensure Codespaces port 8081 is public:
   gh codespace ports visibility 8081:public -c $CODESPACE_NAME
4. Verify both checks:
   - http://127.0.0.1:8081 returns 200
   - forwarded https://<codespace>-8081.app.github.dev returns 200
5. Report final URL and status codes.
