-- Create admin table
CREATE TABLE admin (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL
);

-- Insert default admin credentials
INSERT INTO admin (username, password) 
VALUES ('admin', SHA2('admin', 256)); -- Using SHA-256 for hashing

-- Create parking_slots table
CREATE TABLE parking_slots (
    id INT AUTO_INCREMENT PRIMARY KEY,
    slot_number INT NOT NULL UNIQUE,
    vehicle_reg_number VARCHAR(15),
    vehicle_type ENUM('2-wheeler', '4-wheeler'),
    in_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    out_time TIMESTAMP NULL,
    status ENUM('occupied', 'unoccupied') DEFAULT 'unoccupied'
);

-- Insert initial slots (1 to 50)
INSERT INTO parking_slots (slot_number, status) 
SELECT n, 'unoccupied'
FROM (
    SELECT ROW_NUMBER() OVER () AS n
    FROM information_schema.tables 
    LIMIT 50
) AS numbers;

-- Optional: Create settings table
CREATE TABLE settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    `key` VARCHAR(50) UNIQUE NOT NULL,
    `value` VARCHAR(50) NOT NULL
);

-- Insert default total slots
INSERT INTO settings (`key`, `value`) 
VALUES ('total_slots', '50');
