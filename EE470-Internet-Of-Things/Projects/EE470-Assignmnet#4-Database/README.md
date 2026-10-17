# EE470-ECE570 — Database Assignment, Part 1

Name: Hamza Alshamasneh
Sonoma State University

## Overview

This assignment uses Hostinger, MariaDB, and phpMyAdmin to
create and manage relational database tables.

The work includes Book and Price tables, sensor registration
and measurement tables, combined views, and a foreign key
test that rejects readings from an unregistered sensor.

## Files

- 01_book_initial.sql:
  Creates the original Book table with two records.

- 02_book_and_price.sql:
  Updates Book to five records, creates Price, and joins
  book names with their matching prices.

- 03_book_prices_view.sql:
  Saves the Book and Price join as the Book_Prices view.

- 04_sensor_database.sql:
  Creates sensor_register and sensor_data, registers five
  sensors, inserts twenty readings, and creates the
  sensor_combined view.

- 05_unregistered_sensor_test.sql:
  Attempts an insertion from node_6.
  Foreign key error #1452 is the expected result because
  node_6 has not been registered.

- IoT.html:
  Contains the HTML and CSS for the main course webpage.

## Database setup

The scripts select the Hostinger database:
u288531001_HamzaAlshamasn

When using a different database, update the USE statement
in each SQL file.

To rebuild from an empty assignment database, run files
01 through 04 in numerical order.

Run file 05 separately to demonstrate rejection of an
unregistered sensor.

The setup scripts are intended to run once. Rerunning them
on an existing setup can produce errors for existing tables,
views, or duplicate primary keys.

## Sensor data

The sensor coordinates, temperatures, and humidity values
are sample data for the assignment, not actual measurements.

Five sensors are registered, each with a different manufacturer.

Each sensor has four readings starting on October 1, 2022,
at 11:00 AM, with thirty minutes between readings.

Temperature is restricted to -10 through 100 degrees Celsius.
Humidity is restricted to 0 through 100 percent.

## Course webpage

https://hamzaalshamasneh.com/IoT.html
