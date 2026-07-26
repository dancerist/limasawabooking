// @ts-check
import { defineConfig } from 'astro/config';

import tailwindcss from '@tailwindcss/vite';
import sitemap from '@astrojs/sitemap';

export default defineConfig({
  site: 'https://limasawabooking.com',
  integrations: [
    sitemap({
      // Keep noindex / host-only pages out of the sitemap — they shouldn't be
      // submitted for indexing (each carries a robots noindex meta).
      filter: (page) =>
        !page.includes('/dashboard') &&
        !page.includes('/auth') &&
        !page.includes('/list-your-property') &&
        !page.includes('/list-rental') &&
        !page.includes('/claim-listing'),
      changefreq: 'weekly',
      priority: 0.7,
      lastmod: new Date(),
    }),
  ],
  vite: {
    plugins: [tailwindcss()],
  },
});
