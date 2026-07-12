/**
 * Build-time Open Graph images — /og/{slug}.jpg (1200×630).
 *
 * Renders a branded "hero card" for every share-worthy page that has no
 * natural photo of its own (homepage, archives, SEO landings, host pages):
 * island aerial photo + navy gradient + wordmark + page title, mirroring the
 * homepage hero. Singles (stay/rental/blog) keep sharing their real photos.
 *
 * Static output: satori (layout → SVG) + resvg (SVG → PNG) + sharp (PNG → JPEG) run during
 * `astro build`; production serves plain PNG files.
 */
import type { APIRoute } from 'astro'
import satori from 'satori'
import { Resvg } from '@resvg/resvg-js'
import sharp from 'sharp'
import { readFile } from 'node:fs/promises'
import { resolve } from 'node:path'

type Card = { kicker?: string; title: string; subtitle: string }

// Single source of truth: slug → card copy. Pages reference `/og/{slug}.jpg`.
export const OG_CARDS: Record<string, Card> = {
  'default':        { title: 'Limasawa Island,\nSouthern Leyte', subtitle: 'Stays, rentals & travel guides — book direct with local hosts.' },
  'home':           { kicker: 'Southern Leyte · Philippines', title: 'Explore\nLimasawa Island', subtitle: 'Find accommodation, scooter rentals, tours and local services across Limasawa.' },
  'stays':          { kicker: 'Browse all stays', title: 'Accommodations in\nLimasawa Island', subtitle: 'Beachfront resorts, homestays & budget lodges — compare prices and book direct.' },
  'rentals':        { kicker: 'Vehicle rentals', title: 'Rentals in\nLimasawa', subtitle: 'Scooters, motorbikes, cars & boats — rent from local providers.' },
  'blog':           { kicker: 'Travel guides', title: 'Stories from\nLimasawa', subtitle: 'Island guides, beach & dive tips, and host stories.' },
  'stays-beachfront': { kicker: 'Browse stays', title: 'Beachfront stays in\nLimasawa Island', subtitle: 'Wake up to the sea — resorts & homestays right on the beach.' },
  'stays-cabulihan':  { kicker: 'Browse stays', title: 'Stays in Cabulihan,\nLimasawa Island', subtitle: 'Compare accommodations in Barangay Cabulihan and book direct.' },
  'stays-lugsongan':  { kicker: 'Browse stays', title: 'Stays in Lugsongan,\nLimasawa Island', subtitle: 'Compare accommodations in Barangay Lugsongan and book direct.' },
  'stays-magallanes': { kicker: 'Browse stays', title: 'Stays in Magallanes,\nLimasawa Island', subtitle: 'Compare accommodations in Barangay Magallanes and book direct.' },
  'stays-san-agustin': { kicker: 'Browse stays', title: 'Stays in San Agustin,\nLimasawa Island', subtitle: 'Compare accommodations in Barangay San Agustin and book direct.' },
  'stays-san-bernardo': { kicker: 'Browse stays', title: 'Stays in San Bernardo,\nLimasawa Island', subtitle: 'Compare accommodations in Barangay San Bernardo and book direct.' },
  'stays-triana':     { kicker: 'Browse stays', title: 'Stays in Triana,\nLimasawa Island', subtitle: 'Compare accommodations in Barangay Triana and book direct.' },
  'host':           { kicker: 'For hosts', title: 'List your property\non Limasawa Booking', subtitle: 'Free to start — reach travellers planning their Limasawa trip.' },
  'list-rental':    { kicker: 'For providers', title: 'List your rental\non Limasawa Booking', subtitle: 'Scooters, cars or boats — free to list, guests contact you directly.' },
}

const NAVY = '#182d3c'
const TEAL_BRIGHT = '#3EC7CF'

// Minimal hyperscript for satori's object syntax
const h = (type: string, props: Record<string, any>, ...children: any[]) => ({
  type,
  props: { ...props, ...(children.length ? { children: children.length === 1 ? children[0] : children } : {}) },
})

