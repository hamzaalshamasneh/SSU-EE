-- Select the database created through Hostinger.
USE u288531001_HamzaAlshamasn;

-- Create a table with an automatically generated primary key.
CREATE TABLE Book (
    id INT NOT NULL AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    PRIMARY KEY (id)
);

-- Insert the two original books.
INSERT INTO Book (id, name)
VALUES
    (1, 'MariaDB Book'),
    (2, 'SQLDB Book');

-- Display the original Book table.
SELECT *
FROM Book
ORDER BY id;
