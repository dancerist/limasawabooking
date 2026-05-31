/**
 * Location helpers for Limasawa.
 *
 * Unlike siargao (which split municipality vs barangay by term ID), limasawa's
 * REST listings formatter already emits the `location` taxonomy terms in order:
 * term[0] = municipality, term[1] = barangay. `api.ts` reshapes those into
 * `locations.nodes[]` in the same order, so we split positionally here.
 */

export interface LocationNode {
  databaseId?: number
  name: string
  slug?: string
}

/**
 * Location hierarchy for the "List your property" form's location step.
 * Limasawa is a single island municipality; the `location` taxonomy stores its
 * barangays as flat terms (no municipality parent). We model one municipality
 * ("Limasawa") whose `barangays` are the real `location` term IDs, so the
 * ported siargao step (municipality → barangay) works unchanged.
 * Term IDs match wp_terms on admin.limasawabooking.com (fetched 2026-06-01).
 */
export interface Barangay { id: number; name: string; slug: string }
export interface Municipality { id: number; name: string; slug: string; barangays: Barangay[] }

export const MUNICIPALITIES: Municipality[] = [
  {
    id: 1, name: 'Limasawa', slug: 'limasawa',
    barangays: [
      { id: 39, name: 'Cabulihan',   slug: 'cabulihan' },
      { id: 40, name: 'Lugsongan',   slug: 'lugsongan' },
      { id: 41, name: 'Magallanes',  slug: 'magallanes' },
      { id: 42, name: 'San Agustin', slug: 'san-agustin' },
      { id: 43, name: 'San Bernardo', slug: 'san-bernardo' },
      { id: 44, name: 'Triana',      slug: 'triana' },
    ],
  },
]

/** Given a listing's location nodes, return { municipality, barangay } name strings. */
export function parseLocationNames(nodes: LocationNode[]): {
  municipality: string
  barangay: string
} {
  const list = Array.isArray(nodes) ? nodes : []
  return {
    municipality: list[0]?.name ?? '',
    barangay: list[1]?.name ?? '',
  }
}
