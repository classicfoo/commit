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
$currentUser = $currentUser ?? null;
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
      .app-nav {
        border-bottom: 1px solid #e5e7eb;
        background: #ffffff;
      }
      .app-brand {
        font-weight: 600;
        letter-spacing: 0.08em;
        text-transform: uppercase;
        color: #0f172a;
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
      .status-pill {
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 999px;
        font-size: 0.85rem;
        padding: 6px 14px;
        color: #334155;
      }
    </style>
  </head>
  <body>
    <nav class="app-nav">
      <div class="container-fluid px-4 py-3 d-flex align-items-center justify-content-between">
        <span class="app-brand">commit</span>
        <div class="d-flex align-items-center gap-3">
          <span class="status-pill">
            <?php if ($currentUser): ?>
              Signed in as <?php echo htmlspecialchars($currentUser['email'], ENT_QUOTES, 'UTF-8'); ?>
            <?php else: ?>
              Not signed in
            <?php endif; ?>
          </span>
          <button class="btn btn-outline-dark btn-sm" type="button" data-bs-toggle="offcanvas" data-bs-target="#mainMenu" aria-controls="mainMenu">
            <span class="visually-hidden">Open menu</span>
            ☰
          </button>
        </div>
      </div>
    </nav>
    <div class="offcanvas offcanvas-end" tabindex="-1" id="mainMenu" aria-labelledby="mainMenuLabel">
      <div class="offcanvas-header">
        <h5 class="offcanvas-title" id="mainMenuLabel">Menu</h5>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button>
      </div>
      <div class="offcanvas-body d-flex flex-column gap-3">
        <div class="small text-uppercase text-muted">Navigation</div>
        <a class="text-decoration-none" href="index.php">Login</a>
        <a class="text-decoration-none" href="register.php">Register</a>
        <?php if ($currentUser): ?>
          <form method="post" class="mt-2">
            <input type="hidden" name="action" value="logout">
            <button type="submit" class="btn btn-outline-dark w-100">Log out</button>
          </form>
        <?php endif; ?>
      </div>
    </div>
    <div class="app-shell">
      <header class="d-flex flex-column gap-2 mb-4">
        <span class="brand-mark">commit</span>
        <h1 class="h3 mb-0"><?php echo htmlspecialchars($pageHeading, ENT_QUOTES, 'UTF-8'); ?></h1>
        <?php if ($pageHint !== ''): ?>
          <p class="hint"><?php echo htmlspecialchars($pageHint, ENT_QUOTES, 'UTF-8'); ?></p>
        <?php endif; ?>
      </header>
