USE hr_system;

START TRANSACTION;

INSERT INTO positions (id, company_id, department_id, title, level, is_leave_required, status) VALUES
(126, NULL, 2, 'HR Director (Demo)', 4, FALSE, 1),
(127, NULL, 3, 'Accounting Manager (Demo)', 3, TRUE, 1),
(128, NULL, 3, 'Accounting Director (Demo)', 4, FALSE, 1),
(129, NULL, 4, 'IT Director (Demo)', 4, FALSE, 1),
(130, NULL, 27, 'Sales Manager (Demo)', 3, TRUE, 1),
(131, NULL, 27, 'Sales Director (Demo)', 4, FALSE, 1)
ON DUPLICATE KEY UPDATE
    company_id = VALUES(company_id),
    department_id = VALUES(department_id),
    title = VALUES(title),
    level = VALUES(level),
    is_leave_required = VALUES(is_leave_required),
    status = VALUES(status);

INSERT INTO employees (
    id, company_id, emp_code, first_name, last_name, id_card_no, email,
    department_id, position_id, manager_id, hire_date, base_salary,
    employment_status, status
) VALUES
('EMP-000011', 1, 'VNGS-006', 'HR', 'Director Demo', '1100100100011', 'hr.director.demo@company.com', 2, 126, NULL, '2025-01-01', 60000.00, 'active', 1),
('EMP-000013', 1, 'VNGS-007', 'Accounting', 'Director Demo', '1100100100013', 'accounting.director.demo@company.com', 3, 128, NULL, '2025-01-01', 60000.00, 'active', 1),
('EMP-000014', 2, 'SUB1-006', 'IT', 'Director Demo', '1100100100014', 'it.director.demo@company.com', 4, 129, NULL, '2025-01-01', 60000.00, 'active', 1),
('EMP-000016', 2, 'SUB1-007', 'Sales', 'Director Demo', '1100100100016', 'sales.director.demo@company.com', 27, 131, NULL, '2025-01-01', 60000.00, 'active', 1)
ON DUPLICATE KEY UPDATE
    company_id = VALUES(company_id),
    emp_code = VALUES(emp_code),
    first_name = VALUES(first_name),
    last_name = VALUES(last_name),
    id_card_no = VALUES(id_card_no),
    email = VALUES(email),
    department_id = VALUES(department_id),
    position_id = VALUES(position_id),
    manager_id = VALUES(manager_id),
    hire_date = VALUES(hire_date),
    base_salary = VALUES(base_salary),
    employment_status = VALUES(employment_status),
    status = VALUES(status);

INSERT INTO employees (
    id, company_id, emp_code, first_name, last_name, id_card_no, email,
    department_id, position_id, manager_id, hire_date, base_salary,
    employment_status, status
) VALUES
('EMP-000012', 1, 'VNGS-008', 'Accounting', 'Manager Demo', '1100100100012', 'accounting.manager.demo@company.com', 3, 127, 'EMP-000013', '2025-01-01', 45000.00, 'active', 1),
('EMP-000015', 2, 'SUB1-008', 'Sales', 'Manager Demo', '1100100100015', 'sales.manager.demo@company.com', 27, 130, 'EMP-000016', '2025-01-01', 45000.00, 'active', 1)
ON DUPLICATE KEY UPDATE
    company_id = VALUES(company_id),
    emp_code = VALUES(emp_code),
    first_name = VALUES(first_name),
    last_name = VALUES(last_name),
    id_card_no = VALUES(id_card_no),
    email = VALUES(email),
    department_id = VALUES(department_id),
    position_id = VALUES(position_id),
    manager_id = VALUES(manager_id),
    hire_date = VALUES(hire_date),
    base_salary = VALUES(base_salary),
    employment_status = VALUES(employment_status),
    status = VALUES(status);

INSERT INTO users (employee_id, username, password, password_hash, role, status) VALUES
('EMP-000011', 'hrdirector_demo', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'director', 1),
('EMP-000012', 'accounting_manager_demo', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'manager', 1),
('EMP-000013', 'accounting_director_demo', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'director', 1),
('EMP-000014', 'itdirector_demo', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'director', 1),
('EMP-000015', 'salesmanager_demo', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'manager', 1),
('EMP-000016', 'salesdirector_demo', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'director', 1)
ON DUPLICATE KEY UPDATE
    username = VALUES(username),
    password = VALUES(password),
    password_hash = VALUES(password_hash),
    role = VALUES(role),
    status = VALUES(status);

UPDATE employees
SET manager_id = CASE id
    WHEN 'EMP-000002' THEN 'EMP-000011'
    WHEN 'EMP-000004' THEN 'EMP-000012'
    WHEN 'EMP-000005' THEN 'EMP-000014'
    WHEN 'EMP-000009' THEN NULL
    WHEN 'EMP-000010' THEN 'EMP-000015'
END
WHERE id IN ('EMP-000002', 'EMP-000004', 'EMP-000005', 'EMP-000009', 'EMP-000010');

COMMIT;
