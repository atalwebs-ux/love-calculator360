# Love Calculator 360 API

Backend REST API for **Love Calculator 360** with MySQL storage (`users`, `calculations`, `daily_predictions`, `favorite_tips`, `game_sessions`) and Google Sign-In (`POST /api/auth/google`).

Supports **both** Hostinger **PHP 8.3 (LiteSpeed)** (`index.php` + `.htaccess`) and **Node.js Express** (`server.js` + `db.js`).

## Endpoints
- `GET /api/health`
- `POST /api/auth/register`
- `POST /api/auth/login`
- `POST /api/auth/google`
- `POST /api/auth/firebase` (Node.js)
- `GET /api/auth/me`
- `POST /api/calculations`
- `GET /api/calculations`
- `DELETE /api/calculations/:id`
- `POST /api/predictions`
- `GET /api/tips/favorites`
- `POST /api/tips/favorites/toggle`
- `POST /api/games/sessions`

## Hostinger Deployment

### Option A: Shared Hosting (PHP + Git Auto-Deploy into `public_html`)
1. Connect `https://github.com/atalwebs-ux/love-calculator360.git` in Hostinger **Git**.
2. Create a `.env` file inside `public_html/` (copied from `.env.example`) with your MySQL password and `JWT_SECRET`.
3. Open `https://lcapi.taskearn.app/api/health` to verify.

### Option B: Hostinger Node.js App
1. Connect this repository (`main` branch).
2. Entry file: `server.js`, Start command: `npm start`.
3. Set `DB_HOST`, `DB_PORT`, `DB_USER`, `DB_PASSWORD`, `DB_NAME`, `JWT_SECRET`, `GOOGLE_WEB_CLIENT_ID` in Hostinger Environment Variables.
