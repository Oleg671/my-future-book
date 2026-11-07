# Книги с анекдотами

Учебная версия книжного магазина на PHP 8.3 и MySQL 8.4.

## Запуск

```bash
docker compose up --build -d
```

Сайт: http://localhost:8081

Учётные записи:

- администратор: `admin@myfuturebook.local` / `admin123`;
- пользователь: `user@myfuturebook.local` / `user123`.

## Вход в панель администратора

1. Запустите проект командой `docker compose up --build -d`.
2. Откройте http://localhost:8081/?page=login.
3. Введите e-mail `admin@myfuturebook.local` и пароль `admin123`.
4. После входа откроется страница управления книгами.

Прямые адреса административных страниц:

- управление книгами: http://localhost:8081/?page=admin-books;
- аренды и напоминания: http://localhost:8081/?page=admin-rentals.

## Структура

- `src/public/index.php` — страницы и обработка действий;
- `src/public/style.css` — оформление;
- `src/functions.php` — подключение к БД и общие функции;
- `init.sql` — таблицы, начальные книги и процедура напоминаний;
- `Dockerfile`, `docker-compose.yml` — локальный запуск;
- `REPORT.md` — анализ выполненной задачи.