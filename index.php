<?php
session_start();
require_once 'includes/Database.php';
require_once 'includes/Validator.php';

$formData = [];
$errors = [];
$serverErrors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== ($_SESSION['csrf_token'] ?? '')) {
        $serverErrors[] = 'Ошибка безопасности. Обновите страницу.';
    } else {
        $formData = [
            'full_name' => trim($_POST['full_name'] ?? ''),
            'phone' => trim($_POST['phone'] ?? ''),
            'email' => trim($_POST['email'] ?? ''),
            'birth_date' => $_POST['birth_date'] ?? '',
            'gender' => $_POST['gender'] ?? '',
            'pets' => $_POST['pets'] ?? [],
            'biography' => trim($_POST['biography'] ?? ''),
            'agreed' => $_POST['agreed'] ?? ''
        ];
        
        $errors = Validator::validate($formData);
        
        if (empty($errors)) {
            try {
                $db = Database::getInstance();
                $login = 'friend_' . random_int(1000, 9999);
                $password = substr(bin2hex(random_bytes(4)), 0, 8);
                $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                
                $db->beginTransaction();
                $stmt = $db->prepare("INSERT INTO users_auth (login, password_hash) VALUES (?, ?)");
                $stmt->execute([$login, $passwordHash]);
                $authId = $db->lastInsertId();
                
                $stmt = $db->prepare("INSERT INTO bookings (full_name, phone, email, birth_date, gender, biography, agreed, user_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$formData['full_name'], $formData['phone'], $formData['email'], $formData['birth_date'], $formData['gender'], $formData['biography'] ?? '', $formData['agreed'] ? 1 : 0, $authId]);
                $bookingId = $db->lastInsertId();
                
                if (!empty($formData['pets'])) {
                    $stmtPet = $db->prepare("SELECT id FROM pets WHERE name = ?");
                    $stmtInsert = $db->prepare("INSERT INTO booking_pets (booking_id, pet_id) VALUES (?, ?)");
                    foreach ($formData['pets'] as $pet) {
                        $stmtPet->execute([$pet]);
                        $petId = $stmtPet->fetchColumn();
                        if ($petId) $stmtInsert->execute([$bookingId, $petId]);
                    }
                }
                
                $db->commit();
                setcookie('login', $login, time() + 3600, '/');
                setcookie('pass', $password, time() + 3600, '/');
                setcookie('save_success', '1', time() + 3600, '/');
                header('Location: index.php');
                exit;
            } catch (Exception $e) {
                if (isset($db)) $db->rollBack();
                $serverErrors[] = 'Ошибка сохранения.';
            }
        }
    }
}

if (isset($_SESSION['login'], $_SESSION['uid'])) {
    try {
        $db = Database::getInstance();
        $stmt = $db->prepare("SELECT * FROM bookings WHERE user_id = ? ORDER BY id DESC LIMIT 1");
        $stmt->execute([$_SESSION['uid']]);
        $booking = $stmt->fetch();
        if ($booking) {
            $stmtPet = $db->prepare("SELECT p.name FROM booking_pets bp JOIN pets p ON bp.pet_id = p.id WHERE bp.booking_id = ?");
            $stmtPet->execute([$booking['id']]);
            $booking['pets'] = $stmtPet->fetchAll(PDO::FETCH_COLUMN);
            $formData = array_merge($formData ?: [], ['full_name' => $booking['full_name'], 'phone' => $booking['phone'], 'email' => $booking['email'], 'birth_date' => $booking['birth_date'], 'gender' => $booking['gender'], 'pets' => $booking['pets'], 'biography' => $booking['biography'] ?? '', 'agreed' => $booking['agreed'] ? '1' : '']);
        }
    } catch (Exception $e) {}
}

if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

function pageHeader($title) {
    $loggedIn = isset($_SESSION['login']) ? 'true' : 'false';
    $uid = $_SESSION['uid'] ?? '';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Тёплый дом — <?= htmlspecialchars($title) ?></title>
    <link rel="stylesheet" href="public/css/style.css">
</head>
<body data-logged-in="<?= $loggedIn ?>" data-user-id="<?= htmlspecialchars($uid) ?>">

<nav class="navbar">
    <div class="container nav-container">
        <a href="index.php" class="logo">Тёплый <span>дом</span></a>
        <ul class="nav-menu">
            <li><a href="#about">О нас</a></li>
            <li><a href="#pets">Питомцы</a></li>
            <li><a href="#guardianship">Опека</a></li>
        </ul>
        <button class="mobile-toggle"><span></span><span></span><span></span></button>
    </div>
</nav>

<div class="auth-bar">
    <div class="container">
        <?php if (isset($_SESSION['login'])): ?>
            <span>Вы вошли как <strong><?= htmlspecialchars($_SESSION['login']) ?></strong></span>
            <a href="logout.php" class="btn btn-sm btn-outline">Выйти</a>
        <?php else: ?>
            <span></span>
            <a href="login.php" class="btn btn-sm btn-outline">Войти</a>
        <?php endif; ?>
    </div>
</div>

<?php if (!empty($_COOKIE['save_success'])): ?>
    <?php setcookie('save_success', '', time() - 3600, '/'); ?>
    <div class="message success">Заявка принята. Спасибо!</div>
<?php endif; ?>

<?php if (!empty($_COOKIE['login']) && !empty($_COOKIE['pass'])): ?>
    <div class="message success">
        Логин: <strong><?= htmlspecialchars($_COOKIE['login']) ?></strong> &nbsp;|&nbsp;
        Пароль: <strong><?= htmlspecialchars($_COOKIE['pass']) ?></strong>
        <?php setcookie('login', '', time() - 3600, '/'); setcookie('pass', '', time() - 3600, '/'); ?>
    </div>
<?php endif;
    }

