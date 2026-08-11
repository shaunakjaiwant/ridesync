-- RideSync Non-Sensitive Baseline Data Seed
-- Pre-populates migration baseline records

INSERT IGNORE INTO schema_migrations (version, executed_at) VALUES
('2026_01_01_000001_initial_schema', NOW()),
('2026_01_01_000002_driver_verifications', NOW()),
('2026_01_01_000003_realtime_events', NOW());
