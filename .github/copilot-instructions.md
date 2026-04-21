# Copilot Instructions

For this repository, always use the validated server profile for internet access:

1. Bind PHP server to 0.0.0.0 on port 8081.
2. Use workspace root as document root and router:
   php -S 0.0.0.0:8081 -t /workspaces/LocalTemporaryTalkChat /workspaces/LocalTemporaryTalkChat/index.php
3. In Codespaces, keep port 8081 visibility set to Public.
4. Prefer using existing VS Code tasks in .vscode/tasks.json that target 8081.
5. Do not default to port 8080 for this project unless explicitly requested.
