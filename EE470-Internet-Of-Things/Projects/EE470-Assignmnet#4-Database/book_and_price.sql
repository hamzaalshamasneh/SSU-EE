-- EE470-ECE570: Database Assignment, Part 1
-- Section 1.2: Update Book and create the Price table.
-- Run after 01_book_initial.sql when rebuilding the database.

-- Select the assignment database.
USE u288531001_HamzaAlshamasn;

-- Update the names of the two existing books.
UPDATE Book
SET name = 'MariaDB Book1a'
WHERE id = 1;

UPDATE Book
SET name = 'MariaDB Book2'
WHERE id = 2;

-- Add the remaining three books.
INSERT INTO Book (id, name)
VALUES
    (3, 'MariaDB Book3'),
    (4, 'MariaDB Book4'),
    (5, 'MariaDB Book5');

-- Display the updated Book table.
SELECT *
FROM Book
ORDER BY id;

-- Create a separate table for book prices.
CREATE TABLE Price (
    id INT NOT NULL AUTO_INCREMENT,
    price FLOAT NOT NULL,
    PRIMARY KEY (id)
);

-- Match each price to the book with the same ID.
INSERT INTO Price (id, price)
VALUES
    (1, 250),
    (2, 252),
    (3, 223),
    (4, 194),
    (5, 305);

-- Display the Price table separately.
SELECT *
FROM Price
ORDER BY id;

-- Combine book names and prices by matching their IDs.
-- This SELECT displays results without changing either table.
SELECT Book.name, Price.price
FROM Book
INNER JOIN Price
    ON Book.id = Price.id
ORDER BY Book.id;
