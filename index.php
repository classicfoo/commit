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

function redirect_with_message(string $location, string $message = ''): void
{
    if ($message !== '') {
        $delimiter = str_contains($location, '?') ? '&' : '?';
        header('Location: ' . $location . $delimiter . 'message=' . urlencode($message));
    } else {
        header('Location: ' . $location);
    }
    exit;
}

function fetch_commitment_requirements(SQLite3 $db, int $commitmentId): array
{
    $statement = $db->prepare('SELECT id, type, params FROM requirements WHERE commitment_id = :commitment_id');
    $statement->bindValue(':commitment_id', $commitmentId, SQLITE3_INTEGER);
    $result = $statement->execute();
    $requirements = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $row['params'] = json_decode($row['params'], true) ?? [];
        $requirements[] = $row;
    }
    return $requirements;
}

function evaluate_commitment_status(SQLite3 $db, int $commitmentId, int $ownerUserId): array
{
    $requirements = fetch_commitment_requirements($db, $commitmentId);
    if (!$requirements) {
        return [
            'overall' => 'No requirements yet.',
            'details' => [],
        ];
    }

    $today = date('Y-m-d');
    $details = [];
    $allPass = true;

    foreach ($requirements as $requirement) {
        if ($requirement['type'] === 'post_frequency') {
            $requiredCount = (int) ($requirement['params']['count'] ?? 1);
            $countStatement = $db->prepare('SELECT COUNT(*) as count FROM posts WHERE commitment_id = :commitment_id AND author_user_id = :author_user_id AND type = :type AND date(created_at) = :today');
            $countStatement->bindValue(':commitment_id', $commitmentId, SQLITE3_INTEGER);
            $countStatement->bindValue(':author_user_id', $ownerUserId, SQLITE3_INTEGER);
            $countStatement->bindValue(':type', 'check_in', SQLITE3_TEXT);
            $countStatement->bindValue(':today', $today, SQLITE3_TEXT);
            $countResult = $countStatement->execute();
            $countRow = $countResult->fetchArray(SQLITE3_ASSOC);
            $count = $countRow ? (int) $countRow['count'] : 0;
            $pass = $count >= $requiredCount;
            $details[] = [
                'label' => 'Daily check-ins (' . $requiredCount . ' required)',
                'pass' => $pass,
                'message' => $pass ? 'Completed ' . $count . ' today.' : 'Only ' . $count . ' today.',
            ];
            $allPass = $allPass && $pass;
        } elseif ($requirement['type'] === 'text_update') {
            $countStatement = $db->prepare('SELECT COUNT(*) as count FROM posts WHERE commitment_id = :commitment_id AND author_user_id = :author_user_id AND type = :type AND body_text != "" AND date(created_at) = :today');
            $countStatement->bindValue(':commitment_id', $commitmentId, SQLITE3_INTEGER);
            $countStatement->bindValue(':author_user_id', $ownerUserId, SQLITE3_INTEGER);
            $countStatement->bindValue(':type', 'check_in', SQLITE3_TEXT);
            $countStatement->bindValue(':today', $today, SQLITE3_TEXT);
            $countResult = $countStatement->execute();
            $countRow = $countResult->fetchArray(SQLITE3_ASSOC);
            $count = $countRow ? (int) $countRow['count'] : 0;
            $pass = $count >= 1;
            $details[] = [
                'label' => 'Text update required',
                'pass' => $pass,
                'message' => $pass ? 'At least one check-in includes text.' : 'No text check-ins yet today.',
            ];
            $allPass = $allPass && $pass;
        } elseif ($requirement['type'] === 'image_required') {
            $countStatement = $db->prepare('SELECT COUNT(*) as count FROM posts WHERE commitment_id = :commitment_id AND author_user_id = :author_user_id AND type = :type AND image_url != "" AND date(created_at) = :today');
            $countStatement->bindValue(':commitment_id', $commitmentId, SQLITE3_INTEGER);
            $countStatement->bindValue(':author_user_id', $ownerUserId, SQLITE3_INTEGER);
            $countStatement->bindValue(':type', 'check_in', SQLITE3_TEXT);
            $countStatement->bindValue(':today', $today, SQLITE3_TEXT);
            $countResult = $countStatement->execute();
            $countRow = $countResult->fetchArray(SQLITE3_ASSOC);
            $count = $countRow ? (int) $countRow['count'] : 0;
            $pass = $count >= 1;
            $details[] = [
                'label' => 'Image URL required',
                'pass' => $pass,
                'message' => $pass ? 'At least one check-in includes an image.' : 'No image check-ins yet today.',
            ];
            $allPass = $allPass && $pass;
        }
    }

    return [
        'overall' => $allPass ? 'On track today.' : 'Needs attention today.',
        'details' => $details,
    ];
}

