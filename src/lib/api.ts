const REST_BASE =
  import.meta.env.PUBLIC_WP_REST ??
  'https://admin.limasawabooking.com/wp-json/limasawa/v1'

function reshapeListing(l: any) {
  const galleryNodes = (l.gallery || []).map((url: string) => ({
    sourceUrl: url,
    altText: '',
  }))

  const locationNodes: any[] = []
  if (l.muniName) locationNodes.push({ databaseId: 0, name: l.muniName, slug: l.muniSlug || '' })
  if (l.barName)  locationNodes.push({ databaseId: 0, name: l.barName,  slug: '' })

  const cats: string[]     = Array.isArray(l.cats)     ? l.cats     : []
  const catNames: string[] = Array.isArray(l.catNames) ? l.catNames : []
  const categoryNodes = cats.map((slug, i) => ({ name: catNames[i] || slug, slug }))

  const amenSlugs: string[] = Array.isArray(l.amen)      ? l.amen      : []
  const amenNames: string[] = Array.isArray(l.amenNames) ? l.amenNames : []
  const amenityNodes = amenSlugs.map((slug, i) => ({ name: amenNames[i] || slug, slug }))

  return {
    id: l.id,
    title: l.title,
    slug: l.slug,
    excerpt: l.excerpt || '',
    listingTier: l.tier || 'free',
    featuredImage: l.thumb ? { node: { sourceUrl: l.thumb, altText: '' } } : null,
    accommodationData: {
      accommodationBadges: Array.isArray(l.badges) ? l.badges : [],
      accommodationDailyRent: Number(l.price || 0),
      accommodationMonthlyRent: Number(l.monthly || 0),
      accommodationBedrooms: Number(l.bedrooms || 0),
      accommodationBeds: Number(l.beds || 0),
      accommodationBathrooms: Number(l.baths || 0),
      accommodationNumberOfGuests: Number(l.guests || 0),
      whatTypeOfPlaceWillGuestsHave: l.placeType || '',
      accommodationSubHeading: l.subHeading || '',
      accommodationFullyVerified: !!l.verified,
      accommodationHostName: l.hostName || '',
      accommodationHostPicture: l.hostPic ? { node: { sourceUrl: l.hostPic, altText: '' } } : null,
      customAccommodationShortDescription: l.desc || '',
      accommodationGallery: { nodes: galleryNodes },
      accommodationLocation: { latitude: l.lat ?? null, longitude: l.lng ?? null },
    },
    locations: { nodes: locationNodes },
    accommodationAmenities: { nodes: amenityNodes },
    accommodationsCategory: { nodes: categoryNodes },
  }
}

export async function getAccommodations(first = 12, after?: string) {
  const page = after ? parseInt(after, 10) || 1 : 1
  const url = new URL(`${REST_BASE}/listings`)
  url.searchParams.set('page', String(page))
  url.searchParams.set('per_page', String(Math.min(48, Math.max(1, first))))

  const res = await fetch(url.toString())
  if (!res.ok) throw new Error(`/listings ${res.status}`)
  const json = await res.json()
  const listings: any[] = Array.isArray(json.listings) ? json.listings : []
  const pages: number = Number(json.pages || 1)
  const hasNextPage = page < pages

  return {
    pageInfo: { hasNextPage, endCursor: hasNextPage ? String(page + 1) : null },
    nodes: listings.map(reshapeListing),
  }
}
