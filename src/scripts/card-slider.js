/**
 * Shared card slider initialiser.
 * Used by AccommodationCard.astro (homepage) and stays.astro (dynamic grid).
 *
 * Finds every [data-sb-slider] inside `root` that hasn't been initialised yet
 * and wires up scroll-snap navigation + dots + heart state.
 *
 * @param {HTMLElement|Document} [root=document]
 */
export function initSliders(root = document) {
  const DOT_STEP = 11 // px per dot step (6px dot + 5px gap)
  const VISIBLE  = 5  // max visible dots

  root.querySelectorAll('[data-sb-slider]:not([data-slider-ready])').forEach(card => {
    card.dataset.sliderReady = '1'

    const slug      = card.dataset.listingSlug ?? ''
    const track     = card.querySelector('[id^="track-"]')
    const dotsTrack = card.querySelector('[id^="dots-"]')
    const prevBtn   = card.querySelector('[data-sb-prev]')
    const nextBtn   = card.querySelector('[data-sb-next]')
    const heartEl   = card.querySelector('[data-sb-heart]')
    const dots      = dotsTrack ? Array.from(dotsTrack.querySelectorAll('.sb-dot')) : []
    const total     = dots.length || 1
    if (!track) return

    let cur = 0

    function goTo(idx) {
      idx = Math.max(0, Math.min(idx, total - 1))
      track.scrollTo({ left: idx * track.offsetWidth, behavior: 'smooth' })
      setActive(idx)
    }

    function setActive(idx) {
      cur = idx
      dots.forEach((d, i) => d.classList.toggle('active', i === idx))
      if (dotsTrack && total > VISIBLE) {
        const start = Math.max(0, Math.min(idx - 2, total - VISIBLE))
        dotsTrack.style.transform = `translateX(-${start * DOT_STEP}px)`
      }
      if (prevBtn) prevBtn.toggleAttribute('data-hidden', idx === 0)
      if (nextBtn) nextBtn.toggleAttribute('data-hidden', idx === total - 1)
    }

    prevBtn?.addEventListener('click', e => { e.preventDefault(); e.stopPropagation(); goTo(cur - 1) })
    nextBtn?.addEventListener('click', e => { e.preventDefault(); e.stopPropagation(); goTo(cur + 1) })

    // ── Heart / save ───────────────────────────────────────────────────────
    if (heartEl) {
      const sb = window.__sb
      if (sb) heartEl.classList.toggle('sb-heart--saved', sb.isSaved(slug))

      heartEl.addEventListener('click', async e => {
        e.preventDefault(); e.stopPropagation()
        const sbInst = window.__sb
        if (sbInst) await sbInst.toggle(slug)
      })

      window.addEventListener('sb:saved-changed', e => {
        if (e.detail?.slug === slug) heartEl.classList.toggle('sb-heart--saved', e.detail.saved)
      })
      window.addEventListener('sb:saved-synced', () => {
        const sbInst = window.__sb
        if (sbInst) heartEl.classList.toggle('sb-heart--saved', sbInst.isSaved(slug))
      })
      window.addEventListener('sb:saved-cleared', () => {
        heartEl.classList.remove('sb-heart--saved')
      })
    }

    // ── Scroll → update active dot ─────────────────────────────────────────
    let raf = 0
    track.addEventListener('scroll', () => {
      cancelAnimationFrame(raf)
      raf = requestAnimationFrame(() => {
        const idx = Math.round(track.scrollLeft / (track.offsetWidth || 1))
        if (idx !== cur) setActive(idx)
      })
    }, { passive: true })

    setActive(0)
  })
}
