# oha publishes release binaries but no Docker image, so the bench builds this small image locally.
# The SHA-256 values were computed from the v1.16.0 release assets on 2026-11-01 (oha publishes no
# checksum file); the build fails if a downloaded binary ever differs.
FROM debian:bookworm-slim
ARG OHA_VERSION=1.16.0
ARG TARGETARCH
ARG SHA256_AMD64=620bb9e16fb53eabc9a3fc45f88bdb41fefa3fee5c05e75892011ce320391716
ARG SHA256_ARM64=99a790eb8c3e0feaca974bd6b32f0f8d4426a0c5b289f39e833e5b2c7529cd39
RUN apt-get update && apt-get install -y --no-install-recommends ca-certificates curl \
    && rm -rf /var/lib/apt/lists/*
RUN set -eux; \
    case "${TARGETARCH}" in amd64) sum="${SHA256_AMD64}" ;; arm64) sum="${SHA256_ARM64}" ;; *) echo "unsupported ${TARGETARCH}"; exit 1 ;; esac; \
    curl -fsSL -o /usr/local/bin/oha "https://github.com/hatoo/oha/releases/download/v${OHA_VERSION}/oha-linux-${TARGETARCH}"; \
    echo "${sum}  /usr/local/bin/oha" | sha256sum -c -; \
    chmod +x /usr/local/bin/oha
ENTRYPOINT ["/usr/local/bin/oha"]
