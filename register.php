<?php
require __DIR__ . '/auth.php';

$errors = [];
$successMessage = '';
$email = '';
$firstName = '';
$lastName = '';

$action = $_POST['action'] ?? null;

if ($action === 'register') {
    $email = trim($_POST['email'] ?? '');
    $firstName = trim($_POST['first_name'] ?? '');
    $lastName = trim($_POST['last_name'] ?? '');
    $password = $_POST['password'] ?? '';
    [$errors, $successMessage] = handle_register($db, $email, $firstName, $lastName, $password);
} elseif ($action === 'logout') {
    $successMessage = handle_logout();
}

$currentUser = current_user();

$pageTitle = 'Register';
$pageHeading = 'Create your account';
$pageHint = 'Join the app with a new email and password.';

include __DIR__ . '/auth_header.php';
?>

<?php if ($successMessage): ?>
  <div class="alert alert-success js-auto-dismiss" role="alert">
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
    <p class="hint mb-3">You can log out to register a different account.</p>
    <form method="post">
      <input type="hidden" name="action" value="logout">
      <button type="submit" class="btn btn-outline-dark">Log out</button>
    </form>
    <div class="divider"></div>
    <p class="hint mb-0">Already have an account? <a href="index.php">Return to login</a>.</p>
  </section>
<?php else: ?>
  <section class="surface">
    <h2 class="h5">Register</h2>
    <p class="hint">Create your account to get started.</p>
    <form method="post" class="d-grid gap-3">
      <input type="hidden" name="action" value="register">
      <div>
        <label class="form-label" for="register-email">Email</label>
        <input class="form-control" type="email" id="register-email" name="email" value="<?php echo htmlspecialchars($email, ENT_QUOTES, 'UTF-8'); ?>" required>
      </div>
      <div>
        <label class="form-label" for="register-first-name">First name</label>
        <input class="form-control" type="text" id="register-first-name" name="first_name" value="<?php echo htmlspecialchars($firstName, ENT_QUOTES, 'UTF-8'); ?>" required>
      </div>
      <div>
        <label class="form-label" for="register-last-name">Last name</label>
        <input class="form-control" type="text" id="register-last-name" name="last_name" value="<?php echo htmlspecialchars($lastName, ENT_QUOTES, 'UTF-8'); ?>" required>
      </div>
      <div>
        <label class="form-label" for="register-password">Password</label>
        <input class="form-control" type="password" id="register-password" name="password" minlength="8" required>
      </div>
      <button type="submit" class="btn btn-neutral">Create account</button>
    </form>
    <div class="divider"></div>
    <p class="hint mb-0">Already have an account? <a href="index.php">Log in here</a>.</p>
  </section>
<?php endif; ?>

<?php
include __DIR__ . '/auth_footer.php';
?>
