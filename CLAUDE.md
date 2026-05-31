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
| `limasawa-listings.php` | Listings REST API: `GET /listings` (paginated cards, filters `cat`/`loc`/`amenity`/`guests`/`q`), `GET /listings/{slug}` (single + `detail`), `GET /map-pins`. Reads native ACF `accommodation` CPT; response keys match `src/lib/api.ts`. Live & verified 2026-05-31. |

### Data model (native ACF — read, don't hand-roll)
- **CPT:** `accommodation` (25 published as of 2026-05-31). Field group `group_668c1326c9162` (38 fields, prefix `accommodation_*`).
- **Taxonomies:** `accommodation-category`, `accommodation-amenity`, `accommodation-exclusion`, `location` (in the listings formatter, location term[0]=municipality, term[1]=barangay).
- Key fields: `accommodation_daily_rent`, `accommodation_monthly_rent`, `accommodation_bedrooms/beds/bathrooms`, `accommodation_number_of_guests`, `what_type_of_place_will_guests_have`, `accommodation_gallery` (gallery), `accommodation_location` (google_map → lat/lng), `accommodation_host_picture` (image), `accommodation_badges` (checkbox), `faq_amenities` (group), `services` (repeater gated by `add_services`).

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
| `index.astro` | Homepage — search-first hero, featured `AccommodationCard` grid, browse-by-type/location tiles, host CTA, about. Search + tiles link to `/stays?location=&category=` |
| `stays.astro` | Thin wrapper → renders `StaysExperience` |
| `stay/[slug].astro` | Single listing template (ported from siargao) — photo gallery, facts, amenities, reviews shell, JSON-LD `LodgingBusiness`. `getStaticPaths` via `getAccommodations` |
| `auth.astro` | Login / register (JWT) |
| `dashboard/index.astro` | Host dashboard — listings panel |
| `list-your-property.astro` | Multi-step listing submission (skeleton) |
| `404.astro` | 404 page |

### Components & support files
| File | Purpose |
|---|---|
| `src/components/StaysExperience.astro` | Full `/stays` archive (ported from siargao): Leaflet map + marker cluster (CartoDB tiles, NO API key), filter drawer, category pills, load-more. Client-fetches `/listings`, `/map-pins`, `/taxonomies` |
| `src/components/AccommodationCard.astro` | Listing card (ported). Renders via `src/scripts/card-template.js` (`cardHTML`) + `card-slider.js` + `src/styles/card.css` |
| `src/lib/api.ts` | REST client: flat `getListings`/`getListing`/`getMapPins`/`deriveFacets` + `getAccommodations`/`reshapeListing` (nested shape the ported components consume) |
| `src/lib/locations.ts` | `parseLocationNames` — positional muni/barangay split (term[0]/term[1]) |
| `src/layouts/Layout.astro` | Base HTML shell, `window.__sb` saved-listings singleton |
| `src/styles/global.css` | Design tokens + utility classes; `@import "./card.css"` |
| `.env.example` | `PUBLIC_WP_REST`, `PUBLIC_GOOGLE_MAPS_KEY` (the ported `/stays` uses Leaflet/OSM and needs no key) |

## Status (as of 2026-05-31)
- Scaffold complete — all core pages created
- **Auth layer LIVE**: guest/host roles, JWT, `/auth/register`, `/auth/login`, `/me` — verified end-to-end over HTTP. Code in `wp/sandbox/limasawa-auth.php`.
- **Listings REST API LIVE** (`wp/sandbox/limasawa-listings.php`): `/listings` (rich filters: category/cats/type/badge/rate_mode/price_min/price_max/beds/baths/amenities/location/bounds + `server_filters:true`), `/listings/{slug}`, `/map-pins` (bare array), `/taxonomies`. Verified over HTTP against 25 published accommodations. Filtering: clean tax/meta via WP_Query, price/type/badge/bounds post-filtered in PHP (fine at ~25 listings).
- **Card / `/stays` archive / single template ported from siargaobooking** and adapted to limasawa (2026-05-31). Card design matches siargao. `/stays` = `StaysExperience` (Leaflet map, no API key). Build = 31 pages, all live.
- **Reviews/ratings: NOT built on the backend.** The ported single template + `/stays` rating filter call `/reviews`, `/my-claims` (and read `ratingAvg`/`ratingCount`) which limasawa has no endpoints/fields for — they degrade silently (no reviews shown, no rating pill, rating filter returns nothing). Build the reviews backend to light these up.
- Frontend `auth.astro` not yet wired to the new endpoints
- `window.__sb` (Layout.astro) calls `/save-listing` + `/my-saved` — those backend endpoints do NOT exist yet (heart/save works in localStorage, server sync no-ops)
- list-your-property form: skeleton only
- Homepage mockup target (search + tours/rentals/transport/food/travel-guide verticals) needs new backend content types (only `accommodation` exists today) — out of scope until those CPTs exist
- **SITE IS LIVE** at https://limasawabooking.com (Cloudflare proxies the apex → Vercel; serving the real homepage + listings as of 2026-05-31). Deploy via `vercel --prod --yes` (project not git-auto-deploying). Note: Vercel reports a nameserver mismatch (wants ns*.vercel-dns.com, domain stays on Cloudflare NS) but Cloudflare proxy A/CNAME records make it resolve fine — leave as-is.
