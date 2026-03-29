#!/bin/bash
# NetMan Probe Setup Script
# Run as root: sudo bash setup.sh

set -e

# Fix CRLF line endings if script was transferred from Windows
if file "$0" 2>/dev/null | grep -q CRLF; then
    sed -i 's/\r//' "$0"
    exec bash "$0" "$@"
fi

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

WEBROOT="/var/www/html/netman"
APACHE_CONF="/etc/apache2/sites-available/netman.conf"

printf "${BLUE}"
printf "  _   _      _   __  __             \n"
printf " | \\ | | ___| |_|  \\/  | __ _ _ __  \n"
printf " |  \\| |/ _ \\ __| |\\/| |/ _\` | '_ \\ \n"
printf " | |\\  |  __/ |_| |  | | (_| | | | |\n"
printf " |_| \\_|\\___|\\___|_|  |_|\\__,_|_| |_|\n"
printf "${NC}\n"
printf "Network Management Probe Installer\n"
printf "===================================\n\n"

# Must run as root
if [ "$(id -u)" -ne 0 ]; then
    printf "${RED}Please run as root: sudo bash setup.sh${NC}\n"
    exit 1
fi

# Detect OS
if [ -f /etc/os-release ]; then
    . /etc/os-release
    OS_NAME="$NAME"
    OS_ID="$ID"
    OS_ID_LIKE="${ID_LIKE:-}"
else
    printf "${RED}Cannot detect OS. Exiting.${NC}\n"
    exit 1
fi

printf "${YELLOW}Detected OS: $OS_NAME${NC}\n\n"

# Determine if we use mariadb-server or mysql-server
# Raspberry Pi OS, Raspbian, Debian, Ubuntu 22.04+ all ship MariaDB
DB_PKG="mariadb-server"
DB_SERVICE="mariadb"

# Ubuntu older than 22.04 may still have mysql-server — prefer mariadb anyway
# Check if mariadb-server is available, fall back to mysql-server
if ! apt-cache show mariadb-server &>/dev/null 2>&1; then
    DB_PKG="mysql-server"
    DB_SERVICE="mysql"
fi

# ─── Step 1: Update package list ─────────────────────────────────────────────
printf "${GREEN}[1/7] Updating package list...${NC}\n"
apt-get update -qq

# ─── Step 2: Install dependencies ────────────────────────────────────────────
printf "${GREEN}[2/7] Installing Apache, PHP, %s, nmap...${NC}\n" "$DB_PKG"
apt-get install -y -qq \
    apache2 \
    php \
    php-mysql \
    php-json \
    php-mbstring \
    php-xml \
    libapache2-mod-php \
    "$DB_PKG" \
    nmap \
    net-tools \
    iproute2 \
    curl \
    openssl

# ─── Step 3: Configure Apache ────────────────────────────────────────────────
printf "${GREEN}[3/7] Configuring Apache...${NC}\n"
a2enmod rewrite
a2enmod headers

# ─── Step 4: Set up web directory ────────────────────────────────────────────
printf "${GREEN}[4/7] Setting up web directory...${NC}\n"
mkdir -p "$WEBROOT"
mkdir -p "$WEBROOT/includes"
mkdir -p "$WEBROOT/api"
mkdir -p "$WEBROOT/workers"
mkdir -p "$WEBROOT/assets/css"
mkdir -p "$WEBROOT/assets/js"
mkdir -p "$WEBROOT/assets/img"
mkdir -p "$WEBROOT/storage"

# Copy application files (assumes script is run from the project directory)
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
if [ -f "$SCRIPT_DIR/index.php" ]; then
    printf "Copying application files...\n"
    # Remove CRLF from all PHP/SQL/JS/CSS files during copy
    find "$SCRIPT_DIR" -type f \( -name "*.php" -o -name "*.sql" -o -name "*.js" -o -name "*.css" -o -name "*.htaccess" -o -name ".htaccess" \) | while read -r f; do
        rel="${f#$SCRIPT_DIR/}"
        dest_dir="$WEBROOT/$(dirname "$rel")"
        mkdir -p "$dest_dir"
        sed 's/\r//' "$f" > "$WEBROOT/$rel"
    done
    # Copy remaining files (images, svg, etc.) without modification
    cp -rn "$SCRIPT_DIR/assets" "$WEBROOT/" 2>/dev/null || true
    cp -n "$SCRIPT_DIR/schema.sql" "$WEBROOT/" 2>/dev/null || true
else
    printf "${YELLOW}Warning: Application files not found in %s${NC}\n" "$SCRIPT_DIR"
    printf "Copy your NetMan files to %s manually.\n" "$WEBROOT"
fi

