INSERT INTO watering_run (id, requested_seconds, status, requested_at, deadline_at, started_at, finished_at)
SELECT fixture.id, 999, fixture.status,
    DATE_ADD('2026-01-01 00:00:00', INTERVAL fixture.request_offset SECOND),
    '2026-01-01 02:00:00',
    DATE_ADD('2026-01-01 00:00:00', INTERVAL fixture.start_offset SECOND),
    DATE_ADD('2026-01-01 00:00:00', INTERVAL fixture.end_offset SECOND)
FROM (
    SELECT 1 id, 'completed' status, 60 request_offset, 60 start_offset, 127 end_offset
    UNION ALL SELECT 2, 'completed', 120, 120, 130
    UNION ALL SELECT 3, 'completed', 180, 180, 183
    UNION ALL SELECT 4, 'completed', 240, 240, 240
    UNION ALL SELECT 5, 'completed', 300, NULL, 310
    UNION ALL SELECT 6, 'completed', 360, 360, NULL
    UNION ALL SELECT 7, 'pending', 420, NULL, NULL
    UNION ALL SELECT 8, 'completed', 480, 480, 479
    UNION ALL SELECT 9, 'reviewed', 540, 540, 600
    UNION ALL SELECT 10, 'running', 600, 600, 610
    UNION ALL SELECT 11, 'uncertain', 660, 660, 670
    UNION ALL SELECT 12, 'timed_out', 720, 720, 730
    UNION ALL SELECT 13, 'pending', 780, 780, 790
    UNION ALL SELECT 14, 'completed', -60, 840, 850
    UNION ALL SELECT 15, 'completed', 900, -60, -50
    UNION ALL SELECT 16, 'pending', -1, NULL, NULL
    UNION ALL SELECT 17, 'pending', 3601, NULL, NULL
    UNION ALL SELECT 18, 'completed', -120, 0, 3
    UNION ALL SELECT 19, 'pending', 3600, NULL, NULL
    UNION ALL SELECT 20, 'completed', 3600, 3600, 3603
    UNION ALL SELECT 21, 'completed', 420, NULL, NULL
) fixture;