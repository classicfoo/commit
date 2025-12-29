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

function format_owner_name(?string $firstName, ?string $lastName): string
{
    $name = trim(sprintf('%s %s', $firstName ?? '', $lastName ?? ''));
    return $name !== '' ? $name : 'Unknown owner';
}

function format_person_initials(?string $firstName, ?string $lastName): string
{
    $firstInitial = $firstName ? mb_substr(trim($firstName), 0, 1) : '';
    $lastInitial = $lastName ? mb_substr(trim($lastName), 0, 1) : '';
    $initials = strtoupper($firstInitial . $lastInitial);
    return $initials !== '' ? $initials : 'U';
}

function calculate_people_match_score(array $person, string $query): int
{
    if ($query === '') {
        return 0;
    }
    $needle = mb_strtolower($query);
    $score = 0;
    $name = trim(($person['first_name'] ?? '') . ' ' . ($person['last_name'] ?? ''));
    $role = $person['role'] ?? '';
    $org = $person['org'] ?? '';
    $team = $person['team'] ?? '';
    $location = $person['location'] ?? '';

    if ($name !== '' && mb_stripos($name, $needle) !== false) {
        $score += 5;
    }
    if ($role !== '' && mb_stripos($role, $needle) !== false) {
        $score += 4;
    }
    if ($org !== '' && mb_stripos($org, $needle) !== false) {
        $score += 3;
    }
    if ($team !== '' && mb_stripos($team, $needle) !== false) {
        $score += 2;
    }
    if ($location !== '' && mb_stripos($location, $needle) !== false) {
        $score += 1;
    }

    return $score;
}