function pageFooter() {
?>
<footer class="footer">
    <div class="container">
        <div class="footer-grid">
            <div>
                <h3>Тёплый дом</h3>
                <p>Приют для животных. Находим дом каждому.</p>
            </div>
            <div>
                <h4>Контакты</h4>
                <p>+7 (999) 123-45-67</p>
                <p>hello@warmhome.ru</p>
            </div>
            <div>
                <h4>Часы</h4>
                <p>Ежедневно</p>
                <p>10:00 – 18:00</p>
            </div>
        </div>
        <div class="footer-bottom">© <?= date('Y') ?> Тёплый дом</div>
    </div>
</footer>
<script src="public/js/main.js"></script>
</body>
</html>
<?php
}

pageHeader('Приют для животных');
?>

<header class="hero">
    <div class="hero-content">
        <span class="hero-badge">Приют для животных</span>
        <h1>Найди <em>своего</em> друга</h1>
        <p class="hero-text">Мы соединяем одинокие сердца. Более 850 животных уже обрели дом благодаря нашим опекунам.</p>
        
        <div class="hero-stats-row">
            <div class="hero-stat">
                <span class="hero-stat-number">850+</span>
                <span class="hero-stat-label">Пристроено</span>
            </div>
            <div class="hero-stat">
                <span class="hero-stat-number">60</span>
                <span class="hero-stat-label">Ждут дом</span>
            </div>
            <div class="hero-stat">
                <span class="hero-stat-number">6</span>
                <span class="hero-stat-label">Лет работы</span>
            </div>
        </div>
        
        <div class="hero-buttons">
            <a href="#guardianship" class="btn btn-primary">Стать опекуном</a>
            <a href="#pets" class="btn-outline-dark">Посмотреть питомцев</a>
        </div>
    </div>
    
    <div class="hero-image" style="background-image: url('public/images/hero-bg.jpg');"></div>
    
    <div class="hero-dots">
        <span class="hero-dot"></span><span class="hero-dot"></span><span class="hero-dot"></span>
        <span class="hero-dot"></span><span class="hero-dot"></span><span class="hero-dot"></span>
        <span class="hero-dot"></span><span class="hero-dot"></span><span class="hero-dot"></span>
    </div>
</header>

<section class="section" id="about">
    <div class="container">
        <h2 class="section-title">О приюте</h2>
        <div class="section-line"></div>
        <div class="about-grid">
            <div class="about-text">
                <p>«Тёплый дом» — это не просто приют. Это сообщество людей, которые верят, что у каждого животного должен быть свой человек.</p>
                <p>Мы заботимся о тех, кого бросили, лечим, социализируем и находим для них самые лучшие семьи.</p>
                <div class="stats">
                    <div class="stat"><span>850+</span><p>пристроено</p></div>
                    <div class="stat"><span>6</span><p>лет</p></div>
                    <div class="stat"><span>60</span><p>сейчас в приюте</p></div>
                </div>
            </div>
            <div class="about-image">
                <img src="public/images/shelter.jpg" alt="Приют">
            </div>
        </div>
    </div>
</section>

<section class="section section-alt" id="pets">
    <div class="container">
        <h2 class="section-title">Кого можно взять</h2>
        <div class="section-line"></div>
        <p class="section-description">Все животные здоровы, привиты и готовы к переезду</p>
        <div class="pets-grid">
            <div class="pet-card">
                <img src="public/images/pets/cat.jpg" alt="Кошки" class="pet-photo">
                <div class="pet-info"><h3>Кошки</h3><p>Независимые, ласковые, идеальные компаньоны для дома</p></div>
            </div>
            <div class="pet-card">
                <img src="public/images/pets/dog.jpg" alt="Собаки" class="pet-photo">
                <div class="pet-info"><h3>Собаки</h3><p>Преданные друзья для активных и заботливых хозяев</p></div>
            </div>
            <div class="pet-card">
                <img src="public/images/pets/rodent.jpg" alt="Грызуны" class="pet-photo">
                <div class="pet-info"><h3>Грызуны</h3><p>Хомяки, крысы, морские свинки — маленькие, но общительные</p></div>
            </div>
            <div class="pet-card">
                <img src="public/images/pets/bird.jpg" alt="Птицы" class="pet-photo">
                <div class="pet-info"><h3>Птицы</h3><p>Попугаи, канарейки — красота и песни каждый день</p></div>
            </div>
            <div class="pet-card">
                <img src="public/images/pets/reptile.jpg" alt="Рептилии" class="pet-photo">
                <div class="pet-info"><h3>Рептилии</h3><p>Черепахи, игуаны — для ценителей необычного</p></div>
            </div>
            <div class="pet-card">
                <img src="public/images/pets/exotic.jpg" alt="Экзотические" class="pet-photo">
                <div class="pet-info"><h3>Экзотические</h3><p>Ежи, хорьки, шиншиллы — уникальные питомцы</p></div>
            </div>
        </div>
    </div>
