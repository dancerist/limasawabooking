/**
 * Shared listing card template — single source of truth.
 *
 * Used by:
 *   - src/components/AccommodationCard.astro (homepage static cards)
 *   - src/pages/index.astro                  (homepage REST filter results)
 *   - src/pages/stays.astro                  (stays grid, REST results)
 *
 * Expects the REST API "flat" shape:
 *   { slug, title, price, monthly, beds, baths, guests,
 *     thumb, gallery[], hostName, hostPic, verified,
 *     desc, subHeading, muniName, barName, badges[] }
 *
 * AccommodationCard.astro normalises GraphQL → flat before calling cardHTML().
 */

// ─── Inline SVG library ──────────────────────────────────────────────────────
export const SVG_HEART  = `<svg xmlns="http://www.w3.org/2000/svg" width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4.05 3 5.5l7 7Z"/></svg>`
export const SVG_USER   = `<svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="shrink-0"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>`
export const SVG_BED    = `<svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="shrink-0"><path d="M2 4v16"/><path d="M2 8h18a2 2 0 0 1 2 2v10"/><path d="M2 17h20"/><path d="M6 8v9"/></svg>`
export const SVG_BATH   = `<svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="shrink-0"><path d="M9 6 6.5 3.5a1.5 1.5 0 0 0-1-.5C4.683 3 4 3.683 4 4.5V17a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-5"/><line x1="10" x2="8" y1="5" y2="7"/><line x1="2" x2="22" y1="12" y2="12"/><line x1="7" x2="7" y1="19" y2="21"/><line x1="17" x2="17" y1="19" y2="21"/></svg>`
export const SVG_CHECK  = `<svg xmlns="http://www.w3.org/2000/svg" width="9" height="9" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="3.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>`
export const SVG_PERSON = `<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/></svg>`
export const SVG_NO_IMG = `<svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>`
export const SVG_ARROW_LEFT  = `<svg width="8" height="14" viewBox="0 0 8 14" fill="none"><path d="M7 1L1 7l6 6" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg>`
export const SVG_ARROW_RIGHT = `<svg width="8" height="14" viewBox="0 0 8 14" fill="none"><path d="M1 1l6 6-6 6" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg>`
export const SVG_STAR = `<svg xmlns="http://www.w3.org/2000/svg" width="11" height="11" viewBox="0 0 24 24" fill="currentColor" stroke="none"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>`

// ─── Helpers ─────────────────────────────────────────────────────────────────
export function escapeHtml(value) {
  return String(value ?? '')
    .replace(/&/g, '&amp;')
    .replace(/"/g, '&quot;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
}

export function badgeSlug(label) {
  return String(label || '')
    .toLowerCase()
    .replace(/&/g, 'and')
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/(^-|-$)/g, '')
}

function badgeText(badge) {
  if (!badge) return ''
  if (typeof badge === 'object') {
    return badge.label || badge.value || badge.name || badge.slug || badge.key || ''
  }
  return String(badge)
}

export function badgeLabels(listing) {
  const raw = Array.isArray(listing?.badges)
    ? listing.badges
    : Array.isArray(listing?.accommodationBadges)
      ? listing.accommodationBadges
      : []
  return raw.map(badgeText).map((b) => b.trim()).filter(Boolean)
}

export function badgeChipsHTML(listing) {
  return badgeLabels(listing)
    .map((badge) => `<span class="ac-badge ac-badge-${badgeSlug(badge)}">${escapeHtml(badge.replace(' friendly', ''))}</span>`)
    .join('')
}

// Compose the descriptive heading line shown BELOW the property name.
// Order: customDesc › (barangay, municipality) › subHeading › ''
// (l.title is now used as .ac-sub above this line, so don't repeat it here.)
function headingFor(l) {
  if (l.desc && l.desc.trim()) return l.desc.trim()
  const loc = [l.barName, l.muniName].filter(Boolean).join(', ')
  if (loc) return loc
  return l.subHeading || ''
}

function statsHTML(l) {
  const parts = []
  if (l.guests) parts.push(`<span class="ac-stat">${SVG_USER}${l.guests} guest${l.guests !== 1 ? 's' : ''}</span>`)
  if (l.beds)   parts.push(`<span class="ac-stat">${SVG_BED}${l.beds} bed${l.beds !== 1 ? 's' : ''}</span>`)
  if (l.baths)  parts.push(`<span class="ac-stat">${SVG_BATH}${l.baths} bath${l.baths !== 1 ? 's' : ''}</span>`)
  if (!parts.length) return ''
  return `<div class="ac-stats">${parts.map((p, i) => (i > 0 ? '<span class="ac-sep">·</span>' : '') + p).join('')}</div>`
}

