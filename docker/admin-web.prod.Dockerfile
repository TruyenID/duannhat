# syntax=docker/dockerfile:1.7
# admin-web — production image for the local Mac mini server.
#
# Build context = umbrella root (so we can copy the pnpm workspace files
# without dragging in each sibling's source).
#
# Strategy:
#   1. `deps`   — install pnpm, fetch all deps for the whole workspace.
#                 We need the workspace root because @tempo/eslint-config,
#                 @tempo/tsconfig, @tempo/prettier-config are workspace
#                 packages that admin-web depends on.
#   2. `builder`— copy source, run `pnpm --filter @tempo/admin-web build`.
#                 next.config.ts sets `output: "standalone"`, which copies
#                 just what's needed into .next/standalone/.
#   3. `runner` — minimal alpine, no pnpm, just node. ~50MB final image.

FROM node:22-alpine AS deps
WORKDIR /repo
# git: needed by pnpm to fetch `@godxjp/ui` from `github:godx-jp/...` and
# by the umbrella `prepare` script (`git config core.hooksPath .githooks`).
# openssh-client: SSH-based git URLs in submodules / private deps.
RUN apk add --no-cache git openssh-client
RUN corepack enable && corepack prepare pnpm@latest --activate
COPY pnpm-workspace.yaml pnpm-lock.yaml package.json ./
COPY packages/ ./packages/
COPY web/admin/package.json ./web/admin/
# Root package.json `prepare` script runs `git config core.hooksPath .githooks`
# which needs to be inside a git repo. Init an empty one so the config command
# succeeds (we don't care about the hook path in the image).
RUN git init -q . && git config user.email build@tempo.local && git config user.name build
# Trust github.com host key so `git clone git@github.com:...` doesn't prompt
# inside the non-interactive Docker build. The fingerprint check happens via
# known_hosts pre-populated here.
RUN mkdir -p /root/.ssh && \
    ssh-keyscan -t ed25519,rsa github.com >> /root/.ssh/known_hosts
# Frozen lockfile + offline mirror keeps the build deterministic.
# --mount=type=ssh forwards the host's SSH_AUTH_SOCK so pnpm can clone
# `@godxjp/ui` from the private repo (compose.local-server.yml passes
# `ssh: ["default"]` in the build block to wire this up).
RUN --mount=type=ssh pnpm install --frozen-lockfile --filter @tempo/admin-web...

FROM node:22-alpine AS builder
WORKDIR /repo
# git also needed at builder stage if any postinstall scripts re-run
RUN apk add --no-cache git
RUN corepack enable && corepack prepare pnpm@latest --activate
COPY --from=deps /repo/node_modules ./node_modules
COPY --from=deps /repo/web/admin/node_modules ./web/admin/node_modules
COPY pnpm-workspace.yaml pnpm-lock.yaml package.json ./
COPY packages/ ./packages/
COPY web/admin/ ./web/admin/
ENV NEXT_TELEMETRY_DISABLED=1
ENV NODE_ENV=production
# NEXT_PUBLIC_* baked at build time — pass via --build-arg if customizing.
ARG NEXT_PUBLIC_API_URL=http://api.tempo.local
ARG NEXT_PUBLIC_CONSOLE_URL=https://dev-console.godx.jp
ARG NEXT_PUBLIC_SSO_SERVICE_SLUG=tempo
# Local login is the SSO-bypass form on the /login page. Default OFF so a
# stray production build won't accidentally expose it. compose passes "true"
# for the local-server stack.
ARG NEXT_PUBLIC_ENABLE_LOCAL_LOGIN=false
ENV NEXT_PUBLIC_API_URL=${NEXT_PUBLIC_API_URL}
ENV NEXT_PUBLIC_CONSOLE_URL=${NEXT_PUBLIC_CONSOLE_URL}
ENV NEXT_PUBLIC_SSO_SERVICE_SLUG=${NEXT_PUBLIC_SSO_SERVICE_SLUG}
ENV NEXT_PUBLIC_ENABLE_LOCAL_LOGIN=${NEXT_PUBLIC_ENABLE_LOCAL_LOGIN}
RUN pnpm --filter @tempo/admin-web build

FROM node:22-alpine AS runner
WORKDIR /app
ENV NODE_ENV=production
ENV NEXT_TELEMETRY_DISABLED=1
ENV HOSTNAME=0.0.0.0
ENV PORT=5430
# next standalone output: the server lives in .next/standalone/web/admin/
# because the workspace root tracing pulls the per-package path along.
COPY --from=builder /repo/web/admin/.next/standalone ./
COPY --from=builder /repo/web/admin/.next/static ./web/admin/.next/static
COPY --from=builder /repo/web/admin/public ./web/admin/public
EXPOSE 5430
# server.js lives inside web/admin/ because of outputFileTracingRoot=..
WORKDIR /app/web/admin
CMD ["node", "server.js"]
