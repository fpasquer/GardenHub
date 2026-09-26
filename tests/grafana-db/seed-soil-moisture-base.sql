-- Scenario 3 setup: a second physical soil-moisture sensor (Sensor B) on its
-- own device (a device can only have one sensor per type, so isolation from
-- Sensor A - SE01-Test's existing soil_moisture sensor - requires a second
-- device). Sensor A is deliberately left unseeded here; Scenario 3's own
-- first step establishes its history (the explicit stale-data test).

INSERT INTO device (name, dev_eui, description, created_at) VALUES
    ('SE02-Test', 'AABBCCDDEEFF0022', 'Test device (Sensor B)', NOW());

INSERT INTO sensor (type, unit, label, created_at, device_id) VALUES
    ('soil_moisture', '%', 'Sensor B', NOW(), LAST_INSERT_ID());

-- Offsets must stay safely OLDER than every offset used by Scenario 3's own
-- later insert_measurement calls (which range up to ~20 minutes ago), even
-- after several minutes of real wall-clock drift while the scenario polls -
-- otherwise these fixed-timestamp baseline rows could still outrank a truly
-- newer test reading in the "latest 3 by measured_at" ranking.
INSERT INTO measurement (value, measured_at, created_at, sensor_id, deduplication_id, type)
SELECT 20.0, NOW() - INTERVAL minutes_ago MINUTE, NOW() - INTERVAL minutes_ago MINUTE, s.id, UUID(), s.type
FROM sensor s
INNER JOIN device d ON d.id = s.device_id
CROSS JOIN (SELECT 45 AS minutes_ago UNION SELECT 44 UNION SELECT 43) AS ages
WHERE d.name = 'SE02-Test' AND s.type = 'soil_moisture';
