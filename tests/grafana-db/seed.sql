USE gardenhub_test;
CREATE TABLE device (id INT PRIMARY KEY, name VARCHAR(100) NOT NULL);
CREATE TABLE sensor (id INT PRIMARY KEY, device_id INT NOT NULL, type VARCHAR(50) NOT NULL);
CREATE TABLE measurement (
    id INT PRIMARY KEY,
    sensor_id INT NOT NULL,
    created_at DATETIME NOT NULL,
    deduplication_id VARCHAR(36) NOT NULL
);

INSERT INTO device VALUES (1, 'Active'), (2, 'Silent');
INSERT INTO sensor VALUES
    (1, 1, 'soil_temperature'),
    (2, 1, 'moisture'),
    (3, 2, 'moisture'),
    (4, 2, 'never_reported');
INSERT INTO measurement VALUES
    (1, 1, UTC_TIMESTAMP(), '00000000-0000-0000-0000-000000000001'),
    (2, 2, UTC_TIMESTAMP() - INTERVAL 70 MINUTE, '00000000-0000-0000-0000-000000000002'),
    (3, 3, UTC_TIMESTAMP() - INTERVAL 90 MINUTE, '00000000-0000-0000-0000-000000000003');
