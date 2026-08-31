import path from "node:path";
import type { NextConfig } from "next";
import createNextIntlPlugin from 'next-intl/plugin';

const isDev = process.env.NODE_ENV !== "production";

const withNextIntl = createNextIntlPlugin('./i18n/request.ts');

const nextConfig: NextConfig = {
  // Dev access qua *.local.godx.jp và Cloudflare quick tunnel.
  allowedDevOrigins: ["*.local.godx.jp", "*.trycloudflare.com"],
  // standalone build emits .next/standalone/server.js — used by the prod
  // Docker image (docker/customer-web.prod.Dockerfile) so the runtime
  // layer is ~50MB instead of dragging the entire pnpm workspace in.
  output: "standalone",
  outputFileTracingRoot: path.join(import.meta.dirname, ".."),
  // Test server pragmatism: don't let pre-existing type drift block a deploy.
  // CI catches these on the dev branch; the local-server stack exists to
  // exercise runtime behavior, not act as a typecheck gate. Devs still see
  // errors in their editor and via `pnpm typecheck`.
  typescript: { ignoreBuildErrors: true },
  images: {
    remotePatterns: [
      { protocol: "http", hostname: "localhost", port: "5490", pathname: "/tempo/**" },
      { protocol: "http", hostname: "127.0.0.1", port: "5490", pathname: "/tempo/**" },
    ],
    dangerouslyAllowLocalIP: true,
  },
  async rewrites() {
    return [
      { source: "/s3/:path*", destination: "http://localhost:5490/tempo/:path*" },
      // staging-only (ensure-api-proxy.mjs): prod builds call /api same-origin
      { source: "/api/:path*", destination: "http://localhost:5400/api/:path*" },
    ];
  },
  async headers() {
    if (!isDev) return [];
    return [
      {
        source: "/:path*",
        headers: [
          { key: "Cache-Control", value: "no-store, no-cache, must-revalidate, max-age=0" },
          { key: "Pragma", value: "no-cache" },
          { key: "Expires", value: "0" },
        ],
      },
    ];
  },
};

export default withNextIntl(nextConfig);
