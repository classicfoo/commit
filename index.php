<?php
require __DIR__ . '/auth.php';

$errors = [];
$successMessage = '';
$email = '';

$action = $_POST['action'] ?? null;

if ($action === 'login') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    [$errors, $successMessage] = handle_login($db, $email, $password);
} elseif ($action === 'logout') {
    $successMessage = handle_logout();
}

$currentUser = current_user();

$pageTitle = 'testing';
$pageHeading = 'Welcome back';
$pageHint = 'Sign in to continue to your account.';

if ($currentUser) {
    $pageHeading = 'Welcome, ' . $currentUser['email'];
    $pageHint = 'You are signed in with your username.';
    $showPageHeader = false;
}

include __DIR__ . '/auth_header.php';
?>

<?php if ($errors): ?>
  <div class="alert alert-danger" role="alert">
    <ul class="mb-0">
      <?php foreach ($errors as $error): ?>
        <li><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></li>
      <?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>

<?php if (!$currentUser): ?>
  <section class="surface">
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
      <button type="submit" class="btn btn-neutral">Sign in</button>
    </form>
    <div class="divider"></div>
    <p class="hint mb-0">Don’t have an account? <a href="register.php">Create one here</a>.</p>
  </section>
<?php endif; ?>

<?php
include __DIR__ . '/auth_footer.php';
?>
