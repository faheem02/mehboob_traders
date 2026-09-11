-- Seed default admin user (password: admin123)
-- Usage: mysql -u root < seed_admin.sql
USE mehboob_traders;

INSERT INTO branches (id, name, phone, status, created_at) VALUES
(1, 'Head Office', '0300-1234567', 1, CURDATE());

INSERT INTO users (username, password, full_name, role, branch_id, status, created_at) VALUES
('admin', 'admin123', 'Administrator', 'admin', 1, 1, CURDATE());

INSERT INTO bank_accounts (account_name, bank_name, account_no, account_type, opening_balance, current_balance, status, created_at) VALUES
('Default Account', 'Default Bank', 'BNK-DEFAULT', 'current', 0.00, 0.00, 1, CURDATE());

INSERT INTO expense_categories (name, description, status, created_at) VALUES
('Rent', 'Shop/office rent', 1, CURDATE()),
('Electricity', 'Utility bills', 1, CURDATE()),
('Transport', 'Loading/transport charges', 1, CURDATE()),
('Staff Salary', 'Employee salaries', 1, CURDATE()),
('Misc', 'Other expenses', 1, CURDATE());