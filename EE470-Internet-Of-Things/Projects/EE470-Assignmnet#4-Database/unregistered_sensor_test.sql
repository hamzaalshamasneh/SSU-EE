-- EE470-ECE570: Database Assignment, Part 1
-- Test that an unregistered sensor cannot submit a reading.
-- Run separately after creating the sensor tables.
-- Keep foreign key checks enabled.

-- Select the assignment database.
USE u288531001_HamzaAlshamasn;

-- node_6 is not present in sensor_register.
-- This insertion should fail with foreign key error #1452.
-- A successful rejection means this reading is not stored.
INSERT INTO sensor_data
    (node_name, time_received, temperature, humidity)
VALUES
    ('node_6', '2022-10-01 11:00:00', 25.00, 50.00);
