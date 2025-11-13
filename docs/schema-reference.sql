-- Database Schema Reference
-- This file provides a quick reference of all table structures

-- ============================================================
-- Users Table - Authentication and Authorization
-- ============================================================
CREATE TABLE users (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    email VARCHAR(255) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role ENUM('admin', 'user', 'viewer') DEFAULT 'user',
    email_verified_at TIMESTAMP NULL,
    last_login_at TIMESTAMP NULL,
    remember_token VARCHAR(100) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_email (email),
    INDEX idx_role (role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Global Matches Table - Successfully Linked Matches
-- ============================================================
CREATE TABLE global_matches (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    global_match_id VARCHAR(255) NOT NULL UNIQUE,
    tracking_id BIGINT NOT NULL,
    video_id VARCHAR(255) NOT NULL,
    match_score DECIMAL(5,2) NOT NULL,
    confidence_level VARCHAR(50) NOT NULL,
    match_details JSON NOT NULL,
    tracking_data JSON NOT NULL,
    video_data JSON NOT NULL,
    status ENUM('pending', 'confirmed', 'rejected') DEFAULT 'pending',
    processed_by VARCHAR(50) DEFAULT 'hub',
    created_by_user_id BIGINT UNSIGNED NULL,
    verified_by_user_id BIGINT UNSIGNED NULL,
    matched_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_tracking_video (tracking_id, video_id),
    INDEX idx_match_score (match_score),
    INDEX idx_status (status),
    INDEX idx_matched_at (matched_at),
    INDEX idx_created_by (created_by_user_id),
    INDEX idx_verified_by (verified_by_user_id),
    
    FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (verified_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Tracking Dashboard Table - Unlinked Tracking Records
-- ============================================================
CREATE TABLE tracking_dashboard (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tracking_id BIGINT NOT NULL,
    tracking_reference VARCHAR(255) NOT NULL,
    tracking_data JSON NOT NULL,
    source_system VARCHAR(100) NOT NULL,
    status ENUM('unmatched', 'pending', 'processed', 'ignored') DEFAULT 'unmatched',
    match_attempts INT DEFAULT 0,
    last_match_attempt_at TIMESTAMP NULL,
    assigned_to_user_id BIGINT UNSIGNED NULL,
    priority ENUM('low', 'medium', 'high', 'urgent') DEFAULT 'medium',
    notes TEXT NULL,
    received_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_tracking_id (tracking_id),
    INDEX idx_status (status),
    INDEX idx_priority (priority),
    INDEX idx_assigned_to (assigned_to_user_id),
    INDEX idx_received_at (received_at),
    
    FOREIGN KEY (assigned_to_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Video Dashboard Table - Unlinked Video Records
-- ============================================================
CREATE TABLE video_dashboard (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    video_id VARCHAR(255) NOT NULL,
    video_reference VARCHAR(255) NOT NULL,
    video_data JSON NOT NULL,
    source_system VARCHAR(100) NOT NULL,
    status ENUM('unmatched', 'pending', 'processed', 'ignored') DEFAULT 'unmatched',
    match_attempts INT DEFAULT 0,
    last_match_attempt_at TIMESTAMP NULL,
    assigned_to_user_id BIGINT UNSIGNED NULL,
    priority ENUM('low', 'medium', 'high', 'urgent') DEFAULT 'medium',
    notes TEXT NULL,
    received_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_video_id (video_id),
    INDEX idx_status (status),
    INDEX idx_priority (priority),
    INDEX idx_assigned_to (assigned_to_user_id),
    INDEX idx_received_at (received_at),
    
    FOREIGN KEY (assigned_to_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Match History Table - Audit Trail for Matches
-- ============================================================
CREATE TABLE match_history (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    global_match_id BIGINT UNSIGNED NULL,
    tracking_id BIGINT NOT NULL,
    video_id VARCHAR(255) NOT NULL,
    action ENUM('created', 'updated', 'deleted', 'verified', 'rejected') NOT NULL,
    old_values JSON NULL,
    new_values JSON NULL,
    user_id BIGINT UNSIGNED NULL,
    ip_address VARCHAR(45) NULL,
    user_agent TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    INDEX idx_global_match (global_match_id),
    INDEX idx_tracking_id (tracking_id),
    INDEX idx_video_id (video_id),
    INDEX idx_user_id (user_id),
    INDEX idx_action (action),
    INDEX idx_created_at (created_at),
    
    FOREIGN KEY (global_match_id) REFERENCES global_matches(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Dashboard Activity Log Table - Dashboard Activity Tracking
-- ============================================================
CREATE TABLE dashboard_activity_log (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    dashboard_type ENUM('tracking', 'video') NOT NULL,
    record_id BIGINT NOT NULL,
    action ENUM('viewed', 'updated', 'assigned', 'status_changed', 'note_added') NOT NULL,
    details JSON NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    INDEX idx_user_id (user_id),
    INDEX idx_dashboard_type (dashboard_type),
    INDEX idx_record_id (record_id),
    INDEX idx_action (action),
    INDEX idx_created_at (created_at),
    
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Sample Queries
-- ============================================================

-- Get all unmatched tracking records with high priority
-- SELECT * FROM tracking_dashboard 
-- WHERE status = 'unmatched' AND priority = 'high'
-- ORDER BY received_at ASC;

-- Get all pending global matches with creator info
-- SELECT gm.*, u.name as created_by_name, u.email as created_by_email
-- FROM global_matches gm
-- LEFT JOIN users u ON gm.created_by_user_id = u.id
-- WHERE gm.status = 'pending'
-- ORDER BY gm.matched_at DESC;

-- Get match history for a specific match
-- SELECT mh.*, u.name as user_name
-- FROM match_history mh
-- LEFT JOIN users u ON mh.user_id = u.id
-- WHERE mh.global_match_id = ?
-- ORDER BY mh.created_at DESC;

-- Get dashboard activity for a user
-- SELECT dal.*, 
--        CASE 
--          WHEN dal.dashboard_type = 'tracking' THEN td.tracking_reference
--          WHEN dal.dashboard_type = 'video' THEN vd.video_reference
--        END as reference
-- FROM dashboard_activity_log dal
-- LEFT JOIN tracking_dashboard td ON dal.dashboard_type = 'tracking' AND dal.record_id = td.id
-- LEFT JOIN video_dashboard vd ON dal.dashboard_type = 'video' AND dal.record_id = vd.id
-- WHERE dal.user_id = ?
-- ORDER BY dal.created_at DESC
-- LIMIT 50;