</section>

<section class="section" id="guardianship">
    <div class="container">
        <h2 class="section-title">Стать опекуном</h2>
        <div class="section-line"></div>
        <p class="section-description">Оставьте заявку — мы подберём вам друга</p>
        
        <?php if (!empty($serverErrors)): ?><div class="message error"><?= htmlspecialchars(implode(', ', $serverErrors)) ?></div><?php endif; ?>
        <?php if (!empty($errors)): ?><div class="message error"><strong>Ошибки:</strong><ul><?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
        
        <div class="booking-container">
            <div class="booking-info">
                <h3>Что вы получаете</h3>
                <ul class="features-list">
                    <li>Здоровое и привитое животное</li>
                    <li>Помощь ветеринара в первый месяц</li>
                    <li>Консультации по уходу</li>
                    <li>Поддержку куратора</li>
                    <li>Нового верного друга</li>
                </ul>
            </div>
            <div class="booking-form-wrapper">
                <form id="booking-form" action="index.php" method="POST">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="full_name">Ваше имя *</label>
                            <input type="text" id="full_name" name="full_name" value="<?= htmlspecialchars($formData['full_name'] ?? '') ?>" placeholder="Анна Смирнова" required>
                            <span class="error-message" data-error="full_name"></span>
                        </div>
                        <div class="form-group">
                            <label for="phone">Телефон *</label>
                            <input type="tel" id="phone" name="phone" value="<?= htmlspecialchars($formData['phone'] ?? '') ?>" placeholder="+7 999 123-45-67" required>
                            <span class="error-message" data-error="phone"></span>
                        </div>
                        <div class="form-group">
                            <label for="email">Email *</label>
                            <input type="email" id="email" name="email" value="<?= htmlspecialchars($formData['email'] ?? '') ?>" placeholder="anna@mail.ru" required>
                            <span class="error-message" data-error="email"></span>
                        </div>
                        <div class="form-group">
                            <label for="birth_date">Дата рождения *</label>
                            <input type="date" id="birth_date" name="birth_date" value="<?= htmlspecialchars($formData['birth_date'] ?? '') ?>" max="<?= date('Y-m-d') ?>" required>
                            <span class="error-message" data-error="birth_date"></span>
                        </div>
                        <div class="form-group">
                            <label>Пол *</label>
                            <div class="radio-group">
                                <label class="radio-label"><input type="radio" name="gender" value="male" <?= ($formData['gender'] ?? '') === 'male' ? 'checked' : '' ?> required> Мужской</label>
                                <label class="radio-label"><input type="radio" name="gender" value="female" <?= ($formData['gender'] ?? '') === 'female' ? 'checked' : '' ?>> Женский</label>
                            </div>
                            <span class="error-message" data-error="gender"></span>
                        </div>
                        <div class="form-group full-width">
                            <label for="pets">Кто вам интересен? *</label>
                            <select id="pets" name="pets[]" multiple required size="6">
                                <?php foreach (Validator::getAllowedPets() as $pet): ?>
                                <option value="<?= $pet ?>" <?= in_array($pet, $formData['pets'] ?? []) ? 'selected' : '' ?>><?= $pet ?></option>
                                <?php endforeach; ?>
                            </select>
                            <small>Выберите один или несколько вариантов (Ctrl+клик)</small>
                            <span class="error-message" data-error="pets"></span>
                        </div>
                        <div class="form-group full-width">
                            <label for="biography">О себе</label>
                            <textarea id="biography" name="biography" rows="3" placeholder="Расскажите о вашем опыте с животными..."><?= htmlspecialchars($formData['biography'] ?? '') ?></textarea>
                        </div>
                        <div class="form-group full-width">
                            <label class="checkbox-label"><input type="checkbox" name="agreed" value="1" <?= !empty($formData['agreed']) ? 'checked' : '' ?> required> Я согласен(а) с условиями опекунства *</label>
                            <span class="error-message" data-error="agreed"></span>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary" id="submit-btn">Отправить заявку</button>
                    <div id="form-response" class="form-response" style="display:none;"></div>
                </form>
            </div>
        </div>
    </div>
</section>

<?php pageFooter(); ?>