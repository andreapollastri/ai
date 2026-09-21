#!/usr/bin/env bash
#
# TryGeorge — unattended installer for Ubuntu (24.04 / 25.x / 26.04).
#
#   curl -fsSL https://raw.githubusercontent.com/andreapollastri/ai/main/deploy/install.sh | sudo bash
#
# Installs PHP, nginx, the app and its inference workers, publishes the site on
# a domain and requests a Let's Encrypt certificate. Safe to run again: a second
# run updates the code and keeps the database, the APP_KEY and the models.
#
# The site is live in a couple of minutes. The ONNX models are several GB, so
# they download in the background; until they land George answers with the
# built-in keyword heuristic and the page says so.
#
# Everything is overridable from the environment:
#
#   curl -fsSL <url> | sudo DOMAIN=george.example.com EMAIL=me@example.com bash
#
set -Eeuo pipefail

# ---------------------------------------------------------------- settings --

DOMAIN="${DOMAIN:-george.web.ap.it}"
EMAIL="${EMAIL:-}"                       # Let's Encrypt contact, optional
TLS="${TLS:-auto}"                       # auto | on | off
REPO="${REPO:-https://github.com/andreapollastri/ai.git}"
BRANCH="${BRANCH:-main}"
APP_DIR="${APP_DIR:-/var/www/george}"
APP_USER="${APP_USER:-george}"
PHP_WANTED="${PHP_WANTED:-8.5}"          # falls back to 8.4, then 8.3
REASONER="${REASONER:-auto}"             # auto | on | off — slot C, the LLM
ENSEMBLE="${ENSEMBLE:-auto}"             # auto | on | off — slot B, 2nd NLI head
MODELS="${MODELS:-auto}"                 # auto | off     — background download
SWAP="${SWAP:-auto}"                     # auto | off
FIREWALL="${FIREWALL:-auto}"             # auto | off

MIN_DISK_GB=8

# ---------------------------------------------------------------- plumbing --

step() { printf '\n\033[1;36m==>\033[0m \033[1m%s\033[0m\n' "$*"; }
info() { printf '    %s\n' "$*"; }
warn() { printf '    \033[1;33m!\033[0m %s\n' "$*"; }
die()  { printf '\n\033[1;31mx\033[0m %s\n\n' "$*" >&2; exit 1; }

trap 'printf "\n\033[1;31mx\033[0m Install failed at line %s.\n\n" "$LINENO" >&2' ERR

export DEBIAN_FRONTEND=noninteractive
export NEEDRESTART_MODE=a
export NEEDRESTART_SUSPEND=1
export COMPOSER_ALLOW_SUPERUSER=1

# Run a command as the app user, keeping the current directory.
as_app() { sudo -u "$APP_USER" -H env HOME="$APP_DIR" "$@"; }

# Replace a key in an env file, or append it, leaving every other line alone.
set_env() {
    local file=$1 key=$2 value=$3
    python3 - "$file" "$key" "$value" <<'PY'
import re, sys
path, key, value = sys.argv[1:4]
with open(path) as fh:
    text = fh.read()
line = f'{key}={value}'
pattern = re.compile(rf'^{re.escape(key)}=.*$', re.M)
text = pattern.sub(lambda _: line, text, count=1) if pattern.search(text) \
    else text.rstrip('\n') + f'\n{line}\n'
with open(path, 'w') as fh:
    fh.write(text)
PY
}

# ---------------------------------------------------------------- preflight --

step "Preflight"

[[ $EUID -eq 0 ]] || die "Run as root: curl -fsSL <url> | sudo bash"
[[ -r /etc/os-release ]] || die "Cannot read /etc/os-release. This installer targets Ubuntu."
# shellcheck disable=SC1091
. /etc/os-release
[[ "${ID:-}" == "ubuntu" ]] || warn "Tested on Ubuntu, found '${ID:-unknown}'. Continuing."
info "Distribution: ${PRETTY_NAME:-unknown}"

