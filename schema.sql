-- Cow Bingo Database Schema (Multi-Event)
-- Run this on your PHP server's MariaDB instance

CREATE DATABASE IF NOT EXISTS cow_bingo CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE cow_bingo;

-- Events table: one row per cow bingo game
CREATE TABLE IF NOT EXISTS cow_bingo_events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    hievents_event_id VARCHAR(100) UNIQUE DEFAULT NULL,  -- NULL = standalone / offline game
    event_name VARCHAR(255) NOT NULL,
    event_slug VARCHAR(255) DEFAULT NULL,             -- slug for public URL
    event_start DATETIME DEFAULT NULL,               -- event start date/time
    event_venue VARCHAR(255) DEFAULT NULL,            -- venue name
    grid_cols INT NOT NULL DEFAULT 16,
    grid_rows INT NOT NULL DEFAULT 16,
    pricing_tiers TEXT DEFAULT NULL,                  -- JSON string of {qty: price} tiers
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Cell assignments: scoped per event
CREATE TABLE IF NOT EXISTS cow_bingo_cells (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_id INT NOT NULL,                          -- FK to cow_bingo_events.id
    ticket_id VARCHAR(100) NOT NULL,
    order_id VARCHAR(100) DEFAULT NULL,
    buyer_name VARCHAR(255) NOT NULL,
    buyer_email VARCHAR(255) DEFAULT NULL,
    cell_reference VARCHAR(5) NOT NULL,             -- e.g. 'C13'
    is_paid TINYINT(1) NOT NULL DEFAULT 1,
    assigned_by VARCHAR(100) NULL DEFAULT 'hi.events', -- 'hi.events', admin name, or iPad label
    prize VARCHAR(255) DEFAULT NULL,                -- e.g. '1st Prize', 'Free Cake'
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_ticket_per_event (event_id, ticket_id),
    UNIQUE KEY unique_cell_per_event (event_id, cell_reference),
    FOREIGN KEY (event_id) REFERENCES cow_bingo_events(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Admin users table: super admins see all, event admins see their events, sellers are locked to pos.php
CREATE TABLE IF NOT EXISTS cow_bingo_admins (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(100) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,            -- password_hash() output
    display_name VARCHAR(255) NOT NULL,
    role ENUM('super_admin','event_admin','seller') NOT NULL DEFAULT 'event_admin',
    is_super_admin TINYINT(1) DEFAULT 0,           -- 1 = can manage all events + users
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Links admins & sellers to specific events they can manage / sell for
CREATE TABLE IF NOT EXISTS cow_bingo_admin_events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    admin_id INT NOT NULL,
    event_id INT NOT NULL,
    UNIQUE KEY unique_admin_event (admin_id, event_id),
    FOREIGN KEY (admin_id) REFERENCES cow_bingo_admins(id) ON DELETE CASCADE,
    FOREIGN KEY (event_id) REFERENCES cow_bingo_events(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Insert default super admin (change password after first login!)
INSERT IGNORE INTO cow_bingo_admins (username, password_hash, display_name, role, is_super_admin)
VALUES ('admin', '$2y$10$placeholder', 'Super Admin', 'super_admin', 1);

-- Login attempts tracking for brute force protection
CREATE TABLE IF NOT EXISTS cow_bingo_login_attempts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ip_address VARCHAR(45) NOT NULL,
    attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ip_time (ip_address, attempted_at)
) ENGINE=InnoDB;

-- Log table to capture raw webhook payloads for debugging
CREATE TABLE IF NOT EXISTS webhook_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    hievents_event_id VARCHAR(100) DEFAULT NULL,
    payload TEXT NOT NULL,
    event_type VARCHAR(100) DEFAULT NULL,
    processed TINYINT(1) DEFAULT 0,
    error_message TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;