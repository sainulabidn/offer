// @ts-check
import { defineConfig, envField } from 'astro/config';
import cloudflare from '@astrojs/cloudflare';
import react from '@astrojs/react';
import tailwindcss from '@tailwindcss/vite';

// https://astro.build/config
export default defineConfig({
  // Pages render on Cloudflare Workers and are cached at the edge; static pages can opt in to prerendering.
  output: 'server',
  adapter: cloudflare(),
  integrations: [react()],
  prefetch: { prefetchAll: false, defaultStrategy: 'viewport' },
  env: {
    schema: {
      // Server-side addresses; the defaults are the Docker service names for local development.
      API_URL: envField.string({ context: 'server', access: 'public', default: 'http://app:8000' }),
      TYPESENSE_URL: envField.string({ context: 'server', access: 'public', default: 'http://typesense:8108' }),
      // The API as the shopper's browser reaches it (sign-in, likes, reports).
      PUBLIC_API_URL: envField.string({ context: 'client', access: 'public', default: 'http://localhost:8000' }),
    },
  },
  vite: {
    plugins: [tailwindcss()],
    // The repo is mounted from Windows into Docker, where file events don't arrive; poll instead.
    server: { watch: { usePolling: true, interval: 300 } },
  },
});
