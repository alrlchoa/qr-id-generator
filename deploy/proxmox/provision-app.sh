#!/usr/bin/env bash
#
# Runs INSIDE the App LXC. Pushed and executed by create-qrid-stack.sh — the
# ${...} placeholders below are substituted by that script's envsubst call
# before this file ever reaches the container. SUDO_USERNAME/SUDO_PASSWORD
# are the one exception: they arrive as real environment variables (see
# push_and_run in create-qrid-stack.sh), never substituted into this text.
# shellcheck disable=SC2016  # single-quoted ${...} below are envsubst placeholders, not bash
set -euo pipefail

export DEBIAN_FRONTEND=noninteractive

# `pct exec ... env ... bash script.sh` runs with a minimal PATH that
# doesn't include /usr/local/bin — where the Composer installer below
# puts the composer binary — so composer would otherwise fail with
# "command not found" the moment it's invoked, well after apt already
# succeeded, deep enough into the script that little of it has actually
# run yet.
export PATH="/usr/local/bin:${PATH}"

# Composer refuses to run plugins as root and warns loudly about it. With
# --no-dev there are no plugins to run anyway; this just stops the warning
# from looking like the cause when something else fails.
export COMPOSER_ALLOW_SUPERUSER=1

# Every network step below can fail transiently — a GitHub 504 on a zipball,
# an apt mirror timing out, a slow NodeSource redirect. Under `set -e` a
# single blip kills a provision that is minutes long and leaves a
# half-configured container behind, which is far more painful to recover
# from than the blip was. Retry with backoff instead of aborting.
retry() {
    local attempts="$1" delay="$2"
    shift 2
    local n=1
    until "$@"; do
        if (( n >= attempts )); then
            echo "FAILED after ${attempts} attempts: $*" >&2
            return 1
        fi
        echo "Attempt ${n}/${attempts} failed: $* — retrying in ${delay}s" >&2
        sleep "$delay"
        n=$(( n + 1 ))
        delay=$(( delay * 2 ))
    done
}

# Optional non-root sudo user, identical on both containers. Arrives as a
# real environment variable (see push_and_run in create-qrid-stack.sh),
# never substituted into this script's text.
if [[ -n "${SUDO_USERNAME:-}" ]]; then
    if ! id "$SUDO_USERNAME" >/dev/null 2>&1; then
        useradd -m -s /bin/bash -G sudo "$SUDO_USERNAME"
    fi
    echo "${SUDO_USERNAME}:${SUDO_PASSWORD}" | chpasswd
fi

REPO_URL='${REPO_URL}'
REPO_BRANCH='${REPO_BRANCH}'
APP_IP='${APP_IP}'
DB_HOST='${DB_HOST}'
DB_NAME='${DB_NAME}'
DB_USER='${DB_USER}'
DB_PASSWORD='${DB_PASSWORD}'

APP_DIR=/opt/qrid/app

retry 3 5 apt-get update -y
retry 3 5 apt-get install -y ca-certificates curl gnupg unzip git software-properties-common \
    apt-transport-https debian-keyring debian-archive-keyring

# --- PHP 8.3 + the extensions this app needs (matches the Phase 0 local
# dev recipe: mbstring, xml, bcmath, curl, zip, pgsql, gd, intl — plus
# fpm, since Caddy talks to PHP over FastCGI here, not php artisan serve).
retry 3 5 apt-get install -y \
    php8.3 php8.3-cli php8.3-fpm php8.3-common php8.3-mbstring php8.3-xml \
    php8.3-bcmath php8.3-curl php8.3-zip php8.3-pgsql php8.3-gd php8.3-intl

# --- Composer, signature-verified the same way as the local dev setup.
if ! command -v composer >/dev/null 2>&1; then
    cd /tmp
    EXPECTED_SIG="$(retry 3 5 curl -fsS https://composer.github.io/installer.sig)"
    retry 3 5 php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
    ACTUAL_SIG="$(php -r "echo hash_file('sha384', 'composer-setup.php');")"
    if [[ "$EXPECTED_SIG" != "$ACTUAL_SIG" ]]; then
        echo "Composer installer signature mismatch — aborting." >&2
        rm -f composer-setup.php
        exit 1
    fi
    php composer-setup.php --install-dir=/usr/local/bin --filename=composer
    rm -f composer-setup.php
fi

# --- Node 20.x LTS, needed only to build the Vite assets the default
# welcome view references (@vite(...)) — not a runtime dependency once
# public/build/ exists.
if ! command -v node >/dev/null 2>&1; then
    retry 3 5 bash -c 'curl -fsSL https://deb.nodesource.com/setup_20.x | bash -'
    retry 3 5 apt-get install -y nodejs
