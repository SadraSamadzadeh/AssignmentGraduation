-- ============================================================================
-- MATCH LINKING SYSTEM - CLEAN DATABASE SETUP
-- ============================================================================
-- This script creates a clean database structure for the match linking system
-- Based on understanding that Primeplay tracking data lacks club names,
-- requiring time-focused matching algorithm (70% time, 20% duration, 10% overlap)
-- ============================================================================

-- Drop existing tables (in correct order due to foreign keys)
DROP TABLE IF EXISTS match_history CASCADE;
DROP TABLE IF EXISTS global_matches CASCADE;
DROP TABLE IF EXISTS tracking_dashboard CASCADE;
DROP TABLE IF EXISTS video_dashboard CASCADE;
DROP TABLE IF EXISTS jobs CASCADE;
DROP TABLE IF EXISTS failed_jobs CASCADE;
DROP TABLE IF EXISTS users CASCADE;

-- ============================================================================
-- CORE AUTHENTICATION
-- ============================================================================

CREATE TABLE users (
    id BIGSERIAL PRIMARY KEY,
    email VARCHAR(255) UNIQUE NOT NULL,
    name VARCHAR(255) NOT NULL,
    password VARCHAR(255) NOT NULL,
    role VARCHAR(50) NOT NULL DEFAULT 'user',
    email_verified_at TIMESTAMP NULL,
    remember_token VARCHAR(100) NULL,
    last_login_at TIMESTAMP NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT users_role_check CHECK (role IN ('admin', 'user', 'viewer'))
);

CREATE INDEX idx_users_email ON users(email);
CREATE INDEX idx_users_role ON users(role);

COMMENT ON TABLE users IS 'User authentication and authorization';
COMMENT ON COLUMN users.role IS 'User role: admin (full access), user (create/confirm), viewer (read-only)';

-- ============================================================================
-- UNMATCHED DATA STORAGE (Temporary holding area)
-- ============================================================================

CREATE TABLE tracking_dashboard (
    id BIGSERIAL PRIMARY KEY,
    tracking_id BIGINT NOT NULL,
    event_date DATE NOT NULL,
    status VARCHAR(50) NOT NULL DEFAULT 'unmatched',
    message_content JSONB NOT NULL,
    source_system VARCHAR(100) NOT NULL DEFAULT 'primeplay',
    
    -- Extracted fields for quick filtering (from JSONB)
    dataset_name VARCHAR(255),
    team_name VARCHAR(255),
    start_time TIMESTAMP,
    end_time TIMESTAMP,
    duration_minutes INTEGER,
    
    -- Match tracking
    match_attempts INTEGER NOT NULL DEFAULT 0,
    last_match_attempt_at TIMESTAMP NULL,
    
    -- Lifecycle
    received_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    
    CONSTRAINT tracking_status_check CHECK (status IN ('unmatched', 'matched', 'expired', 'ignored'))
);

-- Indexes for tracking_dashboard
CREATE INDEX idx_tracking_id ON tracking_dashboard(tracking_id);
CREATE INDEX idx_tracking_date ON tracking_dashboard(event_date);
CREATE INDEX idx_tracking_status ON tracking_dashboard(status);
CREATE INDEX idx_tracking_date_status ON tracking_dashboard(event_date, status);
CREATE INDEX idx_tracking_start_time ON tracking_dashboard(start_time);
CREATE INDEX idx_tracking_unmatched ON tracking_dashboard(status) WHERE status='unmatched';
CREATE INDEX idx_tracking_message_content ON tracking_dashboard USING GIN (message_content);

COMMENT ON TABLE tracking_dashboard IS 'Temporary storage for unmatched Primeplay tracking data. Records expire after 7 days.';
COMMENT ON COLUMN tracking_dashboard.team_name IS 'Generic identifier like "Test Team" or "Team 1" - NOT actual club names';
COMMENT ON COLUMN tracking_dashboard.event_date IS 'Extracted date for fast date pre-filtering (±1 day)';
COMMENT ON COLUMN tracking_dashboard.status IS 'unmatched: awaiting match | matched: linked to video | expired: timeout | ignored: user action';

-- ============================================================================

