#!/bin/sh
# Dedicated fresh WSL distribution only. Official Debian repository procedure:
# https://docs.docker.com/engine/install/debian/
set -eu
test "${WSL_DISTRO_NAME:-}" = AmbulatorioFacile-FSE
test "$(id -u)" = 0
. /etc/os-release
test "$ID" = debian
test "$VERSION_CODENAME" = trixie
test "$(dpkg --print-architecture)" = amd64
if command -v docker >/dev/null 2>&1; then
    echo 'Docker already present; inspect before changing it.'
    exit 1
fi
test ! -e /etc/apt/sources.list.d/docker.sources
test ! -e /etc/apt/keyrings/docker.asc
export DEBIAN_FRONTEND=noninteractive
apt-get update
apt-get install -y --no-install-recommends ca-certificates curl
install -m 0755 -d /etc/apt/keyrings
curl --fail --show-error --silent --location --proto '=https' https://download.docker.com/linux/debian/gpg -o /etc/apt/keyrings/docker.asc
chmod 0644 /etc/apt/keyrings/docker.asc
cat > /etc/apt/sources.list.d/docker.sources <<'EOF'
Types: deb
URIs: https://download.docker.com/linux/debian
Suites: trixie
Components: stable
Architectures: amd64
Signed-By: /etc/apt/keyrings/docker.asc
EOF
apt-get update
apt-get install -y --no-install-recommends docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
systemctl start docker
docker version
docker compose version
docker ps --format '{{.Names}}'
