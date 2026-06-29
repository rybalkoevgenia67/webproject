<?php
header('Content-Type: application/json; charset=utf-8');

require_once 'includes/Database.php';
require_once 'includes/Validator.php';

$method = $_SERVER['REQUEST_METHOD'];
$resourceId = $_GET['id'] ?? null;

$input = json_decode(file_get_contents('php://input'), true);
if (!$input && $method === 'POST') {
    $input = $_POST;
}

try {
    $db = Database::getInstance();
} catch (RuntimeException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Ошибка сервера']);
    exit;
}

switch ($method) {
    case 'POST': handlePost($db, $input); break;
    case 'GET': handleGet($db, $resourceId); break;
    case 'PUT': handlePut($db, $resourceId, $input); break;
    default:
        http_response_code(405);
        echo json_encode(['error' => 'Метод не поддерживается']);
}

function handlePost($db, $input) {
    $errors = Validator::validate($input);
    if (!empty($errors)) {
        http_response_code(400);
        echo json_encode(['errors' => $errors]);
        return;
    }
    
    $login = 'guardian_' . random_int(10000, 99999);
    $password = substr(bin2hex(random_bytes(4)), 0, 8);
    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
    
    try {
        $db->beginTransaction();
        
        $stmt = $db->prepare("INSERT INTO users_auth (login, password_hash) VALUES (?, ?)");
        $stmt->execute([$login, $passwordHash]);
        $authId = $db->lastInsertId();
        
        $stmt = $db->prepare("INSERT INTO bookings (full_name, phone, email, birth_date, gender, biography, agreed, user_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$input['full_name'], $input['phone'], $input['email'], $input['birth_date'], $input['gender'], $input['biography'] ?? '', $input['agreed'] ? 1 : 0, $authId]);
        $bookingId = $db->lastInsertId();
        
        if (!empty($input['pets'])) {
            $stmtPet = $db->prepare("SELECT id FROM pets WHERE name = ?");
            $stmtInsert = $db->prepare("INSERT INTO booking_pets (booking_id, pet_id) VALUES (?, ?)");
            foreach ($input['pets'] as $pet) {
                $stmtPet->execute([$pet]);
                $petId = $stmtPet->fetchColumn();
                if ($petId) $stmtInsert->execute([$bookingId, $petId]);
            }
        }
        
        $db->commit();
        
        echo json_encode(['success' => true, 'login' => $login, 'password' => $password, 'profile_url' => 'index.php'], JSON_UNESCAPED_UNICODE);
    } catch (Exception $e) {
        $db->rollBack();
        http_response_code(500);
        echo json_encode(['error' => 'Ошибка сохранения']);
    }
}

function handleGet($db, $id) {
    session_start();
    if (!isset($_SESSION['uid'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Необходима авторизация']);
        return;
    }
    
    $stmt = $db->prepare("SELECT * FROM bookings WHERE user_id = ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([(int)$id]);
    $booking = $stmt->fetch();
    
    if (!$booking) {
        http_response_code(404);
        echo json_encode(['error' => 'Не найдено']);
        return;
    }
    
    $stmtP = $db->prepare("SELECT p.name FROM booking_pets bp JOIN pets p ON bp.pet_id = p.id WHERE bp.booking_id = ?");
    $stmtP->execute([$booking['id']]);
    $booking['pets'] = $stmtP->fetchAll(PDO::FETCH_COLUMN);
    
    echo json_encode($booking, JSON_UNESCAPED_UNICODE);
}

function handlePut($db, $id, $input) {
    session_start();
    if (!isset($_SESSION['uid'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Необходима авторизация']);
        return;
    }
    
    $errors = Validator::validate($input);
    if (!empty($errors)) {
        http_response_code(400);
        echo json_encode(['errors' => $errors]);
        return;
    }
    
    try {
        $db->beginTransaction();
        
        $stmt = $db->prepare("SELECT id FROM bookings WHERE user_id = ? ORDER BY id DESC LIMIT 1");
        $stmt->execute([(int)$id]);
        $booking = $stmt->fetch();
        
        if (!$booking) {
            http_response_code(404);
            echo json_encode(['error' => 'Не найдено']);
            return;
        }
        
        $stmt = $db->prepare("UPDATE bookings SET full_name=?, phone=?, email=?, birth_date=?, gender=?, biography=?, agreed=? WHERE id=?");
        $stmt->execute([$input['full_name'], $input['phone'], $input['email'], $input['birth_date'], $input['gender'], $input['biography'] ?? '', $input['agreed'] ? 1 : 0, $booking['id']]);
        
        $db->exec("DELETE FROM booking_pets WHERE booking_id = " . (int)$booking['id']);
        
        if (!empty($input['pets'])) {
            $stmtPet = $db->prepare("SELECT id FROM pets WHERE name = ?");
            $stmtInsert = $db->prepare("INSERT INTO booking_pets (booking_id, pet_id) VALUES (?, ?)");
            foreach ($input['pets'] as $pet) {
                $stmtPet->execute([$pet]);
                $petId = $stmtPet->fetchColumn();
                if ($petId) $stmtInsert->execute([$booking['id'], $petId]);
            }
        }
        
        $db->commit();
        echo json_encode(['success' => true, 'message' => 'Обновлено']);
    } catch (Exception $e) {
        $db->rollBack();
        http_response_code(500);
        echo json_encode(['error' => 'Ошибка обновления']);
    }
}