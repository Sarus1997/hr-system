ALTER TABLE ot_requests
    ADD COLUMN ot_type ENUM('workday_post', 'workday_pre', 'holiday', 'workday_post_vei', 'holiday_vei') NOT NULL DEFAULT 'workday_post' AFTER end_time,
    ADD COLUMN raw_hours DECIMAL(5,2) NOT NULL DEFAULT 0.00 AFTER hours,
    ADD COLUMN net_hours DECIMAL(5,2) NOT NULL DEFAULT 0.00 AFTER raw_hours,
    ADD COLUMN lunch_deducted TINYINT(1) NOT NULL DEFAULT 0 AFTER net_hours,
    ADD COLUMN total_pay DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER ot_rate;

UPDATE ot_requests
SET raw_hours = hours,
    net_hours = hours,
    total_pay = CASE
        WHEN ROUND(hours, 0) < 1 THEN 0
        WHEN ROUND(hours, 0) <= 2 THEN 325
        WHEN ROUND(hours, 0) <= 4 THEN 650
        WHEN ROUND(hours, 0) <= 6 THEN 975
        WHEN ROUND(hours, 0) <= 8 THEN 1300
        ELSE LEAST(ROUND(hours, 0) * 162.5, 2600)
    END;