fi

# --- Caddy, from its official apt repo.
if ! command -v caddy >/dev/null 2>&1; then
    retry 3 5 bash -c "set -o pipefail; curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/gpg.key' \
        | gpg --dearmor --yes -o /usr/share/keyrings/caddy-stable-archive-keyring.gpg"
    retry 3 5 bash -c "curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/debian.deb.txt' \
        > /etc/apt/sources.list.d/caddy-stable.list"
    retry 3 5 apt-get update -y
    retry 3 5 apt-get install -y caddy
fi

# --- Clone or update the app.
mkdir -p /opt/qrid
if [[ -d "$APP_DIR/.git" ]]; then
    retry 3 5 git -C "$APP_DIR" fetch origin
    git -C "$APP_DIR" checkout "$REPO_BRANCH"
    git -C "$APP_DIR" reset --hard "origin/${REPO_BRANCH}"
else
    retry 3 5 git clone --branch "$REPO_BRANCH" "$REPO_URL" "$APP_DIR"
fi
cd "$APP_DIR"

if [[ ! -f .env ]]; then
    cp .env.example .env
fi
sed -i \
    -e "s#^APP_ENV=.*#APP_ENV=production#" \
    -e "s#^APP_DEBUG=.*#APP_DEBUG=false#" \
    -e "s#^APP_URL=.*#APP_URL=https://${APP_IP}#" \
    -e "s#^DB_HOST=.*#DB_HOST=${DB_HOST}#" \
    -e "s#^DB_DATABASE=.*#DB_DATABASE=${DB_NAME}#" \
    -e "s#^DB_USERNAME=.*#DB_USERNAME=${DB_USER}#" \
    -e "s#^DB_PASSWORD=.*#DB_PASSWORD=${DB_PASSWORD}#" \
    .env

# Composer 2 does not fall back from dist to source on its own ("Source
# fallback is disabled. Not trying alternative sources."), so a single 504
# from api.github.com on one package aborts the whole install. Retry first;
# if the API is genuinely unhappy, clone over the git protocol instead,
# which never touches the zipball endpoint that fails.
if ! retry 3 15 composer install --no-dev --optimize-autoloader --no-interaction; then
    echo "Dist downloads keep failing — falling back to --prefer-source (git protocol)." >&2
    retry 2 15 composer install --no-dev --optimize-autoloader --no-interaction --prefer-source
fi

retry 3 10 npm install --ignore-scripts
npm run build

if ! grep -q '^APP_KEY=base64' .env; then
    php artisan key:generate --force
fi

# No public disk, no storage:link — architecture §9.2. Photos are served
# through one authenticated route; nothing here is meant to be reachable
# as a static file.
php artisan migrate --force

php artisan config:cache
php artisan route:cache
php artisan view:cache

# A dedicated, unprivileged user for the app files and the PHP-FPM pool,
# rather than running everything as root inside the container.
if ! id qrid >/dev/null 2>&1; then
    useradd --system --home "$APP_DIR" --shell /usr/sbin/nologin qrid
fi
chown -R qrid:qrid "$APP_DIR"
find "$APP_DIR/storage" "$APP_DIR/bootstrap/cache" -type d -exec chmod 775 {} \;

# listen.owner/group/mode default to commented-out in Debian's stock
# www.conf, and PHP-FPM's own fallback behavior for an unset listen.owner
# is not reliably "match the web server" — so these are set explicitly
# (matching both the commented and uncommented forms) rather than left to
# chance. The socket must be owned by Caddy's user, not the PHP worker
# user (qrid), or Caddy can't connect to it at all.
sed -i \
    -e 's/^user = .*/user = qrid/' \
    -e 's/^group = .*/group = qrid/' \
    -e 's/^;\?listen\.owner = .*/listen.owner = caddy/' \
    -e 's/^;\?listen\.group = .*/listen.group = caddy/' \
    -e 's/^;\?listen\.mode = .*/listen.mode = 0660/' \
    /etc/php/8.3/fpm/pool.d/www.conf

cat > /etc/caddy/Caddyfile <<CADDYFILE
${APP_IP} {
    tls internal

    root * ${APP_DIR}/public
    encode gzip

    php_fastcgi unix//run/php/php8.3-fpm.sock
    file_server
}
CADDYFILE

systemctl enable --now php8.3-fpm
systemctl restart php8.3-fpm
systemctl enable --now caddy
systemctl restart caddy

echo "App deployed to ${APP_DIR}, serving ${APP_IP}"
