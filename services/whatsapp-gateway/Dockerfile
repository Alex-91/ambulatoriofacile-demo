# syntax=docker/dockerfile:1.7

FROM golang:1.27-bookworm AS build

RUN apt-get update \
    && apt-get install -y --no-install-recommends gcc libc6-dev libsqlite3-dev \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /src
COPY go.mod go.sum ./
RUN --mount=type=cache,target=/go/pkg/mod \
    go mod download

COPY . .
RUN --mount=type=cache,target=/go/pkg/mod \
    --mount=type=cache,target=/root/.cache/go-build \
    CGO_ENABLED=1 GOOS=linux go build -trimpath -ldflags="-s -w" -o /out/ambulatoriofacile-whatsapp-gateway ./cmd/gateway \
    && CGO_ENABLED=1 GOOS=linux go test ./...

FROM debian:bookworm-slim

RUN apt-get update \
    && apt-get install -y --no-install-recommends ca-certificates libsqlite3-0 tzdata \
    && rm -rf /var/lib/apt/lists/* \
    && groupadd --gid 10001 gateway \
    && useradd --uid 10001 --gid gateway --home-dir /nonexistent --shell /usr/sbin/nologin gateway \
    && mkdir -p /data \
    && chown gateway:gateway /data

COPY --from=build /out/ambulatoriofacile-whatsapp-gateway /usr/local/bin/ambulatoriofacile-whatsapp-gateway

ENV TZ=Europe/Rome \
    WHATSAPP_GATEWAY_LISTEN_ADDR=:8080 \
    WHATSAPP_GATEWAY_DATABASE_DSN=file:/data/whatsapp-gateway.db?_foreign_keys=on&_busy_timeout=5000&_journal_mode=WAL \
    WHATSAPP_GATEWAY_LOG_LEVEL=INFO

USER gateway:gateway
VOLUME ["/data"]
EXPOSE 8080

HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
  CMD ["/usr/local/bin/ambulatoriofacile-whatsapp-gateway", "healthcheck"]

ENTRYPOINT ["/usr/local/bin/ambulatoriofacile-whatsapp-gateway"]
