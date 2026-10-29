-- REFERENCE ONLY. The app creates and verifies these tables itself on every request
-- (includes/schema.php -> ensure_schema), and /setup_db.php shows the result.
-- Handy if you ever want to create them by hand.

CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(150) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,            -- password_hash() output
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS bikes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    name VARCHAR(100) NOT NULL,
    model VARCHAR(100) NOT NULL DEFAULT '',
    reg_no VARCHAR(30) NOT NULL DEFAULT '',
    odometer INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_bike_user (user_id),
    CONSTRAINT fk_bike_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- A reminder = "do X every N km and/or every N days". last_done_* is the baseline the next due date is counted from.
CREATE TABLE IF NOT EXISTS reminders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    bike_id INT NOT NULL,
    task VARCHAR(100) NOT NULL,
    interval_km INT NULL,
    interval_days INT NULL,
    last_done_date DATE NOT NULL,
    last_done_km INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_rem_bike (bike_id),
    CONSTRAINT fk_rem_bike FOREIGN KEY (bike_id) REFERENCES bikes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS services (
    id INT AUTO_INCREMENT PRIMARY KEY,
    bike_id INT NOT NULL,
    reminder_id INT NULL,
    task VARCHAR(100) NOT NULL,
    service_date DATE NOT NULL,
    odometer INT NOT NULL DEFAULT 0,
    cost DECIMAL(10,2) NULL,
    notes VARCHAR(500) NOT NULL DEFAULT '',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_srv_bike (bike_id, service_date),
    CONSTRAINT fk_srv_bike FOREIGN KEY (bike_id) REFERENCES bikes(id) ON DELETE CASCADE,
    CONSTRAINT fk_srv_rem  FOREIGN KEY (reminder_id) REFERENCES reminders(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