$route = $_GET['r'] ?? null;
$route = $route ?: ($currentUser ? 'commitments' : 'login');

if ($currentUser) {
    if ($action === 'create_commitment') {
        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        if ($title === '') {
            $errors[] = 'Commitment title is required.';
        }
        if ($description === '') {
            $errors[] = 'Commitment description is required.';
        }
        if (!$errors) {
            $insert = $db->prepare('INSERT INTO commitments (owner_user_id, title, description, created_at) VALUES (:owner_user_id, :title, :description, :created_at)');
            $insert->bindValue(':owner_user_id', $currentUser['id'], SQLITE3_INTEGER);
            $insert->bindValue(':title', $title, SQLITE3_TEXT);
            $insert->bindValue(':description', $description, SQLITE3_TEXT);
            $insert->bindValue(':created_at', date('Y-m-d H:i:s'), SQLITE3_TEXT);
            if ($insert->execute()) {
                $commitmentId = (int) $db->lastInsertRowID();
                redirect_with_message('index.php?r=commitment&id=' . $commitmentId, 'Commitment created.');
            } else {
                $errors[] = 'Unable to create commitment.';
            }
        }
    }

    if ($action === 'add_requirement') {
        $commitmentId = (int) ($_POST['commitment_id'] ?? 0);
        $type = $_POST['type'] ?? '';
        $count = (int) ($_POST['count'] ?? 1);

        if ($commitmentId <= 0) {
            $errors[] = 'Invalid commitment.';
        }
        if (!in_array($type, ['post_frequency', 'text_update', 'image_required'], true)) {
            $errors[] = 'Invalid requirement type.';
        }

        if (!$errors) {
            $params = [];
            if ($type === 'post_frequency') {
                $params['count'] = max(1, $count);
            }

            $insert = $db->prepare('INSERT INTO requirements (commitment_id, type, params, created_at) VALUES (:commitment_id, :type, :params, :created_at)');
            $insert->bindValue(':commitment_id', $commitmentId, SQLITE3_INTEGER);
            $insert->bindValue(':type', $type, SQLITE3_TEXT);
            $insert->bindValue(':params', json_encode($params), SQLITE3_TEXT);
            $insert->bindValue(':created_at', date('Y-m-d H:i:s'), SQLITE3_TEXT);
            if ($insert->execute()) {
                redirect_with_message('index.php?r=commitment&id=' . $commitmentId, 'Requirement added.');
            } else {
                $errors[] = 'Unable to add requirement.';
            }
        }
    }

    if ($action === 'create_post') {
        $commitmentId = (int) ($_POST['commitment_id'] ?? 0);
        $type = $_POST['type'] ?? '';
        $bodyText = trim($_POST['body_text'] ?? '');
        $imageUrl = trim($_POST['image_url'] ?? '');

        if ($commitmentId <= 0) {
            $errors[] = 'Invalid commitment.';
        }
        if (!in_array($type, ['check_in', 'comment'], true)) {
            $errors[] = 'Invalid post type.';
        }

        if ($type === 'check_in') {
            $requirements = fetch_commitment_requirements($db, $commitmentId);
            $requiresText = false;
            $requiresImage = false;
            foreach ($requirements as $requirement) {
                if ($requirement['type'] === 'text_update') {
                    $requiresText = true;
                }
                if ($requirement['type'] === 'image_required') {
                    $requiresImage = true;
                }
            }
            if ($requiresText && $bodyText === '') {
                $errors[] = 'A text update is required for this check-in.';
            }
            if ($requiresImage && $imageUrl === '') {
                $errors[] = 'An image URL is required for this check-in.';
            }
        }

        if ($type === 'comment' && $bodyText === '') {
            $errors[] = 'Please add a comment.';
        }

        if (!$errors) {
            $insert = $db->prepare('INSERT INTO posts (commitment_id, author_user_id, type, body_text, image_url, created_at) VALUES (:commitment_id, :author_user_id, :type, :body_text, :image_url, :created_at)');
            $insert->bindValue(':commitment_id', $commitmentId, SQLITE3_INTEGER);
            $insert->bindValue(':author_user_id', $currentUser['id'], SQLITE3_INTEGER);
            $insert->bindValue(':type', $type, SQLITE3_TEXT);
            $insert->bindValue(':body_text', $bodyText, SQLITE3_TEXT);
            $insert->bindValue(':image_url', $imageUrl, SQLITE3_TEXT);
            $insert->bindValue(':created_at', date('Y-m-d H:i:s'), SQLITE3_TEXT);
            if ($insert->execute()) {
                $postId = (int) $db->lastInsertRowID();
                if ($type === 'check_in') {
                    $subscriberStatement = $db->prepare('SELECT user_id FROM subscriptions WHERE commitment_id = :commitment_id');
                    $subscriberStatement->bindValue(':commitment_id', $commitmentId, SQLITE3_INTEGER);
                    $subscriberResult = $subscriberStatement->execute();
                    $insertNotification = $db->prepare('INSERT INTO notifications (recipient_user_id, commitment_id, post_id, created_at, read_at) VALUES (:recipient_user_id, :commitment_id, :post_id, :created_at, NULL)');
                    while ($subscriber = $subscriberResult->fetchArray(SQLITE3_ASSOC)) {
                        $recipientId = (int) $subscriber['user_id'];
                        if ($recipientId === (int) $currentUser['id']) {
                            continue;
                        }
                        $insertNotification->bindValue(':recipient_user_id', $recipientId, SQLITE3_INTEGER);
                        $insertNotification->bindValue(':commitment_id', $commitmentId, SQLITE3_INTEGER);
                        $insertNotification->bindValue(':post_id', $postId, SQLITE3_INTEGER);
                        $insertNotification->bindValue(':created_at', date('Y-m-d H:i:s'), SQLITE3_TEXT);
                        $insertNotification->execute();
                    }
                    // TODO: Comment notifications could be added later.
                }
                redirect_with_message('index.php?r=commitment&id=' . $commitmentId, 'Post published.');
            } else {
                $errors[] = 'Unable to create post.';
            }
        }
    }

    if ($action === 'toggle_subscription') {
        $commitmentId = (int) ($_POST['commitment_id'] ?? 0);
        if ($commitmentId <= 0) {
            $errors[] = 'Invalid commitment.';
        }
        if (!$errors) {
            $check = $db->prepare('SELECT id FROM subscriptions WHERE user_id = :user_id AND commitment_id = :commitment_id');
            $check->bindValue(':user_id', $currentUser['id'], SQLITE3_INTEGER);
            $check->bindValue(':commitment_id', $commitmentId, SQLITE3_INTEGER);
            $checkResult = $check->execute();
            $existing = $checkResult->fetchArray(SQLITE3_ASSOC);
            if ($existing) {
                $delete = $db->prepare('DELETE FROM subscriptions WHERE id = :id');
                $delete->bindValue(':id', $existing['id'], SQLITE3_INTEGER);
                $delete->execute();
                redirect_with_message('index.php?r=commitment&id=' . $commitmentId, 'Subscription removed.');
            } else {
                $insert = $db->prepare('INSERT INTO subscriptions (user_id, commitment_id, created_at) VALUES (:user_id, :commitment_id, :created_at)');
                $insert->bindValue(':user_id', $currentUser['id'], SQLITE3_INTEGER);
                $insert->bindValue(':commitment_id', $commitmentId, SQLITE3_INTEGER);
                $insert->bindValue(':created_at', date('Y-m-d H:i:s'), SQLITE3_TEXT);
                $insert->execute();
                redirect_with_message('index.php?r=commitment&id=' . $commitmentId, 'Subscribed to commitment.');
            }
        }
    }

    if ($action === 'mark_notification_read') {
        $notificationId = (int) ($_POST['notification_id'] ?? 0);
        if ($notificationId > 0) {
            $update = $db->prepare('UPDATE notifications SET read_at = :read_at WHERE id = :id AND recipient_user_id = :recipient_user_id');
            $update->bindValue(':read_at', date('Y-m-d H:i:s'), SQLITE3_TEXT);
            $update->bindValue(':id', $notificationId, SQLITE3_INTEGER);
            $update->bindValue(':recipient_user_id', $currentUser['id'], SQLITE3_INTEGER);
            $update->execute();
            redirect_with_message('index.php?r=notifications', 'Notification marked as read.');
        }
    }
}

