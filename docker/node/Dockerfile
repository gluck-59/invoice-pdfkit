# Node 22 LTS (alpine, pinned). Runtime for PDF rendering + preview static.
FROM node@sha256:0a7108bf6c7bf5de370ffb1a3ed6be93d405b43ff159f681a8d18c0e2bc2e402

WORKDIR /app

# Install prod deps reproducibly from lockfile (no new packages allowed)
COPY package.json package-lock.json docker/node/audit-deps.js ./
RUN npm ci --omit=dev --no-audit --no-fund \
  && node audit-deps.js \
  && npm audit --omit=dev --audit-level=high \
  && npm cache clean --force \
  && rm -f audit-deps.js

# App sources (fonts/locales needed at runtime; tests/screenshots excluded via .dockerignore)
COPY index.js server.js ./
COPY src/ ./src/
COPY locales/ ./locales/
COPY public/ ./public/

ENV NODE_ENV=production PORT=3000
EXPOSE 3000

CMD ["node", "server.js"]
