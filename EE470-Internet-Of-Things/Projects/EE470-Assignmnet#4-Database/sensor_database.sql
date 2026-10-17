-- EE470-ECE570: Database Assignment, Part 1
-- Section 1.3: Sensor registration, readings, and combined view.
-- Run only when these sensor tables and view do not already exist.

-- Select the assignment database.
USE u288531001_HamzaAlshamasn;


-- =========================================================
-- 1. CREATE THE SENSOR REGISTRATION TABLE
-- =========================================================

-- Store the name, manufacturer, and location of each sensor.
CREATE TABLE sensor_register (
    node_name VARCHAR(10) NOT NULL,
    manufacturer VARCHAR(10) NOT NULL,
    longitude DECIMAL(15,10) NOT NULL,
    Latitude DECIMAL(15,10) NOT NULL,

    -- Each sensor can be registered only once.
    PRIMARY KEY (node_name)
) ENGINE=InnoDB;


-- =========================================================
-- 2. CREATE THE SENSOR READINGS TABLE
-- =========================================================

-- Store the measurements received from registered sensors.
CREATE TABLE sensor_data (
    node_name VARCHAR(10) NOT NULL,
    time_received DATETIME NOT NULL,
    temperature DECIMAL(6,2) NOT NULL,
    humidity DECIMAL(6,2) NOT NULL,

    -- Allow multiple readings per sensor at different times.
    -- Reject a duplicate timestamp for the same sensor.
    PRIMARY KEY (node_name, time_received),

    -- Only accept readings from a registered sensor.
    CONSTRAINT fk_sensor_node
        FOREIGN KEY (node_name)
        REFERENCES sensor_register (node_name),

    -- Accept temperatures from -10 through 100 degrees Celsius.
    CONSTRAINT chk_temperature
        CHECK (temperature BETWEEN -10 AND 100),

    -- Accept humidity values from 0 through 100 percent.
    CONSTRAINT chk_humidity
        CHECK (humidity BETWEEN 0 AND 100)
) ENGINE=InnoDB;


-- =========================================================
-- 3. REGISTER FIVE SENSORS
-- =========================================================

-- Use five different manufacturer names.
-- The coordinates are sample values for this assignment.
-- They are not verified locations of actual sensors.
INSERT INTO sensor_register
    (node_name, manufacturer, longitude, Latitude)
VALUES
    ('node_1', 'Bosch',     -122.7000000000, 38.4400000000),
    ('node_2', 'Honeywell', -122.7100000000, 38.4500000000),
    ('node_3', 'Sensirion', -122.7200000000, 38.4600000000),
    ('node_4', 'Siemens',   -122.7300000000, 38.4700000000),
    ('node_5', 'TI',        -122.7400000000, 38.4800000000);


-- =========================================================
-- 4. INSERT TWENTY SENSOR READINGS
-- =========================================================

-- Add four readings for each of the five sensors.
-- Begin October 1, 2022, at 11:00 AM.
-- Space each sensor's readings thirty minutes apart.
-- Temperatures and humidity values are sample measurements.
INSERT INTO sensor_data
    (node_name, time_received, temperature, humidity)
VALUES
    -- Four readings from node_1.
    ('node_1', '2022-10-01 11:00:00', 21.50, 45.00),
    ('node_1', '2022-10-01 11:30:00', 22.00, 46.00),
    ('node_1', '2022-10-01 12:00:00', 22.50, 47.00),
    ('node_1', '2022-10-01 12:30:00', 23.00, 48.00),

    -- Four readings from node_2.
    ('node_2', '2022-10-01 11:00:00', 19.00, 50.00),
    ('node_2', '2022-10-01 11:30:00', 19.50, 51.00),
    ('node_2', '2022-10-01 12:00:00', 20.00, 52.00),
    ('node_2', '2022-10-01 12:30:00', 20.50, 53.00),

    -- Four readings from node_3.
    ('node_3', '2022-10-01 11:00:00', 24.00, 40.00),
    ('node_3', '2022-10-01 11:30:00', 24.50, 41.00),
    ('node_3', '2022-10-01 12:00:00', 25.00, 42.00),
    ('node_3', '2022-10-01 12:30:00', 25.50, 43.00),

    -- Four readings from node_4.
    ('node_4', '2022-10-01 11:00:00', 17.00, 60.00),
    ('node_4', '2022-10-01 11:30:00', 17.50, 61.00),
    ('node_4', '2022-10-01 12:00:00', 18.00, 62.00),
    ('node_4', '2022-10-01 12:30:00', 18.50, 63.00),

    -- Four readings from node_5.
    ('node_5', '2022-10-01 11:00:00', 26.00, 35.00),
    ('node_5', '2022-10-01 11:30:00', 26.50, 36.00),
    ('node_5', '2022-10-01 12:00:00', 27.00, 37.00),
    ('node_5', '2022-10-01 12:30:00', 27.50, 38.00);


-- =========================================================
-- 5. CREATE THE COMBINED SENSOR VIEW
-- =========================================================

-- Match each reading to its sensor registration.
-- The aliases r and d shorten the table references.
CREATE VIEW sensor_combined AS
SELECT
    r.node_name,
    r.manufacturer,
    r.longitude,
    r.Latitude,
    d.time_received,
    d.temperature,
    d.humidity
FROM sensor_register AS r
INNER JOIN sensor_data AS d
    ON r.node_name = d.node_name;


-- =========================================================
-- 6. DISPLAY THE COMBINED RESULTS
-- =========================================================

-- Show all twenty readings, sorted by sensor name and time.
SELECT *
FROM sensor_combined
ORDER BY node_name, time_received;
