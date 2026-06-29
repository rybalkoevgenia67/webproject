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
                
                $login = 'guardian_' . random_int(10000, 99999);
                $password = substr(bin2hex(random_bytes(4)), 0, 8);
                $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                
                $db->beginTransaction();
                
                $stmt = $db->prepare("INSERT INTO users_auth (login, password_hash) VALUES (?, ?)");
                $stmt->execute([$login, $passwordHash]);
                $authId = $db->lastInsertId();
                
                $stmt = $db->prepare(
                    "INSERT INTO bookings (full_name, phone, email, birth_date, gender, biography, agreed, user_id) 
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
                );
                $stmt->execute([
                    $formData['full_name'], $formData['phone'], $formData['email'],
                    $formData['birth_date'], $formData['gender'],
                    $formData['biography'] ?? '', $formData['agreed'] ? 1 : 0, $authId
                ]);
                $bookingId = $db->lastInsertId();
                
                if (!empty($formData['pets'])) {
                    $stmtPet = $db->prepare("SELECT id FROM pets WHERE name = ?");
                    $stmtInsert = $db->prepare("INSERT INTO booking_pets (booking_id, pet_id) VALUES (?, ?)");
                    foreach ($formData['pets'] as $pet) {
                        $stmtPet->execute([$pet]);
                        $petId = $stmtPet->fetchColumn();
                        if ($petId) {
                            $stmtInsert->execute([$bookingId, $petId]);
                        }
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
                $serverErrors[] = 'Ошибка сохранения данных.';
            }
        }
    }
}

if (isset($_SESSION['login']) && isset($_SESSION['uid'])) {
    try {
        $db = Database::getInstance();
        $stmt = $db->prepare("SELECT * FROM bookings WHERE user_id = ? ORDER BY id DESC LIMIT 1");
        $stmt->execute([$_SESSION['uid']]);
        $booking = $stmt->fetch();
        
        if ($booking) {
            $stmtPet = $db->prepare(
                "SELECT p.name FROM booking_pets bp 
                 JOIN pets p ON bp.pet_id = p.id 
                 WHERE bp.booking_id = ?"
            );
            $stmtPet->execute([$booking['id']]);
            $booking['pets'] = $stmtPet->fetchAll(PDO::FETCH_COLUMN);
            
            $formData = array_merge($formData ?: [], [
                'full_name' => $booking['full_name'],
                'phone' => $booking['phone'],
                'email' => $booking['email'],
                'birth_date' => $booking['birth_date'],
                'gender' => $booking['gender'],
                'pets' => $booking['pets'],
                'biography' => $booking['biography'] ?? '',
                'agreed' => $booking['agreed'] ? '1' : ''
            ]);
        }
    } catch (Exception $e) {}
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function renderHeader($title) {
    $loggedIn = isset($_SESSION['login']) ? 'true' : 'false';
    $uid = $_SESSION['uid'] ?? '';
    ob_start();
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Приют «Тёплый дом» | <?= htmlspecialchars($title) ?></title>
    <link rel="stylesheet" href="public/css/style.css">
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;600;700&family=Roboto:wght@300;400;500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body data-logged-in="<?= $loggedIn ?>" data-user-id="<?= htmlspecialchars($uid) ?>">
    <nav class="navbar">
        <div class="container nav-container">
            <a href="index.php" class="logo"><i class="fas fa-paw"></i> Тёплый дом</a>
            <ul class="nav-menu">
                <li><a href="#about">О приюте</a></li>
                <li><a href="#pets">Наши питомцы</a></li>
                <li><a href="#guardianship">Опека</a></li>
            </ul>
            <button class="mobile-toggle"><span></span><span></span><span></span></button>
        </div>
    </nav>

    <div class="auth-bar">
        <div class="container">
            <?php if (isset($_SESSION['login'])): ?>
                <span>✅ Вы вошли как: <strong><?= htmlspecialchars($_SESSION['login']) ?></strong></span>
                <a href="logout.php" class="btn btn-sm btn-outline">Выйти</a>
            <?php else: ?>
                <span>🔐</span>
                <a href="login.php" class="btn btn-sm btn-outline">Войти для изменения заявки</a>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!empty($_COOKIE['save_success'])): ?>
        <?php setcookie('save_success', '', time() - 3600, '/'); ?>
        <div class="message success"><div class="container">✅ Заявка успешно сохранена!</div></div>
    <?php endif; ?>

    <?php if (!empty($_COOKIE['login']) && !empty($_COOKIE['pass'])): ?>
        <div class="message success">
            <div class="container">
                🔑 Логин: <strong><?= htmlspecialchars($_COOKIE['login']) ?></strong><br>
                🔒 Пароль: <strong><?= htmlspecialchars($_COOKIE['pass']) ?></strong>
                <?php setcookie('login', '', time() - 3600, '/'); ?>
                <?php setcookie('pass', '', time() - 3600, '/'); ?>
            </div>
        </div>
    <?php endif; ?>
<?php
    return ob_get_clean();
}

