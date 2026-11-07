<?php
declare(strict_types=1);

session_name('MY_FUTURE_BOOK_SESSION');
session_start();
require_once dirname(__DIR__) . '/functions.php';

bootstrap_users();
create_due_reminders();

$page = (string)($_GET['page'] ?? 'catalog');

if ($page === 'my-orders') {
    require_login();
}
if (in_array($page, ['admin-books', 'admin-rentals'], true)) {
    require_admin();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');

    try {
        if ($action === 'login') {
            $email = trim((string)($_POST['email'] ?? ''));
            $statement = db()->prepare('SELECT * FROM users WHERE email = ?');
            $statement->execute([$email]);
            $account = $statement->fetch();
            if (!$account || !password_verify((string)($_POST['password'] ?? ''), $account['password_hash'])) {
                throw new InvalidArgumentException('Неверный e-mail или пароль.');
            }
            session_regenerate_id(true);
            unset($account['password_hash']);
            $_SESSION['user'] = $account;
            flash('Вы вошли в систему.');
            redirect(is_admin() ? '?page=admin-books' : '?page=catalog');
        }

        if ($action === 'register') {
            $name = trim((string)($_POST['name'] ?? ''));
            $email = trim((string)($_POST['email'] ?? ''));
            $password = (string)($_POST['password'] ?? '');
            if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 6) {
                throw new InvalidArgumentException('Укажите имя, корректный e-mail и пароль не короче 6 символов.');
            }
            $statement = db()->prepare('INSERT INTO users (name, email, password_hash) VALUES (?, ?, ?)');
            try {
                $statement->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT)]);
            } catch (PDOException $exception) {
                if ($exception->getCode() === '23000') {
                    throw new InvalidArgumentException('Пользователь с таким e-mail уже существует.');
                }
                throw $exception;
            }
            flash('Регистрация завершена. Теперь войдите.');
            redirect('?page=login');
        }

        if ($action === 'logout') {
            $_SESSION = [];
            session_regenerate_id(true);
            flash('Вы вышли из системы.');
            redirect('?page=catalog');
        }

        if ($action === 'order') {
            require_login();
            $bookId = (int)($_POST['book_id'] ?? 0);
            $type = (string)($_POST['order_type'] ?? 'purchase');
            $period = (string)($_POST['rental_period'] ?? '2w');
            if (!in_array($type, ['purchase', 'rental'], true) || !in_array($period, ['2w', '1m', '3m'], true)) {
                throw new InvalidArgumentException('Выбран неверный вариант заказа.');
            }

            $pdo = db();
            $pdo->beginTransaction();
            try {
                $statement = $pdo->prepare('SELECT * FROM books WHERE id = ? FOR UPDATE');
                $statement->execute([$bookId]);
                $book = $statement->fetch();
                if (!$book || $book['status'] !== 'available' || (int)$book['quantity'] < 1) {
                    throw new InvalidArgumentException('Книга сейчас недоступна.');
                }

                $price = (float)$book['purchase_price'];
                $dueDate = null;
                $rentalPeriod = null;
                $status = 'completed';
                if ($type === 'rental') {
                    $rentalPeriod = $period;
                    $status = 'active';
                    $priceFields = ['2w' => 'rent_price_2w', '1m' => 'rent_price_1m', '3m' => 'rent_price_3m'];
                    $dateIntervals = ['2w' => '+14 days', '1m' => '+1 month', '3m' => '+3 months'];
                    $price = (float)$book[$priceFields[$period]];
                    $dueDate = (new DateTimeImmutable($dateIntervals[$period]))->format('Y-m-d');
                }

                $statement = $pdo->prepare(
                    'INSERT INTO book_orders (user_id, book_id, order_type, rental_period, start_date, due_date, price, status)
                     VALUES (?, ?, ?, ?, CURRENT_DATE, ?, ?, ?)'
                );
                $statement->execute([user()['id'], $bookId, $type, $rentalPeriod, $dueDate, $price, $status]);
                $pdo->prepare('UPDATE books SET quantity = quantity - 1 WHERE id = ?')->execute([$bookId]);
                $pdo->commit();
            } catch (Throwable $exception) {
                $pdo->rollBack();
                throw $exception;
            }
            flash($type === 'purchase' ? 'Покупка оформлена.' : 'Аренда оформлена до ' . date('d.m.Y', strtotime((string)$dueDate)) . '.');
            redirect('?page=my-orders');
        }

        if ($action === 'read-reminders') {
            require_login();
            db()->prepare('UPDATE reminders SET is_read = 1 WHERE user_id = ?')->execute([user()['id']]);
            redirect('?page=my-orders');
        }

        if ($action === 'create-book') {
            require_admin();
            $book = valid_book_data($_POST);
            $statement = db()->prepare(
                'INSERT INTO books (title, author, category, written_year, description, content, status, quantity,
                 purchase_price, rent_price_2w, rent_price_1m, rent_price_3m)
                 VALUES (:title, :author, :category, :written_year, :description, :content, :status, :quantity,
                 :purchase_price, :rent_price_2w, :rent_price_1m, :rent_price_3m)'
            );
            $statement->execute($book);
            flash('Книга добавлена.');
            redirect('?page=admin-books');
        }

        if ($action === 'update-book') {
            require_admin();
            $book = valid_book_data($_POST);
            $book['id'] = (int)($_POST['book_id'] ?? 0);
            $statement = db()->prepare(
                'UPDATE books SET title=:title, author=:author, category=:category, written_year=:written_year,
                 description=:description, content=:content, status=:status, quantity=:quantity,
                 purchase_price=:purchase_price, rent_price_2w=:rent_price_2w,
                 rent_price_1m=:rent_price_1m, rent_price_3m=:rent_price_3m WHERE id=:id'
            );
            $statement->execute($book);
            flash('Изменения сохранены.');
            redirect('?page=admin-books');
        }

        if ($action === 'delete-book') {
            require_admin();
            try {
                db()->prepare('DELETE FROM books WHERE id = ?')->execute([(int)($_POST['book_id'] ?? 0)]);
            } catch (PDOException $exception) {
                if ($exception->getCode() === '23000') {
                    throw new InvalidArgumentException('Нельзя удалить книгу, по которой уже есть заказы. Измените её статус.');
                }
                throw $exception;
            }
            flash('Книга удалена.');
            redirect('?page=admin-books');
        }
    } catch (InvalidArgumentException $exception) {
        flash($exception->getMessage(), 'error');
        redirect('?page=' . urlencode($page));
    }
}

