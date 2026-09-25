-- Scenario 2: global silence. Every measurement is older than 60 minutes
-- (none deleted, to keep the device-silent history query's
-- "HAVING MAX(m.created_at) IS NOT NULL" satisfied), so both rules must fire:
-- device-silent for every sensor, and no-measurements for the whole system.

UPDATE measurement SET measured_at = NOW() - INTERVAL 90 MINUTE, created_at = NOW() - INTERVAL 90 MINUTE;
