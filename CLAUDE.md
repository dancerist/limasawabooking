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
| `limasawa-auth.php` | Roles (guest/host) + JWT helpers + auth REST API (`/auth/register`, `/auth/login`, `GET/POST /me`) + CORS. Enforces `sb_user_suspended` (login 403, JWT resolver → null). Live & verified 2026-05-30. |
| `limasawa-listings.php` | Listings REST API: `GET /listings` (cards + rich filters), `GET /listings/{slug}` (plural — `{listing:{…,detail}}`), **`GET /listing/{slug}` (SINGULAR — rich flat shape the `stay/[slug].astro` client-renderer fetches: `galleryFull`/`amenityNames`/`faqAmenities` short-keys/`exclusions`/`customInfo`/`claimable`/`hostId`/flat link keys)**, `GET /map-pins`, `GET /taxonomies`. Adds `ratingAvg`/`ratingCount` to cards. Reads native ACF `accommodation` CPT. Live & verified 2026-06-01. |
| `limasawa-submit.php` | Listing-submission + subscription: `POST /submit-listing` (JWT host → pending accommodation, payload → ACF + taxonomies + media + tier/payment meta), `POST /upload-media`, `GET /coupon/validate` (codes in `sb_coupons` option), `POST /renew-subscription` (server-priced, coupon re-validated, writes `sb_payment` pending_review; tier upgrades only on admin approval). Tiers free/pro/featured. Manual e-wallet (no gateway). Live & verified 2026-06-01. |
| `limasawa-dashboard.php` | Dashboard data + analytics: `GET /my-listings`, `POST /listing-action` (pause/unpause/trash), `POST/DELETE /save-listing` + `GET /my-saved`, `POST /track/view` + `/track/click` (per-listing `sb_stats_daily`, dedup+bot filter+source bucket), `GET /host-stats` (period aggregation), `GET /listing-stats`. JWT, owner-scoped. Live & verified 2026-06-01. |
| `limasawa-reviews.php` | Reviews as WP comments (`sb_review`, host-approval): `GET/POST /reviews`, `GET /reviews/pending`, `POST /reviews/{id}/approve|reject`, `/reply`, `GET /my-reviews`, `POST /reviews/{id}` (edit). Per-listing `sb_rating_avg/count` recalc → card pill + analytics KPI. Live & verified 2026-06-01. |
| `limasawa-posts.php` | Host blog (dashboard Posts panel): `GET /my-posts`, `POST /create-post`, `GET/POST/DELETE /post/{id}`. WP posts; EditorJS HTML→post_content + JSON→`sb_content_blocks`. Live & verified 2026-06-01. |
| `limasawa-admin.php` | WP-admin CMS (rich port of siargao's sb-dashboard-widget.php, adapted to limasawa meta): Analytics dashboard widget = stat tiles (active-now, views 7d/30d, signups, listings, reviews-waiting, payments/MRR) + 30d SVG views chart + payments-to-verify + expiring-soon + recent listings + signups + posts-to-approve + composite top-listings + pending-claims section. approve-payment→paid+publish+tier+expiry, approve/reject posts, list filters (tier/payment), per-listing meta box, "Limasawa" CMS menu page, admin columns. Cap `edit_others_posts`. Impersonation omitted. Live 2026-06-01. |
| `limasawa-claims.php` | Listing claims (ported siargao sb-claims.php): `POST /claim-listing`, `GET /my-claims`, `wp_sb_claims` table, admin approve/reject (transfers post_author + seeds avatar), pending-claims table in CMS widget. Adapted to limasawa JWT + `sb_profile_picture_id`. Live & verified 2026-06-01. |
| `limasawa-admin-login.php` | Admin-subdomain entry: branded wp-login (Limasawa wordmark + cyan), root `/` → `/wp-admin` when logged in / branded login when out; skips REST/cron/wp-admin. Live 2026-06-01. |
| `limasawa-coupons.php` | Coupons (ported siargao sb-coupons.php): `wp_sb_coupons` table (migrates demo `WELCOME10`/`SAVE500` from the old `sb_coupons` option), wp-admin **Coupons** CRUD page + REST `limasawa/v1/admin/coupons`, `sb_validate_and_apply_coupon()` (active/expiry/usage-limit/tier/billing + bonus months). `/coupon/validate` + renew delegate to it; approve-payment applies bonus months + increments usage. Live & verified 2026-06-01. |
| `limasawa-manage-hosts.php` | WP-admin "Hosts" page (ported siargao sb-manage-hosts.php): filterable table of accommodation authors (listing counts by status, tier, MRR, 30d views, rating, flags) + slide-in drawer (Listings/Subscriptions/Claims/Activity/Notes) + suspend toggle. REST `limasawa/v1/admin/host/{id}` (+`/notes`,`/suspend`), cap `manage_options`. Impersonation omitted; suspend sets `sb_user_suspended` meta — **enforced** in `limasawa-auth.php` (login → 403 SUSPENDED, JWT resolver → null). Live & verified 2026-06-01. |
| `limasawa-cron.php` | Daily `sb_subscription_check`: paid listings past expiry+3-day grace → downgrade to free (`sb_payment.status=expired`, `listing_tier=free`, stays published) + lapse email; expiring within 7 days → one warning email (transient-guarded). Ported from siargao lifecycle, adapted to `sb_payment` array. Has `$dry` mode. Live & verified 2026-06-01. |

### Secrets / data notes
- JWT secret `SB_JWT_SECRET` in `wp-config.php` (not in git). Coupons live in the `sb_coupons` wp_option (demo: `WELCOME10` 10%, `SAVE500` ₱500). Per-user meta: `sb_saved`, `sb_contact`, `sb_profile_picture_id`, `sb_review_count`. Per-listing meta: `listing_tier`, `sb_payment` (array), `sb_stats_daily`, `sb_rating_avg/count`, `sb_paused`. Reviews = `sb_review` comments; host blog = native WP posts.

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
| `list-your-property.astro` | Conversational multi-step listing form (ported from siargao list-your-home): tier→billing→host→property→category→location→capacity→rates→description→links→services→amenities→generator→gallery→payment→review→success. Leaflet+Nominatim address step; uploads via `/upload-media`; submits via `/submit-listing`; coupon via `/coupon/validate`. Live 2026-06-01. |
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
- **Reviews/ratings LIVE** (`limasawa-reviews.php`): host-approval moderation, card rating pill + analytics rating KPI wired. (`/my-claims` from the ported single template is the one remaining unbuilt endpoint — claim-listing flow; degrades silently.)
- **`auth.astro` LIVE & wired** to `/auth/login` + `/auth/register` ({email,password} → `authToken` in `localStorage.sb_token`), with backend error codes mapped to friendly messages. `/me` extended (GET) + `POST /me` (account/photo save). Verified against live backend.
- **Full host/guest DASHBOARD LIVE** (`/dashboard`, ported siargao UI, 2026-06-01): my-listings + actions (pause/trash), saved listings (server-synced — `window.__sb` now persists), account edit + optional photo (face-gate dropped), analytics (real view/click tracking; history accrues from launch), orders/subscription renewal (tier upgrades pending admin approval), reviews (pending/my-reviews), host blog. Tier-gating off `listing_tier`. Backends: `limasawa-dashboard.php` + `limasawa-reviews.php` + `limasawa-posts.php` + `limasawa-admin.php` (WP-admin approve-payment→publish). All verified over HTTP.
- **`list-your-property` LIVE**: full conversational form ported from siargao + `limasawa-submit.php` backend (verified end-to-end: coupon math, media upload, submit→pending with correct ACF/taxonomy mapping). Submissions land as `pending` accommodations (admin approves → publish). TODO: add payment QR images at `public/payments/{gcash,maya,bpi}-number.webp` (currently 404 on the payment step); demo coupons `WELCOME10`/`SAVE500` seeded in `sb_coupons` option; Google Places autocomplete needs Places API enabled on the key (Nominatim geocode works without it); `faq_hot_&_cold_shower` ACF subfield name has an `&` so that one amenity toggle won't map (9/10 faq amenities map fine).
- Homepage mockup target (search + tours/rentals/transport/food/travel-guide verticals) needs new backend content types (only `accommodation` exists today) — out of scope until those CPTs exist
- **SITE IS LIVE** at https://limasawabooking.com (Cloudflare proxies the apex → Vercel; serving the real homepage + listings as of 2026-05-31). Deploy via `vercel --prod --yes` (project not git-auto-deploying). Note: Vercel reports a nameserver mismatch (wants ns*.vercel-dns.com, domain stays on Cloudflare NS) but Cloudflare proxy A/CNAME records make it resolve fine — leave as-is.