function fetch_commitments_for_person(SQLite3 $db, int $personId, string $query, array $filters): array
{
    $sql = 'SELECT id, title, description, category, start_date, end_date, created_at FROM commitments WHERE owner_user_id = :owner_user_id';
    if ($query !== '') {
        $sql .= ' AND (title LIKE :query OR description LIKE :query OR category LIKE :query)';
    }
    if ($filters['category'] !== '') {
        $sql .= ' AND category LIKE :category';
    }
    if ($filters['start_date'] !== '') {
        $sql .= ' AND date(COALESCE(start_date, created_at)) >= date(:start_date)';
    }
    if ($filters['end_date'] !== '') {
        $sql .= ' AND date(COALESCE(end_date, created_at)) <= date(:end_date)';
    }
    $sql .= ' ORDER BY created_at DESC';

    $statement = $db->prepare($sql);
    $statement->bindValue(':owner_user_id', $personId, SQLITE3_INTEGER);
    if ($query !== '') {
        $statement->bindValue(':query', '%' . $query . '%', SQLITE3_TEXT);
    }
    if ($filters['category'] !== '') {
        $statement->bindValue(':category', '%' . $filters['category'] . '%', SQLITE3_TEXT);
    }
    if ($filters['start_date'] !== '') {
        $statement->bindValue(':start_date', $filters['start_date'], SQLITE3_TEXT);
    }
    if ($filters['end_date'] !== '') {
        $statement->bindValue(':end_date', $filters['end_date'], SQLITE3_TEXT);
    }
    $result = $statement->execute();
    $commitments = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $commitments[] = $row;
    }
    return $commitments;
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
        $category = trim($_POST['category'] ?? '');
        $startDate = trim($_POST['start_date'] ?? '');
        $endDate = trim($_POST['end_date'] ?? '');
        if ($title === '') {
            $errors[] = 'Commitment title is required.';
        }
        if ($description === '') {
            $errors[] = 'Commitment description is required.';
        }
        if (!$errors) {
            $insert = $db->prepare('INSERT INTO commitments (owner_user_id, title, description, category, start_date, end_date, created_at) VALUES (:owner_user_id, :title, :description, :category, :start_date, :end_date, :created_at)');
            $insert->bindValue(':owner_user_id', $currentUser['id'], SQLITE3_INTEGER);
            $insert->bindValue(':title', $title, SQLITE3_TEXT);
            $insert->bindValue(':description', $description, SQLITE3_TEXT);
            $insert->bindValue(':category', $category !== '' ? $category : null, SQLITE3_TEXT);
            $insert->bindValue(':start_date', $startDate !== '' ? $startDate : null, SQLITE3_TEXT);
            $insert->bindValue(':end_date', $endDate !== '' ? $endDate : null, SQLITE3_TEXT);
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
        if ($commitmentId > 0 && !can_add_requirement($db, $currentUser['id'], $commitmentId)) {
            $errors[] = 'Only the owner can add requirements.';
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

    if ($action === 'create_check_in') {
        $commitmentId = (int) ($_POST['commitment_id'] ?? 0);
        $bodyText = trim($_POST['body_text'] ?? '');
        $imageUrl = trim($_POST['image_url'] ?? '');

        if ($commitmentId <= 0) {
            $errors[] = 'Invalid commitment.';
        }
        if ($commitmentId > 0 && !can_create_checkin($db, $currentUser['id'], $commitmentId)) {
            $errors[] = 'Only the owner can post a check-in.';
        }

        if (!$errors) {
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

        if (!$errors) {
            $insert = $db->prepare('INSERT INTO posts (commitment_id, author_user_id, type, body_text, image_url, created_at) VALUES (:commitment_id, :author_user_id, :type, :body_text, :image_url, :created_at)');
            $insert->bindValue(':commitment_id', $commitmentId, SQLITE3_INTEGER);
            $insert->bindValue(':author_user_id', $currentUser['id'], SQLITE3_INTEGER);
            $insert->bindValue(':type', 'check_in', SQLITE3_TEXT);
            $insert->bindValue(':body_text', $bodyText, SQLITE3_TEXT);
            $insert->bindValue(':image_url', $imageUrl, SQLITE3_TEXT);
            $insert->bindValue(':created_at', date('Y-m-d H:i:s'), SQLITE3_TEXT);
            if ($insert->execute()) {
                $postId = (int) $db->lastInsertRowID();
                $ownerStatement = $db->prepare('SELECT owner_user_id FROM commitments WHERE id = :commitment_id');
                $ownerStatement->bindValue(':commitment_id', $commitmentId, SQLITE3_INTEGER);
                $ownerResult = $ownerStatement->execute();
                $ownerRow = $ownerResult->fetchArray(SQLITE3_ASSOC);
                $ownerUserId = $ownerRow ? (int) $ownerRow['owner_user_id'] : 0;
                $subscriberStatement = $db->prepare('SELECT user_id FROM subscriptions WHERE commitment_id = :commitment_id');
                $subscriberStatement->bindValue(':commitment_id', $commitmentId, SQLITE3_INTEGER);
                $subscriberResult = $subscriberStatement->execute();
                $insertNotification = $db->prepare('INSERT INTO notifications (recipient_user_id, commitment_id, post_id, created_at, read_at) VALUES (:recipient_user_id, :commitment_id, :post_id, :created_at, NULL)');
                while ($subscriber = $subscriberResult->fetchArray(SQLITE3_ASSOC)) {
                    $recipientId = (int) $subscriber['user_id'];
                    if ($recipientId === (int) $currentUser['id'] || $recipientId === $ownerUserId) {
                        continue;
                    }
                    $insertNotification->bindValue(':recipient_user_id', $recipientId, SQLITE3_INTEGER);
                    $insertNotification->bindValue(':commitment_id', $commitmentId, SQLITE3_INTEGER);
                    $insertNotification->bindValue(':post_id', $postId, SQLITE3_INTEGER);
                    $insertNotification->bindValue(':created_at', date('Y-m-d H:i:s'), SQLITE3_TEXT);
                    $insertNotification->execute();
                }
                redirect_with_message('index.php?r=commitment&id=' . $commitmentId, 'Check-in published.');
            } else {
                $errors[] = 'Unable to create check-in.';
            }
        }
    }

    if ($action === 'create_comment') {
        $commitmentId = (int) ($_POST['commitment_id'] ?? 0);
        $bodyText = trim($_POST['body_text'] ?? '');

        if ($commitmentId <= 0) {
            $errors[] = 'Invalid commitment.';
        }
        if ($commitmentId > 0) {
            $isOwner = is_owner($db, $currentUser['id'], $commitmentId);
            $isSubscribed = $isOwner || is_subscribed($db, $currentUser['id'], $commitmentId);
            if (!$isSubscribed) {
                $errors[] = 'Only subscribers can comment.';
            }
        }
        if ($bodyText === '') {
            $errors[] = 'Please add a comment.';
        }

        if (!$errors) {
            $insert = $db->prepare('INSERT INTO posts (commitment_id, author_user_id, type, body_text, image_url, created_at) VALUES (:commitment_id, :author_user_id, :type, :body_text, :image_url, :created_at)');
            $insert->bindValue(':commitment_id', $commitmentId, SQLITE3_INTEGER);
            $insert->bindValue(':author_user_id', $currentUser['id'], SQLITE3_INTEGER);
            $insert->bindValue(':type', 'comment', SQLITE3_TEXT);
            $insert->bindValue(':body_text', $bodyText, SQLITE3_TEXT);
            $insert->bindValue(':image_url', '', SQLITE3_TEXT);
            $insert->bindValue(':created_at', date('Y-m-d H:i:s'), SQLITE3_TEXT);
            if ($insert->execute()) {
                $postId = (int) $db->lastInsertRowID();
                $ownerStatement = $db->prepare('SELECT owner_user_id FROM commitments WHERE id = :commitment_id');
                $ownerStatement->bindValue(':commitment_id', $commitmentId, SQLITE3_INTEGER);
                $ownerResult = $ownerStatement->execute();
                $ownerRow = $ownerResult->fetchArray(SQLITE3_ASSOC);
                $ownerUserId = $ownerRow ? (int) $ownerRow['owner_user_id'] : 0;
                $subscriberStatement = $db->prepare('SELECT user_id FROM subscriptions WHERE commitment_id = :commitment_id');
                $subscriberStatement->bindValue(':commitment_id', $commitmentId, SQLITE3_INTEGER);
                $subscriberResult = $subscriberStatement->execute();
                $insertNotification = $db->prepare('INSERT INTO notifications (recipient_user_id, commitment_id, post_id, created_at, read_at) VALUES (:recipient_user_id, :commitment_id, :post_id, :created_at, NULL)');
                while ($subscriber = $subscriberResult->fetchArray(SQLITE3_ASSOC)) {
                    $recipientId = (int) $subscriber['user_id'];
                    if ($recipientId === (int) $currentUser['id'] || $recipientId === $ownerUserId) {
                        continue;
                    }
                    $insertNotification->bindValue(':recipient_user_id', $recipientId, SQLITE3_INTEGER);
                    $insertNotification->bindValue(':commitment_id', $commitmentId, SQLITE3_INTEGER);
                    $insertNotification->bindValue(':post_id', $postId, SQLITE3_INTEGER);
                    $insertNotification->bindValue(':created_at', date('Y-m-d H:i:s'), SQLITE3_TEXT);
                    $insertNotification->execute();
                }
                redirect_with_message('index.php?r=commitment&id=' . $commitmentId, 'Comment published.');
            } else {
                $errors[] = 'Unable to add comment.';
            }
        }
    }

    if ($action === 'toggle_subscription') {
        $commitmentId = (int) ($_POST['commitment_id'] ?? 0);
        if ($commitmentId <= 0) {
            $errors[] = 'Invalid commitment.';
        }
        if ($commitmentId > 0 && is_owner($db, $currentUser['id'], $commitmentId)) {
            $errors[] = 'Owners cannot subscribe to their own commitments.';
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
    } elseif ($route === 'person') {
        $pageHeading = 'Person profile';
        $pageHint = 'Explore commitments owned by this person.';
    } elseif ($route === 'explore') {
        $pageHeading = 'Explore people';
        $pageHint = 'Search for people and the commitments they lead.';
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
      $activeTab = $_GET['tab'] ?? 'all';
      if ($action === 'create_commitment') {
          $activeTab = 'create';
      }
      if (!in_array($activeTab, ['all', 'create'], true)) {
          $activeTab = 'all';
      }
      $statement = $db->prepare('SELECT commitments.id, commitments.title, commitments.description, commitments.created_at, users.first_name as owner_first_name, users.last_name as owner_last_name FROM commitments JOIN users ON commitments.owner_user_id = users.id ORDER BY commitments.created_at DESC');
      $commitmentsResult = $statement->execute();
    ?>
    <div class="d-flex gap-3 mb-4">
      <a class="btn btn-link p-0 text-decoration-none<?php echo $activeTab === 'all' ? ' text-body fw-semibold' : ' text-muted'; ?>" href="index.php?r=commitments&tab=all">All commitments</a>
      <a class="btn btn-link p-0 text-decoration-none<?php echo $activeTab === 'create' ? ' text-body fw-semibold' : ' text-muted'; ?>" href="index.php?r=commitments&tab=create">Create commitment</a>
    </div>

    <?php if ($activeTab === 'all'): ?>
      <section class="surface">
        <h2 class="h5">All commitments</h2>
        <p class="hint">Select a commitment to view its requirements, posts, and status.</p>
        <div class="d-grid gap-3">
          <?php while ($commitment = $commitmentsResult->fetchArray(SQLITE3_ASSOC)): ?>
            <?php $ownerName = format_owner_name($commitment['owner_first_name'] ?? '', $commitment['owner_last_name'] ?? ''); ?>
            <div class="border rounded-3 p-3">
              <div class="d-flex justify-content-between align-items-start">
                <div>
                  <h3 class="h6 mb-1">
                    <a href="index.php?r=commitment&id=<?php echo (int) $commitment['id']; ?>">
                      <?php echo htmlspecialchars($commitment['title'], ENT_QUOTES, 'UTF-8'); ?>
                    </a>
                  </h3>
                  <p class="hint mb-2">Owner: <?php echo htmlspecialchars($ownerName, ENT_QUOTES, 'UTF-8'); ?></p>
                  <p class="mb-0"><?php echo nl2br(htmlspecialchars($commitment['description'], ENT_QUOTES, 'UTF-8')); ?></p>
                </div>
                <span class="status-pill">Commitment</span>
              </div>
            </div>
          <?php endwhile; ?>
        </div>
      </section>
    <?php endif; ?>

    <?php if ($activeTab === 'create'): ?>
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
            <label class="form-label" for="commitment-category">Category</label>
            <input class="form-control" type="text" id="commitment-category" name="category" placeholder="Mentorship, Q3 Roadmap">
          </div>
          <div>
            <label class="form-label" for="commitment-description">Description</label>
            <textarea class="form-control" id="commitment-description" name="description" rows="3" required></textarea>
          </div>
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label" for="commitment-start-date">Start date</label>
              <input class="form-control" type="date" id="commitment-start-date" name="start_date">
            </div>
            <div class="col-md-6">
              <label class="form-label" for="commitment-end-date">End date</label>
              <input class="form-control" type="date" id="commitment-end-date" name="end_date">
            </div>
          </div>
          <button type="submit" class="btn btn-neutral">Create commitment</button>
        </form>
      </section>
    <?php endif; ?>
  <?php elseif ($route === 'explore'): ?>
    <?php
      $searchQuery = trim($_GET['q'] ?? '');
      $peopleFilters = [
          'org' => trim($_GET['org'] ?? ''),
          'team' => trim($_GET['team'] ?? ''),
          'location' => trim($_GET['location'] ?? ''),
          'role' => trim($_GET['role'] ?? ''),
      ];
      $commitmentFilters = [
          'category' => trim($_GET['category'] ?? ''),
          'start_date' => trim($_GET['start_date'] ?? ''),
          'end_date' => trim($_GET['end_date'] ?? ''),
      ];

      $peopleStatement = $db->prepare('SELECT id, first_name, last_name, email, org, team, location, role FROM users WHERE (:org = "" OR org LIKE :org_like) AND (:team = "" OR team LIKE :team_like) AND (:location = "" OR location LIKE :location_like) AND (:role = "" OR role LIKE :role_like) ORDER BY last_name, first_name');
      $peopleStatement->bindValue(':org', $peopleFilters['org'], SQLITE3_TEXT);
      $peopleStatement->bindValue(':org_like', '%' . $peopleFilters['org'] . '%', SQLITE3_TEXT);
      $peopleStatement->bindValue(':team', $peopleFilters['team'], SQLITE3_TEXT);
      $peopleStatement->bindValue(':team_like', '%' . $peopleFilters['team'] . '%', SQLITE3_TEXT);
      $peopleStatement->bindValue(':location', $peopleFilters['location'], SQLITE3_TEXT);
      $peopleStatement->bindValue(':location_like', '%' . $peopleFilters['location'] . '%', SQLITE3_TEXT);
      $peopleStatement->bindValue(':role', $peopleFilters['role'], SQLITE3_TEXT);
      $peopleStatement->bindValue(':role_like', '%' . $peopleFilters['role'] . '%', SQLITE3_TEXT);
      $peopleResult = $peopleStatement->execute();
      $peopleMatches = [];
      while ($person = $peopleResult->fetchArray(SQLITE3_ASSOC)) {
          $commitments = fetch_commitments_for_person($db, (int) $person['id'], $searchQuery, $commitmentFilters);
          $peopleScore = calculate_people_match_score($person, $searchQuery);
          $hasCommitments = count($commitments) > 0;
          $hasFilters = array_filter($commitmentFilters) || array_filter($peopleFilters);
          $includePerson = $searchQuery === ''
              ? (!$hasFilters || $hasCommitments)
              : ($peopleScore > 0 || $hasCommitments);
          if (!$includePerson) {
              continue;
          }
          $person['commitments'] = $commitments;
          $person['people_score'] = $peopleScore;
          $peopleMatches[] = $person;
      }

      usort($peopleMatches, function (array $left, array $right): int {
          if ($left['people_score'] === $right['people_score']) {
              return strcasecmp(($left['last_name'] ?? '') . ($left['first_name'] ?? ''), ($right['last_name'] ?? '') . ($right['first_name'] ?? ''));
          }
          return $right['people_score'] <=> $left['people_score'];
      });
    ?>
    <section class="surface">
      <form method="get" class="d-grid gap-4">
        <input type="hidden" name="r" value="explore">
        <div>
          <label class="form-label" for="explore-search">Search people and commitments</label>
          <input class="form-control" type="search" id="explore-search" name="q" placeholder="Search by name, role, org, or commitment" value="<?php echo htmlspecialchars($searchQuery, ENT_QUOTES, 'UTF-8'); ?>">
        </div>
        <div>
          <p class="text-uppercase text-muted fw-semibold small mb-2">People filters</p>
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label" for="filter-org">Organization</label>
              <input class="form-control" type="text" id="filter-org" name="org" value="<?php echo htmlspecialchars($peopleFilters['org'], ENT_QUOTES, 'UTF-8'); ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label" for="filter-team">Team</label>
              <input class="form-control" type="text" id="filter-team" name="team" value="<?php echo htmlspecialchars($peopleFilters['team'], ENT_QUOTES, 'UTF-8'); ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label" for="filter-location">Location</label>
              <input class="form-control" type="text" id="filter-location" name="location" value="<?php echo htmlspecialchars($peopleFilters['location'], ENT_QUOTES, 'UTF-8'); ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label" for="filter-role">Role</label>
              <input class="form-control" type="text" id="filter-role" name="role" value="<?php echo htmlspecialchars($peopleFilters['role'], ENT_QUOTES, 'UTF-8'); ?>">
            </div>
          </div>
        </div>
        <div>
          <p class="text-uppercase text-muted fw-semibold small mb-2">Commitment filters</p>
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label" for="filter-category">Category</label>
              <input class="form-control" type="text" id="filter-category" name="category" value="<?php echo htmlspecialchars($commitmentFilters['category'], ENT_QUOTES, 'UTF-8'); ?>">
            </div>
            <div class="col-md-3">
              <label class="form-label" for="filter-start-date">Start date</label>
              <input class="form-control" type="date" id="filter-start-date" name="start_date" value="<?php echo htmlspecialchars($commitmentFilters['start_date'], ENT_QUOTES, 'UTF-8'); ?>">
            </div>
            <div class="col-md-3">
              <label class="form-label" for="filter-end-date">End date</label>
              <input class="form-control" type="date" id="filter-end-date" name="end_date" value="<?php echo htmlspecialchars($commitmentFilters['end_date'], ENT_QUOTES, 'UTF-8'); ?>">
            </div>
          </div>
        </div>
        <div class="d-flex flex-wrap gap-2">
          <button type="submit" class="btn btn-neutral">Update results</button>
          <a class="btn btn-outline-dark" href="index.php?r=explore">Reset</a>
        </div>
      </form>
    </section>

    <section class="surface">
      <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
          <h2 class="h5 mb-1">People-first results</h2>
          <p class="hint mb-0">People matches are prioritized, with commitments nested under each person.</p>
        </div>
        <span class="status-pill"><?php echo count($peopleMatches); ?> people</span>
      </div>
      <?php if (!$peopleMatches): ?>
        <p class="hint mb-0">No matches yet. Try adjusting your people or commitment filters.</p>
      <?php else: ?>
        <div class="d-grid gap-3">
          <?php foreach ($peopleMatches as $person): ?>
            <?php
              $commitments = $person['commitments'];
              $commitmentCount = count($commitments);
              $commitmentPreview = array_slice($commitments, 0, 3);
              $personName = trim(($person['first_name'] ?? '') . ' ' . ($person['last_name'] ?? ''));
              $personName = $personName !== '' ? $personName : 'Unknown person';
              $personMeta = trim(($person['role'] ?? '') . ($person['org'] ? ' · ' . $person['org'] : ''));
              $personDetails = array_filter([$person['team'] ?? '', $person['location'] ?? '']);
            ?>
            <div class="person-card">
              <div class="d-flex align-items-start justify-content-between gap-3">
                <div class="d-flex gap-3">
                  <div class="person-avatar" aria-hidden="true">
                    <?php echo htmlspecialchars(format_person_initials($person['first_name'] ?? '', $person['last_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                  </div>
                  <div>
                    <h3 class="h6 mb-1"><?php echo htmlspecialchars($personName, ENT_QUOTES, 'UTF-8'); ?></h3>
                    <?php if ($personMeta !== ''): ?>
                      <p class="hint mb-1"><?php echo htmlspecialchars($personMeta, ENT_QUOTES, 'UTF-8'); ?></p>
                    <?php endif; ?>
                    <?php if ($personDetails): ?>
                      <p class="person-subtext mb-2"><?php echo htmlspecialchars(implode(' · ', $personDetails), ENT_QUOTES, 'UTF-8'); ?></p>
                    <?php endif; ?>
                    <div class="commitment-summary">
                      <?php if ($commitmentCount > 0): ?>
                        <p class="mb-2"><strong><?php echo $commitmentCount; ?> commitment<?php echo $commitmentCount === 1 ? '' : 's'; ?>:</strong></p>
                        <div class="commitment-chips">
                          <?php foreach ($commitmentPreview as $commitment): ?>
                            <span class="commitment-chip"><?php echo htmlspecialchars($commitment['title'], ENT_QUOTES, 'UTF-8'); ?></span>
                          <?php endforeach; ?>
                          <?php if ($commitmentCount > count($commitmentPreview)): ?>
                            <span class="commitment-chip commitment-chip-muted">+<?php echo $commitmentCount - count($commitmentPreview); ?> more</span>
                          <?php endif; ?>
                        </div>
                      <?php else: ?>
                        <p class="hint mb-0">No commitments match the current filters.</p>
                      <?php endif; ?>
                    </div>
                  </div>
                </div>
                <div class="d-flex flex-column align-items-end gap-2">
                  <button type="button" class="btn btn-outline-dark btn-sm">Follow</button>
                  <a class="btn btn-link p-0 text-decoration-none" href="index.php?r=person&id=<?php echo (int) $person['id']; ?>">View all commitments</a>
                </div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>
  <?php elseif ($route === 'person'): ?>
    <?php
      $personId = (int) ($_GET['id'] ?? 0);
      $personStatement = $db->prepare('SELECT id, first_name, last_name, email, org, team, location, role FROM users WHERE id = :id');
      $personStatement->bindValue(':id', $personId, SQLITE3_INTEGER);
      $personResult = $personStatement->execute();
      $person = $personResult->fetchArray(SQLITE3_ASSOC);
    ?>
    <?php if (!$person): ?>
      <section class="surface">
        <h2 class="h5">Person not found</h2>
        <p class="hint">Return to Explore to choose another person.</p>
        <a class="btn btn-outline-dark" href="index.php?r=explore">Back to Explore</a>
      </section>
    <?php else: ?>
      <?php
        $personCommitments = fetch_commitments_for_person($db, (int) $person['id'], '', [
            'category' => '',
            'start_date' => '',
            'end_date' => '',
        ]);
        $personName = trim(($person['first_name'] ?? '') . ' ' . ($person['last_name'] ?? ''));
        $personName = $personName !== '' ? $personName : 'Unknown person';
        $personMeta = trim(($person['role'] ?? '') . ($person['org'] ? ' · ' . $person['org'] : ''));
        $personDetails = array_filter([$person['team'] ?? '', $person['location'] ?? '']);
      ?>
      <section class="surface">
        <div class="d-flex align-items-start justify-content-between gap-3">
          <div class="d-flex gap-3">
            <div class="person-avatar person-avatar-lg" aria-hidden="true">
              <?php echo htmlspecialchars(format_person_initials($person['first_name'] ?? '', $person['last_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>
            </div>
            <div>
              <h2 class="h5 mb-1"><?php echo htmlspecialchars($personName, ENT_QUOTES, 'UTF-8'); ?></h2>
              <?php if ($personMeta !== ''): ?>
                <p class="hint mb-1"><?php echo htmlspecialchars($personMeta, ENT_QUOTES, 'UTF-8'); ?></p>
              <?php endif; ?>
              <?php if ($personDetails): ?>
                <p class="person-subtext mb-0"><?php echo htmlspecialchars(implode(' · ', $personDetails), ENT_QUOTES, 'UTF-8'); ?></p>
              <?php endif; ?>
            </div>
          </div>
          <button type="button" class="btn btn-outline-dark btn-sm">Follow</button>
        </div>
        <div class="divider"></div>
        <h3 class="h6">Commitments</h3>
        <?php if (!$personCommitments): ?>
          <p class="hint mb-0">No commitments yet.</p>
        <?php else: ?>
          <div class="d-grid gap-3">
            <?php foreach ($personCommitments as $commitment): ?>
              <div class="border rounded-3 p-3">
                <div class="d-flex justify-content-between align-items-start">
                  <div>
                    <h3 class="h6 mb-1">
                      <a href="index.php?r=commitment&id=<?php echo (int) $commitment['id']; ?>">
                        <?php echo htmlspecialchars($commitment['title'], ENT_QUOTES, 'UTF-8'); ?>
                      </a>
                    </h3>
                    <?php if (!empty($commitment['category'])): ?>
                      <p class="hint mb-2">Category: <?php echo htmlspecialchars($commitment['category'], ENT_QUOTES, 'UTF-8'); ?></p>
                    <?php endif; ?>
                    <p class="mb-0"><?php echo nl2br(htmlspecialchars($commitment['description'], ENT_QUOTES, 'UTF-8')); ?></p>
                  </div>
                  <span class="status-pill">Commitment</span>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
        <div class="divider"></div>
        <a class="btn btn-outline-dark" href="index.php?r=explore">Back to Explore</a>
      </section>
    <?php endif; ?>
  <?php elseif ($route === 'commitment'): ?>
    <?php
      $commitmentId = (int) ($_GET['id'] ?? 0);
      $statement = $db->prepare('SELECT commitments.*, users.first_name as owner_first_name, users.last_name as owner_last_name FROM commitments JOIN users ON commitments.owner_user_id = users.id WHERE commitments.id = :id');
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
        $isOwner = is_owner($db, $currentUser['id'], $commitmentId);
        $isSubscribed = false;
        if (!$isOwner) {
            $subscriptionStatement = $db->prepare('SELECT id FROM subscriptions WHERE user_id = :user_id AND commitment_id = :commitment_id');
            $subscriptionStatement->bindValue(':user_id', $currentUser['id'], SQLITE3_INTEGER);
            $subscriptionStatement->bindValue(':commitment_id', $commitmentId, SQLITE3_INTEGER);
            $subscriptionResult = $subscriptionStatement->execute();
            $isSubscribed = (bool) $subscriptionResult->fetchArray(SQLITE3_ASSOC);
        }
        $status = evaluate_commitment_status($db, $commitmentId, (int) $commitment['owner_user_id']);
        $ownerName = format_owner_name($commitment['owner_first_name'] ?? '', $commitment['owner_last_name'] ?? '');
      ?>
      <section class="surface">
        <div class="d-flex justify-content-between align-items-start gap-3">
          <div>
            <h2 class="h5 mb-1"><?php echo htmlspecialchars($commitment['title'], ENT_QUOTES, 'UTF-8'); ?></h2>
            <p class="hint mb-3">Owner: <?php echo htmlspecialchars($ownerName, ENT_QUOTES, 'UTF-8'); ?></p>
          </div>
          <?php if (!$isOwner): ?>
            <form method="post">
              <input type="hidden" name="action" value="toggle_subscription">
              <input type="hidden" name="commitment_id" value="<?php echo (int) $commitmentId; ?>">
              <button type="submit" class="btn btn-outline-dark btn-sm">
                <?php echo $isSubscribed ? 'Unsubscribe' : 'Subscribe'; ?>
              </button>
            </form>
          <?php endif; ?>
        </div>
        <ul class="nav nav-tabs mt-4" id="commitmentTabs" role="tablist">
          <li class="nav-item" role="presentation">
            <button class="nav-link" id="overview-tab" data-bs-toggle="tab" data-bs-target="#commitment-overview" type="button" role="tab" aria-controls="commitment-overview" aria-selected="false">
              Overview/Status
            </button>
          </li>
          <li class="nav-item" role="presentation">
            <button class="nav-link" id="requirements-tab" data-bs-toggle="tab" data-bs-target="#commitment-requirements" type="button" role="tab" aria-controls="commitment-requirements" aria-selected="false">
              Requirements
            </button>
          </li>
          <li class="nav-item" role="presentation">
            <button class="nav-link active" id="checkin-tab" data-bs-toggle="tab" data-bs-target="#commitment-checkin" type="button" role="tab" aria-controls="commitment-checkin" aria-selected="true">
              Check-in/Post
            </button>
          </li>
          <li class="nav-item" role="presentation">
            <button class="nav-link" id="activity-tab" data-bs-toggle="tab" data-bs-target="#commitment-activity" type="button" role="tab" aria-controls="commitment-activity" aria-selected="false">
              Activity/Recent posts
            </button>
          </li>
        </ul>
        <div class="tab-content pt-4" id="commitmentTabsContent">
          <div class="tab-pane fade" id="commitment-overview" role="tabpanel" aria-labelledby="overview-tab" tabindex="0">
            <p class="mb-3"><?php echo nl2br(htmlspecialchars($commitment['description'], ENT_QUOTES, 'UTF-8')); ?></p>
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
          </div>
          <div class="tab-pane fade" id="commitment-requirements" role="tabpanel" aria-labelledby="requirements-tab" tabindex="0">
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
              <p class="hint">No requirements yet<?php echo $isOwner ? '. Add one below.' : '.'; ?></p>
            <?php endif; ?>
            <?php if ($isOwner): ?>
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
            <?php endif; ?>
          </div>
          <div class="tab-pane fade show active" id="commitment-checkin" role="tabpanel" aria-labelledby="checkin-tab" tabindex="0">
            <h2 class="h5">Create a post</h2>
            <p class="hint">Only owner check-ins are evaluated. Subscribers can add comments.</p>
            <?php if ($isOwner): ?>
              <form method="post" class="d-grid gap-3">
                <input type="hidden" name="action" id="post-action" value="create_check_in">
                <input type="hidden" name="commitment_id" value="<?php echo (int) $commitmentId; ?>">
                <div>
                  <label class="form-label d-block" for="post-type-checkin">Post type</label>
                  <div class="btn-group" role="group" aria-label="Choose post type">
                    <input type="radio" class="btn-check" name="post_type" id="post-type-checkin" value="checkin" autocomplete="off" checked>
                    <label class="btn btn-outline-dark" for="post-type-checkin">Check-in</label>
                    <input type="radio" class="btn-check" name="post_type" id="post-type-update" value="update" autocomplete="off">
                    <label class="btn btn-outline-dark" for="post-type-update">Update</label>
                  </div>
                  <p class="hint mb-0" id="post-type-hint" data-checkin-hint="Check-ins count toward requirements." data-update-hint="Updates do not count toward requirements.">Check-ins count toward requirements.</p>
                </div>
                <div>
                  <label class="form-label" for="checkin-text" id="post-text-label" data-checkin-label="Check-in notes" data-update-label="Update">Check-in notes</label>
                  <textarea class="form-control" id="checkin-text" name="body_text" rows="3"></textarea>
                </div>
                <div id="post-image-field">
                  <label class="form-label" for="checkin-image">Image URL</label>
                  <input class="form-control" type="url" id="checkin-image" name="image_url">
                </div>
                <button type="submit" class="btn btn-neutral" id="post-submit" data-checkin-label="Post check-in" data-update-label="Post update">Post check-in</button>
              </form>
            <?php elseif ($isSubscribed): ?>
              <form method="post" class="d-grid gap-3">
                <input type="hidden" name="action" value="create_comment">
                <input type="hidden" name="commitment_id" value="<?php echo (int) $commitmentId; ?>">
                <div>
                  <label class="form-label" for="subscriber-comment">Comment</label>
                  <textarea class="form-control" id="subscriber-comment" name="body_text" rows="3"></textarea>
                </div>
                <button type="submit" class="btn btn-neutral">Add comment</button>
              </form>
            <?php else: ?>
              <p class="hint mb-0">Subscribe to add a comment.</p>
            <?php endif; ?>
          </div>
          <div class="tab-pane fade" id="commitment-activity" role="tabpanel" aria-labelledby="activity-tab" tabindex="0">
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
          </div>
        </div>
      </section>
    <?php endif; ?>
  <?php elseif ($route === 'notifications'): ?>
    <?php
      $notificationsStatement = $db->prepare('SELECT notifications.*, commitments.title as commitment_title, posts.type as post_type, posts.body_text as post_body, users.email as author_email FROM notifications JOIN commitments ON notifications.commitment_id = commitments.id JOIN posts ON notifications.post_id = posts.id JOIN users ON posts.author_user_id = users.id WHERE notifications.recipient_user_id = :user_id AND posts.author_user_id != :user_id ORDER BY notifications.created_at DESC');
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

<script>
  document.addEventListener('DOMContentLoaded', () => {
    const postAction = document.getElementById('post-action');
    const postTypeCheckin = document.getElementById('post-type-checkin');
    const postTypeUpdate = document.getElementById('post-type-update');
    const postTextLabel = document.getElementById('post-text-label');
    const postImageField = document.getElementById('post-image-field');
    const postSubmit = document.getElementById('post-submit');
    const postTypeHint = document.getElementById('post-type-hint');

    if (!postAction || !postTypeCheckin || !postTypeUpdate) {
      return;
    }

    const updatePostForm = () => {
      const isCheckin = postTypeCheckin.checked;
      postAction.value = isCheckin ? 'create_check_in' : 'create_comment';
      if (postTextLabel) {
        postTextLabel.textContent = isCheckin ? postTextLabel.dataset.checkinLabel : postTextLabel.dataset.updateLabel;
      }
      if (postTypeHint) {
        postTypeHint.textContent = isCheckin ? postTypeHint.dataset.checkinHint : postTypeHint.dataset.updateHint;
      }
      if (postImageField) {
        postImageField.style.display = isCheckin ? '' : 'none';
      }
      if (postSubmit) {
        postSubmit.textContent = isCheckin ? postSubmit.dataset.checkinLabel : postSubmit.dataset.updateLabel;
      }
    };

    postTypeCheckin.addEventListener('change', updatePostForm);
    postTypeUpdate.addEventListener('change', updatePostForm);
    updatePostForm();
  });
</script>
<?php
include __DIR__ . '/auth_footer.php';
?>
