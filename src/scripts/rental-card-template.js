/**
 * Shared rental card template.
 * Used by /rentals archive (and the related section on /rental/[slug]).
 * Pair with src/styles/rental-card.css.
 *
 * Expected shape (matches GET /wp-json/limasawa/v1/rentals):
 *   { slug, title, type, daily, hourly, area, thumb, available }
 */

export const RT_NO_IMG = `<svg width="46" height="46" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"><circle cx="5.5" cy="17.5" r="3.5"/><circle cx="18.5" cy="17.5" r="3.5"/><path d="M15 17.5h-5l-2-7H5"/><path d="M10 10.5h5l2 7"/></svg>`

export function rentalEsc(s) {
  return String(s ?? '').replace(/[&<>"']/g, c => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
  }[c]))
}

const peso = n => '₱' + Number(n || 0).toLocaleString('en-PH')
const RT_UNIT = { day: '/day', hour: '/hr', trip: '/trip', person: '/person' }
export const rtUnitLabel = u => RT_UNIT[u] || '/day'

export function rentalCardHTML(r) {
  const x = r || {}
  const thumb = x.thumb
    ? `<img src="${rentalEsc(x.thumb)}" alt="${rentalEsc(x.title || '')}" loading="lazy" />`
    : `<div class="rt-card-img-ph">${RT_NO_IMG}</div>`
  const typeBadge = x.type ? `<span class="rt-card-type">${rentalEsc(x.type)}</span>` : ''
  const tierBadge = x.tier === 'featured'
    ? `<span class="rt-card-tier rt-tier-featured">★ Featured</span>`
    : (x.tier === 'pro' ? `<span class="rt-card-tier rt-tier-verified">✓ Verified</span>` : '')
  const unavail = x.available === false ? `<span class="rt-card-unavail">Unavailable</span>` : ''
  const unit = rtUnitLabel(x.rateUnit)
  // Secondary hourly rate, only when the primary rate isn't already hourly.
  const hourly = (Number(x.hourly) && x.rateUnit !== 'hour') ? `<span class="rt-card-hr">· ${peso(x.hourly)}/hr</span>` : ''
  const area = x.area ? `<p class="rt-card-area">${rentalEsc(x.area)}, Limasawa</p>` : ''

  return `<a class="rt-card" href="/rental/${rentalEsc(x.slug)}">
    <div class="rt-card-img">${thumb}${typeBadge}${tierBadge}${unavail}</div>
    <h3 class="rt-card-title">${rentalEsc(x.title || '')}</h3>
    ${area}
    <div class="rt-card-price"><strong>${peso(x.daily)}</strong><span>${unit}</span> ${hourly}</div>
  </a>`
}

export function rentalSkeletonHTML(count = 6) {
  return Array.from({ length: count }, () => `
    <div class="rt-card-skel">
      <div class="rt-skel rt-skel-img"></div>
      <div class="rt-skel rt-skel-line" style="width:70%"></div>
      <div class="rt-skel rt-skel-line" style="width:40%"></div>
    </div>
  `).join('')
}