CREATE TABLE video_dashboard (
    id BIGSERIAL PRIMARY KEY,
    video_id VARCHAR(255) NOT NULL,
    event_date DATE NOT NULL,
    status VARCHAR(50) NOT NULL DEFAULT 'unmatched',
    message_content JSONB NOT NULL,
    source_system VARCHAR(100) NOT NULL DEFAULT 'usf_sport',
    
    -- Extracted fields for quick filtering (from JSONB)
    home_club_name VARCHAR(255),
    away_club_name VARCHAR(255),
    field_name VARCHAR(255),
    start_time TIMESTAMP,
    end_time TIMESTAMP,
    duration_minutes INTEGER,
    is_training BOOLEAN DEFAULT false,
    
    -- Match tracking
    match_attempts INTEGER NOT NULL DEFAULT 0,
    last_match_attempt_at TIMESTAMP NULL,
    
    -- Lifecycle
    received_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    
    CONSTRAINT video_status_check CHECK (status IN ('unmatched', 'matched', 'expired', 'ignored'))
);

-- Indexes for video_dashboard
CREATE INDEX idx_video_id ON video_dashboard(video_id);
CREATE INDEX idx_video_date ON video_dashboard(event_date);
CREATE INDEX idx_video_status ON video_dashboard(status);
CREATE INDEX idx_video_date_status ON video_dashboard(event_date, status);
CREATE INDEX idx_video_start_time ON video_dashboard(start_time);
CREATE INDEX idx_video_unmatched ON video_dashboard(status) WHERE status='unmatched';
CREATE INDEX idx_video_message_content ON video_dashboard USING GIN (message_content);
CREATE INDEX idx_video_clubs ON video_dashboard(home_club_name, away_club_name);

COMMENT ON TABLE video_dashboard IS 'Temporary storage for unmatched USF Sport video data. Records expire after 7 days.';
COMMENT ON COLUMN video_dashboard.home_club_name IS 'Actual club name from video system (e.g., "VV Capelle")';
COMMENT ON COLUMN video_dashboard.away_club_name IS 'Actual club name from video system (e.g., "FC s-Gravenzande")';
COMMENT ON COLUMN video_dashboard.event_date IS 'Extracted date for fast date pre-filtering (±1 day)';
COMMENT ON COLUMN video_dashboard.is_training IS 'True if training session, false if match';

-- ============================================================================
-- MATCHED DATA STORAGE (Permanent records)
-- ============================================================================

CREATE TABLE global_matches (
    id BIGSERIAL PRIMARY KEY,
    global_match_id VARCHAR(255) UNIQUE NOT NULL,
    tracking_id BIGINT NOT NULL,
    video_id VARCHAR(255) NOT NULL,
    
    -- Match quality metrics
    match_score NUMERIC(5,2) NOT NULL,
    confidence_level VARCHAR(50) NOT NULL,
    match_details JSONB NOT NULL,
    
    -- Original data snapshots
    tracking_data JSONB NOT NULL,
    video_data JSONB NOT NULL,
    
    -- Match status and review
    status VARCHAR(50) NOT NULL DEFAULT 'pending_review',
    rejection_reason TEXT NULL,
    reviewed_by_user_id BIGINT NULL REFERENCES users(id) ON DELETE SET NULL,
    reviewed_at TIMESTAMP NULL,
    
    -- Match metadata
    processed_by VARCHAR(255) NOT NULL,
    matched_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    
    CONSTRAINT match_status_check CHECK (status IN ('pending_review', 'confirmed', 'rejected', 'auto_confirmed')),
    CONSTRAINT match_confidence_check CHECK (confidence_level IN ('high', 'medium', 'low', 'very_low')),
    CONSTRAINT match_score_range CHECK (match_score >= 0 AND match_score <= 100),
    CONSTRAINT unique_tracking_video UNIQUE (tracking_id, video_id)
);

