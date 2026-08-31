# syntax=docker/dockerfile:1.7
# godx-kiosk — Expo/React Native tablet kiosk app, exported as a web bundle.
#
# CAVEAT: kiosk was designed for native iOS/Android tablets. The web export
# WILL render most screens but some native modules degrade gracefully or
# break:
#   - expo-camera (QR scanner): falls back to file-input on web (no live cam)
#   - expo-secure-store: shimmed to localStorage by Expo (less secure)
#   - mDNS workstation discovery: not available on web (uses NetInfo only)
#
# For production-like kiosk testing, use Expo Go QR distribution instead.
# This web build is for "quick smoke test from a laptop" scenarios.

FROM node:22-alpine AS deps
WORKDIR /app
RUN apk add --no-cache git openssh-client
COPY godx-kiosk/package.json godx-kiosk/package-lock.json* ./
RUN mkdir -p /root/.ssh && ssh-keyscan -t ed25519,rsa github.com >> /root/.ssh/known_hosts
RUN --mount=type=ssh npm install --no-audit --no-fund

FROM node:22-alpine AS builder
WORKDIR /app
RUN apk add --no-cache git
COPY --from=deps /app/node_modules ./node_modules
COPY godx-kiosk/ ./
ARG EXPO_PUBLIC_API_URL=http://api.tempo.local
ENV EXPO_PUBLIC_API_URL=${EXPO_PUBLIC_API_URL}
ARG EXPO_PUBLIC_WORKSTATION_URL=
ENV EXPO_PUBLIC_WORKSTATION_URL=${EXPO_PUBLIC_WORKSTATION_URL}
ENV CI=true
# Expo web export — produces dist/ with index.html + assets.
RUN npx expo export --platform web --output-dir dist

FROM nginx:alpine AS runner
COPY docker/godx-kiosk.nginx.conf /etc/nginx/conf.d/default.conf
COPY --from=builder /app/dist /usr/share/nginx/html
EXPOSE 5480
