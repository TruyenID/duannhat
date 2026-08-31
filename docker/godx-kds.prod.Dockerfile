# syntax=docker/dockerfile:1.7
# godx-kds — Kitchen Display System PWA (Vite/React).
# Standalone submodule with its own pnpm-lock — build context = godx-kds/
# Final image ~25MB (nginx serving static bundle).

FROM node:22-alpine AS deps
WORKDIR /app
RUN apk add --no-cache git openssh-client
RUN corepack enable && corepack prepare pnpm@latest --activate
COPY godx-kds/package.json godx-kds/pnpm-lock.yaml ./
# SSH mount for `@godxjp/ui` private repo fetch.
RUN mkdir -p /root/.ssh && ssh-keyscan -t ed25519,rsa github.com >> /root/.ssh/known_hosts
# pnpm v10 blocks postinstall scripts by default (security). Allow them for
# the build container — msw needs to generate its service worker, sharp/etc
# need to compile native bindings. Equivalent of `pnpm approve-builds`.
ENV PNPM_CONFIG_DANGEROUSLY_ALLOW_ALL_BUILDS=true
RUN --mount=type=ssh pnpm install --frozen-lockfile

FROM node:22-alpine AS builder
WORKDIR /app
RUN apk add --no-cache git
RUN corepack enable && corepack prepare pnpm@latest --activate
COPY --from=deps /app/node_modules ./node_modules
COPY godx-kds/ ./
ARG VITE_API_URL=http://api.tempo.local
ENV VITE_API_URL=${VITE_API_URL}
RUN pnpm build

FROM nginx:alpine AS runner
COPY docker/godx-kds.nginx.conf /etc/nginx/conf.d/default.conf
COPY --from=builder /app/dist /usr/share/nginx/html
EXPOSE 5460