function renderFooter() {
    ob_start();
?>
    <footer class="footer">
        <div class="container">
            <div class="footer-grid">
                <div class="footer-col">
                    <h3><i class="fas fa-paw"></i> Тёплый дом</h3>
                    <p>Приют для бездомных животных. Мы помогаем найти дом каждому питомцу с 2018 года.</p>
                </div>
                <div class="footer-col">
                    <h4>Контакты</h4>
                    <p><i class="fas fa-phone"></i> +7 (999) 123-45-67</p>
                    <p><i class="fas fa-envelope"></i> info@warmhome.ru</p>
                </div>
                <div class="footer-col">
                    <h4>Часы работы</h4>
                    <p>Пн-Вс: 10:00–18:00</p>
                    <p>Без выходных</p>
                </div>
            </div>
            <div class="footer-bottom">© <?= date('Y') ?> Приют «Тёплый дом»</div>
        </div>
    </footer>
    <script src="public/js/main.js"></script>
</body>
</html>
<?php
    return ob_get_clean();
}

echo renderHeader('Опека над животными');
?>

<header class="hero" style="background-image: url('public/images/hero-bg.jpg');">
    <div class="hero-content">
        <h1>Приют «Тёплый дом»</h1>
        <p class="hero-subtitle">Подарите дом и заботу тем, кто в этом нуждается</p>
        <p class="hero-description">Станьте опекуном для бездомных животных и получите верного друга на всю жизнь</p>
        <a href="#guardianship" class="btn btn-primary btn-lg">
            <i class="fas fa-heart"></i> Стать опекуном
        </a>
    </div>
</header>

<section class="section" id="about">
    <div class="container">
        <h2 class="section-title">О нашем приюте</h2>
        <div class="about-grid">
            <div class="about-text">
                <p>«Тёплый дом» — это место, где бездомные животные обретают заботу, любовь и шанс на новую жизнь. Мы верим, что у каждого питомца должен быть свой человек.</p>
                <p>Наш приют существует с 2018 года. За это время мы нашли дом для более чем 2000 животных и продолжаем помогать каждый день.</p>
                <div class="stats">
                    <div class="stat"><span>2000+</span><p>пристроенных</p></div>
                    <div class="stat"><span>5</span><p>лет работы</p></div>
                    <div class="stat"><span>150</span><p>питомцев сейчас</p></div>
                </div>
            </div>
            <div class="about-image">
                <img src="public/images/shelter.jpg" alt="Наш приют">
            </div>
        </div>
    </div>
</section>

<section class="section section-dark" id="pets">
    <div class="container">
        <h2 class="section-title">Наши питомцы</h2>
        <p class="section-description">Эти животные ждут своих заботливых хозяев</p>
        <div class="pets-grid">
            <div class="pet-card">
                <img src="public/images/pets/cat.jpg" alt="Кошка" class="pet-photo">
                <h3>Кошка</h3>
                <p>Ласковые и независимые компаньоны</p>
            </div>
            <div class="pet-card">
                <img src="public/images/pets/dog.jpg" alt="Собака" class="pet-photo">
                <h3>Собака</h3>
                <p>Верные друзья и защитники</p>
            </div>
            <div class="pet-card">
                <img src="public/images/pets/hamster.jpg" alt="Хомяк" class="pet-photo">
                <h3>Хомяк</h3>
                <p>Маленькие и забавные питомцы</p>
            </div>
            <div class="pet-card">
                <img src="public/images/pets/parrot.jpg" alt="Попугай" class="pet-photo">
                <h3>Попугай</h3>
                <p>Умные и общительные птицы</p>
            </div>
            <div class="pet-card">
                <img src="public/images/pets/rabbit.jpg" alt="Кролик" class="pet-photo">
                <h3>Кролик</h3>
                <p>Пушистые и нежные создания</p>
            </div>
            <div class="pet-card">
                <img src="public/images/pets/turtle.jpg" alt="Черепаха" class="pet-photo">
                <h3>Черепаха</h3>
                <p>Спокойные и мудрые питомцы</p>
            </div>
            <div class="pet-card">
                <img src="public/images/pets/chinchilla.jpg" alt="Шиншилла" class="pet-photo">
                <h3>Шиншилла</h3>
                <p>Мягкие и игривые зверьки</p>
            </div>
            <div class="pet-card">
                <img src="public/images/pets/guinea-pig.jpg" alt="Морская свинка" class="pet-photo">
                <h3>Морская свинка</h3>
                <p>Дружелюбные и общительные</p>
            </div>
            <div class="pet-card">
                <img src="public/images/pets/ferret.jpg" alt="Хорек" class="pet-photo">
                <h3>Хорек</h3>
                <p>Энергичные и любопытные</p>
            </div>
            <div class="pet-card">
                <img src="public/images/pets/iguana.jpg" alt="Игуана" class="pet-photo">
                <h3>Игуана</h3>
                <p>Экзотические и спокойные</p>
            </div>
            <div class="pet-card">
                <img src="public/images/pets/hedgehog.jpg" alt="Ёжик" class="pet-photo">
                <h3>Ёжик</h3>
                <p>Необычные и милые питомцы</p>
            </div>
            <div class="pet-card">
                <img src="public/images/pets/fish.jpg" alt="Рыбки" class="pet-photo">
                <h3>Рыбки</h3>
                <p>Красота и спокойствие в аквариуме</p>
            </div>
        </div>
    </div>
