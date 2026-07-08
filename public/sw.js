/*
 * Limasawa Booking service worker.
 *
 * Strategy (static Astro site that redeploys on every CMS change):
 *  - HTML navigations: network-first, fall back to cache, then to cached "/".
 *    Never serve stale HTML when online — deploy-hook rebuilds must show up.
 *  - /_astro/* hashed assets: cache-first (immutable by construction).
 *  - Same-origin images/icons/fonts: stale-while-revalidate.
 *  - Cross-origin (WP uploads, map tiles, fonts CDNs): left to the browser.
 */
const VERSION = 'v1'
const STATIC_CACHE = `sb-static-${VERSION}`
const PAGE_CACHE = `sb-pages-${VERSION}`

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(PAGE_CACHE).then((cache) => cache.addAll(['/'])).then(() => self.skipWaiting())
  )
})

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) =>
      Promise.all(keys.filter((k) => ![STATIC_CACHE, PAGE_CACHE].includes(k)).map((k) => caches.delete(k)))
    ).then(() => self.clients.claim())
  )
})

self.addEventListener('fetch', (event) => {
  const req = event.request
  if (req.method !== 'GET') return
  const url = new URL(req.url)
  if (url.origin !== self.location.origin) return

  // HTML navigations: network-first.
  if (req.mode === 'navigate') {
    event.respondWith(
      fetch(req)
        .then((res) => {
          const copy = res.clone()
          caches.open(PAGE_CACHE).then((cache) => cache.put(req, copy))
          return res
        })
        .catch(() =>
          caches.match(req).then((hit) => hit || caches.match('/'))
        )
    )
    return
  }

  // Hashed build assets: cache-first.
  if (url.pathname.startsWith('/_astro/')) {
    event.respondWith(
      caches.match(req).then((hit) =>
        hit ||
        fetch(req).then((res) => {
          const copy = res.clone()
          caches.open(STATIC_CACHE).then((cache) => cache.put(req, copy))
          return res
        })
      )
    )
    return
  }

  // Local images / icons / manifest: stale-while-revalidate.
  if (/\.(png|jpe?g|webp|avif|svg|ico|webmanifest)$/.test(url.pathname)) {
    event.respondWith(
      caches.match(req).then((hit) => {
        const refresh = fetch(req)
          .then((res) => {
            const copy = res.clone()
            caches.open(STATIC_CACHE).then((cache) => cache.put(req, copy))
            return res
          })
          .catch(() => hit)
        return hit || refresh
      })
    )
  }
})