case "$(uname -m)" in
    x86_64|aarch64|arm64) info "Architecture: $(uname -m)" ;;
    *) die "ONNX Runtime ships no binaries for $(uname -m)." ;;
esac

RAM_MB=$(awk '/MemTotal/ {print int($2/1024)}' /proc/meminfo)
DISK_GB=$(df -BG --output=avail /var | tail -1 | tr -dc '0-9')
info "Memory ${RAM_MB} MB, free disk on /var ${DISK_GB} GB"
(( DISK_GB >= MIN_DISK_GB )) || die "Need ${MIN_DISK_GB} GB free on /var, found ${DISK_GB} GB."

# Each NLI worker holds roughly 2 GB of weights and the reasoner roughly 4 GB.
# Enabling more slots than the memory allows just gets them OOM-killed mid-job.
[[ "$REASONER" == "auto" ]] && { (( RAM_MB >= 6000 )) && REASONER=on || REASONER=off; }
[[ "$ENSEMBLE" == "auto" ]] && { (( RAM_MB >= 3500 )) && ENSEMBLE=on || ENSEMBLE=off; }

SLOTS=(a)
[[ "$ENSEMBLE" == "on" ]] && SLOTS+=(b)
[[ "$REASONER" == "on" ]] && SLOTS+=(c)
SLOT_UNITS=()
for s in "${SLOTS[@]}"; do SLOT_UNITS+=("george@${s}"); done

info "Slots enabled: ${SLOTS[*]}"
[[ "$REASONER" == "on" ]] || warn "Reasoner off on ${RAM_MB} MB of RAM. George stays lexical. Force with REASONER=on."

# ------------------------------------------------------------ base packages --

step "Installing base packages"

apt-get update -qq
apt-get install -y -qq --no-install-recommends \
    ca-certificates curl git sudo unzip gnupg lsb-release acl \
    software-properties-common apt-transport-https \
    nginx sqlite3 python3 >/dev/null
info "nginx, git, sqlite3, python3 ready"

# ---------------------------------------------------------------------- swap --

if [[ "$SWAP" != "off" ]] && (( RAM_MB < 8000 )) && [[ -z "$(swapon --show --noheadings 2>/dev/null)" ]]; then
    step "Adding a 4 GB swapfile"
    info "Loading an ONNX graph peaks well above its steady-state footprint."
    fallocate -l 4G /swapfile 2>/dev/null || dd if=/dev/zero of=/swapfile bs=1M count=4096 status=none
    chmod 600 /swapfile
    mkswap -q /swapfile >/dev/null
    swapon /swapfile
    grep -q '^/swapfile' /etc/fstab || echo '/swapfile none swap sw 0 0' >>/etc/fstab
    info "Swap active"
fi

# ----------------------------------------------------------------------- PHP --

step "Installing PHP"

# The PPA may not have a build for a very fresh Ubuntu yet, so a failure to
# update must not poison apt for the rest of the install.
add_php_ppa() {
    add-apt-repository -y ppa:ondrej/php >/dev/null 2>&1 || return 1
    if ! apt-get update -qq 2>/dev/null; then
        warn "ppa:ondrej/php has no packages for this release, removing it."
        add-apt-repository -y --remove ppa:ondrej/php >/dev/null 2>&1 || true
        rm -f /etc/apt/sources.list.d/ondrej-ubuntu-php-*.list \
              /etc/apt/sources.list.d/ondrej-ubuntu-php-*.sources
        apt-get update -qq || true
        return 1
    fi
    return 0
}

# Package names move between releases, so read what apt actually offers rather
# than guessing. Newest first, so 8.5 wins over 8.4 when both are present.
available_php_versions() {
    apt-cache search --names-only '^php[0-9]+\.[0-9]+-cli$' 2>/dev/null \
        | awk '{print $1}' \
        | sed -nE 's/^php([0-9]+\.[0-9]+)-cli$/\1/p' \
        | awk -F. '($1 > 8) || ($1 == 8 && $2 >= 3) { print $1 "." $2 }' \
        | sort -Vr
}

