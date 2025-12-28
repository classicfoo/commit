<?php
if (!isset($pageTitle)) {
    $pageTitle = 'Auth';
}
if (!isset($pageHeading)) {
    $pageHeading = 'Welcome';
}
if (!isset($pageHint)) {
    $pageHint = '';
}
?>
<!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8'); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
      :root {
        color-scheme: light;
      }
      body {
        font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        background: #ffffff;
        color: #0f172a;
        min-height: 100vh;
        border-top: 1px solid #e5e7eb;
      }
      .app-shell {
        max-width: 920px;
        margin: 48px auto;
        padding: 0 24px 64px;
      }
      .brand-mark {
        letter-spacing: 0.08em;
        text-transform: uppercase;
        font-weight: 600;
        font-size: 0.85rem;
        color: #6b7280;
      }
      .surface {
        border: 1px solid #e5e7eb;
        border-radius: 16px;
        padding: 32px;
        background: #ffffff;
        box-shadow: 0 10px 30px rgba(15, 23, 42, 0.04);
      }
      .surface + .surface {
        margin-top: 24px;
      }
      .divider {
        height: 1px;
        background: #e5e7eb;
        margin: 24px 0;
      }
      .form-control {
        border-radius: 12px;
        border-color: #d1d5db;
      }
      .btn-neutral {
        background: #0f172a;
        color: #ffffff;
        border-radius: 999px;
        padding: 10px 24px;
      }
      .btn-neutral:hover {
        background: #1e293b;
        color: #ffffff;
      }
      .hint {
        color: #6b7280;
        font-size: 0.9rem;
      }
    </style>
  </head>
  <body>
    <div class="app-shell">
      <header class="d-flex flex-column gap-2 mb-4">
        <span class="brand-mark">Minimal Auth</span>
        <h1 class="h3 mb-0"><?php echo htmlspecialchars($pageHeading, ENT_QUOTES, 'UTF-8'); ?></h1>
        <?php if ($pageHint !== ''): ?>
          <p class="hint"><?php echo htmlspecialchars($pageHint, ENT_QUOTES, 'UTF-8'); ?></p>
        <?php endif; ?>
      </header>
