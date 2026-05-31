# limasawabooking.com — Astro Build

> Read this file before every session. Update at end of each session.

## Stack
- **Frontend:** Astro 6, Tailwind CSS v4 — `src/pages/`
- **Backend / Admin:** WordPress at `https://admin.limasawabooking.com` (headless, REST + GraphQL)
- **WP API base:** `https://admin.limasawabooking.com/wp-json/limasawa/v1`
- **GraphQL:** `https://admin.limasawabooking.com/graphql` (WPGraphQL) — to be removed (going REST-native, per siargao's final architecture)
- **Auth:** custom JWT (HS256) via `limasawa/v1/auth/{register,login}` + `limasawa/v1/me`, token stored in `localStorage` as `sb_token`. Two roles: `guest` + `host`.
- **Rendering:** Static — no SSR
- **Permalinks:** pretty `/%postname%/` (enabled 2026-05-30; was plain, which broke `/wp-json/` routing)

## Backend code convention (limasawa-specific — differs from siargao)
- Custom PHP lives in **Novamira sandbox files** at `wp-content/novamira-sandbox/*.php` (auto-loaded), NOT WPCodeBox. limasawa's WAF does NOT block PHP file writes (siargao's did — that's why siargao used WPCodeBox).
- **Source of truth is the git repo `wp/sandbox/*.php`.** Deploy by copying file contents to the server via `novamira/write-file`. Roll back instantly with `novamira/disable-file`.
- **JWT secret** is `SB_JWT_SECRET` in `wp-config.php` (NOT in any snippet, sandbox file, or git). `SB_JWT_ACCESS_TTL` also there (7 days).
- Guard every function with `if (!function_exists())` and keep one owner per function to avoid "Cannot redeclare" fatals.

### Backend files (`wp/sandbox/` → server `wp-content/novamira-sandbox/`)
| File | Purpose |
|---|---|
| `limasawa-auth.php` | Roles (guest/host) + JWT helpers + auth REST API (`/auth/register`, `/auth/login`, `/me`) + CORS. Live & verified 2026-05-30. |

## MCP / Tooling
- `novamira-admin-limasawabooking-com` — MCP connected to admin subdomain

## Files

### Components (`src/components/`)
| File | Purpose |
|---|---|
| `SiteNav.astro` | Sticky header — logo, Browse Stays, List your property, account dropdown |
| `SiteFooter.astro` | Footer with nav columns |

### Pages (`src/pages/`)
| File | Purpose |
|---|---|
| `index.astro` | Homepage — hero + featured listings |
| `stays.astro` | Browse page — card list + map shell |
| `auth.astro` | Login / register (JWT) |
| `dashboard/index.astro` | Host dashboard — listings panel |
| `list-your-property.astro` | Multi-step listing submission (skeleton) |
| `404.astro` | 404 page |

### Support files
| File | Purpose |
|---|---|
| `src/lib/api.ts` | REST `/listings` fetcher, reshapes to GraphQL-style shape |
| `src/layouts/Layout.astro` | Base HTML shell, `window.__sb` saved-listings singleton |
| `src/styles/global.css` | Design tokens + utility classes |

## Status (as of 2026-05-30)
- Scaffold complete — all core pages created
- **Auth layer LIVE**: guest/host roles, JWT, `/auth/register`, `/auth/login`, `/me` — verified end-to-end over HTTP. Code in `wp/sandbox/limasawa-auth.php`.
- Listings REST endpoints (`/listings`, `/map-pins`, etc.) NOT yet built (use siargaobooking as reference)
- Frontend `auth.astro` not yet wired to the new endpoints
- list-your-property form: skeleton only
- Deployed to Vercel, domain pending Cloudflare DNS update