# Set permissions
chown -R www-data:www-data "$WEBROOT"
chmod -R 755 "$WEBROOT"
chmod -R 775 "$WEBROOT/storage"

# Apache virtual host
cat > "$APACHE_CONF" << 'APACHECONF'
<VirtualHost *:80>
    DocumentRoot /var/www/html/netman
    ServerName netman.local

    <Directory /var/www/html/netman>
        AllowOverride All
        Require all granted
        Options -Indexes +FollowSymLinks
    </Directory>

    Header always set X-Content-Type-Options nosniff
    Header always set X-Frame-Options SAMEORIGIN
    Header always set X-XSS-Protection "1; mode=block"

    ErrorLog ${APACHE_LOG_DIR}/netman_error.log
    CustomLog ${APACHE_LOG_DIR}/netman_access.log combined
</VirtualHost>
APACHECONF

a2ensite netman
a2dissite 000-default 2>/dev/null || true
systemctl reload apache2

# ─── Step 5: Configure database ──────────────────────────────────────────────
printf "${GREEN}[5/7] Configuring %s...${NC}\n" "$DB_SERVICE"
systemctl enable "$DB_SERVICE"
systemctl start "$DB_SERVICE"

# Generate random password for the netman DB user
DB_PASS=$(openssl rand -base64 18 | tr -d '/+=' | head -c 18)
APP_SECRET=$(openssl rand -hex 32)

# MariaDB/MySQL: on Raspbian/Debian, root uses unix_socket auth by default
# We use 'sudo mysql' (no password) to set things up, then lock it down.
mysql << MYSQLEOF
-- Remove anonymous users and test database
DELETE FROM mysql.user WHERE User='';
DROP DATABASE IF EXISTS test;
DELETE FROM mysql.db WHERE Db='test' OR Db='test\\_%';

-- Create netman database and user
CREATE DATABASE IF NOT EXISTS \`netman\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'netman'@'localhost' IDENTIFIED BY '${DB_PASS}';
GRANT ALL PRIVILEGES ON \`netman\`.* TO 'netman'@'localhost';
FLUSH PRIVILEGES;
MYSQLEOF

# Import schema (strip CREATE DATABASE / USE lines — db already selected via user grant)
if [ -f "$WEBROOT/schema.sql" ]; then
    grep -v -E '^\s*(CREATE DATABASE|USE)\s' "$WEBROOT/schema.sql" | \
        mysql -u netman -p"${DB_PASS}" netman
    printf "Schema imported.\n"
fi

# Save credentials for reference
cat > /root/.netman_db_credentials << CREDS
NetMan Database Credentials
============================
DB Host:  localhost
DB Name:  netman
DB User:  netman
DB Pass:  ${DB_PASS}

Keep this file secure: chmod 600 /root/.netman_db_credentials
CREDS
chmod 600 /root/.netman_db_credentials

# Write config.php
cat > "$WEBROOT/config.php" << PHPCONF
<?php
define('DB_HOST', 'localhost');
define('DB_USER', 'netman');
define('DB_PASS', '${DB_PASS}');
define('DB_NAME', 'netman');
define('APP_SECRET', '${APP_SECRET}');
define('APP_INSTALLED', false);
PHPCONF
chown www-data:www-data "$WEBROOT/config.php"
chmod 640 "$WEBROOT/config.php"

# ─── Step 6: nmap sudo permissions ───────────────────────────────────────────
printf "${GREEN}[6/7] Configuring nmap permissions...${NC}\n"
cat > /etc/sudoers.d/netman-nmap << 'SUDOERS'
# Allow www-data to run nmap for NetMan
www-data ALL=(ALL) NOPASSWD: /usr/bin/nmap
SUDOERS
chmod 440 /etc/sudoers.d/netman-nmap

# ─── Step 7: Enable and start services ───────────────────────────────────────
printf "${GREEN}[7/7] Starting services...${NC}\n"
systemctl enable apache2
systemctl restart apache2

# Get probe IP
PROBE_IP=$(ip route get 1.1.1.1 2>/dev/null | awk '/src/{for(i=1;i<=NF;i++) if($i=="src") print $(i+1)}' | head -1)
if [ -z "$PROBE_IP" ]; then
    PROBE_IP=$(hostname -I | awk '{print $1}')
fi

printf "\n"
printf "${GREEN}======================================\n"
printf " NetMan Installation Complete!\n"
printf "======================================${NC}\n\n"
printf "Access the web interface at:\n"
printf "  ${BLUE}http://%s/${NC}\n\n" "$PROBE_IP"
printf "Database credentials saved to: ${YELLOW}/root/.netman_db_credentials${NC}\n\n"
printf "Complete setup by visiting the web interface.\n\n"
