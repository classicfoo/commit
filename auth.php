<?php
session_start();

$databaseDir = __DIR__ . '/data';
if (!is_dir($databaseDir)) {
    mkdir($databaseDir, 0777, true);
}

$databasePath = $databaseDir . '/app.db';
$db = new SQLite3($databasePath);
$db->exec('CREATE TABLE IF NOT EXISTS users (id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT UNIQUE NOT NULL, password_hash TEXT NOT NULL, created_at TEXT NOT NULL)');

function handle_register(SQLite3 $db, string $email, string $password): array
{
    $errors = [];
    $successMessage = '';

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