$titles = [
    'catalog' => 'Каталог', 'book' => 'Книга', 'login' => 'Вход', 'register' => 'Регистрация',
    'my-orders' => 'Мои книги', 'admin-books' => 'Управление книгами', 'admin-rentals' => 'Аренды и напоминания',
];
$title = $titles[$page] ?? 'Каталог';
$flash = take_flash();
$unreadCount = 0;
if (user()) {
    $statement = db()->prepare('SELECT COUNT(*) FROM reminders WHERE user_id = ? AND is_read = 0');
    $statement->execute([user()['id']]);
    $unreadCount = (int)$statement->fetchColumn();
}
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h($title) ?> — Книги с анекдотами</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<header>
    <div class="header-inner">
        <a class="brand" href="?page=catalog">Книги с анекдотами</a>
        <nav>
            <a href="?page=catalog">Каталог</a>
            <?php if (user()): ?>
                <a href="?page=my-orders">Мои книги<?= $unreadCount ? ' (' . $unreadCount . ')' : '' ?></a>
                <?php if (is_admin()): ?>
                    <a href="?page=admin-books">Книги</a>
                    <a href="?page=admin-rentals">Аренды</a>
                <?php endif; ?>
                <span><?= h(user()['name']) ?></span>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                    <input type="hidden" name="action" value="logout">
                    <button class="secondary">Выйти</button>
                </form>
            <?php else: ?>
                <a href="?page=login">Войти</a>
                <a href="?page=register">Регистрация</a>
            <?php endif; ?>
        </nav>
    </div>
