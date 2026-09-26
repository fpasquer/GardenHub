-- Scenario 1: one recently-reporting sensor + one stale sensor on the same
-- device, so "GardenHub device silent" must produce exactly one alerting
-- instance with distinct, non-empty {device, sensor_type} labels, while
-- "GardenHub no measurements received" stays Normal (a measurement exists
-- inside the last 60 minutes).

INSERT INTO device (name, dev_eui, description, created_at) VALUES
    ('SE01-Test', 'AABBCCDDEEFF0011', 'Test device', NOW());

INSERT INTO sensor (type, unit, label, created_at, device_id) VALUES
    ('soil_moisture', '%', 'Active sensor', NOW(), LAST_INSERT_ID()),
    ('soil_temperature', 'C', 'Silent sensor', NOW(), LAST_INSERT_ID());

INSERT INTO measurement (value, measured_at, created_at, sensor_id, deduplication_id, type)
SELECT 42.0, NOW() - INTERVAL 5 MINUTE, NOW() - INTERVAL 5 MINUTE, id, UUID(), type
FROM sensor WHERE type = 'soil_moisture';

INSERT INTO measurement (value, measured_at, created_at, sensor_id, deduplication_id, type)
SELECT 18.5, NOW() - INTERVAL 90 MINUTE, NOW() - INTERVAL 90 MINUTE, id, UUID(), type
FROM sensor WHERE type = 'soil_temperature';
