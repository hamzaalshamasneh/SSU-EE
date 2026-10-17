-- EE470-ECE570: Database Assignment, Part 1
-- Save the Book and Price join as a reusable view.

-- Select the assignment database.
USE u288531001_HamzaAlshamasn;

-- A view stores a query that reads from the original tables.
CREATE VIEW Book_Prices AS
SELECT Book.name, Price.price
FROM Book
INNER JOIN Price
    ON Book.id = Price.id
ORDER BY Book.id;

-- Display the saved view.
SELECT *
FROM Book_Prices;