</header>
<main>
    <?php if ($flash): ?>
        <div class="flash <?= $flash['type'] === 'error' ? 'error' : '' ?>"><?= h($flash['message']) ?></div>
    <?php endif; ?>

    <?php if ($page === 'catalog'): ?>
        <?php
        $allowedSorts = ['category' => 'category, title', 'author' => 'author, title', 'year' => 'written_year DESC, title'];
        $sort = (string)($_GET['sort'] ?? 'category');
        if (!isset($allowedSorts[$sort])) {
            $sort = 'category';
        }
        $books = db()->query('SELECT * FROM books ORDER BY ' . $allowedSorts[$sort])->fetchAll();
        ?>
        <h1>Каталог книг</h1>
        <form class="panel filters" method="get">
            <input type="hidden" name="page" value="catalog">
            <label>Сортировать по
                <select name="sort">
                    <option value="category" <?= $sort === 'category' ? 'selected' : '' ?>>категории</option>
                    <option value="author" <?= $sort === 'author' ? 'selected' : '' ?>>автору</option>
                    <option value="year" <?= $sort === 'year' ? 'selected' : '' ?>>году написания</option>
                </select>
            </label>
            <button>Применить</button>
        </form>
        <div class="book-grid">
            <?php foreach ($books as $book): ?>
                <article class="book-card">
                    <h2><?= h($book['title']) ?></h2>
                    <div class="meta"><?= h($book['author']) ?> · <?= h($book['written_year']) ?> · <?= h($book['category']) ?></div>
                    <p><?= h($book['description']) ?></p>
                    <p><span class="status <?= $book['status'] !== 'available' || !$book['quantity'] ? 'off' : '' ?>">
                        <?= $book['status'] === 'available' && $book['quantity'] ? 'Доступна: ' . h($book['quantity']) : 'Недоступна' ?>
                    </span></p>
                    <a class="button" href="?page=book&id=<?= h($book['id']) ?>">Открыть книгу</a>
                </article>
            <?php endforeach; ?>
        </div>

    <?php elseif ($page === 'book'): ?>
        <?php
        $statement = db()->prepare('SELECT * FROM books WHERE id = ?');
        $statement->execute([(int)($_GET['id'] ?? 0)]);
        $book = $statement->fetch();
        if (!$book) {
            http_response_code(404);
        }
        ?>
        <?php if (!$book): ?>
            <div class="panel"><h1>Книга не найдена</h1><a href="?page=catalog">Вернуться в каталог</a></div>
        <?php else: ?>
            <article class="panel">
                <h1><?= h($book['title']) ?></h1>
                <div class="meta">Автор: <?= h($book['author']) ?> · Категория: <?= h($book['category']) ?> · Год: <?= h($book['written_year']) ?></div>
                <p><?= h($book['description']) ?></p>
                <h2>Фрагмент книги</h2>
                <div class="reading"><?= h($book['content']) ?></div>
                <h2>Стоимость</h2>
                <ul class="price-list">
                    <li>Покупка: <?= money($book['purchase_price']) ?></li>
                    <li>Аренда на 2 недели: <?= money($book['rent_price_2w']) ?></li>
                    <li>Аренда на месяц: <?= money($book['rent_price_1m']) ?></li>
                    <li>Аренда на 3 месяца: <?= money($book['rent_price_3m']) ?></li>
                </ul>
                <?php if ($book['status'] === 'available' && (int)$book['quantity'] > 0): ?>
                    <?php if (user()): ?>
                        <form method="post" class="inline">
                            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                            <input type="hidden" name="action" value="order">
                            <input type="hidden" name="book_id" value="<?= h($book['id']) ?>">
                            <label>Операция
                                <select name="order_type">
                                    <option value="purchase">Покупка</option>
                                    <option value="rental">Аренда</option>
                                </select>
                            </label>
                            <label>Срок аренды
                                <select name="rental_period">
                                    <option value="2w">2 недели</option>
                                    <option value="1m">1 месяц</option>
                                    <option value="3m">3 месяца</option>
                                </select>
                            </label>
                            <button>Оформить</button>
                        </form>
                    <?php else: ?>
                        <a class="button" href="?page=login">Войти для покупки или аренды</a>
                    <?php endif; ?>
                <?php else: ?>
                    <p class="flash error">Книга сейчас недоступна для заказа.</p>
                <?php endif; ?>
            </article>
        <?php endif; ?>

    <?php elseif ($page === 'login' || $page === 'register'): ?>
        <div class="panel">
            <h1><?= $page === 'login' ? 'Вход' : 'Регистрация' ?></h1>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="action" value="<?= $page === 'login' ? 'login' : 'register' ?>">
                <?php if ($page === 'register'): ?>
                    <label>Имя<input name="name" required maxlength="100"></label>
                <?php endif; ?>
                <label>E-mail<input name="email" type="email" required maxlength="190"></label>
                <label>Пароль<input name="password" type="password" required minlength="6"></label>
                <button><?= $page === 'login' ? 'Войти' : 'Зарегистрироваться' ?></button>
            </form>
        </div>

    <?php elseif ($page === 'my-orders'): ?>
        <?php
        require_login();
        $statement = db()->prepare(
            'SELECT o.*, b.title FROM book_orders o JOIN books b ON b.id = o.book_id
             WHERE o.user_id = ? ORDER BY o.created_at DESC'
        );
        $statement->execute([user()['id']]);
        $orders = $statement->fetchAll();
        $statement = db()->prepare('SELECT * FROM reminders WHERE user_id = ? ORDER BY created_at DESC');
        $statement->execute([user()['id']]);
        $reminders = $statement->fetchAll();
        ?>
        <h1>Мои книги</h1>
        <?php if ($reminders): ?>
            <section class="panel">
                <h2>Напоминания</h2>
                <?php foreach ($reminders as $reminder): ?>
                    <div class="notice"><?= h($reminder['message']) ?></div>
                <?php endforeach; ?>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                    <input type="hidden" name="action" value="read-reminders">
                    <button class="secondary">Отметить прочитанными</button>
                </form>
            </section>
        <?php endif; ?>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Книга</th><th>Операция</th><th>Дата</th><th>Вернуть до</th><th>Цена</th><th>Статус</th></tr></thead>
                <tbody>
                <?php foreach ($orders as $order): ?>
                    <tr>
                        <td><?= h($order['title']) ?></td>
                        <td><?= $order['order_type'] === 'purchase' ? 'Покупка' : 'Аренда' ?></td>
                        <td><?= h(date('d.m.Y', strtotime($order['start_date']))) ?></td>
                        <td><?= $order['due_date'] ? h(date('d.m.Y', strtotime($order['due_date']))) : '—' ?></td>
                        <td><?= money($order['price']) ?></td>
                        <td><?= $order['status'] === 'active' ? 'Активна' : 'Завершена' ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$orders): ?><tr><td colspan="6" class="empty">У вас пока нет покупок и аренд.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>

    <?php elseif ($page === 'admin-books'): ?>
        <?php require_admin(); $books = db()->query('SELECT * FROM books ORDER BY id')->fetchAll(); ?>
        <h1>Управление книгами</h1>
        <section class="panel">
            <h2>Добавить книгу</h2>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="action" value="create-book">
                <div class="form-row">
                    <label>Название<input name="title" required></label>
                    <label>Автор<input name="author" required></label>
                    <label>Категория<input name="category" required></label>
                    <label>Год<input name="written_year" type="number" min="1000" max="<?= date('Y') ?>" required></label>
                </div>
                <label>Описание<textarea name="description" required></textarea></label>
                <label>Фрагмент книги<textarea name="content" required></textarea></label>
                <div class="form-row">
                    <label>Статус<select name="status"><option value="available">Доступна</option><option value="unavailable">Недоступна</option></select></label>
                    <label>Количество<input name="quantity" type="number" min="0" value="1" required></label>
                    <label>Цена покупки<input name="purchase_price" type="number" min="0" step="0.01" required></label>
                    <label>Аренда 2 недели<input name="rent_price_2w" type="number" min="0" step="0.01" required></label>
                    <label>Аренда 1 месяц<input name="rent_price_1m" type="number" min="0" step="0.01" required></label>
                    <label>Аренда 3 месяца<input name="rent_price_3m" type="number" min="0" step="0.01" required></label>
                </div>
                <button>Добавить</button>
            </form>
        </section>
        <section class="panel">
            <h2>Список книг</h2>
            <?php foreach ($books as $book): ?>
                <form method="post" class="admin-book">
                    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                    <input type="hidden" name="book_id" value="<?= h($book['id']) ?>">
                    <div class="form-row">
                        <label>Название<input name="title" value="<?= h($book['title']) ?>" required></label>
                        <label>Автор<input name="author" value="<?= h($book['author']) ?>" required></label>
                        <label>Категория<input name="category" value="<?= h($book['category']) ?>" required></label>
                        <label>Год<input name="written_year" type="number" min="1000" max="<?= date('Y') ?>" value="<?= h($book['written_year']) ?>" required></label>
                    </div>
                    <label>Описание<textarea name="description" required><?= h($book['description']) ?></textarea></label>
                    <label>Фрагмент книги<textarea name="content" required><?= h($book['content']) ?></textarea></label>
                    <div class="form-row">
                        <label>Статус<select name="status"><option value="available" <?= $book['status'] === 'available' ? 'selected' : '' ?>>Доступна</option><option value="unavailable" <?= $book['status'] === 'unavailable' ? 'selected' : '' ?>>Недоступна</option></select></label>
                        <label>Количество<input name="quantity" type="number" min="0" value="<?= h($book['quantity']) ?>" required></label>
                        <label>Цена покупки<input name="purchase_price" type="number" min="0" step="0.01" value="<?= h($book['purchase_price']) ?>" required></label>
                        <label>Аренда 2 недели<input name="rent_price_2w" type="number" min="0" step="0.01" value="<?= h($book['rent_price_2w']) ?>" required></label>
                        <label>Аренда 1 месяц<input name="rent_price_1m" type="number" min="0" step="0.01" value="<?= h($book['rent_price_1m']) ?>" required></label>
                        <label>Аренда 3 месяца<input name="rent_price_3m" type="number" min="0" step="0.01" value="<?= h($book['rent_price_3m']) ?>" required></label>
                    </div>
                    <button name="action" value="update-book">Сохранить</button>
                    <button class="danger" name="action" value="delete-book" onclick="return confirm('Удалить книгу?')">Удалить</button>
                </form>
            <?php endforeach; ?>
        </section>

    <?php elseif ($page === 'admin-rentals'): ?>
        <?php
        require_admin();
        $rentals = db()->query(
            "SELECT o.*, b.title, u.name, u.email,
             CASE WHEN o.due_date < CURRENT_DATE THEN 'overdue'
                  WHEN o.due_date <= DATE_ADD(CURRENT_DATE, INTERVAL 3 DAY) THEN 'soon'
                  ELSE 'active' END AS due_state
             FROM book_orders o JOIN books b ON b.id=o.book_id JOIN users u ON u.id=o.user_id
             WHERE o.order_type='rental' AND o.status='active' ORDER BY o.due_date"
        )->fetchAll();
        $reminders = db()->query(
            'SELECT r.*, u.email FROM reminders r JOIN users u ON u.id=r.user_id ORDER BY r.created_at DESC LIMIT 30'
        )->fetchAll();
        ?>
        <h1>Аренды и напоминания</h1>
        <section class="panel">
            <h2>Активные аренды</h2>
            <div class="table-wrap"><table>
                <thead><tr><th>Книга</th><th>Пользователь</th><th>Начало</th><th>Вернуть до</th><th>Состояние</th></tr></thead>
                <tbody>
                <?php foreach ($rentals as $rental): ?>
                    <tr><td><?= h($rental['title']) ?></td><td><?= h($rental['name']) ?><br><span class="small"><?= h($rental['email']) ?></span></td><td><?= h(date('d.m.Y', strtotime($rental['start_date']))) ?></td><td><?= h(date('d.m.Y', strtotime($rental['due_date']))) ?></td><td><?= ['active'=>'Активна','soon'=>'Срок скоро','overdue'=>'Просрочена'][$rental['due_state']] ?></td></tr>
                <?php endforeach; ?>
                <?php if (!$rentals): ?><tr><td colspan="5" class="empty">Активных аренд нет.</td></tr><?php endif; ?>
                </tbody>
            </table></div>
        </section>
        <section class="panel">
            <h2>Автоматические напоминания</h2>
            <p class="small">Система создаёт уведомления ежедневно для книг, которые нужно вернуть в ближайшие 3 дня, и для просроченных аренд.</p>
            <div class="table-wrap"><table>
                <thead><tr><th>Дата</th><th>Получатель</th><th>Сообщение</th><th>Прочитано</th></tr></thead>
                <tbody>
                <?php foreach ($reminders as $reminder): ?>
                    <tr><td><?= h(date('d.m.Y', strtotime($reminder['reminder_date']))) ?></td><td><?= h($reminder['email']) ?></td><td><?= h($reminder['message']) ?></td><td><?= $reminder['is_read'] ? 'Да' : 'Нет' ?></td></tr>
                <?php endforeach; ?>
                <?php if (!$reminders): ?><tr><td colspan="4" class="empty">Напоминаний пока нет.</td></tr><?php endif; ?>
                </tbody>
            </table></div>
        </section>
    <?php else: ?>
        <?php http_response_code(404); ?><div class="panel"><h1>Страница не найдена</h1></div>
    <?php endif; ?>
</main>
<footer>Учебный проект «Книги с анекдотами»</footer>
</body>
</html>

