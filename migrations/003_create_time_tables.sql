CREATE TABLE holidays (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    holiday_date DATE NOT NULL,
    name VARCHAR(150) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_holidays_date (holiday_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE attendance_records (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    employee_id INT UNSIGNED NOT NULL,
    work_date DATE NOT NULL,
    status ENUM('present', 'leave', 'sick', 'absent') NOT NULL,
    clock_in TIME NULL,
    clock_out TIME NULL,
    note VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_attendance_employee_date (employee_id, work_date),
    KEY idx_attendance_date_status (work_date, status),
    CONSTRAINT fk_attendance_employee FOREIGN KEY (employee_id) REFERENCES employees (id),
    CONSTRAINT chk_attendance_clock_order CHECK (clock_in IS NULL OR clock_out IS NULL OR clock_out > clock_in),
    CONSTRAINT chk_attendance_clock_out_needs_in CHECK (clock_out IS NULL OR clock_in IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE overtime_requests (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    employee_id INT UNSIGNED NOT NULL,
    work_date DATE NOT NULL,
    minutes SMALLINT UNSIGNED NOT NULL,
    reason VARCHAR(500) NOT NULL,
    status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
    decided_by INT UNSIGNED NULL,
    decided_at DATETIME NULL,
    decision_note VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_overtime_employee_date (employee_id, work_date),
    KEY idx_overtime_date_status (work_date, status),
    CONSTRAINT fk_overtime_employee FOREIGN KEY (employee_id) REFERENCES employees (id),
    CONSTRAINT fk_overtime_decided_by FOREIGN KEY (decided_by) REFERENCES users (id),
    CONSTRAINT chk_overtime_minutes CHECK (minutes > 0 AND minutes <= 720)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
