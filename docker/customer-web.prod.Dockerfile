# syntax=docker/dockerfile:1.7
# customer-web — production image. Same pattern as admin-web.prod.Dockerfile.
# Build context = umbrella root.

FROM node:22-alpine AS deps
WORKDIR /repo
RUN apk add --no-cache git openssh-client
RUN corepack enable && corepack prepare pnpm@latest --activate
COPY pnpm-workspace.yaml pnpm-lock.yaml package.json ./
COPY packages/ ./packages/
COPY web/customer/package.json ./web/customer/
RUN git init -q . && git config user.email build@tempo.local && git config user.name build
RUN mkdir -p /root/.ssh && \
    ssh-keyscan -t ed25519,rsa github.com >> /root/.ssh/known_hosts
RUN --mount=type=ssh pnpm install --frozen-lockfile --filter @tempo/customer-web...

FROM node:22-alpine AS builder
WORKDIR /repo
RUN apk add --no-cache git
RUN corepack enable && corepack prepare pnpm@latest --activate
COPY --from=deps /repo/node_modules ./node_modules
COPY --from=deps /repo/web/customer/node_modules ./web/customer/node_modules
COPY pnpm-workspace.yaml pnpm-lock.yaml package.json ./
COPY packages/ ./packages/
COPY web/customer/ ./web/customer/
ENV NEXT_TELEMETRY_DISABLED=1
ENV NODE_ENV=production
ARG NEXT_PUBLIC_API_URL=http://api.tempo.local
ENV NEXT_PUBLIC_API_URL=${NEXT_PUBLIC_API_URL}
RUN pnpm --filter @tempo/customer-web build

FROM node:22-alpine AS runner
WORKDIR /app
ENV NODE_ENV=production
ENV NEXT_TELEMETRY_DISABLED=1
ENV HOSTNAME=0.0.0.0
ENV PORT=5450
COPY --from=builder /repo/web/customer/.next/standalone ./
COPY --from=builder /repo/web/customer/.next/static ./web/customer/.next/static
COPY --from=builder /repo/web/customer/public ./web/customer/public
EXPOSE 5450
WORKDIR /app/web/customer
CMD ["node", "server.js"]
