<?php
session_start();

$databaseDir = __DIR__ . '/data';
if (!is_dir($databaseDir)) {
    mkdir($databaseDir, 0777, true);
}

$databasePath = $databaseDir . '/app.db';
$db = new SQLite3($databasePath);
$db->exec('CREATE TABLE IF NOT EXISTS users (id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT UNIQUE NOT NULL, first_name TEXT NOT NULL, last_name TEXT NOT NULL, password_hash TEXT NOT NULL, created_at TEXT NOT NULL)');
$db->exec('CREATE TABLE IF NOT EXISTS commitments (id INTEGER PRIMARY KEY AUTOINCREMENT, owner_user_id INTEGER NOT NULL, title TEXT NOT NULL, description TEXT NOT NULL, created_at TEXT NOT NULL)');
$db->exec('CREATE TABLE IF NOT EXISTS requirements (id INTEGER PRIMARY KEY AUTOINCREMENT, commitment_id INTEGER NOT NULL, type TEXT NOT NULL, params TEXT NOT NULL, created_at TEXT NOT NULL)');
$db->exec('CREATE TABLE IF NOT EXISTS posts (id INTEGER PRIMARY KEY AUTOINCREMENT, commitment_id INTEGER NOT NULL, author_user_id INTEGER NOT NULL, type TEXT NOT NULL, body_text TEXT NOT NULL, image_url TEXT NOT NULL, created_at TEXT NOT NULL)');
$db->exec('CREATE TABLE IF NOT EXISTS subscriptions (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, commitment_id INTEGER NOT NULL, created_at TEXT NOT NULL, UNIQUE(user_id, commitment_id))');
$db->exec('CREATE TABLE IF NOT EXISTS notifications (id INTEGER PRIMARY KEY AUTOINCREMENT, recipient_user_id INTEGER NOT NULL, commitment_id INTEGER NOT NULL, post_id INTEGER NOT NULL, created_at TEXT NOT NULL, read_at TEXT)');

$tableInfoStatement = $db->prepare('PRAGMA table_info(users)');
$tableInfo = $tableInfoStatement->execute();
$columns = [];
while ($column = $tableInfo->fetchArray(SQLITE3_ASSOC)) {
    $columns[] = $column['name'];
}
if (!in_array('first_name', $columns, true)) {
    $db->exec('ALTER TABLE users ADD COLUMN first_name TEXT NOT NULL DEFAULT ""');
}
if (!in_array('last_name', $columns, true)) {
    $db->exec('ALTER TABLE users ADD COLUMN last_name TEXT NOT NULL DEFAULT ""');
}

$userCountStatement = $db->prepare('SELECT COUNT(*) as count FROM users');
$userCountResult = $userCountStatement->execute();
$userCountRow = $userCountResult->fetchArray(SQLITE3_ASSOC);
if ($userCountRow && (int) $userCountRow['count'] === 0) {
    $seedUsers = [
        ['email' => 'demo_owner@commit.local', 'first_name' => 'Demo', 'last_name' => 'Owner', 'password' => 'password123'],
        ['email' => 'demo_supporter@commit.local', 'first_name' => 'Demo', 'last_name' => 'Supporter', 'password' => 'password123'],
    ];

    $insertUser = $db->prepare('INSERT INTO users (email, first_name, last_name, password_hash, created_at) VALUES (:email, :first_name, :last_name, :hash, :created_at)');
    foreach ($seedUsers as $seedUser) {
        $insertUser->bindValue(':email', $seedUser['email'], SQLITE3_TEXT);
        $insertUser->bindValue(':first_name', $seedUser['first_name'], SQLITE3_TEXT);
        $insertUser->bindValue(':last_name', $seedUser['last_name'], SQLITE3_TEXT);
        $insertUser->bindValue(':hash', password_hash($seedUser['password'], PASSWORD_DEFAULT), SQLITE3_TEXT);
        $insertUser->bindValue(':created_at', date('Y-m-d H:i:s'), SQLITE3_TEXT);
        $insertUser->execute();
    }
}

