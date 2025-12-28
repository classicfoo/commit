<?php
session_start();

$databaseDir = __DIR__ . '/data';
if (!is_dir($databaseDir)) {
    mkdir($databaseDir, 0777, true);
}

$databasePath = $databaseDir . '/app.db';
$db = new SQLite3($databasePath);
$db->exec('CREATE TABLE IF NOT EXISTS users (id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT UNIQUE NOT NULL, password_hash TEXT NOT NULL, created_at TEXT NOT NULL)');

$errors = [];
$successMessage = '';

$action = $_POST['action'] ?? null;
$email = '';

if ($action === 'register') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please provide a valid email address.';
    }

    if (strlen($password) < 8) {
        $errors[] = 'Password must be at least 8 characters.';
    }

    if (!$errors) {
        $statement = $db->prepare('SELECT id FROM users WHERE email = :email');
        $statement->bindValue(':email', $email, SQLITE3_TEXT);
        $result = $statement->execute();
        if ($result->fetchArray(SQLITE3_ASSOC)) {
            $errors[] = 'An account with that email already exists.';
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $insert = $db->prepare('INSERT INTO users (email, password_hash, created_at) VALUES (:email, :hash, :created_at)');
            $insert->bindValue(':email', $email, SQLITE3_TEXT);
            $insert->bindValue(':hash', $hash, SQLITE3_TEXT);
            $insert->bindValue(':created_at', gmdate('Y-m-d H:i:s'), SQLITE3_TEXT);
            if ($insert->execute()) {
                $successMessage = 'Account created! You can now log in.';
            } else {
                $errors[] = 'Unable to create account. Please try again.';
            }
        }
    }
} elseif ($action === 'login') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please provide a valid email address.';
    }

    if ($password === '') {
        $errors[] = 'Please enter your password.';
    }

    if (!$errors) {
        $statement = $db->prepare('SELECT id, email, password_hash FROM users WHERE email = :email');
        $statement->bindValue(':email', $email, SQLITE3_TEXT);
        $result = $statement->execute();
        $user = $result->fetchArray(SQLITE3_ASSOC);
        if (!$user || !password_verify($password, $user['password_hash'])) {
            $errors[] = 'Invalid email or password.';
        } else {
            $_SESSION['user'] = [
                'id' => $user['id'],
                'email' => $user['email'],
            ];
            $successMessage = 'Welcome back! You are now signed in.';
        }
    }
} elseif ($action === 'logout') {
    $_SESSION = [];
    if (session_id() !== '' || isset($_COOKIE[session_name()])) {
        setcookie(session_name(), '', time() - 3600, '/');
    }
    session_destroy();
    $successMessage = 'You have been signed out.';
}

$currentUser = $_SESSION['user'] ?? null;
?>
<!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Minimal Auth Starter</title>
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
        <h1 class="h3 mb-0">Create accounts and sign in</h1>
        <p class="hint">A clean, neutral authentication starter using PHP, SQLite3, and Bootstrap.</p>
      </header>

      <?php if ($successMessage): ?>
        <div class="alert alert-success" role="alert">
          <?php echo htmlspecialchars($successMessage, ENT_QUOTES, 'UTF-8'); ?>
        </div>
      <?php endif; ?>

      <?php if ($errors): ?>
        <div class="alert alert-danger" role="alert">
          <ul class="mb-0">
            <?php foreach ($errors as $error): ?>
              <li><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></li>
            <?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>

      <?php if ($currentUser): ?>
        <section class="surface">
          <h2 class="h5">You are signed in</h2>
          <p class="mb-1"><strong>Email:</strong> <?php echo htmlspecialchars($currentUser['email'], ENT_QUOTES, 'UTF-8'); ?></p>
          <p class="hint mb-3">Next step: redirect authenticated users to the main app experience.</p>
          <form method="post">
            <input type="hidden" name="action" value="logout">
            <button type="submit" class="btn btn-outline-dark">Log out</button>
          </form>
        </section>
      <?php else: ?>
        <section class="surface">
          <div class="row g-4">
            <div class="col-12 col-lg-6">
              <h2 class="h5">Register</h2>
              <p class="hint">Create your account to get started.</p>
              <form method="post" class="d-grid gap-3">
                <input type="hidden" name="action" value="register">
                <div>
                  <label class="form-label" for="register-email">Email</label>
                  <input class="form-control" type="email" id="register-email" name="email" value="<?php echo htmlspecialchars($email, ENT_QUOTES, 'UTF-8'); ?>" required>
                </div>
                <div>
                  <label class="form-label" for="register-password">Password</label>
                  <input class="form-control" type="password" id="register-password" name="password" minlength="8" required>
                </div>
                <button type="submit" class="btn btn-neutral">Create account</button>
              </form>
            </div>
            <div class="col-12 col-lg-6">
              <h2 class="h5">Log in</h2>
              <p class="hint">Welcome back. Enter your credentials.</p>
              <form method="post" class="d-grid gap-3">
                <input type="hidden" name="action" value="login">
                <div>
                  <label class="form-label" for="login-email">Email</label>
                  <input class="form-control" type="email" id="login-email" name="email" value="<?php echo htmlspecialchars($email, ENT_QUOTES, 'UTF-8'); ?>" required>
                </div>
                <div>
                  <label class="form-label" for="login-password">Password</label>
                  <input class="form-control" type="password" id="login-password" name="password" required>
                </div>
                <button type="submit" class="btn btn-outline-dark">Sign in</button>
              </form>
            </div>
          </div>
        </section>

        <section class="surface">
          <h2 class="h6 text-uppercase text-muted">What’s next</h2>
          <div class="divider"></div>
          <ul class="mb-0">
            <li>Protect your primary routes by checking <code>$_SESSION['user']</code>.</li>
            <li>Add profile fields (name, avatar) in the <code>users</code> table.</li>
            <li>Consider email verification before granting access.</li>
          </ul>
        </section>
      <?php endif; ?>
    </div>
  </body>
</html>