VERSIONS=$(available_php_versions || true)
if [[ -z "$VERSIONS" ]] || ! grep -qx "$PHP_WANTED" <<<"$VERSIONS"; then
    info "php${PHP_WANTED} is not in the archive, trying ppa:ondrej/php"
    add_php_ppa || true
    VERSIONS=$(available_php_versions || true)
fi
[[ -n "$VERSIONS" ]] || die "No PHP 8.3+ package is available from apt on this release."

# Try the requested version first, then everything else newest first.
CANDIDATES=$(printf '%s\n%s\n' "$PHP_WANTED" "$VERSIONS" | awk '!seen[$0]++')
info "PHP versions offered by apt: $(tr '\n' ' ' <<<"$VERSIONS")"

# The core packages must install; each extension is separate so that one
# missing package on a new release cannot abort the whole run.
PHP_EXTENSIONS=(sqlite3 mbstring xml curl zip bcmath gd intl opcache readline)

install_php_version() {
    local v=$1 ext
    grep -qx "$v" <<<"$VERSIONS" || return 1
    if ! apt-get install -y -qq --no-install-recommends "php${v}-cli" "php${v}-fpm" >/tmp/php-install.log 2>&1; then
        warn "php${v}-cli / php${v}-fpm did not install:"
        tail -5 /tmp/php-install.log | sed 's/^/      /' >&2
        return 1
    fi
    for ext in "${PHP_EXTENSIONS[@]}"; do
        apt-get install -y -qq --no-install-recommends "php${v}-${ext}" >/dev/null 2>&1 \
            || warn "php${v}-${ext} is unavailable, continuing without it"
    done
    return 0
}

PHP_VER=""
while read -r candidate; do
    [[ -n "$candidate" ]] || continue
    [[ -n "$PHP_VER" ]] && break
    install_php_version "$candidate" && PHP_VER="$candidate"
done <<<"$CANDIDATES"
[[ -n "$PHP_VER" ]] || die "Could not install any PHP from: $(tr '\n' ' ' <<<"$VERSIONS")"

PHP_BIN="/usr/bin/php${PHP_VER}"
[[ -x "$PHP_BIN" ]] || PHP_BIN="$(command -v php)"
update-alternatives --set php "/usr/bin/php${PHP_VER}" >/dev/null 2>&1 || true
info "PHP ${PHP_VER} at ${PHP_BIN}"

# TransformersPHP drives ONNX Runtime through FFI, which Debian ships disabled.
for sapi in cli fpm; do
    dir="/etc/php/${PHP_VER}/${sapi}/conf.d"
    [[ -d "$dir" ]] || continue
    limit=512M
    [[ "$sapi" == "cli" ]] && limit=4096M
    cat >"${dir}/99-george.ini" <<INI
; TryGeorge — managed by deploy/install.sh
ffi.enable = true
memory_limit = ${limit}
max_execution_time = 300
INI
done

"$PHP_BIN" -m | grep -qix 'FFI' || apt-get install -y -qq --no-install-recommends "php${PHP_VER}-ffi" >/dev/null 2>&1 || true

LOADED=$("$PHP_BIN" -m)
MISSING=()
# FFI loads ONNX Runtime, gd backs the image driver, the rest is what Laravel
# and Composer need to boot at all.
for module in FFI pdo_sqlite mbstring curl dom gd tokenizer; do
    grep -qix "$module" <<<"$LOADED" || MISSING+=("$module")
