# Astro dev/build image. Node 26 no longer ships corepack, so pnpm is installed and pinned here.
FROM node:26-trixie-slim

# workerd (Cloudflare's local runtime) needs system CA certificates, which the slim image leaves out.
RUN apt-get update \
    && apt-get install -y --no-install-recommends ca-certificates \
    && rm -rf /var/lib/apt/lists/*

RUN npm install -g pnpm@12.15.0

WORKDIR /repo/web
