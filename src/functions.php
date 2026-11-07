<?php
declare(strict_types=1);

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $host = getenv('DB_HOST') ?: 'db';
    $name = getenv('DB_NAME') ?: 'my_future_book';
    $user = getenv('DB_USER') ?: 'book_user';
    $password = getenv('DB_PASSWORD') ?: 'book_password';

    for ($attempt = 0; $attempt < 15; $attempt++) {
        try {
            $pdo = new PDO(
                "mysql:host={$host};dbname={$name};charset=utf8mb4",
                $user,
                $password,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]
            );
            break;
        } catch (PDOException $exception) {
            if ($attempt === 14) {
                throw $exception;
            }
            sleep(1);
        }
    }

    return $pdo;
}

function bootstrap_users(): void
{
    $users = [
        ['Администратор', 'admin@myfuturebook.local', 'admin123', 'admin'],
        ['Читатель', 'user@myfuturebook.local', 'user123', 'user'],
    ];
    $check = db()->prepare('SELECT id FROM users WHERE email = ?');
    $insert = db()->prepare('INSERT INTO users (name, email, password_hash, role) VALUES (?, ?, ?, ?)');
    foreach ($users as [$name, $email, $password, $role]) {
        $check->execute([$email]);
        if (!$check->fetchColumn()) {
            $insert->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT), $role]);
        }
    }
}

function create_due_reminders(): void
{
    $statement = db()->prepare('CALL create_rental_reminders()');
    $statement->execute();
    $statement->closeCursor();
}

function user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function is_admin(): bool
{
    return (user()['role'] ?? '') === 'admin';
}

function require_login(): void
{
    if (!user()) {
        flash('Войдите в систему, чтобы продолжить.', 'error');
        redirect('?page=login');
    }
}

function require_admin(): void
{
    if (!is_admin()) {
        http_response_code(403);
        exit('Доступ запрещён');
    }
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(24));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf(): void
{
    if (!hash_equals($_SESSION['csrf_token'] ?? '', (string)($_POST['csrf_token'] ?? ''))) {
        http_response_code(419);
        exit('Форма устарела. Обновите страницу.');
    }
}

function h(string|int|float|null $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function money(string|float $value): string
{
    return number_format((float)$value, 2, ',', ' ') . ' ₽';
}

function redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}

function flash(string $message, string $type = 'success'): void
{
    $_SESSION['flash'] = ['message' => $message, 'type' => $type];
}

function take_flash(): ?array
{
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $flash;
}

function valid_book_data(array $source): array
{
    $data = [
        'title' => trim((string)($source['title'] ?? '')),
        'author' => trim((string)($source['author'] ?? '')),
        'category' => trim((string)($source['category'] ?? '')),
        'written_year' => (int)($source['written_year'] ?? 0),
        'description' => trim((string)($source['description'] ?? '')),
        'content' => trim((string)($source['content'] ?? '')),
        'status' => (string)($source['status'] ?? 'available'),
        'quantity' => (int)($source['quantity'] ?? -1),
        'purchase_price' => (float)($source['purchase_price'] ?? -1),
        'rent_price_2w' => (float)($source['rent_price_2w'] ?? -1),
        'rent_price_1m' => (float)($source['rent_price_1m'] ?? -1),
        'rent_price_3m' => (float)($source['rent_price_3m'] ?? -1),
    ];

    $currentYear = (int)date('Y');
    if ($data['title'] === '' || $data['author'] === '' || $data['category'] === '' ||
        $data['description'] === '' || $data['content'] === '') {
        throw new InvalidArgumentException('Заполните все текстовые поля книги.');
    }
    if ($data['written_year'] < 1000 || $data['written_year'] > $currentYear) {
        throw new InvalidArgumentException('Укажите корректный год книги.');
    }
    if (!in_array($data['status'], ['available', 'unavailable'], true)) {
        throw new InvalidArgumentException('Выбран неизвестный статус.');
    }
    foreach (['quantity', 'purchase_price', 'rent_price_2w', 'rent_price_1m', 'rent_price_3m'] as $field) {
        if ($data[$field] < 0) {
            throw new InvalidArgumentException('Количество и цены не могут быть отрицательными.');
        }
    }
    return $data;
}

