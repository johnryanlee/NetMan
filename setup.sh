#!/bin/bash
# NetMan Probe Setup Script
# Run as root: sudo bash setup.sh

set -e

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

WEBROOT="/var/www/html/netman"
APACHE_CONF="/etc/apache2/sites-available/netman.conf"

echo -e "${BLUE}"
echo "  _   _      _   __  __             "
echo " | \ | | ___| |_|  \/  | __ _ _ __  "
echo " |  \| |/ _ \ __| |\/| |/ _\` | '_ \ "
echo " | |\  |  __/ |_| |  | | (_| | | | |"
echo " |_| \_|\___|\__|_|  |_|\__,_|_| |_|"
echo -e "${NC}"
echo "Network Management Probe Installer"
echo "==================================="
echo ""

# Must run as root
if [ "$EUID" -ne 0 ]; then
    echo -e "${RED}Please run as root: sudo bash setup.sh${NC}"
    exit 1
fi

# Detect OS
if [ -f /etc/os-release ]; then
    . /etc/os-release
    OS=$NAME
else
    echo -e "${RED}Cannot detect OS. Exiting.${NC}"
    exit 1
fi

echo -e "${YELLOW}Detected OS: $OS${NC}"
echo ""

# Update package list
echo -e "${GREEN}[1/7] Updating package list...${NC}"
apt-get update -qq

# Install dependencies
echo -e "${GREEN}[2/7] Installing Apache, PHP, MySQL, nmap...${NC}"
apt-get install -y -qq \
    apache2 \
    php \
    php-mysql \
    php-json \
    php-mbstring \
    php-xml \
    libapache2-mod-php \
    mysql-server \
    nmap \
    net-tools \
    iproute2 \
    curl

# Enable Apache modules
echo -e "${GREEN}[3/7] Configuring Apache...${NC}"
a2enmod rewrite
a2enmod headers

# Create web directory
echo -e "${GREEN}[4/7] Setting up web directory...${NC}"
mkdir -p "$WEBROOT"
mkdir -p "$WEBROOT/includes"
mkdir -p "$WEBROOT/api"
mkdir -p "$WEBROOT/workers"
mkdir -p "$WEBROOT/assets/css"
mkdir -p "$WEBROOT/assets/js"
mkdir -p "$WEBROOT/storage"

# Copy application files (assumes script is run from the project directory)
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
if [ -f "$SCRIPT_DIR/index.php" ]; then
    echo "Copying application files..."
    cp -r "$SCRIPT_DIR/"* "$WEBROOT/" 2>/dev/null || true
else
    echo -e "${YELLOW}Warning: Application files not found in $SCRIPT_DIR${NC}"
    echo "Copy your NetMan files to $WEBROOT manually."
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

    # Security headers
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

# Configure MySQL
echo -e "${GREEN}[5/7] Configuring MySQL...${NC}"
systemctl start mysql

# Generate random passwords
DB_PASS=$(openssl rand -base64 16 | tr -d '/+=' | head -c 16)
ROOT_PASS=$(openssl rand -base64 16 | tr -d '/+=' | head -c 16)

# Secure MySQL and create netman database/user
mysql -u root << MYSQLEOF
ALTER USER 'root'@'localhost' IDENTIFIED WITH mysql_native_password BY '${ROOT_PASS}';
DELETE FROM mysql.user WHERE User='';
DELETE FROM mysql.user WHERE User='root' AND Host NOT IN ('localhost', '127.0.0.1', '::1');
DROP DATABASE IF EXISTS test;
DELETE FROM mysql.db WHERE Db='test' OR Db='test\\_%';
FLUSH PRIVILEGES;
CREATE DATABASE IF NOT EXISTS netman CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'netman'@'localhost' IDENTIFIED BY '${DB_PASS}';
GRANT ALL PRIVILEGES ON netman.* TO 'netman'@'localhost';
FLUSH PRIVILEGES;
MYSQLEOF

# Import schema
if [ -f "$WEBROOT/schema.sql" ]; then
    mysql -u root -p"${ROOT_PASS}" netman < "$WEBROOT/schema.sql" 2>/dev/null || \
    mysql -u netman -p"${DB_PASS}" netman < "$WEBROOT/schema.sql"
fi

# Save credentials
cat > /root/.netman_db_credentials << CREDS
NetMan Database Credentials
============================
DB Host: localhost
DB Name: netman
DB User: netman
DB Pass: ${DB_PASS}
MySQL Root Pass: ${ROOT_PASS}
CREDS
chmod 600 /root/.netman_db_credentials

# Write initial config.php
cat > "$WEBROOT/config.php" << PHPCONF
<?php
define('DB_HOST', 'localhost');
define('DB_USER', 'netman');
define('DB_PASS', '${DB_PASS}');
define('DB_NAME', 'netman');
define('APP_SECRET', '$(openssl rand -hex 32)');
define('APP_INSTALLED', false);
PHPCONF
chown www-data:www-data "$WEBROOT/config.php"
chmod 640 "$WEBROOT/config.php"

# Configure sudo for nmap (www-data user)
echo -e "${GREEN}[6/7] Configuring nmap permissions...${NC}"
cat > /etc/sudoers.d/netman-nmap << 'SUDOERS'
# Allow www-data to run nmap for NetMan
www-data ALL=(ALL) NOPASSWD: /usr/bin/nmap
SUDOERS
chmod 440 /etc/sudoers.d/netman-nmap

# Enable and start services
echo -e "${GREEN}[7/7] Starting services...${NC}"
systemctl enable apache2 mysql
systemctl start apache2 mysql

# Get probe IP
PROBE_IP=$(ip route get 1 2>/dev/null | awk '{print $7; exit}' || hostname -I | awk '{print $1}')

echo ""
echo -e "${GREEN}======================================"
echo " NetMan Installation Complete!"
echo "======================================"
echo -e "${NC}"
echo "Access the web interface at:"
echo -e "  ${BLUE}http://${PROBE_IP}/${NC}"
echo ""
echo -e "Database credentials saved to: ${YELLOW}/root/.netman_db_credentials${NC}"
echo ""
echo "Complete setup by visiting the web interface."
echo ""
