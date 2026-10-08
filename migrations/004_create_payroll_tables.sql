CREATE TABLE bpjs_settings (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    program ENUM('kesehatan', 'jht', 'jp', 'jkk', 'jkm') NOT NULL,
    employee_rate_bp SMALLINT UNSIGNED NOT NULL,
    employer_rate_bp SMALLINT UNSIGNED NOT NULL,
    wage_cap BIGINT UNSIGNED NULL,
    effective_from DATE NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_bpjs_program_effective (program, effective_from),
    CONSTRAINT chk_bpjs_rates CHECK (employee_rate_bp <= 10000 AND employer_rate_bp <= 10000),
    CONSTRAINT chk_bpjs_cap CHECK (wage_cap IS NULL OR wage_cap > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE payroll_periods (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    period CHAR(7) NOT NULL,
    status ENUM('draft', 'calculated', 'approved', 'paid') NOT NULL DEFAULT 'draft',
    created_by INT UNSIGNED NOT NULL,
    calculated_by INT UNSIGNED NULL,
    calculated_at DATETIME NULL,
    calculation_note VARCHAR(255) NULL,
    approved_by INT UNSIGNED NULL,
    approved_at DATETIME NULL,
    paid_by INT UNSIGNED NULL,
    paid_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_payroll_periods_period (period),
    KEY idx_payroll_periods_status (status),
    CONSTRAINT fk_periods_created_by FOREIGN KEY (created_by) REFERENCES users (id),
    CONSTRAINT fk_periods_calculated_by FOREIGN KEY (calculated_by) REFERENCES users (id),
    CONSTRAINT fk_periods_approved_by FOREIGN KEY (approved_by) REFERENCES users (id),
    CONSTRAINT fk_periods_paid_by FOREIGN KEY (paid_by) REFERENCES users (id),
    CONSTRAINT chk_periods_format CHECK (period REGEXP '^[0-9]{4}-(0[1-9]|1[0-2])$'),
    CONSTRAINT chk_periods_segregation CHECK (approved_by IS NULL OR approved_by <> calculated_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE payslips (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    payroll_period_id INT UNSIGNED NOT NULL,
    employee_id INT UNSIGNED NOT NULL,
    employee_number VARCHAR(20) NOT NULL,
    employee_name VARCHAR(150) NOT NULL,
    department_name VARCHAR(100) NOT NULL,
    gross_pay BIGINT NOT NULL,
    total_deductions BIGINT NOT NULL,
    net_pay BIGINT NOT NULL,
    employer_contributions BIGINT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_payslips_period_employee (payroll_period_id, employee_id),
    KEY idx_payslips_employee (employee_id),
    CONSTRAINT fk_payslips_period FOREIGN KEY (payroll_period_id) REFERENCES payroll_periods (id),
    CONSTRAINT fk_payslips_employee FOREIGN KEY (employee_id) REFERENCES employees (id),
    CONSTRAINT chk_payslips_net CHECK (net_pay = gross_pay - total_deductions)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE payslip_lines (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    payslip_id BIGINT UNSIGNED NOT NULL,
    line_type ENUM('earning', 'deduction', 'employer_contribution') NOT NULL,
    code VARCHAR(50) NOT NULL,
    label VARCHAR(100) NOT NULL,
    amount BIGINT UNSIGNED NOT NULL,
    basis VARCHAR(150) NOT NULL DEFAULT '',
    sort_order SMALLINT UNSIGNED NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_payslip_lines_code (payslip_id, code),
    CONSTRAINT fk_payslip_lines_payslip FOREIGN KEY (payslip_id) REFERENCES payslips (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE idempotency_keys (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NOT NULL,
    idempotency_key VARCHAR(255) NOT NULL,
    request_hash CHAR(64) NOT NULL,
    response_status SMALLINT UNSIGNED NULL,
    response_body MEDIUMTEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_idempotency_user_key (user_id, idempotency_key),
    CONSTRAINT fk_idempotency_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
