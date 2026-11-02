declare namespace App {
  interface Locals {
    /** Worked out once per request in middleware.ts. Pages read the shopper's area only from here. */
    prefs: import('@offer/rank').Prefs;
  }
}

// Worker bindings from wrangler.jsonc, read with `import { env } from 'cloudflare:workers'`.
declare module 'cloudflare:workers' {
  interface MediaObject {
    body: ReadableStream;
    httpEtag: string;
  }

  export const env: {
    /** R2 bucket "offer" with the product photos (production only). */
    MEDIA?: { get(key: string): Promise<MediaObject | null> };
    /** This deploy's version (production only); keys the page cache. */
    CF_VERSION_METADATA?: { id: string; tag: string; timestamp: string };
  };
}
