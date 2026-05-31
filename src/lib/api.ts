/**
 * REST client for the limasawa/v1 Listings API.
 *
 * Shapes mirror the server response exactly (wp/sandbox/limasawa-listings.php).
 * Every fetcher swallows network/HTTP errors and returns a safe empty value so
 * the static Astro build never hard-fails when the WP API is briefly down.
 */

const REST_BASE =
  import.meta.env.PUBLIC_WP_REST ??
  'https://admin.limasawabooking.com/wp-json/limasawa/v1'

export interface Listing {
  id: number
  title: string
  slug: string
  excerpt: string
  tier: 'free' | 'featured' | string
  thumb: string
  badges: string[]
  price: number
  monthly: number
  bedrooms: number
  beds: number
  baths: number
  guests: number
  placeType: string
  subHeading: string
  verified: boolean
  hostName: string
  hostPic: string
  desc: string
  gallery: string[]
  muniName: string
  muniSlug: string
  barName: string
  cats: string[]
  catNames: string[]
  amen: string[]
  amenNames: string[]
  lat: number | null
  lng: number | null
}

export interface ListingDetail extends Listing {
  detail: {
    content: string
    address1: string
    faqAmenities: Record<string, boolean>
    services: { name: string; description: string; icon: string }[]
    customInfo: { title: string; label: string; gallery: string[] }[]
    generatorSched: string
    host: { name: string; contact: string; whatsapp: string; email: string }
    links: {
      booking: string
      bookingcom: string
      airbnb: string
      website: string
      facebook: string
      instagram: string
    }
  }
}

export interface MapPin {
  id: number
  slug: string
  title: string
  price: number
  lat: number
  lng: number
}

export interface ListingsResponse {
  listings: Listing[]
  total: number
  pages: number
  page: number
}

export interface Facet {
  slug: string
  name: string
  count: number
  image: string
}

/**
 * Aggregate categories + locations from a set of listings (build-time facets
 * for the homepage search/browse sections). Only terms that actually have a
 * listing appear; each carries a count and a representative listing image.
 */
export function deriveFacets(listings: Listing[]): { categories: Facet[]; locations: Facet[] } {
  const cats = new Map<string, Facet>()
  const locs = new Map<string, Facet>()
  for (const l of listings) {
    l.cats.forEach((slug, i) => {
      if (!slug) return
      const f = cats.get(slug) ?? { slug, name: l.catNames[i] ?? slug, count: 0, image: '' }
      f.count++
      if (!f.image && l.thumb) f.image = l.thumb
      cats.set(slug, f)
    })
    if (l.muniSlug) {
      const f = locs.get(l.muniSlug) ?? { slug: l.muniSlug, name: l.muniName || l.muniSlug, count: 0, image: '' }
      f.count++
      if (!f.image && l.thumb) f.image = l.thumb
      locs.set(l.muniSlug, f)
    }
  }
  const byCount = (a: Facet, b: Facet) => b.count - a.count || a.name.localeCompare(b.name)
  return {
    categories: [...cats.values()].sort(byCount),
    locations: [...locs.values()].sort(byCount),
  }
}

export interface ListFilters {
  page?: number
  perPage?: number
  cat?: string
  loc?: string
  amenity?: string
  guests?: number
  q?: string
}

/**
 * fetch with a timeout and a couple of retries. Build-time fetches against the
 * WP API occasionally hit a transient ETIMEDOUT; without retries that silently
 * drops a listing from the static build (a null getListing → a missing page).
 */
async function fetchWithRetry(url: string, retries = 2, timeoutMs = 10000): Promise<Response> {
  let lastErr: unknown
  for (let attempt = 0; attempt <= retries; attempt++) {
    const ctrl = new AbortController()
    const timer = setTimeout(() => ctrl.abort(), timeoutMs)
    try {
      const res = await fetch(url, { signal: ctrl.signal })
      clearTimeout(timer)
      return res
    } catch (err) {
      clearTimeout(timer)
      lastErr = err
      if (attempt < retries) {
        await new Promise((r) => setTimeout(r, 300 * (attempt + 1)))
      }
    }
  }
  throw lastErr
}

function buildQuery(filters: ListFilters): string {
  const params = new URLSearchParams()
  if (filters.page) params.set('page', String(filters.page))
  if (filters.perPage) params.set('per_page', String(Math.min(48, Math.max(1, filters.perPage))))
  if (filters.cat) params.set('cat', filters.cat)
  if (filters.loc) params.set('loc', filters.loc)
  if (filters.amenity) params.set('amenity', filters.amenity)
  if (filters.guests) params.set('guests', String(filters.guests))
  if (filters.q) params.set('q', filters.q)
  const qs = params.toString()
  return qs ? `?${qs}` : ''
}

/** Paginated card list. Returns an empty page on failure. */
export async function getListings(filters: ListFilters = {}): Promise<ListingsResponse> {
  const empty: ListingsResponse = { listings: [], total: 0, pages: 1, page: filters.page ?? 1 }
  try {
    const res = await fetchWithRetry(`${REST_BASE}/listings${buildQuery(filters)}`)
    if (!res.ok) throw new Error(`/listings ${res.status}`)
    const json = await res.json()
    return {
      listings: Array.isArray(json.listings) ? json.listings : [],
      total: Number(json.total ?? 0),
      pages: Math.max(1, Number(json.pages ?? 1)),
      page: Number(json.page ?? filters.page ?? 1),
    }
  } catch (err) {
    console.error('[api] getListings failed:', err)
    return empty
  }
}

/** Single listing with the full `detail` block, or null if not found. */
export async function getListing(slug: string): Promise<ListingDetail | null> {
  try {
    const res = await fetchWithRetry(`${REST_BASE}/listings/${encodeURIComponent(slug)}`)
    if (!res.ok) return null
    const json = await res.json()
    return json.listing ?? null
  } catch (err) {
    console.error(`[api] getListing(${slug}) failed:`, err)
    return null
  }
}

/** Lightweight lat/lng pins for the browse map. Returns [] on failure. */
export async function getMapPins(filters: ListFilters = {}): Promise<MapPin[]> {
  try {
    const res = await fetchWithRetry(`${REST_BASE}/map-pins${buildQuery(filters)}`)
    if (!res.ok) throw new Error(`/map-pins ${res.status}`)
    const json = await res.json()
    return Array.isArray(json.pins) ? json.pins : []
  } catch (err) {
    console.error('[api] getMapPins failed:', err)
    return []
  }
}
