// Worker bindings from wrangler.jsonc, read with `import { env } from 'cloudflare:workers'`.
declare module 'cloudflare:workers' {
  interface MediaObject {
    body: ReadableStream;
    httpEtag: string;
  }

  export const env: {
    /** R2 bucket "offer" with the product photos (production only). */
    MEDIA?: { get(key: string): Promise<MediaObject | null> };
  };
}
