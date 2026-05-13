# limasawabooking.com — Astro Build

> Read this file before every session. Update at end of each session.

## Stack
- **Frontend:** Astro 6, Tailwind CSS v4 — `src/pages/`
- **Backend / Admin:** WordPress at `https://admin.limasawabooking.com` (headless, REST + GraphQL)
- **WP API base:** `https://admin.limasawabooking.com/wp-json/limasawa/v1`
- **GraphQL:** `https://admin.limasawabooking.com/graphql` (WPGraphQL)
- **Auth:** JWT via `wp-json/jwt-auth/v1/token`, stored in `localStorage` as `sb_token`
- **Rendering:** Static — no SSR

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

## Status (as of 2026-05-13)
- Scaffold complete — all core pages created
- WordPress REST API endpoints not yet built (use siargaobooking as reference)
- list-your-property form: skeleton only
- Deployed to Vercel, domain pending Cloudflare DNS update
