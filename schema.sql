-- NetMan Database Schema
-- Run: mysql -u root -p < schema.sql

CREATE DATABASE IF NOT EXISTS netman CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE netman;

CREATE TABLE IF NOT EXISTS settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) UNIQUE NOT NULL,
    setting_value TEXT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('admin', 'viewer') DEFAULT 'viewer',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_login TIMESTAMP NULL
);

CREATE TABLE IF NOT EXISTS devices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ip_address VARCHAR(45) NOT NULL,
    mac_address VARCHAR(17) DEFAULT NULL,
    hostname VARCHAR(255) DEFAULT NULL,
    vendor VARCHAR(255) DEFAULT NULL,
    os_guess VARCHAR(255) DEFAULT NULL,
    open_ports TEXT DEFAULT NULL,
    device_type VARCHAR(100) DEFAULT NULL,
    status ENUM('online', 'offline', 'unknown') DEFAULT 'unknown',
    custom_name VARCHAR(255) DEFAULT NULL,
    notes TEXT DEFAULT NULL,
    first_seen TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_seen TIMESTAMP NULL,
    last_scan TIMESTAMP NULL,
    UNIQUE KEY unique_ip (ip_address),
    INDEX idx_mac (mac_address),
    INDEX idx_status (status),
    INDEX idx_last_seen (last_seen)
);

CREATE TABLE IF NOT EXISTS scan_jobs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    scan_type ENUM('discovery', 'quick', 'full') DEFAULT 'discovery',
    target_range VARCHAR(50) NOT NULL,
    status ENUM('pending', 'running', 'completed', 'failed') DEFAULT 'pending',
    started_at TIMESTAMP NULL,
    completed_at TIMESTAMP NULL,
    devices_found INT DEFAULT 0,
    devices_new INT DEFAULT 0,
    error_message TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_by INT DEFAULT NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_status (status),
    INDEX idx_created (created_at)
);

-- Default settings
INSERT IGNORE INTO settings (setting_key, setting_value) VALUES
    ('app_name', 'NetMan'),
    ('scan_range', ''),
    ('scan_interval', '0'),
    ('nmap_path', '/usr/bin/nmap'),
    ('max_scan_history', '50'),
    ('installed', '0');