function priceHTML(l, rateMode) {
  const daily   = Number(l.price   || 0)
  const monthly = Number(l.monthly || 0)
  if (!daily && !monthly) return ''

  const showMonthlyFirst = rateMode === 'monthly' && monthly > 0
  const primary = showMonthlyFirst ? monthly : daily
  const primaryLabel = showMonthlyFirst ? ' / month' : ' / night'

  let alt = ''
  if (showMonthlyFirst && daily) {
    alt = `<div class="ac-price-month">₱${daily.toLocaleString()} / night</div>`
  } else if (rateMode === 'daily' && monthly) {
    alt = `<div class="ac-price-month">₱${monthly.toLocaleString()} / month</div>`
  }

  if (!primary && !alt) return ''
  return `<div class="ac-price-row"><div>
    ${primary ? `<div class="ac-price-night">₱${primary.toLocaleString()}<span class="ac-price-sub">${primaryLabel}</span></div>` : ''}
    ${alt}
  </div></div>`
}

function avatarHTML(l) {
  const initials = (l.hostName || '').split(' ').map(n => n[0] || '').slice(0, 2).join('').toUpperCase()
  const inner = l.hostPic
    ? `<img src="${escapeHtml(l.hostPic)}" alt="${escapeHtml(l.hostName)}" />`
    : initials
      ? `<span>${escapeHtml(initials)}</span>`
      : SVG_PERSON
  return `<div class="ac-avatar-outer"><div class="ac-avatar-rel">
    <div class="ac-avatar">${inner}</div>
    ${l.verified ? `<div class="ac-verified">${SVG_CHECK}</div>` : ''}
  </div></div>`
}

function sliderHTML(l, uid) {
  const images = Array.isArray(l.gallery) && l.gallery.length
    ? l.gallery
    : (l.thumb ? [l.thumb] : [])
  const total = images.length

  const slides = images.length
    ? images.map((src, i) => `<div class="sb-slide"><img src="${escapeHtml(src)}" alt="${escapeHtml(l.title || '')}" loading="${i === 0 ? 'eager' : 'lazy'}" decoding="${i === 0 ? 'auto' : 'async'}" /></div>`).join('')
    : `<div class="sb-slide ac-img-empty">${SVG_NO_IMG}</div>`

  const arrows = total > 1
    ? `<button class="sb-arrow sb-prev" data-sb-prev aria-label="Previous photo">${SVG_ARROW_LEFT}</button>
       <button class="sb-arrow sb-next" data-sb-next aria-label="Next photo">${SVG_ARROW_RIGHT}</button>`
    : ''

  const dots = total > 1
    ? `<div class="sb-dots"><div class="sb-dots-clip"><div class="sb-dots-track" id="dots-${uid}">${
        images.map((_, i) => `<span class="sb-dot${i === 0 ? ' active' : ''}"></span>`).join('')
      }</div></div></div>`
    : ''

  return `<div class="sb-track" id="track-${uid}">${slides}</div>${arrows}${dots}`
}

// ─── Main template ───────────────────────────────────────────────────────────
/**
 * Build a listing card as an HTML string.
 *
 * @param {object} listing  Flat listing object (REST API shape).
 * @param {object} options
 * @param {boolean} [options.saved=false]    Whether the heart is in the saved state.
 * @param {string}  [options.rateMode='daily']  'daily' | 'monthly' — controls primary price line.
 */
export function cardHTML(listing, options = {}) {
  const { saved = false, rateMode = 'daily' } = options
  const l = listing
  const uid = String(l.slug || '').replace(/[^a-z0-9]/gi, '') || Math.random().toString(36).slice(2)

  const heading  = headingFor(l)
  const badges   = badgeChipsHTML(l)
  const slides   = sliderHTML(l, uid)
  const avatar   = avatarHTML(l)
  const stats    = statsHTML(l)
  const price    = priceHTML(l, rateMode)

  const ratingCount = Number(l.ratingCount || 0)
  const ratingAvg   = Number(l.ratingAvg   || 0)
  const ratingPill  = ratingCount >= 1
    ? `<span class="card-rating-pill">${SVG_STAR} ${ratingAvg.toFixed(1)} (${ratingCount})</span>`
    : ''

  return `<a class="ac" href="/stay/${escapeHtml(l.slug)}" data-sb-slider data-listing-slug="${escapeHtml(l.slug)}">
    <div class="ac-img-wrap">
      <div class="ac-img-inner">
        ${slides}
        ${badges ? `<div class="ac-badges">${badges}</div>` : ''}
        <div class="ac-heart sb-heart${saved ? ' sb-heart--saved' : ''}" data-sb-heart aria-label="Save listing" role="button">
          ${SVG_HEART}
        </div>
      </div>
      ${avatar}
    </div>
    <div class="ac-body">
      ${ratingPill}
      ${l.title ? `<p class="ac-sub">${escapeHtml(l.title)}</p>` : ''}
      <div class="ac-title">${escapeHtml(heading)}</div>
      ${stats}
      ${price}
    </div>
  </a>`
}
