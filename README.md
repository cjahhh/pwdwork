# PWDWork

An inclusive job matching web platform for persons with disabilities (PWDs) in the Philippines.

CIT 301 prototype at the Technological Institute of the Philippines. Supports SDG 8 and SDG 10; aligns with RA 7277 and RA 10524.

**Current phase:** audit and setup. The React app still shows placeholder pages. Laravel + Sanctum are installed in `/backend` and pointed at local MySQL. Tables, seeders, and login come in the next phases.

## Tech stack

- Front end: React (Vite), React Router, CSS Modules, Axios
- Back end: Laravel 12, Sanctum (API tokens), MySQL (XAMPP MariaDB)
- Cloud deployment is out of scope for now

Laravel 13 needs PHP 8.3. This machine has PHP 8.2.12, so the project uses **Laravel 12** (latest line that matches XAMPP).

## Local environment (this project)

- Windows + XAMPP (`C:\xampp`)
- PHP 8.2.12
- Composer 2.9.7
- MySQL user `root` with an empty password
- Database name: `pwdwork`

## Run locally

1. In the **XAMPP Control Panel**, start **Apache** (optional) and **MySQL**.
2. API (Terminal 1):

```powershell
cd C:\Users\A-204\pwdwork\backend
php artisan serve
```

3. Front end (Terminal 2):

```powershell
cd C:\Users\A-204\pwdwork
npm install
npm run dev
```

4. Open [http://localhost:5173](http://localhost:5173) for the UI and [http://localhost:8000/api/health](http://localhost:8000/api/health) for the API ping.

Copy env files if you clone on another PC:

```powershell
copy .env.example .env
copy backend\.env.example backend\.env
cd backend
php artisan key:generate
```

Do **not** run `php artisan migrate` yet. Custom tables are the next phase.

`php artisan db:show` may error on XAMPP (`performance_schema.session_status`). That is a MariaDB quirk, not a bad `.env`. A real query to `pwdwork` succeeds.

## Folder map (for the defense)

| Path | What it is for |
| --- | --- |
| `src/pages/` | One file per screen (still placeholders). |
| `src/components/` | Shared UI: Layout, Navbar, Footer, ProtectedRoute. |
| `src/services/api.js` | Shared Axios instance (`VITE_API_URL`). |
| `src/context/` | AuthContext will go here later. |
| `backend/` | Laravel REST API. |
| `backend/.env` | Local secrets (never commit). |
| `backend/config/cors.php` | Allows the Vite origin `http://localhost:5173`. |
| `backend/app/Models/User.php` | User model with Sanctum `HasApiTokens`. |
| `backend/routes/api.php` | API routes (`/api/health`, `/api/user`). |

## Front-end routes (unchanged)

`/`, `/jobs`, `/jobs/:id`, `/employers`, `/about`, `/login`, `/register`, `/forgot-password`, `/applicant`, `/employer`, `/admin`

## Next phases

Database (migrations, models, seeders) → Authentication → public pages with real jobs → applicant → employer → admin → testing.