-- Indexes for global_matches
CREATE INDEX idx_match_tracking_id ON global_matches(tracking_id);
CREATE INDEX idx_match_video_id ON global_matches(video_id);
CREATE INDEX idx_match_status ON global_matches(status);
CREATE INDEX idx_match_score ON global_matches(match_score);
CREATE INDEX idx_match_status_score ON global_matches(status, match_score);
CREATE INDEX idx_match_matched_at ON global_matches(matched_at);
CREATE INDEX idx_match_reviewed_by ON global_matches(reviewed_by_user_id);
CREATE INDEX idx_match_details ON global_matches USING GIN (match_details);

COMMENT ON TABLE global_matches IS 'Permanent storage for confirmed matches between tracking and video data';
COMMENT ON COLUMN global_matches.match_score IS 'Overall similarity score (0-100). Threshold: 65 for match, 85 for auto-confirm';
COMMENT ON COLUMN global_matches.confidence_level IS 'high: ≥85 | medium: 70-84 | low: 55-69 | very_low: <55';
COMMENT ON COLUMN global_matches.match_details IS 'JSON containing score breakdown: time_proximity (70%), duration (20%), temporal_overlap (10%)';
COMMENT ON COLUMN global_matches.status IS 'pending_review: needs review | auto_confirmed: score≥85 | confirmed: user approved | rejected: user rejected';

-- ============================================================================
-- MATCH HISTORY & AUDIT
-- ============================================================================

CREATE TABLE match_history (
    id BIGSERIAL PRIMARY KEY,
    global_match_id BIGINT NOT NULL REFERENCES global_matches(id) ON DELETE CASCADE,
    action VARCHAR(50) NOT NULL,
    previous_status VARCHAR(50) NULL,
    new_status VARCHAR(50) NOT NULL,
    previous_score NUMERIC(5,2) NULL,
    new_score NUMERIC(5,2) NULL,
    changes JSONB NULL,
    reason TEXT NULL,
    performed_by_user_id BIGINT NULL REFERENCES users(id) ON DELETE SET NULL,
    performed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    
    CONSTRAINT history_action_check CHECK (action IN ('created', 'confirmed', 'rejected', 'score_updated', 'data_updated'))
);

-- Indexes for match_history
CREATE INDEX idx_history_match_id ON match_history(global_match_id);
CREATE INDEX idx_history_action ON match_history(action);
CREATE INDEX idx_history_performed_at ON match_history(performed_at);
CREATE INDEX idx_history_performed_by ON match_history(performed_by_user_id);

COMMENT ON TABLE match_history IS 'Audit trail for all match changes - used for compliance, debugging, and algorithm improvement';
COMMENT ON COLUMN match_history.action IS 'created: initial match | confirmed: approval | rejected: denial | score_updated: recalc | data_updated: source change';

-- ============================================================================
-- SYSTEM TABLES (Queue Management)
-- ============================================================================

CREATE TABLE jobs (
    id BIGSERIAL PRIMARY KEY,
    queue VARCHAR(255) NOT NULL,
    payload TEXT NOT NULL,
    attempts SMALLINT NOT NULL DEFAULT 0,
    reserved_at INTEGER NULL,
    available_at INTEGER NOT NULL,
    created_at INTEGER NOT NULL
);

CREATE INDEX idx_jobs_queue ON jobs(queue);
CREATE INDEX idx_jobs_reserved_at ON jobs(reserved_at);

COMMENT ON TABLE jobs IS 'Laravel queue jobs table for background processing';

-- ============================================================================

