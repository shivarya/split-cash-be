# Copilot Instructions for PHP Backend (Split Cash)

## Project Overview
- This is the **PHP backend API** for the Split Cash app.
- Purpose: manage users, groups, expenses, balances, settlements, and invitations.
- Tech stack: **PHP 8+**, **MySQL**, **Composer**, **Firebase PHP-JWT**.
- Entry point: `server/index.php` acting as a small router over clean URLs via `.htaccess`.

## Architecture
- `index.php`: parses `REQUEST_URI`, strips `/split_cash` base path, and routes to controllers:
  - `/health` → `healthController.php`
  - `/auth/*` → `authController.php`
  - `/groups/*` → `groupController.php`
  - `/expenses/*` → `expenseController.php`
  - `/balances/*` → `balanceController.php`
- `config/config.php`: loads `.env`, defines DB, JWT, email, and URL constants.
- `config/database.php`:
  - `Database` singleton wrapping PDO.
  - Helper `getDB()` returns the singleton; use `getDB()->getConnection()` for raw PDO when needed.
  - Prefer `Database::query/fetchOne/fetchAll/insert/execute` for new code.
- `utils/jwt.php`:
  - Uses `Firebase\JWT\JWT`.
  - Exposes `JWTHandler` class with `generate($userId, $email)`, `verify($token)`, `getUserFromToken()`.
  - **Always** use `JWTHandler::getUserFromToken()` to get the current user; do not manually parse headers.
- `utils/response.php`:
  - Central JSON responses: `Response::success($data, $message = null, $statusCode = 200)` and `Response::error($message, $statusCode = 400)`.
  - All controllers should exit via these helpers, not `echo`/`die`.

## Database Model (MySQL)
- Schema is defined in `database/schema.sql`. Main tables:
  - `users`: core user profile + Google account linkage.
  - `expense_groups`: logical groups of users (a "group").
  - `group_members`: membership and role (`admin` or `member`).
  - `expenses`: per-group expenses with `paid_by`, `split_type`, `date`, etc.
  - `expense_splits`: per-user owed amounts per expense.
  - `balances`: cached group balances (may be recomputed from expenses/splits).
  - `settlements`: manual settlements between two users in a group.
  - `invitations`: email invitations to join groups.
  - `activities`: audit log for group actions.
- Foreign keys all point to `expense_groups` (not `groups`) and `users`. Avoid using `groups` as a table name.

## Routing & Semantics (must stay in sync with mobile app)
See `split-app/src/services/api.ts` in the main repo for exact paths. The PHP API **must** match these:

- **Auth** (`/auth/*`): login/Google Sign-In, JWT issuance, and token validation.
- **Groups** (`/groups/*`) – `groupController.php`:
  - `POST /groups` – create group.
  - `GET /groups` – list user groups with fields similar to Node version:
    - `id`, `name`, `description`, `category`, `image`, `created_at`, `role`, `member_count`.
  - `GET /groups/{groupId}` – group details and members.
  - `GET /groups/{groupId}/members` – member list.
  - `POST /groups/{groupId}/invite` – send invitation.
  - `POST /groups/accept-invitation` – accept invite by token.
- **Expenses** (`/expenses/*`) – `expenseController.php`:
  - `POST /expenses/{groupId}` – create expense, supports `splitType` = `equal` | `unequal` | `percentage` and `splits` array.
  - `GET /expenses/{groupId}` – list expenses with splits and payer info.
  - `PUT /expenses/{expenseId}` – update description/amount/category/date/notes (creator only).
  - `DELETE /expenses/{expenseId}` – delete expense (creator or group admin).
- **Balances & Settlements** (`/balances/*`) – `balanceController.php`:
  - `GET /balances/my-balances` – per-group summary for current user.
  - `GET /balances/{groupId}` – per-user balances for a single group.
  - `GET /balances/{groupId}/settlements/suggestions` – suggested transfers to settle group.
  - `GET /balances/{groupId}/settlements/history` – past settlements.
  - `POST /balances/{groupId}/settlements` – record a new settlement.
  - `GET /balances/{groupId}/activity` – activity feed for group.

## Coding Standards
- **Language & versions**: PHP 8+, MySQL 8+ compatible SQL.
- **Style**:
  - Controllers = plain functions (no frameworks) grouped by file.
  - Use early returns for errors.
  - Avoid global variables; use helpers (`getDB()`, `JWTHandler`, `Response`).
  - No HTML output from API endpoints; always JSON.
- **Security**:
  - All non-health routes must require a valid JWT (via `JWTHandler::getUserFromToken()`).
  - Always use prepared statements (`$db->prepare(...)` or `Database::query(...)`) with bound parameters.
  - Never trust client-provided IDs without membership checks (`group_members` lookup).
  - Keep JWT secret and DB credentials only in `.env`, never hard-coded in repo.
- **Errors & responses**:
  - Use `Response::error('message', 4xx/5xx)` for all failures.
  - Log server-side exceptions, but return generic messages to clients where appropriate.
  - Maintain consistent response shapes between PHP and the previous Node.js API (success flag, message/data fields).

## When Modifying or Adding Endpoints
- Mirror the behavior, path, and payloads from the Node.js backend under `bk/server/src/controllers/*`.
- Ensure new endpoints:
  - Are wired in `index.php` router.
  - Enforce auth and membership checks.
  - Use existing DB schema (`schema.sql`) without altering fundamental table names or columns unless explicitly requested.
- Keep implementation **small and focused**; reuse existing helpers instead of introducing new abstractions.

## What Not to Change (without explicit request)
- Table names and key columns in `database/schema.sql`.
- JWT token format or claim names.
- Public API paths used by the mobile app (`/auth`, `/groups`, `/expenses`, `/balances`).
- `.env` variable names consumed in `config/config.php`.

This file is for LLM tools (Copilot/Chat) to understand the backend quickly; keep future edits brief and high-signal.