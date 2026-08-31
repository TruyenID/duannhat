FROM node:22-alpine

# libc6-compat: Next.js SWC binary đôi khi linker dynamic vào glibc trên alpine.
RUN apk add --no-cache libc6-compat

WORKDIR /app

# Pre-install deps để seed named volume "frontend-node_modules" ở compose,
# tránh chạy `npm ci` qua bind mount Windows (chậm 10-50x).
COPY frontend/package.json frontend/package-lock.json ./
RUN npm ci --prefer-offline --no-audit --no-fund || true

EXPOSE 3000