CREATE TABLE failed_jobs (
    id BIGSERIAL PRIMARY KEY,
    uuid VARCHAR(255) UNIQUE NOT NULL,
    connection TEXT NOT NULL,
    queue TEXT NOT NULL,
    payload TEXT NOT NULL,
    exception TEXT NOT NULL,
    failed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_failed_jobs_uuid ON failed_jobs(uuid);

COMMENT ON TABLE failed_jobs IS 'Failed queue jobs for debugging and retry';

-- ============================================================================
-- HELPER FUNCTIONS
-- ============================================================================

-- Function to automatically update updated_at timestamp
CREATE OR REPLACE FUNCTION update_updated_at_column()
RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = CURRENT_TIMESTAMP;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

-- Apply triggers to tables with updated_at
CREATE TRIGGER update_users_updated_at
    BEFORE UPDATE ON users
    FOR EACH ROW
    EXECUTE FUNCTION update_updated_at_column();

CREATE TRIGGER update_tracking_updated_at
    BEFORE UPDATE ON tracking_dashboard
    FOR EACH ROW
    EXECUTE FUNCTION update_updated_at_column();

CREATE TRIGGER update_video_updated_at
    BEFORE UPDATE ON video_dashboard
    FOR EACH ROW
    EXECUTE FUNCTION update_updated_at_column();

CREATE TRIGGER update_matches_updated_at
    BEFORE UPDATE ON global_matches
    FOR EACH ROW
    EXECUTE FUNCTION update_updated_at_column();

-- ============================================================================
-- INITIAL DATA (Optional)
-- ============================================================================

-- Create default admin user (password: 'password' - CHANGE IN PRODUCTION!)
INSERT INTO users (email, name, password, role, email_verified_at) VALUES
('admin@example.com', 'System Admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin', CURRENT_TIMESTAMP);

-- ============================================================================
-- VIEWS FOR COMMON QUERIES
-- ============================================================================

-- View: Active unmatched records with extracted fields
CREATE OR REPLACE VIEW active_unmatched_tracking AS
SELECT 
    id,
    tracking_id,
    dataset_name,
    team_name,
    start_time,
    end_time,
    duration_minutes,
    match_attempts,
    last_match_attempt_at,
    received_at,
    EXTRACT(EPOCH FROM (CURRENT_TIMESTAMP - received_at))/3600 as hours_since_received
FROM tracking_dashboard
WHERE status = 'unmatched'
AND (expires_at IS NULL OR expires_at > CURRENT_TIMESTAMP)
ORDER BY received_at DESC;

COMMENT ON VIEW active_unmatched_tracking IS 'Active unmatched tracking records ready for matching';

-- View: Active unmatched videos with extracted fields
CREATE OR REPLACE VIEW active_unmatched_videos AS
SELECT 
    id,
    video_id,
    home_club_name,
    away_club_name,
    field_name,
    start_time,
    end_time,
    duration_minutes,
    is_training,
    match_attempts,
    last_match_attempt_at,
    received_at,
    EXTRACT(EPOCH FROM (CURRENT_TIMESTAMP - received_at))/3600 as hours_since_received
FROM video_dashboard
WHERE status = 'unmatched'
AND (expires_at IS NULL OR expires_at > CURRENT_TIMESTAMP)
ORDER BY received_at DESC;

COMMENT ON VIEW active_unmatched_videos IS 'Active unmatched video records ready for matching';

-- View: Matches pending review
CREATE OR REPLACE VIEW matches_pending_review AS
SELECT 
    gm.id,
    gm.global_match_id,
    gm.tracking_id,
    gm.video_id,
    gm.match_score,
    gm.confidence_level,
    gm.matched_at,
    EXTRACT(EPOCH FROM (CURRENT_TIMESTAMP - gm.matched_at))/3600 as hours_pending,
    gm.tracking_data->>'teamName' as tracking_team,
    gm.video_data->'home'->>'name' as video_home,
    gm.video_data->'away'->>'name' as video_away
FROM global_matches gm
WHERE gm.status = 'pending_review'
ORDER BY gm.match_score DESC, gm.matched_at ASC;

COMMENT ON VIEW matches_pending_review IS 'Matches awaiting manual review, ordered by score and age';

-- ============================================================================
-- STATISTICS VIEWS
-- ============================================================================

CREATE OR REPLACE VIEW matching_statistics AS
SELECT 
    (SELECT COUNT(*) FROM tracking_dashboard WHERE status = 'unmatched') as unmatched_tracking,
    (SELECT COUNT(*) FROM video_dashboard WHERE status = 'unmatched') as unmatched_videos,
    (SELECT COUNT(*) FROM global_matches WHERE status = 'pending_review') as pending_review,
    (SELECT COUNT(*) FROM global_matches WHERE status = 'auto_confirmed') as auto_confirmed,
    (SELECT COUNT(*) FROM global_matches WHERE status = 'confirmed') as user_confirmed,
    (SELECT COUNT(*) FROM global_matches WHERE status = 'rejected') as rejected,
    (SELECT AVG(match_score) FROM global_matches WHERE status IN ('confirmed', 'auto_confirmed')) as avg_confirmed_score,
    (SELECT AVG(match_score) FROM global_matches WHERE status = 'rejected') as avg_rejected_score;

COMMENT ON VIEW matching_statistics IS 'System-wide matching statistics dashboard';

-- ============================================================================
-- CLEANUP FUNCTION
-- ============================================================================

-- Function to clean up expired records
CREATE OR REPLACE FUNCTION cleanup_expired_records()
RETURNS TABLE(
    tracking_deleted INTEGER,
    video_deleted INTEGER,
    message TEXT
) AS $$
DECLARE
    tracking_count INTEGER;
    video_count INTEGER;
BEGIN
    -- Mark expired tracking records (older than 7 days and still unmatched)
    UPDATE tracking_dashboard
    SET status = 'expired',
        expires_at = CURRENT_TIMESTAMP
    WHERE status = 'unmatched'
    AND received_at < CURRENT_TIMESTAMP - INTERVAL '7 days';
    
    GET DIAGNOSTICS tracking_count = ROW_COUNT;
    
    -- Mark expired video records (older than 7 days and still unmatched)
    UPDATE video_dashboard
    SET status = 'expired',
        expires_at = CURRENT_TIMESTAMP
    WHERE status = 'unmatched'
    AND received_at < CURRENT_TIMESTAMP - INTERVAL '7 days';
    
    GET DIAGNOSTICS video_count = ROW_COUNT;
    
    -- Delete expired records older than 30 days
    DELETE FROM tracking_dashboard
    WHERE status = 'expired'
    AND expires_at < CURRENT_TIMESTAMP - INTERVAL '30 days';
    
    DELETE FROM video_dashboard
    WHERE status = 'expired'
    AND expires_at < CURRENT_TIMESTAMP - INTERVAL '30 days';
    
    RETURN QUERY SELECT 
        tracking_count,
        video_count,
        format('Expired %s tracking and %s video records', tracking_count, video_count);
END;
$$ LANGUAGE plpgsql;

COMMENT ON FUNCTION cleanup_expired_records IS 'Marks records >7 days as expired, deletes expired records >30 days old';

-- ============================================================================
-- GRANT PERMISSIONS (adjust as needed)
-- ============================================================================

-- Grant permissions to application user (adjust username as needed)
-- GRANT ALL PRIVILEGES ON ALL TABLES IN SCHEMA public TO your_app_user;
-- GRANT ALL PRIVILEGES ON ALL SEQUENCES IN SCHEMA public TO your_app_user;
-- GRANT EXECUTE ON ALL FUNCTIONS IN SCHEMA public TO your_app_user;

-- ============================================================================
-- COMPLETION MESSAGE
-- ============================================================================

DO $$
BEGIN
    RAISE NOTICE '============================================================================';
    RAISE NOTICE 'DATABASE SETUP COMPLETE';
    RAISE NOTICE '============================================================================';
    RAISE NOTICE 'Tables created:';
    RAISE NOTICE '  - users (authentication)';
    RAISE NOTICE '  - tracking_dashboard (temporary unmatched tracking data)';
    RAISE NOTICE '  - video_dashboard (temporary unmatched video data)';
    RAISE NOTICE '  - global_matches (permanent match records)';
    RAISE NOTICE '  - match_history (audit trail)';
    RAISE NOTICE '  - jobs, failed_jobs (queue system)';
    RAISE NOTICE '';
    RAISE NOTICE 'Views created:';
    RAISE NOTICE '  - active_unmatched_tracking';
    RAISE NOTICE '  - active_unmatched_videos';
    RAISE NOTICE '  - matches_pending_review';
    RAISE NOTICE '  - matching_statistics';
    RAISE NOTICE '';
    RAISE NOTICE 'Functions created:';
    RAISE NOTICE '  - cleanup_expired_records()';
    RAISE NOTICE '';
    RAISE NOTICE 'Default admin user created:';
    RAISE NOTICE '  Email: admin@example.com';
    RAISE NOTICE '  Password: password (CHANGE THIS!)';
    RAISE NOTICE '============================================================================';
END $$;
