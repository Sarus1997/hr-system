SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE DATABASE IF NOT EXISTS hr_system CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE hr_system;

-- =========================================================================
-- ส่วนที่ 1 : โครงสร้างตารางทั้งหมด (23 ตาราง)
-- =========================================================================

CREATE TABLE IF NOT EXISTS companies (
    company_id INT AUTO_INCREMENT PRIMARY KEY,
    company_code VARCHAR(20) UNIQUE NOT NULL COMMENT 'รหัสย่อ เช่น VNGS',
    company_name VARCHAR(150) NOT NULL COMMENT 'ชื่อบริษัทเต็ม',
    status TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'สถานะใช้งาน (1=ใช้งาน, 0=เลิกใช้)',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT = 'ข้อมูลบริษัท';

CREATE TABLE IF NOT EXISTS departments (
    id INT AUTO_INCREMENT PRIMARY KEY COMMENT 'รหัสแผนก (PK)',
    company_id INT NOT NULL COMMENT 'รหัสบริษัท',
    code VARCHAR(20) NOT NULL COMMENT 'รหัสย่อแผนก',
    name VARCHAR(100) NOT NULL COMMENT 'ชื่อแผนก',
    parent_id INT NULL COMMENT 'รหัสแผนกแม่',
    status TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'สถานะใช้งาน (1=ใช้งาน, 0=เลิกใช้)',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT 'วันที่สร้าง',
    duplicate_key CHAR(64) NULL,
    UNIQUE KEY uk_departments_duplicate_key (duplicate_key),
    INDEX idx_departments_company_status (company_id, status),
    FOREIGN KEY (company_id) REFERENCES companies(company_id) ON DELETE CASCADE,
    FOREIGN KEY (parent_id) REFERENCES departments(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT = 'ข้อมูลแผนก';

CREATE TABLE IF NOT EXISTS positions (
    id INT AUTO_INCREMENT PRIMARY KEY COMMENT 'รหัสตำแหน่ง (PK)',
    company_id INT NULL COMMENT 'รหัสบริษัท (NULL = ใช้ร่วมกันทั้งเครือ)',
    department_id INT NULL COMMENT 'รหัสแผนกหลัก (NULL = ใช้ร่วมกันได้ทุกแผนก)',
    title VARCHAR(100) NOT NULL COMMENT 'ชื่อตำแหน่ง',
    level INT NOT NULL DEFAULT 1 COMMENT 'ระดับตำแหน่ง (1=พนักงาน, 2=หัวหน้า, 3=ผู้จัดการ, 4=ผู้อำนวยการ, 5=C-Level/CEO)',
    is_leave_required BOOLEAN NOT NULL DEFAULT TRUE COMMENT 'ต้องยื่นลาหรือไม่ (ระดับ ผอ. ขึ้นไป = FALSE)',
    status TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'สถานะใช้งาน (1=ใช้งาน, 0=เลิกใช้)',
    duplicate_key CHAR(64) NULL,
    UNIQUE KEY uk_positions_duplicate_key (duplicate_key),
    INDEX idx_positions_company_status (company_id, status),
    INDEX idx_positions_department (department_id, level),
    FOREIGN KEY (company_id) REFERENCES companies(company_id) ON DELETE CASCADE,
    FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT = 'ข้อมูลตำแหน่งงาน';

CREATE TABLE IF NOT EXISTS employees (
    id VARCHAR(20) PRIMARY KEY COMMENT 'EMP-000001 (PK)',
    company_id INT NOT NULL COMMENT 'รหัสบริษัทที่สังกัด',
    emp_code VARCHAR(20) NOT NULL COMMENT 'รหัสพนักงานประจำบริษัท เช่น VNGS-001, SUB1-001',
    first_name VARCHAR(100) NOT NULL COMMENT 'ชื่อ',
    last_name VARCHAR(100) NOT NULL COMMENT 'นามสกุล',
    id_card_no VARCHAR(13) NOT NULL COMMENT 'เลขบัตรประชาชน (ซ้ำข้ามบริษัท/รอบการจ้างได้)',
    email VARCHAR(100) NOT NULL COMMENT 'อีเมล (ซ้ำข้ามบริษัท/รอบการจ้างได้)',
    phone VARCHAR(20) NULL COMMENT 'เบอร์โทร',
    department_id INT NULL COMMENT 'รหัสแผนก',
    position_id INT NULL COMMENT 'รหัสตำแหน่ง',
    manager_id VARCHAR(20) NULL COMMENT 'รหัสหัวหน้าสายตรง',
    previous_employee_id VARCHAR(20) NULL COMMENT 'รหัสพนักงานเดิมก่อนโอนย้ายบริษัท (อ้างอิง employees.id)',
    hire_date DATE NOT NULL COMMENT 'วันเริ่มงาน',
    base_salary DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'เงินเดือนฐาน',
    employment_status ENUM('active', 'probation', 'resigned') NOT NULL DEFAULT 'active' COMMENT 'สถานะงาน',
    status TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'สถานะใช้งานในระบบ (1=ใช้งาน, 0=เลิกใช้/ลบ)',
    profile_image_url VARCHAR(500) NULL COMMENT 'รูปโปรไฟล์',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT 'วันที่สร้าง',
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'วันที่แก้ไขล่าสุด',
    UNIQUE KEY uk_employees_emp_code (emp_code),
    INDEX idx_employees_company_status (company_id, status, employment_status),
    INDEX idx_employees_manager (manager_id),
    INDEX idx_employees_id_card (id_card_no),
    INDEX idx_employees_email (email),
    INDEX idx_employees_previous (previous_employee_id),
    FOREIGN KEY (company_id) REFERENCES companies(company_id),
    FOREIGN KEY (department_id) REFERENCES departments(id),
    FOREIGN KEY (position_id) REFERENCES positions(id),
    FOREIGN KEY (manager_id) REFERENCES employees(id) ON DELETE SET NULL,
    FOREIGN KEY (previous_employee_id) REFERENCES employees(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT = 'ข้อมูลพนักงาน';

CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY COMMENT 'รหัสผู้ใช้ (PK)',
    employee_id VARCHAR(20) UNIQUE NOT NULL COMMENT 'รหัสพนักงาน',
    username VARCHAR(50) UNIQUE NOT NULL COMMENT 'ชื่อผู้ใช้',
    password VARCHAR(255) NOT NULL COMMENT 'รหัสผ่าน',
    password_hash VARCHAR(255) NOT NULL COMMENT 'รหัสผ่านเข้ารหัส (Bcrypt)',
    role ENUM('admin', 'hr_manager', 'hr', 'director', 'manager', 'supervisor', 'employee') NOT NULL DEFAULT 'employee' COMMENT 'สิทธิ์ใช้งาน',
    status TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'สถานะบัญชี (1=ใช้งาน, 0=เลิกใช้/ระงับ)',
    last_login DATETIME NULL COMMENT 'เข้าสู่ระบบล่าสุด',
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_users_role_status (role, status),
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT = 'บัญชีผู้ใช้งาน';

CREATE TABLE IF NOT EXISTS cloud_documents (
    id INT AUTO_INCREMENT PRIMARY KEY COMMENT 'รหัสเอกสาร (PK)',
    employee_id VARCHAR(20) NOT NULL COMMENT 'รหัสพนักงาน',
    doc_type ENUM('id_card', 'house_reg', 'contract', 'certificate', 'face_photo', 'other') NOT NULL COMMENT 'ประเภทเอกสาร',
    file_name VARCHAR(255) NOT NULL COMMENT 'ชื่อไฟล์',
    cloud_bucket VARCHAR(100) NOT NULL COMMENT 'ชื่อ Bucket',
    cloud_object_key VARCHAR(255) NOT NULL COMMENT 'Path บน Cloud',
    file_url VARCHAR(500) NOT NULL COMMENT 'URL ไฟล์',
    status TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'สถานะเอกสาร (1=ใช้งาน, 0=เลิกใช้/ลบ)',
    uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT 'วันที่อัปโหลด',
    INDEX idx_cloud_docs_emp_status (employee_id, status),
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT = 'เอกสารพนักงานบน Cloud';

CREATE TABLE IF NOT EXISTS shifts (
    id INT AUTO_INCREMENT PRIMARY KEY COMMENT 'รหัสกะงาน (PK)',
    company_id INT NULL COMMENT 'รหัสบริษัท (NULL = ใช้ร่วมกันทั้งเครือ)',
    shift_name VARCHAR(50) NOT NULL COMMENT 'ชื่อกะงาน',
    start_time TIME NOT NULL COMMENT 'เวลาเข้างาน',
    end_time TIME NOT NULL COMMENT 'เวลาเลิกงาน',
    break_minutes INT NOT NULL DEFAULT 60 COMMENT 'เวลาพัก (นาที)',
    late_grace_minutes INT NOT NULL DEFAULT 5 COMMENT 'อนุโลมสาย (นาที)',
    status TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'สถานะกะงาน (1=ใช้งาน, 0=เลิกใช้)',
    duplicate_key CHAR(64) NULL,
    UNIQUE KEY uk_shifts_duplicate_key (duplicate_key),
    INDEX idx_shifts_company_status (company_id, status),
    FOREIGN KEY (company_id) REFERENCES companies(company_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT = 'ข้อมูลกะการทำงาน';

CREATE TABLE IF NOT EXISTS employee_shifts (
    id INT AUTO_INCREMENT PRIMARY KEY COMMENT 'รหัสกะพนักงาน (PK)',
    employee_id VARCHAR(20) NOT NULL COMMENT 'รหัสพนักงาน',
    shift_id INT NOT NULL COMMENT 'รหัสกะงาน',
    effective_date DATE NOT NULL COMMENT 'วันที่มีผล',
    status TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'สถานะใช้งาน (1=ใช้งาน, 0=เลิกใช้)',
    INDEX idx_emp_shifts_lookup (employee_id, status, effective_date),
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
    FOREIGN KEY (shift_id) REFERENCES shifts(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT = 'กะทำงานรายบุคคล';

CREATE TABLE IF NOT EXISTS attendance_devices (
    id INT AUTO_INCREMENT PRIMARY KEY COMMENT 'รหัสอุปกรณ์ (PK)',
    company_id INT NULL COMMENT 'รหัสบริษัท (NULL = ใช้ร่วมกันในออฟฟิศ)',
    device_sn VARCHAR(100) UNIQUE NOT NULL COMMENT 'Serial Number',
    device_name VARCHAR(100) NOT NULL COMMENT 'ชื่อเครื่อง',
    device_type ENUM('face_3d', 'fingerprint', 'mobile_gps') NOT NULL COMMENT 'ประเภทเครื่อง',
    location VARCHAR(150) NULL COMMENT 'จุดติดตั้ง',
    api_token VARCHAR(255) NOT NULL COMMENT 'API Token',
    status TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'สถานะใช้งาน (1=ใช้งาน, 0=เลิกใช้)',
    FOREIGN KEY (company_id) REFERENCES companies(company_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT = 'เครื่องบันทึกเวลา';

CREATE TABLE IF NOT EXISTS attendance_logs (
    id INT AUTO_INCREMENT PRIMARY KEY COMMENT 'รหัสบันทึกเวลา (PK)',
    employee_id VARCHAR(20) NOT NULL COMMENT 'รหัสพนักงาน',
    device_id INT NULL COMMENT 'รหัสเครื่อง',
    scan_time DATETIME NOT NULL COMMENT 'เวลาที่สแกน',
    scan_type ENUM('check_in', 'check_out', 'ot_in', 'ot_out') NOT NULL COMMENT 'ประเภทสแกน',
    event_key CHAR(64) NULL,
    verify_mode ENUM('face_3d', 'fingerprint', 'mobile_gps', 'web') NOT NULL COMMENT 'วิธียืนยันตัวตน',
    latitude DECIMAL(10,8) NULL COMMENT 'ละติจูด',
    longitude DECIMAL(11,8) NULL COMMENT 'ลองจิจูด',
    snapshot_url VARCHAR(500) NULL COMMENT 'ภาพถ่ายสแกน',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT 'วันที่บันทึก',
    UNIQUE KEY uk_attendance_event_key (event_key),
    INDEX idx_attendance_emp_time (employee_id, scan_time, scan_type),
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
    FOREIGN KEY (device_id) REFERENCES attendance_devices(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT = 'บันทึกเวลาเข้า-ออกงาน';

CREATE TABLE IF NOT EXISTS leave_types (
    id INT AUTO_INCREMENT PRIMARY KEY COMMENT 'รหัสประเภทลา (PK)',
    company_id INT NULL COMMENT 'รหัสบริษัท (NULL = ใช้ร่วมกันทั้งเครือ)',
    name VARCHAR(50) NOT NULL COMMENT 'ชื่อประเภทลา',
    max_days_per_year INT NOT NULL COMMENT 'สิทธิ์ลาต่อปี (วัน)',
    is_paid BOOLEAN NOT NULL DEFAULT TRUE COMMENT 'จ่ายค่าจ้างไหม',
    is_carry_over BOOLEAN NOT NULL DEFAULT FALSE COMMENT 'ยกยอดได้ไหม',
    max_carry_over_years INT NOT NULL DEFAULT 0 COMMENT 'สะสมได้สูงสุด (ปี)',
    status TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'สถานะใช้งาน (1=ใช้งาน, 0=เลิกใช้)',
    INDEX idx_leave_types_company_status (company_id, status),
    FOREIGN KEY (company_id) REFERENCES companies(company_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT = 'ประเภทการลา';

CREATE TABLE IF NOT EXISTS leave_balances (
    id INT AUTO_INCREMENT PRIMARY KEY COMMENT 'รหัสยอดวันลา (PK)',
    employee_id VARCHAR(20) NOT NULL COMMENT 'รหัสพนักงาน',
    leave_type_id INT NOT NULL COMMENT 'รหัสประเภทลา',
    year YEAR NOT NULL COMMENT 'ปี ค.ศ.',
    quota_days DECIMAL(4,1) NOT NULL COMMENT 'สิทธิ์ปีนี้ (วัน)',
    carried_days DECIMAL(4,1) NOT NULL DEFAULT 0.0 COMMENT 'ยอดยกมา (วัน)',
    used_days DECIMAL(4,1) NOT NULL DEFAULT 0.0 COMMENT 'ใช้ไปแล้ว (วัน)',
    carry_cycle_count INT NOT NULL DEFAULT 1 COMMENT 'รอบสะสมปีที่',
    UNIQUE KEY emp_leave_year (employee_id, leave_type_id, year),
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
    FOREIGN KEY (leave_type_id) REFERENCES leave_types(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT = 'วันลาคงเหลือรายปี';

CREATE TABLE IF NOT EXISTS leave_requests (
    id INT AUTO_INCREMENT PRIMARY KEY COMMENT 'รหัสคำขอลา (PK)',
    employee_id VARCHAR(20) NOT NULL COMMENT 'รหัสพนักงาน',
    leave_type_id INT NOT NULL COMMENT 'รหัสประเภทลา',
    start_date DATETIME NOT NULL COMMENT 'วันเวลาเริ่มลา',
    end_date DATETIME NOT NULL COMMENT 'วันเวลาสิ้นสุดลา',
    total_days DECIMAL(4,1) NOT NULL COMMENT 'จำนวนวันลา',
    reason TEXT NULL COMMENT 'เหตุผล',
    attachment_url VARCHAR(500) NULL COMMENT 'ไฟล์แนบ (ไม่บังคับ)',
    current_step INT NOT NULL DEFAULT 1 COMMENT 'ลำดับการอนุมัติปัจจุบัน',
    total_steps INT NOT NULL DEFAULT 4 COMMENT 'จำนวนขั้นอนุมัติทั้งหมด',
    status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending' COMMENT 'สถานะคำขอรวม',
    pending_dedupe_key CHAR(64) NULL,
    approved_by VARCHAR(20) NULL COMMENT 'ผู้อนุมัติปิดงานขั้นสุดท้าย',
    approved_at DATETIME NULL COMMENT 'วันเวลาอนุมัติเสร็จสิ้น',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT 'วันที่ยื่นคำขอ',
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'วันที่แก้ไขล่าสุด',
    UNIQUE KEY uk_leave_pending_dedupe (pending_dedupe_key),
    INDEX idx_leave_req_emp_status_dates (employee_id, status, start_date, end_date),
    INDEX idx_leave_req_status_step (status, current_step),
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
    FOREIGN KEY (leave_type_id) REFERENCES leave_types(id),
    FOREIGN KEY (approved_by) REFERENCES employees(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT = 'คำขอลางาน';

CREATE TABLE IF NOT EXISTS leave_attachments (
    id INT AUTO_INCREMENT PRIMARY KEY COMMENT 'รหัสไฟล์แนบการลา (PK)',
    leave_request_id INT NOT NULL COMMENT 'รหัสคำขอลา',
    file_name VARCHAR(255) NOT NULL COMMENT 'ชื่อไฟล์',
    cloud_bucket VARCHAR(100) NULL COMMENT 'ชื่อ Bucket',
    cloud_object_key VARCHAR(255) NULL COMMENT 'Path ไฟล์ใน Storage',
    file_url VARCHAR(500) NOT NULL COMMENT 'URL ไฟล์',
    file_type VARCHAR(50) NULL COMMENT 'ประเภทไฟล์ เช่น image/jpeg, application/pdf',
    file_size INT NULL COMMENT 'ขนาดไฟล์ (bytes)',
    status TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'สถานะใช้งาน (1=ใช้งาน, 0=เลิกใช้/ลบ)',
    uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT 'วันที่อัปโหลด',
    INDEX idx_leave_attachments_req_status (leave_request_id, status),
    FOREIGN KEY (leave_request_id) REFERENCES leave_requests(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT = 'ไฟล์แนบประกอบคำขอลางาน';

CREATE TABLE IF NOT EXISTS leave_approvals (
    id INT AUTO_INCREMENT PRIMARY KEY COMMENT 'รหัสขั้นตอนอนุมัติลา (PK)',
    leave_request_id INT NOT NULL COMMENT 'รหัสคำขอลา',
    step_order INT NOT NULL COMMENT 'ลำดับขั้น (1, 2, 3, 4)',
    approver_role ENUM('supervisor', 'manager', 'director', 'hr_admin') NOT NULL COMMENT 'บทบาทผู้อนุมัติในขั้นนี้',
    approver_id VARCHAR(20) NULL COMMENT 'รหัสพนักงานผู้อนุมัติ',
    status ENUM('waiting', 'pending', 'approved', 'rejected') NOT NULL DEFAULT 'waiting' COMMENT 'waiting=รอคิว, pending=ถึงคิวแสดงให้กดอนุมัติ',
    comment TEXT NULL COMMENT 'หมายเหตุจากผู้อนุมัติ',
    action_at DATETIME NULL COMMENT 'วันเวลาที่ดำเนินการ',
    UNIQUE KEY uk_leave_step (leave_request_id, step_order),
    INDEX idx_leave_app_approver_status (approver_id, status),
    FOREIGN KEY (leave_request_id) REFERENCES leave_requests(id) ON DELETE CASCADE,
    FOREIGN KEY (approver_id) REFERENCES employees(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT = 'ลำดับขั้นการอนุมัติลางาน';

CREATE TABLE IF NOT EXISTS leave_reset_logs (
    id INT AUTO_INCREMENT PRIMARY KEY COMMENT 'รหัสประวัติ (PK)',
    company_id INT NULL COMMENT 'รหัสบริษัท (NULL = ทั้งเครือ)',
    from_year YEAR NOT NULL COMMENT 'ปีต้นทาง',
    to_year YEAR NOT NULL COMMENT 'ปีปลายทาง',
    processed_employees INT NOT NULL COMMENT 'จำนวนพนักงาน',
    executed_by VARCHAR(20) NULL COMMENT 'ผู้สั่งประมวลผล',
    executed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT 'เวลาประมวลผล',
    company_scope_id INT GENERATED ALWAYS AS (IFNULL(company_id, 0)) STORED,
    UNIQUE KEY uk_company_scope_to_year (company_scope_id, to_year),
    FOREIGN KEY (company_id) REFERENCES companies(company_id),
    FOREIGN KEY (executed_by) REFERENCES employees(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT = 'ประวัติยกยอดวันลา';

CREATE TABLE IF NOT EXISTS ot_requests (
    id INT AUTO_INCREMENT PRIMARY KEY COMMENT 'รหัสคำขอ OT (PK)',
    employee_id VARCHAR(20) NOT NULL COMMENT 'รหัสพนักงาน',
    ot_date DATE NOT NULL COMMENT 'วันที่ทำ OT',
    start_time TIME NOT NULL COMMENT 'เวลาเริ่ม OT',
    end_time TIME NOT NULL COMMENT 'เวลาเลิก OT',
    ot_type ENUM('workday_post', 'workday_pre', 'holiday', 'workday_post_vei', 'holiday_vei') NOT NULL DEFAULT 'workday_post' COMMENT 'ประเภท OT',
    hours DECIMAL(4,2) NOT NULL COMMENT 'ชั่วโมงที่ขอ',
    raw_hours DECIMAL(5,2) NOT NULL DEFAULT 0.00 COMMENT 'ชั่วโมงดิบ',
    net_hours DECIMAL(5,2) NOT NULL DEFAULT 0.00 COMMENT 'ชั่วโมงสุทธิ',
    lunch_deducted TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'หักพักเที่ยงไหม',
    total_pay DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'ค่า OT สุทธิ (บาท)',
    ot_rate ENUM('1.0', '1.5', '2.0', '3.0') NOT NULL DEFAULT '1.5' COMMENT 'ตัวคูณ OT',
    reason TEXT NULL COMMENT 'เหตุผล',
    attachment_url VARCHAR(500) NULL COMMENT 'ไฟล์แนบ (ไม่บังคับ)',
    current_step INT NOT NULL DEFAULT 1 COMMENT 'ลำดับการอนุมัติปัจจุบัน',
    total_steps INT NOT NULL DEFAULT 4 COMMENT 'จำนวนขั้นอนุมัติทั้งหมด',
    status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending' COMMENT 'สถานะคำขอรวม',
    pending_dedupe_key CHAR(64) NULL,
    approved_by VARCHAR(20) NULL COMMENT 'ผู้อนุมัติปิดงานขั้นสุดท้าย',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT 'วันที่ยื่นคำขอ',
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'วันที่แก้ไขล่าสุด',
    UNIQUE KEY uk_ot_pending_dedupe (pending_dedupe_key),
    INDEX idx_ot_req_emp_date_status (employee_id, ot_date, status),
    INDEX idx_ot_req_status_step (status, current_step),
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
    FOREIGN KEY (approved_by) REFERENCES employees(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT = 'คำขอทำงานล่วงเวลา (OT)';

CREATE TABLE IF NOT EXISTS ot_approvals (
    id INT AUTO_INCREMENT PRIMARY KEY COMMENT 'รหัสขั้นตอนอนุมัติ OT (PK)',
    ot_request_id INT NOT NULL COMMENT 'รหัสคำขอ OT',
    step_order INT NOT NULL COMMENT 'ลำดับขั้น (1, 2, 3, 4)',
    approver_role ENUM('supervisor', 'manager', 'director', 'hr_admin') NOT NULL COMMENT 'บทบาทผู้อนุมัติในขั้นนี้',
    approver_id VARCHAR(20) NULL COMMENT 'รหัสพนักงานผู้อนุมัติ',
    status ENUM('waiting', 'pending', 'approved', 'rejected') NOT NULL DEFAULT 'waiting' COMMENT 'waiting=รอคิว, pending=ถึงคิวแสดงให้กดอนุมัติ',
    comment TEXT NULL COMMENT 'หมายเหตุจากผู้อนุมัติ',
    action_at DATETIME NULL COMMENT 'วันเวลาที่ดำเนินการ',
    UNIQUE KEY uk_ot_step (ot_request_id, step_order),
    INDEX idx_ot_app_approver_status (approver_id, status),
    FOREIGN KEY (ot_request_id) REFERENCES ot_requests(id) ON DELETE CASCADE,
    FOREIGN KEY (approver_id) REFERENCES employees(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT = 'ลำดับขั้นการอนุมัติ OT';

CREATE TABLE IF NOT EXISTS holidays (
    id INT AUTO_INCREMENT PRIMARY KEY COMMENT 'รหัสวันหยุด (PK)',
    company_id INT NULL COMMENT 'รหัสบริษัท (NULL = วันหยุดส่วนกลาง)',
    holiday_date DATE NOT NULL COMMENT 'วันที่หยุด',
    holiday_name VARCHAR(150) NOT NULL COMMENT 'ชื่อวันหยุด',
    status TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'สถานะใช้งาน (1=ใช้งาน, 0=เลิกใช้)',
    duplicate_key CHAR(64) NULL,
    UNIQUE KEY uk_holidays_duplicate_key (duplicate_key),
    INDEX idx_holidays_company_date (company_id, holiday_date, status),
    FOREIGN KEY (company_id) REFERENCES companies(company_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT = 'วันหยุดประจำปี';

CREATE TABLE IF NOT EXISTS payroll_periods (
    id VARCHAR(20) PRIMARY KEY COMMENT 'รหัสงวด เช่น VNGS-000001 (PK)',
    company_id INT NOT NULL COMMENT 'รหัสบริษัท',
    period_name VARCHAR(50) NOT NULL COMMENT 'ชื่องวดเงินเดือน',
    start_date DATE NOT NULL COMMENT 'วันเริ่มรอบ',
    end_date DATE NOT NULL COMMENT 'วันสิ้นสุดรอบ',
    pay_date DATE NOT NULL COMMENT 'วันจ่ายเงิน',
    status ENUM('draft', 'calculated', 'locked', 'outsourced') NOT NULL DEFAULT 'draft' COMMENT 'สถานะงวด',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_payroll_company_period (company_id, start_date, end_date),
    FOREIGN KEY (company_id) REFERENCES companies(company_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT = 'งวดการจ่ายเงินเดือน';

CREATE TABLE IF NOT EXISTS payroll_items (
    id INT AUTO_INCREMENT PRIMARY KEY COMMENT 'รหัสรายการ (PK)',
    period_id VARCHAR(20) NOT NULL COMMENT 'รหัสงวดเงินเดือน',
    employee_id VARCHAR(20) NOT NULL COMMENT 'รหัสพนักงาน',
    base_salary DECIMAL(12,2) NOT NULL COMMENT 'เงินเดือนฐาน',
    ot_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'ค่า OT',
    allowance DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'เงินเพิ่ม/สวัสดิการ',
    deduction_late_leave DECIMAL(12,2) NOT NULL DEFAULT 0.00 COMMENT 'หักสาย/ขาด/ลา',
    social_security DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'ประกันสังคม',
    withholding_tax DECIMAL(10,2) NOT NULL DEFAULT 0.00 COMMENT 'ภาษีหัก ณ ที่จ่าย',
    net_salary DECIMAL(12,2) NOT NULL COMMENT 'เงินเดือนสุทธิ',
    payslip_pdf_url VARCHAR(500) NULL COMMENT 'ลิงก์สลิป PDF',
    UNIQUE KEY uk_payroll_period_employee (period_id, employee_id),
    FOREIGN KEY (period_id) REFERENCES payroll_periods(id) ON DELETE CASCADE,
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT = 'รายการเงินเดือนรายบุคคล';

CREATE TABLE IF NOT EXISTS payroll_outsourcing_logs (
    id INT AUTO_INCREMENT PRIMARY KEY COMMENT 'รหัสประวัติส่งออก (PK)',
    period_id VARCHAR(20) NOT NULL COMMENT 'รหัสงวดเงินเดือน',
    provider_name VARCHAR(100) NOT NULL DEFAULT 'Tiger Payroll Outsourcing' COMMENT 'ชื่อผู้ให้บริการ',
    exported_by VARCHAR(20) NOT NULL COMMENT 'ผู้สั่งส่งออก',
    file_url VARCHAR(500) NULL COMMENT 'ลิงก์ไฟล์',
    status ENUM('exported', 'acknowledged', 'completed') NOT NULL DEFAULT 'exported' COMMENT 'สถานะส่งออก',
    exported_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT 'เวลาส่งออก',
    FOREIGN KEY (period_id) REFERENCES payroll_periods(id) ON DELETE CASCADE,
    FOREIGN KEY (exported_by) REFERENCES employees(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT = 'ประวัติส่งออก Payroll Outsource';

CREATE TABLE IF NOT EXISTS audit_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    actor_user_id INT NULL,
    actor_employee_id VARCHAR(20) NULL,
    action VARCHAR(80) NOT NULL,
    entity_type VARCHAR(80) NOT NULL,
    entity_id VARCHAR(80) NULL,
    metadata JSON NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_audit_logs_entity (entity_type, entity_id, created_at),
    INDEX idx_audit_logs_actor (actor_user_id, created_at),
    CONSTRAINT fk_audit_logs_user
        FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_audit_logs_employee
        FOREIGN KEY (actor_employee_id) REFERENCES employees(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT = 'บันทึกประวัติการใช้งานและตรวจสอบระบบ (Audit Logs)';


-- =========================================================================
-- ส่วนที่ 2 : ข้อมูลเริ่มต้นครบทุกตาราง (รหัสผ่านทุกบัญชีคือ: password)
-- =========================================================================

-- 1. บริษัท (status: 1=ใช้งาน, 0=เลิกใช้)
INSERT INTO companies (company_id, company_code, company_name, status) VALUES
(1, 'VNGS', 'บริษัท วีเอ็นจีเอส จำกัด', 1),
(2, 'SUB1', 'บริษัท ในเครือ จำกัด', 1);

-- 2. แผนก (โครงสร้างองค์กรระดับสากล: ฝ่ายหลัก + ฝ่ายย่อย)
INSERT INTO departments (id, company_id, code, name, parent_id, status) VALUES
(1, 1, 'EXEC', 'บริหารและยุทธศาสตร์องค์กร (Executive Office)', NULL, 1),
(2, 1, 'HR', 'ทรัพยากรบุคคลและวัฒนธรรมองค์กร (People & Culture)', 1, 1),
(3, 1, 'ACC', 'บัญชีและการเงิน (Finance & Accounting)', 1, 1),
(4, 2, 'IT', 'เทคโนโลยีและวิศวกรรมดิจิทัล (Technology & Digital)', NULL, 1),
(5, 2, 'SALES', 'พาณิชย์ การขาย และการตลาด (Commercial & Marketing)', NULL, 1),
(6, 2, 'OPS', 'ปฏิบัติการและห่วงโซ่อุปทาน (Operations & Supply Chain)', NULL, 1),

(7, 1, 'STRAT', 'กลยุทธ์และการพัฒนาองค์กร (Corporate Strategy & Transformation)', 1, 1),
(8, 1, 'LEGAL', 'กฎหมาย กำกับดูแล และบริหารความเสี่ยง (Legal, Risk & Compliance)', 1, 1),
(9, 1, 'AUDIT', 'ตรวจสอบภายใน (Internal Audit)', 1, 1),
(10, 1, 'CORPCOM', 'สื่อสารองค์กรและความยั่งยืน (Corporate Communications & ESG)', 1, 1),
(18, 1, 'PROC', 'จัดซื้อจัดจ้างกลาง (Corporate Procurement)', 1, 1),
(19, 1, 'ADMIN', 'ธุรการและบริหารอาคารสถานที่ (Workplace & Facilities)', 1, 1),

(11, 1, 'TA', 'สรรหาและคัดเลือกบุคลากร (Talent Acquisition)', 2, 1),
(12, 1, 'LND', 'พัฒนาศักยภาพและการเรียนรู้ (Learning & Development)', 2, 1),
(13, 1, 'COMPBEN', 'ค่าตอบแทน สวัสดิการ และ Payroll (Total Rewards & Payroll)', 2, 1),
(14, 1, 'HRBP', 'พันธมิตรธุรกิจบุคคลและพนักงานสัมพันธ์ (HRBP & Employee Relations)', 2, 1),

(15, 1, 'FIN', 'วางแผนและวิเคราะห์ทางการเงิน (FP&A)', 3, 1),
(16, 1, 'TAX', 'บัญชี ภาษี และการปิดงบ (General Accounting & Tax)', 3, 1),
(17, 1, 'TREASURY', 'บริหารเงินสดและนักลงทุนสัมพันธ์ (Treasury & Investor Relations)', 3, 1),

(20, 2, 'ENG', 'วิศวกรรมซอฟต์แวร์ (Software Engineering)', 4, 1),
(21, 2, 'DATA', 'ข้อมูลและปัญญาประดิษฐ์ (Data Science, Analytics & AI)', 4, 1),
(22, 2, 'CLOUD', 'โครงสร้างพื้นฐาน คลาวด์ และ DevOps (Cloud & DevOps)', 4, 1),
(23, 2, 'SEC', 'ความมั่นคงปลอดภัยไซเบอร์ (Cybersecurity & InfoSec)', 4, 1),
(24, 2, 'PROD', 'บริหารผลิตภัณฑ์และออกแบบประสบการณ์ (Product & UX/UI Design)', 4, 1),
(25, 2, 'QA', 'ประกันคุณภาพซอฟต์แวร์ (Quality Assurance & Testing)', 4, 1),
(26, 2, 'ITOPS', 'บริการไอทีและสนับสนุนผู้ใช้ (IT Service Desk & Infrastructure)', 4, 1),

(27, 2, 'B2B', 'ขายองค์กรและพันธมิตรธุรกิจ (Enterprise Sales & Partnerships)', 5, 1),
(28, 2, 'MKT', 'การตลาดแบรนด์และดิจิทัล (Brand & Digital Marketing)', 5, 1),
(29, 2, 'CS', 'ดูแลความสำเร็จลูกค้า (Customer Success & Support)', 5, 1),

(30, 2, 'SCM', 'วางแผนห่วงโซ่อุปทานและโลจิสติกส์ (Supply Chain & Logistics)', 6, 1),
(31, 2, 'WH', 'บริหารคลังสินค้าและจัดส่ง (Warehouse & Fulfillment)', 6, 1),
(32, 2, 'QMS', 'ควบคุมคุณภาพและความปลอดภัยอาชีวอนามัย (Quality & HSE)', 6, 1);

-- 3. ตำแหน่งงาน (125 ตำแหน่ง ครบทั้ง 5 ระดับ คล้ายบริษัทระดับโลก พร้อมระบุ department_id)
INSERT INTO positions (id, company_id, department_id, title, level, is_leave_required, status) VALUES
(1, NULL, 1, 'Chief Executive Officer (CEO)', 5, FALSE, 1),
(2, NULL, 1, 'Managing Director (MD)', 5, FALSE, 1),
(3, NULL, 6, 'Chief Operating Officer (COO)', 5, FALSE, 1),
(4, NULL, 3, 'Chief Financial Officer (CFO)', 5, FALSE, 1),
(5, NULL, 4, 'Chief Technology Officer (CTO)', 5, FALSE, 1),
(46, NULL, 2, 'Chief Human Resources Officer (CHRO)', 5, FALSE, 1),
(47, NULL, 4, 'Chief Information Officer (CIO)', 5, FALSE, 1),
(48, NULL, 23, 'Chief Information Security Officer (CISO)', 5, FALSE, 1),
(49, NULL, 21, 'Chief Data Officer (CDO)', 5, FALSE, 1),
(50, NULL, 5, 'Chief Revenue Officer (CRO)', 5, FALSE, 1),
(51, NULL, 28, 'Chief Marketing Officer (CMO)', 5, FALSE, 1),
(52, NULL, 8, 'Chief Legal Officer (CLO / General Counsel)', 5, FALSE, 1),
(53, NULL, 24, 'Chief Product Officer (CPO)', 5, FALSE, 1),
(54, NULL, 1, 'Executive Vice President (EVP / VP)', 5, FALSE, 1),

(6, NULL, 1, 'Director', 4, FALSE, 1),
(7, NULL, 2, 'HR Director', 4, FALSE, 1),
(8, NULL, 3, 'Finance & Accounting Director', 4, FALSE, 1),
(9, NULL, 4, 'IT Director', 4, FALSE, 1),
(10, NULL, 5, 'Sales & Marketing Director', 4, FALSE, 1),
(11, NULL, 6, 'Operations Director', 4, FALSE, 1),
(55, NULL, 20, 'Engineering Director', 4, FALSE, 1),
(56, NULL, 21, 'Data & AI Director', 4, FALSE, 1),
(57, NULL, 24, 'Product Director', 4, FALSE, 1),
(58, NULL, 23, 'Cybersecurity Director', 4, FALSE, 1),
(59, NULL, 8, 'Legal & Compliance Director', 4, FALSE, 1),
(60, NULL, 7, 'Corporate Strategy Director', 4, FALSE, 1),
(61, NULL, 9, 'Internal Audit Director', 4, FALSE, 1),
(62, NULL, 30, 'Supply Chain Director', 4, FALSE, 1),
(63, NULL, 29, 'Customer Success Director', 4, FALSE, 1),
(64, NULL, 18, 'Procurement Director', 4, FALSE, 1),

(12, NULL, 2, 'HR Manager', 3, TRUE, 1),
(13, NULL, 4, 'IT Manager', 3, TRUE, 1),
(14, NULL, 16, 'Accounting Manager', 3, TRUE, 1),
(15, NULL, 15, 'Finance Manager', 3, TRUE, 1),
(16, NULL, 27, 'Sales Manager', 3, TRUE, 1),
(17, NULL, 28, 'Marketing Manager', 3, TRUE, 1),
(18, NULL, 6, 'Operations Manager', 3, TRUE, 1),
(19, NULL, 19, 'Admin & Purchasing Manager', 3, TRUE, 1),
(20, NULL, NULL, 'Project Manager', 3, TRUE, 1),
(65, NULL, 20, 'Engineering Manager', 3, TRUE, 1),
(66, NULL, 24, 'Product Manager', 3, TRUE, 1),
(67, NULL, 21, 'Data Engineering Manager', 3, TRUE, 1),
(68, NULL, 22, 'DevOps & Cloud Manager', 3, TRUE, 1),
(69, NULL, 23, 'Cybersecurity Manager', 3, TRUE, 1),
(70, NULL, 25, 'QA Manager', 3, TRUE, 1),
(71, NULL, 11, 'Talent Acquisition Manager', 3, TRUE, 1),
(72, NULL, 12, 'Learning & Development Manager', 3, TRUE, 1),
(73, NULL, 13, 'Total Rewards & Payroll Manager', 3, TRUE, 1),
(74, NULL, 14, 'HR Business Partner (HRBP) Manager', 3, TRUE, 1),
(75, NULL, 15, 'FP&A Manager', 3, TRUE, 1),
(76, NULL, 16, 'Tax & Compliance Manager', 3, TRUE, 1),
(77, NULL, 8, 'Legal Counsel / Legal Manager', 3, TRUE, 1),
(78, NULL, 9, 'Internal Audit Manager', 3, TRUE, 1),
(79, NULL, 30, 'Supply Chain & Logistics Manager', 3, TRUE, 1),
(80, NULL, 31, 'Warehouse Manager', 3, TRUE, 1),
(81, NULL, 29, 'Customer Success Manager', 3, TRUE, 1),
(82, NULL, 10, 'Corporate Communications Manager', 3, TRUE, 1),

(21, NULL, NULL, 'Team Supervisor', 2, TRUE, 1),
(22, NULL, 2, 'HR Supervisor', 2, TRUE, 1),
(23, NULL, 16, 'Accounting Supervisor', 2, TRUE, 1),
(24, NULL, 20, 'Dev Team Lead', 2, TRUE, 1),
(25, NULL, 26, 'IT Support Supervisor', 2, TRUE, 1),
(26, NULL, 27, 'Sales Supervisor', 2, TRUE, 1),
(27, NULL, 19, 'Admin Supervisor', 2, TRUE, 1),
(28, NULL, 31, 'Warehouse Supervisor', 2, TRUE, 1),
(83, NULL, 20, 'Tech Lead / Staff Software Engineer', 2, TRUE, 1),
(84, NULL, 24, 'Scrum Master / Agile Coach', 2, TRUE, 1),
(85, NULL, 21, 'Lead Data Scientist', 2, TRUE, 1),
(86, NULL, 22, 'Cloud Architect / Lead SRE', 2, TRUE, 1),
(87, NULL, 23, 'Security Operations Lead (SOC Lead)', 2, TRUE, 1),
(88, NULL, 25, 'QA Lead', 2, TRUE, 1),
(89, NULL, 24, 'Product Design Lead', 2, TRUE, 1),
(90, NULL, 11, 'Talent Acquisition Lead', 2, TRUE, 1),
(91, NULL, 13, 'Payroll & Benefits Supervisor', 2, TRUE, 1),
(92, NULL, 18, 'Procurement Supervisor', 2, TRUE, 1),
(93, NULL, 29, 'Customer Support Team Lead', 2, TRUE, 1),
(94, NULL, 32, 'HSE / Safety Supervisor', 2, TRUE, 1),

(29, NULL, 2, 'HR Officer', 1, TRUE, 1),
(30, NULL, 13, 'Payroll Officer', 1, TRUE, 1),
(31, NULL, 16, 'Accountant', 1, TRUE, 1),
(32, NULL, 15, 'Finance Officer', 1, TRUE, 1),
(33, NULL, 20, 'Senior Software Developer', 1, TRUE, 1),
(34, NULL, 20, 'Software Developer', 1, TRUE, 1),
(35, NULL, 24, 'UX/UI Designer', 1, TRUE, 1),
(36, NULL, 25, 'QA / Software Tester', 1, TRUE, 1),
(37, NULL, 26, 'System Administrator', 1, TRUE, 1),
(38, NULL, 26, 'IT Support', 1, TRUE, 1),
(39, NULL, 28, 'Graphic Designer', 1, TRUE, 1),
(40, NULL, 28, 'Digital Marketing Specialist', 1, TRUE, 1),
(41, NULL, 27, 'Sales Executive', 1, TRUE, 1),
(42, NULL, 18, 'Purchasing Officer', 1, TRUE, 1),
(43, NULL, 19, 'Admin Officer', 1, TRUE, 1),
(44, NULL, 19, 'Receptionist', 1, TRUE, 1),
(45, NULL, 19, 'Messenger / Driver', 1, TRUE, 1),
(95, NULL, 20, 'Backend Engineer', 1, TRUE, 1),
(96, NULL, 20, 'Frontend Engineer', 1, TRUE, 1),
(97, NULL, 20, 'Full-Stack Engineer', 1, TRUE, 1),
(98, NULL, 20, 'Mobile Developer (iOS / Android)', 1, TRUE, 1),
(99, NULL, 22, 'DevOps / Site Reliability Engineer (SRE)', 1, TRUE, 1),
(100, NULL, 22, 'Cloud Engineer', 1, TRUE, 1),
(101, NULL, 21, 'Data Engineer', 1, TRUE, 1),
(102, NULL, 21, 'Data Analyst / BI Analyst', 1, TRUE, 1),
(103, NULL, 21, 'Data Scientist / ML Engineer', 1, TRUE, 1),
(104, NULL, 23, 'Cybersecurity Analyst (SOC Analyst)', 1, TRUE, 1),
(105, NULL, 25, 'Automation QA Engineer (SDET)', 1, TRUE, 1),
(106, NULL, 24, 'Product Owner / Product Analyst', 1, TRUE, 1),
(107, NULL, 24, 'Business Analyst (BA)', 1, TRUE, 1),
(108, NULL, 11, 'Talent Acquisition Specialist / Recruiter', 1, TRUE, 1),
(109, NULL, 14, 'HRBP Specialist', 1, TRUE, 1),
(110, NULL, 12, 'Learning & Development Specialist', 1, TRUE, 1),
(111, NULL, 13, 'Compensation & Benefits Analyst', 1, TRUE, 1),
(112, NULL, 15, 'Financial Analyst (FP&A)', 1, TRUE, 1),
(113, NULL, 17, 'Treasury Officer', 1, TRUE, 1),
(114, NULL, 8, 'Legal & Compliance Officer', 1, TRUE, 1),
(115, NULL, 9, 'Internal Auditor', 1, TRUE, 1),
(116, NULL, 7, 'Corporate Strategy Analyst', 1, TRUE, 1),
(117, NULL, 10, 'PR & Corporate Communications Officer', 1, TRUE, 1),
(118, NULL, 27, 'Enterprise Account Executive', 1, TRUE, 1),
(119, NULL, 27, 'Business Development Executive', 1, TRUE, 1),
(120, NULL, 29, 'Customer Success Specialist', 1, TRUE, 1),
(121, NULL, 30, 'Supply Chain Planner', 1, TRUE, 1),
(122, NULL, 30, 'Logistics Coordinator', 1, TRUE, 1),
(123, NULL, 31, 'Warehouse Staff / Inventory Officer', 1, TRUE, 1),
(124, NULL, 32, 'Safety Officer (HSE)', 1, TRUE, 1),
(125, NULL, 32, 'Quality Control Inspector (QC)', 1, TRUE, 1),
(126, NULL, 2, 'HR Director (Demo)', 4, FALSE, 1),
(127, NULL, 3, 'Accounting Manager (Demo)', 3, TRUE, 1),
(128, NULL, 3, 'Accounting Director (Demo)', 4, FALSE, 1),
(129, NULL, 4, 'IT Director (Demo)', 4, FALSE, 1),
(130, NULL, 27, 'Sales Manager (Demo)', 3, TRUE, 1),
(131, NULL, 27, 'Sales Director (Demo)', 4, FALSE, 1);

-- 4. พนักงาน (รวมตัวอย่างการโอนย้ายข้ามบริษัทตามแนวทางที่ 1: EMP-000009 -> EMP-000010)
-- ผู้อนุมัติตัวอย่างที่เพิ่มใหม่อยู่บริษัทและแผนกเดียวกับผู้ยื่นคำขอ
INSERT INTO employees (id, company_id, emp_code, first_name, last_name, id_card_no, email, phone, department_id, position_id, manager_id, previous_employee_id, hire_date, base_salary, employment_status, profile_image_url, status) VALUES
('EMP-000001', 1, 'VNGS-001', 'กิตติพงษ์', 'บริหารกิจ', '1100100100001', 'ceo@company.com', '0811111111', 1, 1, NULL, NULL, '2020-01-01', 95000.00, 'active', 'https://ui-avatars.com/api/?name=Kittipong&background=0D8ABC&color=fff', 1),
('EMP-000003', 1, 'VNGS-003', 'สุรชัย', 'อำนวยการ', '1100100100003', 'director@company.com', '0833333331', 1, 6, 'EMP-000001', NULL, '2021-05-10', 75000.00, 'active', 'https://ui-avatars.com/api/?name=Surachai&background=3F51B5&color=fff', 1),
('EMP-000002', 1, 'VNGS-002', 'ณัฐิดา', 'ดูแลคน', '1100100100002', 'hr@company.com', '0822222222', 2, 12, 'EMP-000011', NULL, '2022-03-15', 52000.00, 'active', 'https://ui-avatars.com/api/?name=Nattida&background=E91E63&color=fff', 1),
('EMP-000004', 1, 'VNGS-004', 'ศิริพร', 'ตัวเลขดี', '1100100100004', 'acc@company.com', '0833333332', 3, 31, 'EMP-000012', NULL, '2023-02-01', 32000.00, 'active', 'https://ui-avatars.com/api/?name=Siriporn&background=795548&color=fff', 1),
('EMP-000005', 2, 'SUB1-001', 'ธนากร', 'ผู้จัดการ', '1100100100005', 'manager@company.com', '0833333333', 4, 13, 'EMP-000014', NULL, '2023-06-01', 48000.00, 'active', 'https://ui-avatars.com/api/?name=Thanakorn&background=009688&color=fff', 1),
('EMP-000006', 2, 'SUB1-002', 'วรพล', 'หัวหน้าทีม', '1100100100006', 'supervisor@company.com', '0844444441', 4, 24, 'EMP-000005', NULL, '2023-09-01', 38000.00, 'active', 'https://ui-avatars.com/api/?name=Woraphon&background=FF9800&color=fff', 1),
('EMP-000007', 2, 'SUB1-003', 'พิมพ์ชนก', 'ดีไซน์', '1100100100007', 'ux@company.com', '0844444444', 4, 35, 'EMP-000006', NULL, '2024-02-01', 28000.00, 'active', 'https://ui-avatars.com/api/?name=Pimchanok&background=9C27B0&color=fff', 1),
('EMP-000008', 2, 'SUB1-004', 'อานนท์', 'โค้ดไว', '1100100100008', 'dev@company.com', '0855555555', 4, 34, 'EMP-000006', NULL, '2024-04-01', 35000.00, 'active', 'https://ui-avatars.com/api/?name=Anon&background=4CAF50&color=fff', 1),
('EMP-000009', 1, 'VNGS-005', 'ปิยะวัฒน์', 'ย้ายสายงาน', '1100100100009', 'piyawat@company.com', '0866666666', 15, 32, NULL, NULL, '2023-01-10', 30000.00, 'resigned', 'https://ui-avatars.com/api/?name=Piyawat&background=607D8B&color=fff', 0),
('EMP-000010', 2, 'SUB1-005', 'ปิยะวัฒน์', 'ย้ายสายงาน', '1100100100009', 'piyawat@company.com', '0866666666', 27, 41, 'EMP-000015', 'EMP-000009', '2025-01-01', 33000.00, 'active', 'https://ui-avatars.com/api/?name=Piyawat&background=00BCD4&color=fff', 1),
('EMP-000011', 1, 'VNGS-006', 'HR', 'Director Demo', '1100100100011', 'hr.director.demo@company.com', NULL, 2, 126, NULL, NULL, '2025-01-01', 60000.00, 'active', NULL, 1),
('EMP-000013', 1, 'VNGS-007', 'Accounting', 'Director Demo', '1100100100013', 'accounting.director.demo@company.com', NULL, 3, 128, NULL, NULL, '2025-01-01', 60000.00, 'active', NULL, 1),
('EMP-000014', 2, 'SUB1-006', 'IT', 'Director Demo', '1100100100014', 'it.director.demo@company.com', NULL, 4, 129, NULL, NULL, '2025-01-01', 60000.00, 'active', NULL, 1),
('EMP-000016', 2, 'SUB1-007', 'Sales', 'Director Demo', '1100100100016', 'sales.director.demo@company.com', NULL, 27, 131, NULL, NULL, '2025-01-01', 60000.00, 'active', NULL, 1),
('EMP-000012', 1, 'VNGS-008', 'Accounting', 'Manager Demo', '1100100100012', 'accounting.manager.demo@company.com', NULL, 3, 127, 'EMP-000013', NULL, '2025-01-01', 45000.00, 'active', NULL, 1),
('EMP-000015', 2, 'SUB1-008', 'Sales', 'Manager Demo', '1100100100015', 'sales.manager.demo@company.com', NULL, 27, 130, 'EMP-000016', NULL, '2025-01-01', 45000.00, 'active', NULL, 1);

-- 5. บัญชีผู้ใช้งาน (รหัสผ่าน: password)
INSERT INTO users (employee_id, username, password, password_hash, role, status, last_login) VALUES
('EMP-000001', 'admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin', 1, NOW()),
('EMP-000002', 'hr', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'hr_manager', 1, NOW()),
('EMP-000003', 'director', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'director', 1, NOW()),
('EMP-000004', 'accountant', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'employee', 1, NULL),
('EMP-000005', 'manager', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'manager', 1, NOW()),
('EMP-000006', 'supervisor', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'supervisor', 1, NOW()),
('EMP-000007', 'employee', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'employee', 1, NOW()),
('EMP-000008', 'dev', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'employee', 1, NULL),
('EMP-000010', 'piyawat', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'employee', 1, NULL),
('EMP-000011', 'hrdirector_demo', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'director', 1, NULL),
('EMP-000012', 'accounting_manager_demo', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'manager', 1, NULL),
('EMP-000013', 'accounting_director_demo', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'director', 1, NULL),
('EMP-000014', 'itdirector_demo', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'director', 1, NULL),
('EMP-000015', 'salesmanager_demo', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'manager', 1, NULL),
('EMP-000016', 'salesdirector_demo', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'director', 1, NULL);

-- 6. เอกสารพนักงานบน Cloud
INSERT INTO cloud_documents (employee_id, doc_type, file_name, cloud_bucket, cloud_object_key, file_url, status) VALUES
('EMP-000002', 'contract', 'contract_EMP-000002.pdf', 'hr-docs-bucket', 'contracts/2022/EMP-000002.pdf', 'https://storage.example.com/hr-docs-bucket/contracts/2022/EMP-000002.pdf', 1),
('EMP-000005', 'id_card', 'idcard_EMP-000005.jpg', 'hr-docs-bucket', 'idcards/EMP-000005.jpg', 'https://storage.example.com/hr-docs-bucket/idcards/EMP-000005.jpg', 1),
('EMP-000007', 'face_photo', 'face_EMP-000007.jpg', 'hr-docs-bucket', 'faces/EMP-000007.jpg', 'https://storage.example.com/hr-docs-bucket/faces/EMP-000007.jpg', 1),
('EMP-000008', 'certificate', 'degree_EMP-000008.pdf', 'hr-docs-bucket', 'certs/EMP-000008.pdf', 'https://storage.example.com/hr-docs-bucket/certs/EMP-000008.pdf', 1),
('EMP-000010', 'contract', 'contract_EMP-000010.pdf', 'hr-docs-bucket', 'contracts/2025/EMP-000010.pdf', 'https://storage.example.com/hr-docs-bucket/contracts/2025/EMP-000010.pdf', 1);

-- 7. กะการทำงาน
INSERT INTO shifts (id, company_id, shift_name, start_time, end_time, break_minutes, late_grace_minutes, status) VALUES
(1, NULL, 'กะปกติ 09:00 - 18:00', '09:00:00', '18:00:00', 60, 5, 1),
(2, 1, 'กะเช้า HQ 08:30 - 17:30', '08:30:00', '17:30:00', 60, 5, 1),
(3, 2, 'กะปฏิบัติการ 08:00 - 17:00', '08:00:00', '17:00:00', 60, 5, 1);

-- 8. กะทำงานรายบุคคล
INSERT INTO employee_shifts (employee_id, shift_id, effective_date, status) VALUES
('EMP-000001', 1, '2024-01-01', 1),
('EMP-000002', 2, '2024-01-01', 1),
('EMP-000003', 1, '2024-01-01', 1),
('EMP-000004', 2, '2024-01-01', 1),
('EMP-000005', 1, '2024-01-01', 1),
('EMP-000006', 1, '2024-01-01', 1),
('EMP-000007', 1, '2024-02-01', 1),
('EMP-000008', 1, '2024-04-01', 1),
('EMP-000009', 2, '2023-01-10', 0),
('EMP-000010', 1, '2025-01-01', 1);

-- 9. เครื่องบันทึกเวลา
INSERT INTO attendance_devices (id, company_id, device_sn, device_name, device_type, location, api_token, status) VALUES
(1, NULL, 'FACE3D-001', 'เครื่องสแกนใบหน้า 3 มิติ (ล็อบบี้ส่วนกลาง)', 'face_3d', 'ชั้น 1 อาคารสำนักงานใหญ่', 'TOKEN_FACE_3D_001', 1),
(2, 2, 'FINGER-002', 'เครื่องสแกนลายนิ้วมือ (ฝั่งบริษัทในเครือ)', 'fingerprint', 'ชั้น 3 โซน IT', 'TOKEN_FINGER_002', 1),
(3, NULL, 'MOBILE-GPS-01', 'ระบบเช็คอินมือถือ (WFH/นอกสถานที่)', 'mobile_gps', 'Mobile App', 'TOKEN_MOBILE_GPS_01', 1);

-- 10. บันทึกเวลาเข้า-ออกงาน
INSERT INTO attendance_logs (employee_id, device_id, scan_time, scan_type, verify_mode, latitude, longitude, snapshot_url) VALUES
('EMP-000002', 1, CAST(CONCAT(DATE_SUB(CURDATE(), INTERVAL 1 DAY), ' 08:25:00') AS DATETIME), 'check_in', 'face_3d', NULL, NULL, NULL),
('EMP-000002', 1, CAST(CONCAT(DATE_SUB(CURDATE(), INTERVAL 1 DAY), ' 17:35:00') AS DATETIME), 'check_out', 'face_3d', NULL, NULL, NULL),
('EMP-000007', 1, CAST(CONCAT(DATE_SUB(CURDATE(), INTERVAL 1 DAY), ' 08:58:00') AS DATETIME), 'check_in', 'face_3d', NULL, NULL, NULL),
('EMP-000007', 1, CAST(CONCAT(DATE_SUB(CURDATE(), INTERVAL 1 DAY), ' 18:05:00') AS DATETIME), 'check_out', 'face_3d', NULL, NULL, NULL),
('EMP-000008', 2, CAST(CONCAT(DATE_SUB(CURDATE(), INTERVAL 1 DAY), ' 08:55:00') AS DATETIME), 'check_in', 'fingerprint', NULL, NULL, NULL),
('EMP-000008', 2, CAST(CONCAT(DATE_SUB(CURDATE(), INTERVAL 1 DAY), ' 18:00:00') AS DATETIME), 'check_out', 'fingerprint', NULL, NULL, NULL),
('EMP-000008', 2, CAST(CONCAT(DATE_SUB(CURDATE(), INTERVAL 1 DAY), ' 18:01:00') AS DATETIME), 'ot_in', 'fingerprint', NULL, NULL, NULL),
('EMP-000008', 2, CAST(CONCAT(DATE_SUB(CURDATE(), INTERVAL 1 DAY), ' 20:02:00') AS DATETIME), 'ot_out', 'fingerprint', NULL, NULL, NULL),
('EMP-000005', 3, CAST(CONCAT(CURDATE(), ' 08:50:00') AS DATETIME), 'check_in', 'mobile_gps', 13.75633090, 100.50176510, NULL);

-- 11. ประเภทการลา
INSERT INTO leave_types (id, company_id, name, max_days_per_year, is_paid, is_carry_over, max_carry_over_years, status) VALUES
(1, NULL, 'ลาป่วย', 30, 1, 0, 0, 1),
(2, NULL, 'ลากิจ', 6, 1, 0, 0, 1),
(3, NULL, 'ลาพักร้อน', 10, 1, 1, 3, 1),
(4, NULL, 'ลาคลอดบุตร', 98, 1, 0, 0, 1),
(5, NULL, 'ลาอุปสมบท', 15, 1, 0, 0, 1);

-- 12. วันลาคงเหลือรายปี (เฉพาะตำแหน่งที่ต้องยื่นลาและยัง active)
INSERT INTO leave_balances (employee_id, leave_type_id, year, quota_days, carried_days, used_days, carry_cycle_count) VALUES
('EMP-000002', 1, YEAR(CURDATE()), 30, 0, 1.0, 1), ('EMP-000002', 2, YEAR(CURDATE()), 6, 0, 1.0, 1), ('EMP-000002', 3, YEAR(CURDATE()), 10, 12.0, 2.0, 3),
('EMP-000004', 1, YEAR(CURDATE()), 30, 0, 0.0, 1), ('EMP-000004', 2, YEAR(CURDATE()), 6, 0, 0.0, 1), ('EMP-000004', 3, YEAR(CURDATE()), 10, 3.0, 1.0, 2),
('EMP-000005', 1, YEAR(CURDATE()), 30, 0, 0.0, 1), ('EMP-000005', 2, YEAR(CURDATE()), 6, 0, 0.0, 1), ('EMP-000005', 3, YEAR(CURDATE()), 10, 4.0, 1.0, 2),
('EMP-000006', 1, YEAR(CURDATE()), 30, 0, 0.0, 1), ('EMP-000006', 2, YEAR(CURDATE()), 6, 0, 1.0, 1), ('EMP-000006', 3, YEAR(CURDATE()), 10, 2.0, 0.0, 1),
('EMP-000007', 1, YEAR(CURDATE()), 30, 0, 2.0, 1), ('EMP-000007', 2, YEAR(CURDATE()), 6, 0, 0.0, 1), ('EMP-000007', 3, YEAR(CURDATE()), 10, 0.0, 3.0, 1),
('EMP-000008', 1, YEAR(CURDATE()), 30, 0, 0.0, 1), ('EMP-000008', 2, YEAR(CURDATE()), 6, 0, 0.0, 1), ('EMP-000008', 3, YEAR(CURDATE()), 10, 0.0, 1.0, 1),
('EMP-000010', 1, YEAR(CURDATE()), 30, 0, 0.0, 1), ('EMP-000010', 2, YEAR(CURDATE()), 6, 0, 0.0, 1), ('EMP-000010', 3, YEAR(CURDATE()), 10, 0.0, 0.0, 1);

-- 13. คำขอลางาน
INSERT INTO leave_requests (id, employee_id, leave_type_id, start_date, end_date, total_days, reason, attachment_url, current_step, total_steps, status, approved_by, approved_at) VALUES
(1, 'EMP-000007', 3, CAST(CONCAT(DATE_ADD(CURDATE(), INTERVAL 5 DAY), ' 09:00:00') AS DATETIME), CAST(CONCAT(DATE_ADD(CURDATE(), INTERVAL 5 DAY), ' 18:00:00') AS DATETIME), 1.0, 'พักผ่อนต่างจังหวัด (ไม่แนบเอกสาร)', NULL, 1, 4, 'pending', NULL, NULL),
(2, 'EMP-000008', 1, CAST(CONCAT(CURDATE(), ' 09:00:00') AS DATETIME), CAST(CONCAT(CURDATE(), ' 18:00:00') AS DATETIME), 1.0, 'มีไข้สูง พบแพทย์แล้ว', 'https://storage.example.com/hr-docs-bucket/leaves/med_cert_008.jpg', 3, 4, 'pending', NULL, NULL),
(3, 'EMP-000006', 2, CAST(CONCAT(DATE_SUB(CURDATE(), INTERVAL 3 DAY), ' 09:00:00') AS DATETIME), CAST(CONCAT(DATE_SUB(CURDATE(), INTERVAL 3 DAY), ' 18:00:00') AS DATETIME), 1.0, 'ติดต่อราชการที่เขต', NULL, 3, 3, 'approved', 'EMP-000002', NOW()),
(4, 'EMP-000005', 3, CAST(CONCAT(DATE_ADD(CURDATE(), INTERVAL 10 DAY), ' 09:00:00') AS DATETIME), CAST(CONCAT(DATE_ADD(CURDATE(), INTERVAL 11 DAY), ' 18:00:00') AS DATETIME), 2.0, 'ธุระครอบครัว', NULL, 2, 2, 'pending', NULL, NULL);

-- 14. ไฟล์แนบประกอบคำขอลางาน
INSERT INTO leave_attachments (leave_request_id, file_name, cloud_bucket, cloud_object_key, file_url, file_type, file_size, status) VALUES
(2, 'med_cert_008.jpg', 'hr-docs-bucket', 'leave_attachments/med_cert_008.jpg', 'https://storage.example.com/hr-docs-bucket/leaves/med_cert_008.jpg', 'image/jpeg', 245760, 1);

-- 15. ลำดับขั้นการอนุมัติลางาน
INSERT INTO leave_approvals (leave_request_id, step_order, approver_role, approver_id, status, comment, action_at) VALUES
(1, 1, 'supervisor', 'EMP-000006', 'pending', NULL, NULL),
(1, 2, 'manager', 'EMP-000005', 'waiting', NULL, NULL),
(1, 3, 'director', 'EMP-000003', 'waiting', NULL, NULL),
(1, 4, 'hr_admin', 'EMP-000002', 'waiting', NULL, NULL),

(2, 1, 'supervisor', 'EMP-000006', 'approved', 'รับทราบ หายไวๆ ครับ', DATE_SUB(NOW(), INTERVAL 3 HOUR)),
(2, 2, 'manager', 'EMP-000005', 'approved', 'อนุมัติ', DATE_SUB(NOW(), INTERVAL 1 HOUR)),
(2, 3, 'director', 'EMP-000003', 'pending', NULL, NULL),
(2, 4, 'hr_admin', 'EMP-000002', 'waiting', NULL, NULL),

(3, 1, 'manager', 'EMP-000005', 'approved', 'อนุมัติ', DATE_SUB(NOW(), INTERVAL 4 DAY)),
(3, 2, 'director', 'EMP-000003', 'approved', 'อนุมัติ', DATE_SUB(NOW(), INTERVAL 3 DAY)),
(3, 3, 'hr_admin', 'EMP-000002', 'approved', 'บันทึกตัดวันลาเรียบร้อย', DATE_SUB(NOW(), INTERVAL 3 DAY)),

(4, 1, 'director', 'EMP-000003', 'approved', 'อนุมัติตามเสนอ', DATE_SUB(NOW(), INTERVAL 2 HOUR)),
(4, 2, 'hr_admin', 'EMP-000002', 'pending', NULL, NULL);

-- 16. ประวัติยกยอดวันลา
INSERT INTO leave_reset_logs (company_id, from_year, to_year, processed_employees, executed_by) VALUES
(NULL, YEAR(CURDATE()) - 1, YEAR(CURDATE()), 7, 'EMP-000002');

-- 17. คำขอทำงานล่วงเวลา (OT)
INSERT INTO ot_requests (id, employee_id, ot_date, start_time, end_time, ot_type, hours, raw_hours, net_hours, lunch_deducted, total_pay, ot_rate, reason, attachment_url, current_step, total_steps, status, approved_by) VALUES
(1, 'EMP-000008', DATE_SUB(CURDATE(), INTERVAL 1 DAY), '18:00:00', '20:00:00', 'workday_post', 2.00, 2.00, 2.00, 0, 437.50, '1.5', 'Deploy ระบบขึ้น Production รอบค่ำ', NULL, 2, 4, 'pending', NULL),
(2, 'EMP-000007', DATE_SUB(CURDATE(), INTERVAL 5 DAY), '09:00:00', '18:00:00', 'holiday', 8.00, 9.00, 8.00, 1, 1866.67, '2.0', 'ออกแบบแคมเปญด่วนวันหยุด', 'https://storage.example.com/hr-docs-bucket/ot/brief_007.pdf', 4, 4, 'approved', 'EMP-000002');

-- 18. ลำดับขั้นการอนุมัติ OT
INSERT INTO ot_approvals (ot_request_id, step_order, approver_role, approver_id, status, comment, action_at) VALUES
(1, 1, 'supervisor', 'EMP-000006', 'approved', 'อยู่ทำงานจริงตามเวลาสแกน', DATE_SUB(NOW(), INTERVAL 2 HOUR)),
(1, 2, 'manager', 'EMP-000005', 'pending', NULL, NULL),
(1, 3, 'director', 'EMP-000003', 'waiting', NULL, NULL),
(1, 4, 'hr_admin', 'EMP-000002', 'waiting', NULL, NULL),

(2, 1, 'supervisor', 'EMP-000006', 'approved', 'รับทราบงานเสร็จเรียบร้อย', DATE_SUB(NOW(), INTERVAL 4 DAY)),
(2, 2, 'manager', 'EMP-000005', 'approved', 'อนุมัติ', DATE_SUB(NOW(), INTERVAL 4 DAY)),
(2, 3, 'director', 'EMP-000003', 'approved', 'อนุมัติ', DATE_SUB(NOW(), INTERVAL 3 DAY)),
(2, 4, 'hr_admin', 'EMP-000002', 'approved', 'คำนวณเข้า Payroll แล้ว', DATE_SUB(NOW(), INTERVAL 3 DAY));

-- 19. วันหยุดประจำปี
INSERT INTO holidays (company_id, holiday_date, holiday_name, status) VALUES
(NULL, CONCAT(YEAR(CURDATE()), '-01-01'), 'วันขึ้นปีใหม่', 1),
(NULL, CONCAT(YEAR(CURDATE()), '-04-13'), 'วันสงกรานต์', 1),
(NULL, CONCAT(YEAR(CURDATE()), '-04-14'), 'วันสงกรานต์', 1),
(NULL, CONCAT(YEAR(CURDATE()), '-05-01'), 'วันแรงงานแห่งชาติ', 1),
(NULL, CONCAT(YEAR(CURDATE()), '-12-05'), 'วันพ่อแห่งชาติ', 1),
(NULL, CONCAT(YEAR(CURDATE()), '-12-10'), 'วันรัฐธรรมนูญ', 1),
(2, CONCAT(YEAR(CURDATE()), '-12-31'), 'วันสิ้นปี (หยุดเพิ่มเติมเฉพาะ SUB1)', 1);

-- 20. งวดการจ่ายเงินเดือน
INSERT INTO payroll_periods (id, company_id, period_name, start_date, end_date, pay_date, status) VALUES
('VNGS-000001', 1, 'งวดประจำเดือนปัจจุบัน (VNGS)', DATE_FORMAT(CURDATE(), '%Y-%m-01'), LAST_DAY(CURDATE()), LAST_DAY(CURDATE()), 'calculated'),
('SUB1-000001', 2, 'งวดประจำเดือนปัจจุบัน (SUB1)', DATE_FORMAT(CURDATE(), '%Y-%m-01'), LAST_DAY(CURDATE()), LAST_DAY(CURDATE()), 'draft');

-- 21. รายการเงินเดือนรายบุคคล
INSERT INTO payroll_items (period_id, employee_id, base_salary, ot_amount, allowance, deduction_late_leave, social_security, withholding_tax, net_salary, payslip_pdf_url) VALUES
('VNGS-000001', 'EMP-000001', 95000.00, 0.00, 5000.00, 0.00, 750.00, 9500.00, 89750.00, 'https://storage.example.com/payslips/VNGS-000001_EMP-000001.pdf'),
('VNGS-000001', 'EMP-000002', 52000.00, 0.00, 2000.00, 0.00, 750.00, 2100.00, 51150.00, 'https://storage.example.com/payslips/VNGS-000001_EMP-000002.pdf'),
('VNGS-000001', 'EMP-000003', 75000.00, 0.00, 3000.00, 0.00, 750.00, 5800.00, 71450.00, 'https://storage.example.com/payslips/VNGS-000001_EMP-000003.pdf'),
('VNGS-000001', 'EMP-000004', 32000.00, 0.00, 1000.00, 0.00, 750.00, 450.00, 31800.00, 'https://storage.example.com/payslips/VNGS-000001_EMP-000004.pdf'),
('SUB1-000001', 'EMP-000005', 48000.00, 0.00, 2000.00, 0.00, 750.00, 1800.00, 47450.00, NULL),
('SUB1-000001', 'EMP-000006', 38000.00, 0.00, 1500.00, 0.00, 750.00, 850.00, 37900.00, NULL),
('SUB1-000001', 'EMP-000007', 28000.00, 1866.67, 1000.00, 0.00, 750.00, 200.00, 29916.67, NULL),
('SUB1-000001', 'EMP-000008', 35000.00, 0.00, 1000.00, 0.00, 750.00, 600.00, 34650.00, NULL),
('SUB1-000001', 'EMP-000010', 33000.00, 0.00, 1000.00, 0.00, 750.00, 500.00, 32750.00, NULL);

-- 22. ประวัติส่งออก Payroll Outsource
INSERT INTO payroll_outsourcing_logs (period_id, provider_name, exported_by, file_url, status) VALUES
('VNGS-000001', 'Tiger Payroll Outsourcing', 'EMP-000002', 'https://storage.example.com/exports/VNGS-000001_tiger.xlsx', 'exported');

-- 23. บันทึกประวัติการใช้งานระบบ (Audit Logs)
INSERT INTO audit_logs (actor_user_id, actor_employee_id, action, entity_type, entity_id, metadata) VALUES
(2, 'EMP-000002', 'LEAVE_APPROVED', 'leave_requests', '3', JSON_OBJECT('step', 3, 'employee_id', 'EMP-000006', 'total_days', 1.0)),
(2, 'EMP-000002', 'PAYROLL_EXPORTED', 'payroll_periods', 'VNGS-000001', JSON_OBJECT('provider', 'Tiger Payroll Outsourcing', 'status', 'exported')),
(1, 'EMP-000001', 'USER_LOGIN', 'users', '1', JSON_OBJECT('role', 'admin', 'ip', '127.0.0.1')),
(2, 'EMP-000002', 'EMPLOYEE_TRANSFERRED', 'employees', 'EMP-000010', JSON_OBJECT('from_employee_id', 'EMP-000009', 'from_emp_code', 'VNGS-005', 'to_emp_code', 'SUB1-005', 'from_company_id', 1, 'to_company_id', 2));

SET FOREIGN_KEY_CHECKS = 1;