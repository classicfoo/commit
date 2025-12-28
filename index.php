<?php
$deployedAt = gmdate('Y-m-d H:i:s');
$commitSha = trim(shell_exec('git rev-parse --short HEAD 2>/dev/null'));
$commitSha = $commitSha !== '' ? $commitSha : 'unknown';
?>
<!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Deployment Test</title>
    <style>
      :root {
        color-scheme: light dark;
      }
      body {
        font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        margin: 0;
        padding: 48px 24px;
        background: #f6f7fb;
        color: #1f2933;
      }
      main {
        max-width: 640px;
        margin: 0 auto;
        background: #fff;
        padding: 32px;
        border-radius: 12px;
        box-shadow: 0 8px 24px rgba(15, 23, 42, 0.08);
      }
      h1 {
        margin-top: 0;
        font-size: 2rem;
      }
      ul {
        padding-left: 20px;
      }
      code {
        font-family: "SFMono-Regular", Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
        background: #f0f4ff;
        padding: 2px 6px;
        border-radius: 4px;
      }
    </style>
  </head>
  <body>
    <main>
      <h1>✅ Deployment Test Page</h1>
      <p>If you can read this, the GitHub Actions deploy workflow is publishing successfully.</p>
      <ul>
        <li><strong>Deployed (UTC):</strong> <?php echo htmlspecialchars($deployedAt, ENT_QUOTES, 'UTF-8'); ?></li>
        <li><strong>Commit:</strong> <code><?php echo htmlspecialchars($commitSha, ENT_QUOTES, 'UTF-8'); ?></code></li>
      </ul>
    </main>
  </body>
</html>