done
if (( ${#MISSING[@]} > 0 )); then
    die "PHP ${PHP_VER} is missing required extensions: ${MISSING[*]}
      Install them and run this script again, for example:
      apt-get install -y php${PHP_VER}-{sqlite3,mbstring,curl,xml,gd}"
fi
grep -qix 'zip' <<<"$LOADED" || warn "The zip extension is missing; Composer will fall back to the unzip binary."
grep -qix 'pcntl' <<<"$LOADED" || warn "pcntl is missing: workers will not stop gracefully."
info "FFI enabled, CLI memory_limit 4096M"

# ------------------------------------------------------------------ composer --

step "Installing Composer"

if ! command -v composer >/dev/null 2>&1; then
    curl -fsSL https://getcomposer.org/installer -o /tmp/composer-setup.php
    expected=$(curl -fsSL https://composer.github.io/installer.sig)
    actual=$("$PHP_BIN" -r "echo hash_file('sha384', '/tmp/composer-setup.php');")
    [[ "$expected" == "$actual" ]] || die "Composer installer checksum mismatch."
    "$PHP_BIN" /tmp/composer-setup.php --quiet --install-dir=/usr/local/bin --filename=composer
    rm -f /tmp/composer-setup.php
fi
info "$(composer --version --no-ansi 2>/dev/null | head -1)"

# ---------------------------------------------------------------------- Node --

step "Installing Node"

node_major() {
    command -v node >/dev/null 2>&1 || { echo 0; return; }
    node -v | tr -dc '0-9.' | cut -d. -f1
}

# Recent Ubuntu ships a new enough Node, and NodeSource does not always have a
# repository ready for a fresh release, so try the archive first.
if (( $(node_major) < 22 )); then
    apt-get install -y -qq --no-install-recommends nodejs npm >/dev/null 2>&1 || true
fi

if (( $(node_major) < 22 )); then
    info "The archive has no Node 22, adding the NodeSource repository"
    if curl -fsSL https://deb.nodesource.com/setup_22.x -o /tmp/nodesource.sh \
       && bash /tmp/nodesource.sh >/dev/null 2>&1; then
        apt-get install -y -qq nodejs >/dev/null 2>&1 || true
    else
        warn "NodeSource has no package for this release."
    fi
    rm -f /tmp/nodesource.sh
fi

(( $(node_major) >= 22 )) || die "Node 22 or newer is required to build the assets.
      Install it by hand and run this script again."
command -v npm >/dev/null 2>&1 || die "npm is missing. Install the npm package and run this script again."
info "Node $(node -v), npm $(npm -v)"

# -------------------------------------------------------------- app and code --

step "Fetching the application"

if ! id -u "$APP_USER" >/dev/null 2>&1; then
    useradd --system --create-home --home-dir "$APP_DIR" --shell /usr/sbin/nologin "$APP_USER"
    info "Created system user ${APP_USER}"
fi
mkdir -p "$APP_DIR"
chown "$APP_USER:$APP_USER" "$APP_DIR"
git config --global --add safe.directory "$APP_DIR" >/dev/null 2>&1 || true

if [[ -d "$APP_DIR/.git" ]]; then
    as_app git -C "$APP_DIR" remote set-url origin "$REPO"
    as_app git -C "$APP_DIR" fetch --depth 1 origin "$BRANCH"
    as_app git -C "$APP_DIR" reset --hard "origin/${BRANCH}"
    info "Updated the existing checkout"
else
    rm -rf "$APP_DIR/.checkout"
    as_app git clone --depth 1 --branch "$BRANCH" "$REPO" "$APP_DIR/.checkout"
    shopt -s dotglob
    mv "$APP_DIR/.checkout/"* "$APP_DIR/"
    shopt -u dotglob
    rmdir "$APP_DIR/.checkout"
    info "Cloned ${REPO} (${BRANCH})"
fi

cd "$APP_DIR"
info "Revision $(as_app git rev-parse --short HEAD)"

# ------------------------------------------------------------- configuration --

step "Writing the configuration"

ENV_FILE="$APP_DIR/.env"
if [[ -f "$ENV_FILE" ]]; then
    info "Keeping the existing APP_KEY and database"
else
    as_app cp "$APP_DIR/.env.example" "$ENV_FILE"
    info "Created .env from the example"
fi

set_env "$ENV_FILE" APP_ENV production
set_env "$ENV_FILE" APP_DEBUG false
set_env "$ENV_FILE" APP_URL "http://${DOMAIN}"
set_env "$ENV_FILE" LOG_LEVEL warning
set_env "$ENV_FILE" DB_CONNECTION sqlite
set_env "$ENV_FILE" QUEUE_CONNECTION database
# Inference never runs inside php-fpm. A request queues a job and the page polls
# for the result, while dedicated workers hold the models in memory.
set_env "$ENV_FILE" GEORGE_DRIVER queue
set_env "$ENV_FILE" GEORGE_ENGINE auto
set_env "$ENV_FILE" GEORGE_ENSEMBLE "$([[ "$ENSEMBLE" == "on" ]] && echo true || echo false)"
set_env "$ENV_FILE" GEORGE_REASONER "$([[ "$REASONER" == "on" ]] && echo true || echo false)"
chown "$APP_USER:$APP_USER" "$ENV_FILE"
chmod 640 "$ENV_FILE"

mkdir -p "$APP_DIR/database" "$APP_DIR/storage/app/george-models" "$APP_DIR/storage/logs"
[[ -f "$APP_DIR/database/database.sqlite" ]] || as_app touch "$APP_DIR/database/database.sqlite"
chown -R "$APP_USER:$APP_USER" "$APP_DIR/database" "$APP_DIR/storage" "$APP_DIR/bootstrap/cache"

# ------------------------------------------------------------- dependencies --

step "Installing dependencies"

# The ONNX Runtime shared libraries are pulled by a composer plugin and are
# platform specific, so this has to run on the target machine.
as_app composer install --no-dev --optimize-autoloader --no-interaction --no-progress --quiet \
    || die "composer install failed. Run it by hand in ${APP_DIR} to see why."
info "PHP dependencies installed"

as_app npm ci --no-audit --no-fund --ignore-scripts >/dev/null 2>&1 \
    || as_app npm install --no-audit --no-fund --ignore-scripts >/dev/null 2>&1 \
    || die "npm could not install the frontend dependencies."
as_app npm run build >/dev/null 2>&1 || die "npm run build failed. Run it by hand in ${APP_DIR}."
[[ -d "$APP_DIR/public/build" ]] || die "The frontend build produced no public/build directory."
info "Frontend assets built"

# --------------------------------------------------------------- app set-up --

step "Preparing the application"

grep -qE '^APP_KEY=base64:' "$ENV_FILE" || as_app "$PHP_BIN" artisan key:generate --force --no-interaction >/dev/null
as_app "$PHP_BIN" artisan migrate --force --no-interaction >/dev/null
as_app "$PHP_BIN" artisan config:cache >/dev/null
as_app "$PHP_BIN" artisan route:cache >/dev/null
as_app "$PHP_BIN" artisan view:cache >/dev/null
info "Database migrated, caches warmed"

# nginx serves the static files itself, so www-data needs to traverse the home
# directory and read public/. Everything else stays private to the app user.
chmod o+x "$APP_DIR"
setfacl -R -m u:www-data:rX "$APP_DIR/public" 2>/dev/null || chmod -R o+rX "$APP_DIR/public"

# ------------------------------------------------------------------- php-fpm --

step "Configuring php-fpm"

# A dedicated pool runs as the app user so that the web process and the workers
# share one owner for storage/ and the SQLite file.
cat >"/etc/php/${PHP_VER}/fpm/pool.d/george.conf" <<POOL
; TryGeorge — managed by deploy/install.sh
[george]
user = ${APP_USER}
group = ${APP_USER}
listen = /run/php/george.sock
listen.owner = www-data
listen.group = www-data
listen.mode = 0660
pm = dynamic
pm.max_children = 12
pm.start_servers = 2
pm.min_spare_servers = 2
pm.max_spare_servers = 4
pm.max_requests = 500
php_admin_value[memory_limit] = 512M
php_admin_value[error_log] = /var/log/php${PHP_VER}-george.log
php_admin_flag[log_errors] = on
POOL

systemctl enable "php${PHP_VER}-fpm" >/dev/null 2>&1 || true
systemctl restart "php${PHP_VER}-fpm"
info "Pool 'george' on /run/php/george.sock"

# --------------------------------------------------------------------- nginx --

step "Configuring nginx"

VHOST="/etc/nginx/sites-available/${DOMAIN}"

# certbot rewrites this file in place to add the TLS server block, so
# regenerating it on an update would silently drop HTTPS.
if [[ -f "$VHOST" ]] && grep -q 'ssl_certificate' "$VHOST" && [[ "${NGINX_FORCE:-off}" != "on" ]]; then
    info "Keeping the virtual host that already carries the certificate"
    info "Run again with NGINX_FORCE=on to regenerate it from scratch"
else
cat >"$VHOST" <<NGINX
# TryGeorge — managed by deploy/install.sh
server {
    listen 80;
    listen [::]:80;
    server_name ${DOMAIN};

    root ${APP_DIR}/public;
    index index.php;
    charset utf-8;

    client_max_body_size 4m;

    access_log /var/log/nginx/${DOMAIN}.access.log;
    error_log  /var/log/nginx/${DOMAIN}.error.log;

    gzip on;
    gzip_types text/css application/javascript application/json image/svg+xml;
    gzip_min_length 512;

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    location ^~ /build/ {
        expires 1y;
        add_header Cache-Control "public, immutable";
        try_files \$uri =404;
    }

    error_page 404 /index.php;

    location ~ \.php\$ {
        fastcgi_pass unix:/run/php/george.sock;
        fastcgi_param SCRIPT_FILENAME \$realpath_root\$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_hide_header X-Powered-By;
        fastcgi_read_timeout 120;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
NGINX
fi

ln -sfn "$VHOST" "/etc/nginx/sites-enabled/${DOMAIN}"
rm -f /etc/nginx/sites-enabled/default
nginx -t >/dev/null 2>&1 || { nginx -t; die "nginx rejected the generated configuration."; }
systemctl enable nginx >/dev/null 2>&1 || true
systemctl reload nginx
info "Serving ${DOMAIN} on port 80"

# ------------------------------------------------------------------ firewall --

if [[ "$FIREWALL" != "off" ]] && command -v ufw >/dev/null 2>&1 \
   && ufw status 2>/dev/null | head -1 | grep -q 'Status: active'; then
    step "Opening the firewall"
    ufw allow 'Nginx Full' >/dev/null 2>&1 || true
    info "ufw now allows HTTP and HTTPS"
fi

# ------------------------------------------------------------------ services --

step "Installing the services"

DOWNLOAD_FLAGS=""
[[ "$REASONER" == "on" ]] || DOWNLOAD_FLAGS=" --skip-reasoner"

cat >/etc/systemd/system/george@.service <<UNIT
# TryGeorge — managed by deploy/install.sh
[Unit]
Description=TryGeorge inference worker, slot %i
After=network.target
StartLimitIntervalSec=300
StartLimitBurst=10

[Service]
Type=simple
User=${APP_USER}
Group=${APP_USER}
WorkingDirectory=${APP_DIR}
Environment=HOME=${APP_DIR}
ExecStart=${PHP_BIN} -d memory_limit=4096M ${APP_DIR}/artisan george:work --slot=%i --memory=4096 --timeout=180 --tries=1 --sleep=1
Restart=always
RestartSec=5
Nice=5

[Install]
WantedBy=multi-user.target
UNIT

# Several GB of weights. Downloading them from a unit rather than inline means
# the installer returns quickly and the transfer survives the SSH session.
cat >/etc/systemd/system/george-models.service <<UNIT
# TryGeorge — managed by deploy/install.sh
[Unit]
Description=TryGeorge model download
After=network-online.target
Wants=network-online.target

[Service]
Type=oneshot
RemainAfterExit=yes
User=${APP_USER}
Group=${APP_USER}
WorkingDirectory=${APP_DIR}
Environment=HOME=${APP_DIR}
ExecStart=${PHP_BIN} -d memory_limit=4096M ${APP_DIR}/artisan george:download --no-interaction${DOWNLOAD_FLAGS}
# '+' runs this one step with full privileges; the unit itself stays unprivileged.
ExecStartPost=+/bin/systemctl restart ${SLOT_UNITS[*]}
TimeoutStartSec=0
UNIT

cat >/etc/systemd/system/george-schedule.service <<UNIT
# TryGeorge — managed by deploy/install.sh
[Unit]
Description=TryGeorge scheduled tasks

[Service]
Type=oneshot
User=${APP_USER}
Group=${APP_USER}
WorkingDirectory=${APP_DIR}
Environment=HOME=${APP_DIR}
ExecStart=${PHP_BIN} ${APP_DIR}/artisan schedule:run
UNIT

cat >/etc/systemd/system/george-schedule.timer <<'UNIT'
# TryGeorge — managed by deploy/install.sh
[Unit]
Description=Run TryGeorge scheduled tasks every minute

[Timer]
OnCalendar=*:0/1
AccuracySec=10s
Persistent=true

[Install]
WantedBy=timers.target
UNIT

systemctl daemon-reload

# Drop slot units left over from a run that had more slots enabled.
for old in a b c; do
    [[ " ${SLOTS[*]} " == *" ${old} "* ]] && continue
    systemctl disable --now "george@${old}" >/dev/null 2>&1 || true
done

for unit in "${SLOT_UNITS[@]}"; do
    systemctl enable "$unit" >/dev/null 2>&1
    systemctl restart "$unit"
done
systemctl enable --now george-schedule.timer >/dev/null 2>&1
info "Workers running: ${SLOT_UNITS[*]}"

# ----------------------------------------------------------------------- TLS --

dns_points_here() {
    local resolved public_v4
    resolved=$(getent ahostsv4 "$DOMAIN" 2>/dev/null | awk '{print $1}' | sort -u)
    public_v4=$(curl -fsS --max-time 8 https://api.ipify.org 2>/dev/null || true)
    [[ -n "$resolved" ]]  || { warn "${DOMAIN} does not resolve yet."; return 1; }
    [[ -n "$public_v4" ]] || { warn "Could not determine this server's public IP."; return 1; }
    if grep -qx "$public_v4" <<<"$resolved"; then
        info "${DOMAIN} resolves to ${public_v4}"
        return 0
    fi
    warn "${DOMAIN} resolves to $(tr '\n' ' ' <<<"$resolved"), this server is ${public_v4}."
    return 1
}

issue_certificate() {
    step "Requesting a Let's Encrypt certificate"
    command -v certbot >/dev/null 2>&1 || \
        apt-get install -y -qq --no-install-recommends certbot python3-certbot-nginx >/dev/null 2>&1 || true
    command -v certbot >/dev/null 2>&1 || { warn "certbot is unavailable, staying on HTTP."; return 1; }

    local args=(--nginx -d "$DOMAIN" --non-interactive --agree-tos --redirect --no-eff-email)
    if [[ -n "$EMAIL" ]]; then
        args+=(-m "$EMAIL")
    else
        args+=(--register-unsafely-without-email)
    fi

    if certbot "${args[@]}" >/tmp/certbot.log 2>&1; then
        systemctl reload nginx
        info "Certificate installed, HTTP now redirects to HTTPS"
        info "Renewal runs from the certbot systemd timer"
        return 0
    fi

    warn "certbot failed, the site stays on HTTP. First lines of the log:"
    sed -n '1,12p' /tmp/certbot.log | sed 's/^/      /' >&2
    return 1
}

has_certificate() { [[ -s "/etc/letsencrypt/live/${DOMAIN}/fullchain.pem" ]]; }

TLS_OK=no
if has_certificate && grep -q 'ssl_certificate' "$VHOST" 2>/dev/null; then
    step "Certificate"
    info "${DOMAIN} is already on HTTPS; renewal runs from the certbot timer"
    TLS_OK=yes
else
    case "$TLS" in
        off) info "TLS skipped (TLS=off)." ;;
        on)  issue_certificate && TLS_OK=yes || true ;;
        *)   if dns_points_here; then
                 issue_certificate && TLS_OK=yes || true
             else
                 warn "Certificate skipped. Point the A record of ${DOMAIN} here, then run:"
                 warn "  certbot --nginx -d ${DOMAIN} --non-interactive --agree-tos --redirect"
             fi ;;
    esac
fi

# APP_URL follows what is actually serving, not what certbot returned.
if [[ "$TLS_OK" == "yes" ]] || has_certificate; then
    TLS_OK=yes
    set_env "$ENV_FILE" APP_URL "https://${DOMAIN}"
    as_app "$PHP_BIN" artisan config:cache >/dev/null
fi

# -------------------------------------------------------------------- models --

if [[ "$MODELS" != "off" ]]; then
    step "Starting the model download in the background"
    info "Roughly 4 GB from Hugging Face. The site already answers meanwhile."
    systemctl enable george-models.service >/dev/null 2>&1 || true
    systemctl start --no-block george-models.service
else
    info "Model download skipped (MODELS=off). Start it with: systemctl start george-models"
fi

# ---------------------------------------------------------------- smoke test --

step "Checking the site"

sleep 2
CURL_RESOLVE=(--resolve "${DOMAIN}:80:127.0.0.1" --resolve "${DOMAIN}:443:127.0.0.1")
HEALTH=$(curl -fsS -L "${CURL_RESOLVE[@]}" -o /dev/null -w '%{http_code}' --max-time 15 \
    "http://${DOMAIN}/up" 2>/dev/null || echo 000)
if [[ "$HEALTH" == "200" ]]; then
    info "Health check /up returned 200"
else
    warn "Health check returned ${HEALTH}. Inspect: journalctl -u nginx -n 30"
fi

STATUS_JSON=$(curl -fsS -L "${CURL_RESOLVE[@]}" --max-time 15 "http://${DOMAIN}/status" 2>/dev/null || echo '{}')
info "Engine status: ${STATUS_JSON}"

# ---------------------------------------------------------------------- done --

SCHEME=http
[[ "$TLS_OK" == "yes" ]] && SCHEME=https
RAW_URL="$(sed -e 's#^https://github.com/#https://raw.githubusercontent.com/#' -e 's#\.git$##' <<<"$REPO")/${BRANCH}/deploy/install.sh"

cat <<SUMMARY

$(printf '\033[1;32m✓\033[0m') TryGeorge is installed.

   URL         ${SCHEME}://${DOMAIN}
   Code        ${APP_DIR}  (${BRANCH})
   PHP         ${PHP_VER}, FFI on, CLI memory_limit 4096M
   Database    SQLite at ${APP_DIR}/database/database.sqlite
   Slots       ${SLOTS[*]}

   The models are still downloading. Until they land the page answers with the
   keyword heuristic and shows a banner saying so.

   Download progress   journalctl -fu george-models
   Worker logs         journalctl -fu 'george@*'
   Restart workers     systemctl restart ${SLOT_UNITS[*]}
   Update the app      curl -fsSL ${RAW_URL} | sudo bash

SUMMARY
