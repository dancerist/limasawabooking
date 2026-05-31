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
