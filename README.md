# Commit (prototype)

A minimal accountability app prototype for tracking commitments, requirements, and check-ins.

## Setup

1. Ensure PHP 8+ with SQLite support is installed.
2. From the repo root, start the built-in server:
   ```bash
   php -S localhost:8000
   ```
3. Visit `http://localhost:8000/index.php`.

The database is created automatically at `data/app.db` on first run, and demo data is seeded.

## Demo accounts

- `demo_owner@commit.local` / `password123`
- `demo_supporter@commit.local` / `password123`

## How to use the prototype

1. Log in with a demo account or register a new user.
2. Go to **Commitments** to view all commitments or create a new one.
3. Open a commitment to:
   - Add requirements (post frequency, text update, image URL required).
   - Post a check-in or comment (any logged-in user can post).
   - Subscribe/unsubscribe to receive notifications for new check-ins.
4. Visit **Notifications** to see new check-ins from commitments you follow.

## Assumptions

- “Status (today)” is evaluated using the server’s local date, based on check-ins created today.
- Text/image requirements are satisfied if there is at least one owner check-in today that includes the required content.
- For `post_frequency`, the parameter is stored as a JSON object with a `count` field in the `requirements.params` column.
- Comment notifications are intentionally skipped in MVP (left as a TODO in the code).

## Data model overview

Tables are created on first run:

- `users`
- `commitments`
- `requirements`
- `posts`
- `subscriptions`
- `notifications`

All database queries use prepared statements.
