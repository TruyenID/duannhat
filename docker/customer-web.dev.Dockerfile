# Dev image cho customer-web — install deps + chạy `pnpm dev` với HMR.
# Source code được bind-mount từ host qua volume trong compose, nên mỗi
# lần sửa file `.tsx`/`.ts` HMR sẽ tự reload mà không cần rebuild image.
#
# Khác với customer-web.prod.Dockerfile: image này không build production
# bundle, chỉ install deps và serve dev. Image-rebuild chỉ cần khi
# dependencies (package.json / pnpm-lock) đổi.

FROM node:22-alpine

WORKDIR /repo

RUN apk add --no-cache git openssh-client && \
    corepack enable && corepack prepare pnpm@latest --activate

# Layer 1 — deps (cached cho đến khi lock-file đổi)
COPY pnpm-workspace.yaml pnpm-lock.yaml package.json ./
COPY packages/ ./packages/
COPY web/customer/package.json ./web/customer/

RUN git init -q . && git config user.email build@tempo.local && git config user.name build
RUN mkdir -p /root/.ssh && \
    ssh-keyscan -t rsa github.com >> /root/.ssh/known_hosts 2>/dev/null || true
RUN --mount=type=ssh pnpm install --frozen-lockfile --filter @tempo/customer-web...

# Layer 2 — source (mặc định COPY, nhưng compose sẽ bind-mount override)
COPY web/customer/ ./web/customer/

WORKDIR /repo/web/customer

EXPOSE 5450

# `next dev` với HMR. -H 0.0.0.0 để container expose ra host.
CMD ["pnpm", "dev"]
