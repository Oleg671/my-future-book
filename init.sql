SET NAMES utf8mb4;

CREATE TABLE users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(190) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('user', 'admin') NOT NULL DEFAULT 'user',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE books (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(200) NOT NULL,
    author VARCHAR(150) NOT NULL,
    category VARCHAR(100) NOT NULL,
    written_year SMALLINT UNSIGNED NOT NULL,
    description TEXT NOT NULL,
    content TEXT NOT NULL,
    status ENUM('available', 'unavailable') NOT NULL DEFAULT 'available',
    quantity INT UNSIGNED NOT NULL DEFAULT 1,
    purchase_price DECIMAL(10,2) UNSIGNED NOT NULL,
    rent_price_2w DECIMAL(10,2) UNSIGNED NOT NULL,
    rent_price_1m DECIMAL(10,2) UNSIGNED NOT NULL,
    rent_price_3m DECIMAL(10,2) UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_books_category (category),
    INDEX idx_books_author (author),
    INDEX idx_books_year (written_year)
);

CREATE TABLE book_orders (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    book_id INT UNSIGNED NOT NULL,
    order_type ENUM('purchase', 'rental') NOT NULL,
    rental_period ENUM('2w', '1m', '3m') NULL,
    start_date DATE NOT NULL,
    due_date DATE NULL,
    price DECIMAL(10,2) UNSIGNED NOT NULL,
    status ENUM('active', 'completed') NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_orders_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_orders_book FOREIGN KEY (book_id) REFERENCES books(id) ON DELETE RESTRICT,
    INDEX idx_orders_user (user_id, created_at),
    INDEX idx_orders_due (due_date, status)
);

CREATE TABLE reminders (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    message VARCHAR(255) NOT NULL,
    reminder_date DATE NOT NULL,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_reminders_order FOREIGN KEY (order_id) REFERENCES book_orders(id) ON DELETE CASCADE,
    CONSTRAINT fk_reminders_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY uq_order_reminder_day (order_id, reminder_date)
);

INSERT INTO books
    (title, author, category, written_year, description, content, status, quantity,
     purchase_price, rent_price_2w, rent_price_1m, rent_price_3m)
VALUES
    ('test1', 'test1', 'test1', 2021, 'test1', 'test1',
     'available', 1, 100.00, 10.00, 20.00, 30.00),
    ('test2', 'test2', 'test2', 2022, 'test2', 'test2',
     'available', 2, 100.00, 10.00, 20.00, 30.00),
    ('test3', 'test3', 'test3', 2023, 'test3', 'test3',
     'available', 3, 100.00, 10.00, 20.00, 30.00),
    ('test4', 'test4', 'test4', 2024, 'test4', 'test4',
     'available', 4, 100.00, 10.00, 20.00, 30.00),
    ('test5', 'test5', 'test5', 2025, 'test5', 'test5',
     'available', 5, 100.00, 10.00, 20.00, 30.00);

DELIMITER //
CREATE PROCEDURE create_rental_reminders()
BEGIN
    INSERT IGNORE INTO reminders (order_id, user_id, message, reminder_date)
    SELECT o.id, o.user_id,
           CASE
               WHEN o.due_date < CURRENT_DATE THEN CONCAT('Срок аренды книги «', b.title, '» истёк ', DATE_FORMAT(o.due_date, '%d.%m.%Y'))
               ELSE CONCAT('Верните книгу «', b.title, '» до ', DATE_FORMAT(o.due_date, '%d.%m.%Y'))
           END,
           CURRENT_DATE
    FROM book_orders o
    JOIN books b ON b.id = o.book_id
    WHERE o.order_type = 'rental'
      AND o.status = 'active'
      AND o.due_date <= DATE_ADD(CURRENT_DATE, INTERVAL 3 DAY);
END//

CREATE EVENT daily_rental_reminders
ON SCHEDULE EVERY 1 DAY
STARTS CURRENT_TIMESTAMP + INTERVAL 1 MINUTE
DO CALL create_rental_reminders()//
DELIMITER ;

