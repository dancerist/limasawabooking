// @ts-check
import { defineConfig } from 'astro/config';

import tailwindcss from '@tailwindcss/vite';
import sitemap from '@astrojs/sitemap';

export default defineConfig({
  site: 'https://limasawabooking.com',
  integrations: [
    sitemap({
      filter: (page) =>
        !page.includes('/dashboard') &&
        !page.includes('/auth') &&
        !page.includes('/list-your-property'),
      changefreq: 'weekly',
      priority: 0.7,
      lastmod: new Date(),
    }),
  ],
  vite: {
    plugins: [tailwindcss()],
  },
});
