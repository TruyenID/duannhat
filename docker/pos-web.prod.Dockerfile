# syntax=docker/dockerfile:1.7
# pos-web — Vite SPA. Build to static assets, serve via nginx alpine.
# Final image ~25MB.

FROM node:22-alpine AS deps
WORKDIR /repo
RUN apk add --no-cache git openssh-client
RUN corepack enable && corepack prepare pnpm@latest --activate
COPY pnpm-workspace.yaml pnpm-lock.yaml package.json ./
COPY packages/ ./packages/
COPY web/pos/package.json ./web/pos/
RUN git init -q . && git config user.email build@tempo.local && git config user.name build
RUN mkdir -p /root/.ssh && \
    ssh-keyscan -t ed25519,rsa github.com >> /root/.ssh/known_hosts
RUN --mount=type=ssh pnpm install --frozen-lockfile --filter @tempo/pos-web...

FROM node:22-alpine AS builder
WORKDIR /repo
RUN apk add --no-cache git
RUN corepack enable && corepack prepare pnpm@latest --activate
COPY --from=deps /repo/node_modules ./node_modules
COPY --from=deps /repo/web/pos/node_modules ./web/pos/node_modules
COPY pnpm-workspace.yaml pnpm-lock.yaml package.json ./
COPY packages/ ./packages/
COPY web/pos/ ./web/pos/
# VITE_* env vars are baked into the bundle at build time.
ARG VITE_API_URL=http://api.tempo.local
ENV VITE_API_URL=${VITE_API_URL}
RUN pnpm --filter @tempo/pos-web build

FROM nginx:alpine AS runner
# SPA routing: any unknown path → index.html so client-side router takes over.
COPY docker/pos-web.nginx.conf /etc/nginx/conf.d/default.conf
COPY --from=builder /repo/web/pos/dist /usr/share/nginx/html
EXPOSE 5440