async function assets() {
  // process.cwd() = project root during `astro build` (bundled endpoints can't
  // resolve src/ paths via import.meta.url — it points into dist/).
  const dir = resolve(process.cwd(), 'src/assets/og')
  const [f500, f800, hero] = await Promise.all([
    readFile(resolve(dir, 'dmsans-500.ttf')),
    readFile(resolve(dir, 'dmsans-800.ttf')),
    readFile(resolve(dir, 'hero.jpg')),
  ])
  return { f500, f800, heroUri: `data:image/jpeg;base64,${hero.toString('base64')}` }
}

function card(c: Card, heroUri: string) {
  const pin = h('svg', { width: 34, height: 34, viewBox: '0 0 24 24', fill: 'none' },
    h('path', { d: 'M12 4.6c-2.6 0-4.7 2.1-4.7 4.7 0 3.3 4.7 7.1 4.7 7.1s4.7-3.8 4.7-7.1c0-2.6-2.1-4.7-4.7-4.7z', fill: '#fff' }),
    h('circle', { cx: 12, cy: 9.3, r: 1.9, fill: '#157E84' }),
  )
  return h('div', { style: { width: 1200, height: 630, display: 'flex', backgroundImage: `url(${heroUri})`, backgroundSize: '1200px 630px', fontFamily: 'DM Sans' } },
    // gradient overlay: dark on the left where text sits, lighter to the right
    h('div', { style: { position: 'absolute', top: 0, left: 0, width: 1200, height: 630, background: 'linear-gradient(100deg, rgba(13,26,36,.94) 0%, rgba(13,26,36,.82) 42%, rgba(13,26,36,.28) 78%, rgba(13,26,36,.12) 100%)' } }),
    h('div', { style: { display: 'flex', flexDirection: 'column', justifyContent: 'space-between', padding: '56px 64px', width: 1200, height: 630 } },
      // logo row
      h('div', { style: { display: 'flex', alignItems: 'center', gap: 16 } },
        h('div', { style: { width: 54, height: 54, borderRadius: 27, background: '#157E84', display: 'flex', alignItems: 'center', justifyContent: 'center', boxShadow: '0 4px 14px rgba(21,126,132,.5)' } }, pin),
        h('div', { style: { display: 'flex', fontSize: 34, fontWeight: 800, color: '#fff' } },
          'Limasawa', h('span', { style: { color: TEAL_BRIGHT } }, 'Booking'),
        ),
      ),
      // title block
      h('div', { style: { display: 'flex', flexDirection: 'column', maxWidth: 860 } },
        ...(c.kicker ? [h('div', { style: { display: 'flex', fontSize: 24, fontWeight: 800, letterSpacing: 4, textTransform: 'uppercase', color: TEAL_BRIGHT, marginBottom: 18 } }, c.kicker)] : []),
        h('div', { style: { display: 'flex', fontSize: 84, fontWeight: 800, lineHeight: 1.04, color: '#fff', whiteSpace: 'pre-wrap', letterSpacing: -2 } }, c.title),
        h('div', { style: { display: 'flex', fontSize: 30, fontWeight: 500, lineHeight: 1.4, color: 'rgba(255,255,255,.86)', marginTop: 22, maxWidth: 760 } }, c.subtitle),
      ),
      // bottom bar
      h('div', { style: { display: 'flex', alignItems: 'center', gap: 12, fontSize: 24, fontWeight: 500, color: 'rgba(255,255,255,.75)' } },
        h('div', { style: { width: 10, height: 10, borderRadius: 5, background: TEAL_BRIGHT } }),
        'limasawabooking.com — book direct with local hosts',
      ),
    ),
  )
}

export function getStaticPaths() {
  return Object.keys(OG_CARDS).map((slug) => ({ params: { slug } }))
}

export const GET: APIRoute = async ({ params }) => {
  const c = OG_CARDS[params.slug as string]
  const { f500, f800, heroUri } = await assets()
  const svg = await satori(card(c, heroUri) as any, {
    width: 1200,
    height: 630,
    fonts: [
      { name: 'DM Sans', data: f500, weight: 500, style: 'normal' },
      { name: 'DM Sans', data: f800, weight: 800, style: 'normal' },
    ],
  })
  const png = new Resvg(svg, { background: NAVY, fitTo: { mode: 'width', value: 1200 } }).render().asPng()
  const jpg = await sharp(png).jpeg({ quality: 84 }).toBuffer()
  return new Response(new Uint8Array(jpg), { headers: { 'Content-Type': 'image/jpeg' } })
}
