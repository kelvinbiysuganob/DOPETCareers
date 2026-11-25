-- DOPET Careers database. In phpMyAdmin: select YOUR database, open the SQL (or Import) tab, paste/run this file.
-- (On free/shared hosting the database already exists, so there is no CREATE DATABASE here.)

CREATE TABLE IF NOT EXISTS users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  full_name VARCHAR(120) NOT NULL,
  email VARCHAR(160) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('applicant','admin') NOT NULL DEFAULT 'applicant',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS profiles (
  user_id INT PRIMARY KEY,
  phone VARCHAR(40), location VARCHAR(120), headline VARCHAR(160),
  skills TEXT, about TEXT,
  cv_path VARCHAR(255), cv_name VARCHAR(200), cv_size INT,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS jobs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(160) NOT NULL, dept VARCHAR(80), location VARCHAR(120),
  type VARCHAR(30), description TEXT,
  is_open TINYINT(1) NOT NULL DEFAULT 1,
  posted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS applications (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  position VARCHAR(160) NOT NULL,
  position_key VARCHAR(160) NOT NULL,
  UNIQUE KEY uq_user_position (user_id, position_key),
  phone VARCHAR(40), address VARCHAR(255), education VARCHAR(160),
  experience_years TINYINT UNSIGNED, expected_salary VARCHAR(60), note TEXT,
  cv_path VARCHAR(255), cv_name VARCHAR(200),
  status ENUM('Submitted','Under review','Interview','Offer','Rejected') NOT NULL DEFAULT 'Submitted',
  applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- AFTER you register your own account on the website, run this to make it the staff/admin account
-- (replace the email), then log in from the "Staff" menu:
-- UPDATE users SET role='admin' WHERE email='your-email@example.com';