$message = $_GET['message'] ?? '';
if ($message !== '' && $successMessage === '') {
    $successMessage = $message;
}

$pageTitle = 'Commit';
$pageHeading = 'Sign in to continue';
$pageHint = '';
$showPageHeader = true;

if ($currentUser) {
    if ($route === 'commitment') {
        $pageHeading = 'Commitment details';
        $pageHint = 'Track requirements, status, and activity.';
    } elseif ($route === 'notifications') {
        $pageHeading = 'Notifications';
        $pageHint = 'Recent check-ins from your subscriptions.';
    } else {
        $pageHeading = 'Commitments';
        $pageHint = 'Create, follow, and check in on commitments.';
    }
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

<?php if ($successMessage): ?>
  <div class="alert alert-success" role="alert" data-auto-dismiss="true">
    <?php echo htmlspecialchars($successMessage, ENT_QUOTES, 'UTF-8'); ?>
  </div>
<?php endif; ?>

<?php if (!$currentUser): ?>
  <section class="surface">
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
  </section>
<?php else: ?>
  <?php if ($route === 'commitments'): ?>
    <?php
      $statement = $db->prepare('SELECT commitments.id, commitments.title, commitments.description, commitments.created_at, users.email as owner_email FROM commitments JOIN users ON commitments.owner_user_id = users.id ORDER BY commitments.created_at DESC');
      $commitmentsResult = $statement->execute();
    ?>
    <section class="surface">
      <h2 class="h5">All commitments</h2>
      <p class="hint">Select a commitment to view its requirements, posts, and status.</p>
      <div class="d-grid gap-3">
        <?php while ($commitment = $commitmentsResult->fetchArray(SQLITE3_ASSOC)): ?>
          <div class="border rounded-3 p-3">
            <div class="d-flex justify-content-between align-items-start">
              <div>
                <h3 class="h6 mb-1">
                  <a href="index.php?r=commitment&id=<?php echo (int) $commitment['id']; ?>">
                    <?php echo htmlspecialchars($commitment['title'], ENT_QUOTES, 'UTF-8'); ?>
                  </a>
                </h3>
                <p class="hint mb-2">Owner: <?php echo htmlspecialchars($commitment['owner_email'], ENT_QUOTES, 'UTF-8'); ?></p>
                <p class="mb-0"><?php echo nl2br(htmlspecialchars($commitment['description'], ENT_QUOTES, 'UTF-8')); ?></p>
              </div>
              <span class="status-pill">Commitment</span>
            </div>
          </div>
        <?php endwhile; ?>
      </div>
    </section>

    <section class="surface">
      <h2 class="h5">Create a commitment</h2>
      <p class="hint">Start a new commitment and invite others to follow along.</p>
      <form method="post" class="d-grid gap-3">
        <input type="hidden" name="action" value="create_commitment">
        <div>
          <label class="form-label" for="commitment-title">Title</label>
          <input class="form-control" type="text" id="commitment-title" name="title" required>
        </div>
        <div>
          <label class="form-label" for="commitment-description">Description</label>
          <textarea class="form-control" id="commitment-description" name="description" rows="3" required></textarea>
        </div>
        <button type="submit" class="btn btn-neutral">Create commitment</button>
      </form>
    </section>
  <?php elseif ($route === 'commitment'): ?>
    <?php
      $commitmentId = (int) ($_GET['id'] ?? 0);
      $statement = $db->prepare('SELECT commitments.*, users.email as owner_email FROM commitments JOIN users ON commitments.owner_user_id = users.id WHERE commitments.id = :id');
      $statement->bindValue(':id', $commitmentId, SQLITE3_INTEGER);
      $commitmentResult = $statement->execute();
      $commitment = $commitmentResult->fetchArray(SQLITE3_ASSOC);
    ?>
    <?php if (!$commitment): ?>
      <section class="surface">
        <h2 class="h5">Commitment not found</h2>
        <p class="hint">Return to the commitments list to choose another one.</p>
        <a class="btn btn-outline-dark" href="index.php?r=commitments">Back to commitments</a>
      </section>
    <?php else: ?>
      <?php
        $requirements = fetch_commitment_requirements($db, $commitmentId);
        $postsStatement = $db->prepare('SELECT posts.*, users.email as author_email FROM posts JOIN users ON posts.author_user_id = users.id WHERE posts.commitment_id = :commitment_id ORDER BY posts.created_at DESC');
        $postsStatement->bindValue(':commitment_id', $commitmentId, SQLITE3_INTEGER);
        $postsResult = $postsStatement->execute();
        $subscriptionStatement = $db->prepare('SELECT id FROM subscriptions WHERE user_id = :user_id AND commitment_id = :commitment_id');
        $subscriptionStatement->bindValue(':user_id', $currentUser['id'], SQLITE3_INTEGER);
        $subscriptionStatement->bindValue(':commitment_id', $commitmentId, SQLITE3_INTEGER);
        $subscriptionResult = $subscriptionStatement->execute();
        $isSubscribed = (bool) $subscriptionResult->fetchArray(SQLITE3_ASSOC);
        $status = evaluate_commitment_status($db, $commitmentId, (int) $commitment['owner_user_id']);
      ?>
      <section class="surface">
        <div class="d-flex justify-content-between align-items-start gap-3">
          <div>
            <h2 class="h5 mb-1"><?php echo htmlspecialchars($commitment['title'], ENT_QUOTES, 'UTF-8'); ?></h2>
            <p class="hint mb-3">Owner: <?php echo htmlspecialchars($commitment['owner_email'], ENT_QUOTES, 'UTF-8'); ?></p>
            <p class="mb-0"><?php echo nl2br(htmlspecialchars($commitment['description'], ENT_QUOTES, 'UTF-8')); ?></p>
          </div>
          <form method="post">
            <input type="hidden" name="action" value="toggle_subscription">
            <input type="hidden" name="commitment_id" value="<?php echo (int) $commitmentId; ?>">
            <button type="submit" class="btn btn-outline-dark btn-sm">
              <?php echo $isSubscribed ? 'Unsubscribe' : 'Subscribe'; ?>
            </button>
          </form>
        </div>
        <div class="divider"></div>
        <div>
          <p class="mb-1"><strong>Status (today):</strong> <?php echo htmlspecialchars($status['overall'], ENT_QUOTES, 'UTF-8'); ?></p>
          <?php if ($status['details']): ?>
            <ul class="mb-0">
              <?php foreach ($status['details'] as $detail): ?>
                <li>
                  <strong><?php echo htmlspecialchars($detail['label'], ENT_QUOTES, 'UTF-8'); ?>:</strong>
                  <?php echo htmlspecialchars($detail['message'], ENT_QUOTES, 'UTF-8'); ?>
                  (<?php echo $detail['pass'] ? 'Pass' : 'Fail'; ?>)
                </li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
        </div>
      </section>

      <section class="surface">
        <h2 class="h5">Requirements</h2>
        <?php if ($requirements): ?>
          <ul>
            <?php foreach ($requirements as $requirement): ?>
              <li>
                <?php if ($requirement['type'] === 'post_frequency'): ?>
                  At least <?php echo (int) ($requirement['params']['count'] ?? 1); ?> check-in(s) per day.
                <?php elseif ($requirement['type'] === 'text_update'): ?>
                  Check-ins must include text updates.
                <?php elseif ($requirement['type'] === 'image_required'): ?>
                  Check-ins must include an image URL.
                <?php endif; ?>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php else: ?>
          <p class="hint">No requirements yet. Add one below.</p>
        <?php endif; ?>
        <div class="divider"></div>
        <form method="post" class="d-grid gap-3">
          <input type="hidden" name="action" value="add_requirement">
          <input type="hidden" name="commitment_id" value="<?php echo (int) $commitmentId; ?>">
          <div>
            <label class="form-label" for="requirement-type">Requirement type</label>
            <select class="form-select" id="requirement-type" name="type" required>
              <option value="post_frequency">Post frequency (daily)</option>
              <option value="text_update">Text update required</option>
              <option value="image_required">Image URL required</option>
            </select>
          </div>
          <div>
            <label class="form-label" for="requirement-count">Daily check-ins (for post frequency)</label>
            <input class="form-control" type="number" id="requirement-count" name="count" min="1" value="1">
          </div>
          <button type="submit" class="btn btn-neutral">Add requirement</button>
        </form>
      </section>

      <section class="surface">
        <h2 class="h5">Create a post</h2>
        <p class="hint">Anyone can post a check-in or comment. Only owner check-ins are evaluated.</p>
        <form method="post" class="d-grid gap-3">
          <input type="hidden" name="action" value="create_post">
          <input type="hidden" name="commitment_id" value="<?php echo (int) $commitmentId; ?>">
          <div>
            <label class="form-label" for="post-type">Type</label>
            <select class="form-select" id="post-type" name="type" required>
              <option value="check_in">Check-in</option>
              <option value="comment">Comment</option>
            </select>
          </div>
          <div>
            <label class="form-label" for="post-text">Text</label>
            <textarea class="form-control" id="post-text" name="body_text" rows="3"></textarea>
          </div>
          <div>
            <label class="form-label" for="post-image">Image URL (for check-ins)</label>
            <input class="form-control" type="url" id="post-image" name="image_url">
          </div>
          <button type="submit" class="btn btn-neutral">Post update</button>
        </form>
      </section>

      <section class="surface">
        <h2 class="h5">Recent posts</h2>
        <div class="d-grid gap-3">
          <?php while ($post = $postsResult->fetchArray(SQLITE3_ASSOC)): ?>
            <div class="border rounded-3 p-3">
              <div class="d-flex justify-content-between align-items-start">
                <div>
                  <p class="mb-1"><strong><?php echo htmlspecialchars($post['author_email'], ENT_QUOTES, 'UTF-8'); ?></strong> · <?php echo htmlspecialchars($post['type'], ENT_QUOTES, 'UTF-8'); ?></p>
                  <?php if ($post['body_text'] !== ''): ?>
                    <p class="mb-1"><?php echo nl2br(htmlspecialchars($post['body_text'], ENT_QUOTES, 'UTF-8')); ?></p>
                  <?php endif; ?>
                  <?php if ($post['image_url'] !== ''): ?>
                    <p class="mb-1"><a href="<?php echo htmlspecialchars($post['image_url'], ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noreferrer">View image</a></p>
                  <?php endif; ?>
                  <p class="hint mb-0">Posted <?php echo htmlspecialchars($post['created_at'], ENT_QUOTES, 'UTF-8'); ?></p>
                </div>
                <span class="status-pill"><?php echo htmlspecialchars($post['type'], ENT_QUOTES, 'UTF-8'); ?></span>
              </div>
            </div>
          <?php endwhile; ?>
        </div>
      </section>
    <?php endif; ?>
  <?php elseif ($route === 'notifications'): ?>
    <?php
      $notificationsStatement = $db->prepare('SELECT notifications.*, commitments.title as commitment_title, posts.type as post_type, posts.body_text as post_body, users.email as author_email FROM notifications JOIN commitments ON notifications.commitment_id = commitments.id JOIN posts ON notifications.post_id = posts.id JOIN users ON posts.author_user_id = users.id WHERE notifications.recipient_user_id = :user_id ORDER BY notifications.created_at DESC');
      $notificationsStatement->bindValue(':user_id', $currentUser['id'], SQLITE3_INTEGER);
      $notificationsResult = $notificationsStatement->execute();
    ?>
    <section class="surface">
      <h2 class="h5">Recent check-ins</h2>
      <p class="hint">New check-ins from commitments you subscribe to.</p>
      <div class="d-grid gap-3">
        <?php while ($notification = $notificationsResult->fetchArray(SQLITE3_ASSOC)): ?>
          <div class="border rounded-3 p-3">
            <p class="mb-1"><strong><?php echo htmlspecialchars($notification['author_email'], ENT_QUOTES, 'UTF-8'); ?></strong> checked in on <a href="index.php?r=commitment&id=<?php echo (int) $notification['commitment_id']; ?>"><?php echo htmlspecialchars($notification['commitment_title'], ENT_QUOTES, 'UTF-8'); ?></a></p>
            <?php if ($notification['post_body'] !== ''): ?>
              <p class="mb-1"><?php echo nl2br(htmlspecialchars($notification['post_body'], ENT_QUOTES, 'UTF-8')); ?></p>
            <?php endif; ?>
            <p class="hint mb-2">Received <?php echo htmlspecialchars($notification['created_at'], ENT_QUOTES, 'UTF-8'); ?></p>
            <div class="d-flex justify-content-between align-items-center">
              <span class="status-pill"><?php echo $notification['read_at'] ? 'Read' : 'Unread'; ?></span>
              <?php if (!$notification['read_at']): ?>
                <form method="post">
                  <input type="hidden" name="action" value="mark_notification_read">
                  <input type="hidden" name="notification_id" value="<?php echo (int) $notification['id']; ?>">
                  <button type="submit" class="btn btn-outline-dark btn-sm">Mark read</button>
                </form>
              <?php endif; ?>
            </div>
          </div>
        <?php endwhile; ?>
      </div>
    </section>
  <?php endif; ?>
<?php endif; ?>

<?php
include __DIR__ . '/auth_footer.php';
?>
