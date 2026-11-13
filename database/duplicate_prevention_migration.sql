-- Review these queries first. Resolve any returned rows before applying the
-- unique indexes; this migration intentionally does not delete existing data.
SELECT company_id, start_date, end_date, COUNT(*) AS duplicate_count
FROM payroll_periods
GROUP BY company_id, start_date, end_date
HAVING COUNT(*) > 1;

SELECT period_id, employee_id, COUNT(*) AS duplicate_count
FROM payroll_items
GROUP BY period_id, employee_id
HAVING COUNT(*) > 1;

SELECT COALESCE(company_id, 0) AS company_scope_id, to_year, COUNT(*) AS duplicate_count
FROM leave_reset_logs
GROUP BY COALESCE(company_id, 0), to_year
HAVING COUNT(*) > 1;

ALTER TABLE departments
    DROP INDEX uk_company_dept_code,
    ADD COLUMN duplicate_key CHAR(64) NULL,
    ADD UNIQUE KEY uk_departments_duplicate_key (duplicate_key);

ALTER TABLE positions
    ADD COLUMN duplicate_key CHAR(64) NULL,
    ADD UNIQUE KEY uk_positions_duplicate_key (duplicate_key);

ALTER TABLE shifts
    ADD COLUMN duplicate_key CHAR(64) NULL,
    ADD UNIQUE KEY uk_shifts_duplicate_key (duplicate_key);

ALTER TABLE attendance_logs
    ADD COLUMN event_key CHAR(64) NULL,
    ADD UNIQUE KEY uk_attendance_event_key (event_key);

ALTER TABLE leave_requests
    ADD COLUMN pending_dedupe_key CHAR(64) NULL,
    ADD UNIQUE KEY uk_leave_pending_dedupe (pending_dedupe_key);

ALTER TABLE ot_requests
    ADD COLUMN pending_dedupe_key CHAR(64) NULL,
    ADD UNIQUE KEY uk_ot_pending_dedupe (pending_dedupe_key);

ALTER TABLE holidays
    DROP INDEX uk_company_holiday,
    ADD COLUMN duplicate_key CHAR(64) NULL,
    ADD UNIQUE KEY uk_holidays_duplicate_key (duplicate_key);

ALTER TABLE leave_reset_logs
    DROP INDEX uk_company_to_year,
    ADD COLUMN company_scope_id INT GENERATED ALWAYS AS (IFNULL(company_id, 0)) STORED,
    ADD UNIQUE KEY uk_company_scope_to_year (company_scope_id, to_year);

ALTER TABLE payroll_periods
    ADD UNIQUE KEY uk_payroll_company_period (company_id, start_date, end_date);

ALTER TABLE payroll_items
    ADD UNIQUE KEY uk_payroll_period_employee (period_id, employee_id);