</section>

<section class="section" id="guardianship">
    <div class="container">
        <h2 class="section-title">Стать опекуном</h2>
        <p class="section-description">Заполните заявку, и мы поможем вам выбрать питомца</p>
        
        <?php if (!empty($serverErrors)): ?>
            <div class="message error"><?= htmlspecialchars(implode(', ', $serverErrors)) ?></div>
        <?php endif; ?>
        
        <?php if (!empty($errors)): ?>
            <div class="message error">
                <h4>Исправьте ошибки:</h4>
                <ul><?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?></ul>
            </div>
        <?php endif; ?>
        
        <div class="booking-container">
            <div class="booking-info">
                <h3>Почему стоит взять питомца?</h3>
                <ul class="features-list">
                    <li><i class="fas fa-check"></i> Вы спасаете жизнь</li>
                    <li><i class="fas fa-check"></i> Бесплатная консультация ветеринара</li>
                    <li><i class="fas fa-check"></i> Поддержка в первые месяцы</li>
                    <li><i class="fas fa-check"></i> Все животные привиты и стерилизованы</li>
                    <li><i class="fas fa-check"></i> Пожизненное сопровождение</li>
                </ul>
            </div>
            <div class="booking-form-container">
                <form id="booking-form" action="index.php" method="POST" novalidate>
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                    
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="full_name">ФИО *</label>
                            <input type="text" id="full_name" name="full_name" 
                                   value="<?= htmlspecialchars($formData['full_name'] ?? '') ?>"
                                   placeholder="Иванов Иван Иванович" required>
                            <span class="error-message" data-error="full_name"></span>
                        </div>
                        
                        <div class="form-group">
                            <label for="phone">Телефон *</label>
                            <input type="tel" id="phone" name="phone"
                                   value="<?= htmlspecialchars($formData['phone'] ?? '') ?>"
                                   placeholder="+7 (999) 123-45-67" required>
                            <span class="error-message" data-error="phone"></span>
                        </div>
                        
                        <div class="form-group">
                            <label for="email">Email *</label>
                            <input type="email" id="email" name="email"
                                   value="<?= htmlspecialchars($formData['email'] ?? '') ?>"
                                   placeholder="example@mail.ru" required>
                            <span class="error-message" data-error="email"></span>
                        </div>
                        
                        <div class="form-group">
                            <label for="birth_date">Дата рождения *</label>
                            <input type="date" id="birth_date" name="birth_date"
                                   value="<?= htmlspecialchars($formData['birth_date'] ?? '') ?>"
                                   max="<?= date('Y-m-d') ?>" required>
                            <span class="error-message" data-error="birth_date"></span>
                        </div>
                        
                        <div class="form-group">
                            <label>Пол *</label>
                            <div class="radio-group">
                                <label class="radio-label">
                                    <input type="radio" name="gender" value="male" 
                                           <?= ($formData['gender'] ?? '') === 'male' ? 'checked' : '' ?> required>
                                    Мужской
                                </label>
                                <label class="radio-label">
                                    <input type="radio" name="gender" value="female"
                                           <?= ($formData['gender'] ?? '') === 'female' ? 'checked' : '' ?>>
                                    Женский
                                </label>
                            </div>
                            <span class="error-message" data-error="gender"></span>
                        </div>
                        
                        <div class="form-group full-width">
                            <label for="pets">Какие животные интересуют? *</label>
                            <select id="pets" name="pets[]" multiple required size="8">
                                <?php foreach (Validator::getAllowedPets() as $pet): ?>
                                <option value="<?= $pet ?>" <?= in_array($pet, $formData['pets'] ?? []) ? 'selected' : '' ?>>
                                    <?= $pet ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <small>Удерживайте Ctrl для выбора нескольких</small>
                            <span class="error-message" data-error="pets"></span>
                        </div>
                        
                        <div class="form-group full-width">
                            <label for="biography">Расскажите о себе</label>
                            <textarea id="biography" name="biography" rows="3"
                                      placeholder="Есть ли у вас опыт содержания животных? Какие условия проживания?"><?= htmlspecialchars($formData['biography'] ?? '') ?></textarea>
                        </div>
                        
                        <div class="form-group full-width">
                            <label class="checkbox-label">
                                <input type="checkbox" name="agreed" value="1"
                                       <?= !empty($formData['agreed']) ? 'checked' : '' ?> required>
                                Я согласен с условиями опекунства *
                            </label>
                            <span class="error-message" data-error="agreed"></span>
                        </div>
                    </div>
                    
                    <button type="submit" class="btn btn-primary btn-lg" id="submit-btn">
                        <i class="fas fa-heart"></i> Стать опекуном
                    </button>
                    <div id="form-response" class="form-response" style="display:none;"></div>
                </form>
            </div>
        </div>
    </div>
</section>

<?= renderFooter() ?>