$commitmentCountStatement = $db->prepare('SELECT COUNT(*) as count FROM commitments');
$commitmentCountResult = $commitmentCountStatement->execute();
$commitmentCountRow = $commitmentCountResult->fetchArray(SQLITE3_ASSOC);
if ($commitmentCountRow && (int) $commitmentCountRow['count'] === 0) {
    $ownerStatement = $db->prepare('SELECT id FROM users WHERE email = :email');
    $ownerStatement->bindValue(':email', 'demo_owner@commit.local', SQLITE3_TEXT);
    $ownerResult = $ownerStatement->execute();
    $owner = $ownerResult->fetchArray(SQLITE3_ASSOC);
    $ownerId = $owner ? (int) $owner['id'] : null;

    if ($ownerId) {
        $insertCommitment = $db->prepare('INSERT INTO commitments (owner_user_id, title, description, created_at) VALUES (:owner_user_id, :title, :description, :created_at)');
        $insertCommitment->bindValue(':owner_user_id', $ownerId, SQLITE3_INTEGER);
        $insertCommitment->bindValue(':title', '30-Day Writing Streak', SQLITE3_TEXT);
        $insertCommitment->bindValue(':description', 'Post at least one daily check-in with a short update about writing progress.', SQLITE3_TEXT);
        $insertCommitment->bindValue(':created_at', date('Y-m-d H:i:s'), SQLITE3_TEXT);
        $insertCommitment->execute();

        $commitmentId = (int) $db->lastInsertRowID();

        $insertRequirement = $db->prepare('INSERT INTO requirements (commitment_id, type, params, created_at) VALUES (:commitment_id, :type, :params, :created_at)');
        $requirements = [
            ['type' => 'post_frequency', 'params' => json_encode(['count' => 1])],
            ['type' => 'text_update', 'params' => json_encode(new stdClass())],
            ['type' => 'image_required', 'params' => json_encode(new stdClass())],
        ];
        foreach ($requirements as $requirement) {
            $insertRequirement->bindValue(':commitment_id', $commitmentId, SQLITE3_INTEGER);
            $insertRequirement->bindValue(':type', $requirement['type'], SQLITE3_TEXT);
            $insertRequirement->bindValue(':params', $requirement['params'], SQLITE3_TEXT);
            $insertRequirement->bindValue(':created_at', date('Y-m-d H:i:s'), SQLITE3_TEXT);
            $insertRequirement->execute();
        }

        $insertPost = $db->prepare('INSERT INTO posts (commitment_id, author_user_id, type, body_text, image_url, created_at) VALUES (:commitment_id, :author_user_id, :type, :body_text, :image_url, :created_at)');
        $insertPost->bindValue(':commitment_id', $commitmentId, SQLITE3_INTEGER);
        $insertPost->bindValue(':author_user_id', $ownerId, SQLITE3_INTEGER);
        $insertPost->bindValue(':type', 'check_in', SQLITE3_TEXT);
        $insertPost->bindValue(':body_text', 'Day one: drafted 500 words.', SQLITE3_TEXT);
        $insertPost->bindValue(':image_url', 'https://placehold.co/600x400', SQLITE3_TEXT);
        $insertPost->bindValue(':created_at', date('Y-m-d H:i:s'), SQLITE3_TEXT);
        $insertPost->execute();
    }
}

function handle_register(SQLite3 $db, string $email, string $firstName, string $lastName, string $password): array
{
    $errors = [];
    $successMessage = '';

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please provide a valid email address.';
    }

    if ($firstName === '') {
        $errors[] = 'Please provide your first name.';
    }

    if ($lastName === '') {
        $errors[] = 'Please provide your last name.';
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
            $insert = $db->prepare('INSERT INTO users (email, first_name, last_name, password_hash, created_at) VALUES (:email, :first_name, :last_name, :hash, :created_at)');
            $insert->bindValue(':email', $email, SQLITE3_TEXT);
            $insert->bindValue(':first_name', $firstName, SQLITE3_TEXT);
            $insert->bindValue(':last_name', $lastName, SQLITE3_TEXT);
            $insert->bindValue(':hash', $hash, SQLITE3_TEXT);
            $insert->bindValue(':created_at', gmdate('Y-m-d H:i:s'), SQLITE3_TEXT);
            if ($insert->execute()) {
                $successMessage = 'Account created! You can now log in.';
            } else {
                $errors[] = 'Unable to create account. Please try again.';
            }
        }
    }

    return [$errors, $successMessage];
}

function handle_login(SQLite3 $db, string $email, string $password): array
{
    $errors = [];
    $successMessage = '';

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please provide a valid email address.';
    }

    if ($password === '') {
        $errors[] = 'Please enter your password.';
    }

    if (!$errors) {
        $statement = $db->prepare('SELECT id, email, first_name, last_name, password_hash FROM users WHERE email = :email');
        $statement->bindValue(':email', $email, SQLITE3_TEXT);
        $result = $statement->execute();
        $user = $result->fetchArray(SQLITE3_ASSOC);
        if (!$user || !password_verify($password, $user['password_hash'])) {
            $errors[] = 'Invalid email or password.';
        } else {
            $_SESSION['user'] = [
                'id' => $user['id'],
                'email' => $user['email'],
                'first_name' => $user['first_name'],
                'last_name' => $user['last_name'],
            ];
            $successMessage = 'Welcome back! You are now signed in.';
        }
    }

    return [$errors, $successMessage];
}

function handle_logout(): string
{
    $_SESSION = [];
    if (session_id() !== '' || isset($_COOKIE[session_name()])) {
        setcookie(session_name(), '', time() - 3600, '/');
    }
    session_destroy();

    return 'You have been signed out.';
}

function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}
