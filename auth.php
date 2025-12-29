<?php
session_start();

$route = $route ?? (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/');

$databaseDir = __DIR__ . '/data';
if (!is_dir($databaseDir)) {
    mkdir($databaseDir, 0777, true);
}

$databasePath = $databaseDir . '/app.db';
$db = new SQLite3($databasePath);
$db->exec('CREATE TABLE IF NOT EXISTS users (id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT UNIQUE NOT NULL, first_name TEXT NOT NULL, last_name TEXT NOT NULL, password_hash TEXT NOT NULL, created_at TEXT NOT NULL)');

$tableInfo = $db->query('PRAGMA table_info(users)');
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
