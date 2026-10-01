-- Mirrors api/migrations/Version20260824163627.php + Version20260918120000.php
-- (device/sensor/measurement tables including the deduplication_id/type columns
-- the "no measurements received" rule and its supporting queries depend on).

CREATE TABLE device (
    id INT AUTO_INCREMENT NOT NULL,
    name VARCHAR(100) NOT NULL,
    dev_eui VARCHAR(32) DEFAULT NULL,
    description VARCHAR(255) DEFAULT NULL,
    created_at DATETIME NOT NULL,
    UNIQUE INDEX uniq_device_name (name),
    PRIMARY KEY (id)
) DEFAULT CHARACTER SET utf8mb4;

CREATE TABLE sensor (
    id INT AUTO_INCREMENT NOT NULL,
    type VARCHAR(50) NOT NULL,
    unit VARCHAR(20) NOT NULL,
    label VARCHAR(100) DEFAULT NULL,
    created_at DATETIME NOT NULL,
    device_id INT NOT NULL,
    INDEX idx_sensor_device (device_id),
    PRIMARY KEY (id),
    CONSTRAINT fk_sensor_device FOREIGN KEY (device_id) REFERENCES device (id)
) DEFAULT CHARACTER SET utf8mb4;

CREATE TABLE measurement (
    id INT AUTO_INCREMENT NOT NULL,
    value DOUBLE PRECISION NOT NULL,
    measured_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL,
    sensor_id INT NOT NULL,
    deduplication_id VARCHAR(36) NOT NULL,
    type VARCHAR(50) NOT NULL,
    INDEX idx_measurement_sensor (sensor_id),
    INDEX idx_measurement_sensor_measured_at (sensor_id, measured_at),
    UNIQUE INDEX uniq_measurement_dedup_type (deduplication_id, type),
    PRIMARY KEY (id),
    CONSTRAINT fk_measurement_sensor FOREIGN KEY (sensor_id) REFERENCES sensor (id)
) DEFAULT CHARACTER SET utf8mb4;

-- Disposable fixture only: mirrors Version20260927140000 and the integer-id
-- conversion in Version20260929130000. No application schema is changed.
CREATE TABLE watering_run (
    id INT AUTO_INCREMENT NOT NULL,
    requested_seconds INT NOT NULL,
    status VARCHAR(20) NOT NULL,
    requested_at DATETIME NOT NULL,
    deadline_at DATETIME NOT NULL,
    started_at DATETIME DEFAULT NULL,
    finished_at DATETIME DEFAULT NULL,
    last_state_at DATETIME DEFAULT NULL,
    last_state VARCHAR(10) DEFAULT NULL,
    error VARCHAR(255) DEFAULT NULL,
    PRIMARY KEY (id),
    INDEX idx_watering_requested (requested_at)
) DEFAULT CHARACTER SET utf8mb4;
