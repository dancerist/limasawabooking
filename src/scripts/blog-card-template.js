/**
 * Shared blog card template (ported from siargaobooking).
 * Used by:
 *   - /blog archive (src/pages/blog/index.astro)
 *   - (optionally) a homepage "From the blog" section
 *
 * One source of truth — change here, change everywhere.
 * Pair with src/styles/blog-card.css for the styles.
 *
 * Expected post shape (matches GET /wp-json/limasawa/v1/blog/posts):
 *   { slug, title, excerpt, dateLabel, readMin, thumb,
 *     category: { name, slug },
 *     author:   { name, avatar } }
 */

export const SVG_NO_IMG = `<svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.25" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>`

export function blogEsc(s) {
  return String(s ?? '').replace(/[&<>"']/g, c => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
  }[c]))
}

// WordPress sometimes returns names already HTML-encoded (e.g. "News &amp; Updates").
// Decoding before re-escaping keeps the entity rendering correct.
function decodeAmp(s) {
  return String(s ?? '').replace(/&amp;/g, '&')
}

export function blogCardHTML(post) {
  const p = post || {}
  const thumb = p.thumb
    ? `<img src="${blogEsc(p.thumb)}" alt="${blogEsc(p.title || '')}" loading="lazy" />`
    : `<div class="bl-card-img-placeholder">${SVG_NO_IMG}</div>`

  const catBadge = p.category?.name
    ? `<span class="bl-card-cat">${blogEsc(decodeAmp(p.category.name))}</span>`
    : ''

  const author = p.author
    ? `<img class="bl-card-avatar" src="${blogEsc(p.author.avatar)}" alt="${blogEsc(p.author.name)}" loading="lazy" /><span>${blogEsc(p.author.name)}</span><span class="dot"></span>`
    : ''

  const dateLabel = p.dateLabel ? `<span>${blogEsc(p.dateLabel)}</span>` : ''
  const readMin   = p.readMin
    ? `${dateLabel ? '<span class="dot"></span>' : ''}<span>${Number(p.readMin)} min read</span>`
    : ''

  return `<a class="bl-card" href="/blog/${blogEsc(p.slug)}">
    <div class="bl-card-img">${thumb}${catBadge}</div>
    <h2 class="bl-card-title">${blogEsc(p.title || '')}</h2>
    <p class="bl-card-excerpt">${blogEsc(decodeAmp(p.excerpt || ''))}</p>
    <div class="bl-card-meta">${author}${dateLabel}${readMin}</div>
  </a>`
}

export function blogSkeletonHTML(count = 6) {
  return Array.from({ length: count }, () => `
    <div class="bl-card-skel">
      <div class="bl-skel bl-skel-img"></div>
      <div class="bl-skel bl-skel-line" style="width:60%"></div>
      <div class="bl-skel bl-skel-line"></div>
      <div class="bl-skel bl-skel-line" style="width:40%"></div>
    </div>
  `).join('')
}
