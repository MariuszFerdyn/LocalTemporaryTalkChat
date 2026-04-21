---
mode: agent
description: "Restart LocalTemporaryTalkChat server with the stable internet-access settings (kill/restart on 8081, 0.0.0.0 bind, public tunnel)."
---
Restart the LocalTemporaryTalkChat server with the working configuration.

Requirements:
1. Kill processes on port 8081.
2. Restart server with:
   php -S 0.0.0.0:8081 -t /workspaces/LocalTemporaryTalkChat /workspaces/LocalTemporaryTalkChat/index.php
3. Ensure Codespaces port 8081 visibility is public.
4. Verify:
   - local health check on http://127.0.0.1:8081 is 200
   - forwarded URL on 8081 is 200
5. If 8081 fails, report diagnostics instead of switching ports.
