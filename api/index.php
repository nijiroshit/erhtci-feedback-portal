<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$isHttps = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
session_set_cookie_params([
    'httponly' => true,
    'secure' => $isHttps,
    'samesite' => 'Lax',
    'path' => '/',
]);
session_start();

const SESSION_IDLE_TIMEOUT = 1800;
const SESSION_MAX_LIFETIME = 43200;

if (isset($_SESSION['user'])) {
    $now = time();
    $lastActivity = (int)($_SESSION['last_activity'] ?? 0);
    $sessionStarted = (int)($_SESSION['session_started'] ?? 0);
    if (
        $lastActivity === 0 ||
        $sessionStarted === 0 ||
        $now - $lastActivity > SESSION_IDLE_TIMEOUT ||
        $now - $sessionStarted > SESSION_MAX_LIFETIME
    ) {
        $_SESSION = [];
        session_regenerate_id(true);
    } else {
        $_SESSION['last_activity'] = $now;
    }
}

function respond(array $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function requestData(): array
{
    if (str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'multipart/form-data')) {
        return $_POST;
    }
    $decoded = json_decode(file_get_contents('php://input'), true);
    return is_array($decoded) ? $decoded : [];
}

function requireUser(): array
{
    $user = $_SESSION['user'] ?? null;
    if (!is_array($user) || !isset($user['id'], $user['role'])) {
        respond(['error' => 'Please log in to continue.'], 401);
    }
    return $user;
}

function requireAdmin(): array
{
    $user = requireUser();
    if ($user['role'] !== 'Admin') {
        respond(['error' => 'This action is available to the administrator only.'], 403);
    }
    return $user;
}

function requireCsrf(): void
{
    $submitted = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    $expected = $_SESSION['csrf_token'] ?? '';
    if (!is_string($expected) || $expected === '' || !hash_equals($expected, $submitted)) {
        respond(['error' => 'Your session expired. Refresh the page and try again.'], 403);
    }
}

function consumeRateLimit(PDO $pdo, string $scope, string $key, int $maxAttempts, int $windowSeconds): bool
{
    $now = time();
    $bucket = hash('sha256', $scope . "\0" . $key);
    $statement = $pdo->prepare(
        'INSERT INTO auth_rate_limits (bucket_hash, attempts, window_started_at)
         VALUES (:bucket_insert, 1, :started_insert)
         ON DUPLICATE KEY UPDATE
           attempts = IF(window_started_at <= :expired_attempts, 1, attempts + 1),
           window_started_at = IF(window_started_at <= :expired_start, :started_update, window_started_at)'
    );
    $statement->execute([
        'bucket_insert' => $bucket,
        'started_insert' => $now,
        'expired_attempts' => $now - $windowSeconds,
        'expired_start' => $now - $windowSeconds,
        'started_update' => $now,
    ]);

    $statement = $pdo->prepare('SELECT attempts FROM auth_rate_limits WHERE bucket_hash = :bucket');
    $statement->execute(['bucket' => $bucket]);
    $attempts = (int)$statement->fetchColumn();

    if (random_int(1, 100) === 1) {
        $statement = $pdo->prepare(
            'DELETE FROM auth_rate_limits WHERE window_started_at < :stale_before LIMIT 100'
        );
        $statement->execute(['stale_before' => $now - 86400]);
    }

    return $attempts <= $maxAttempts;
}

function clearRateLimit(PDO $pdo, string $scope, string $key): void
{
    $statement = $pdo->prepare('DELETE FROM auth_rate_limits WHERE bucket_hash = :bucket');
    $statement->execute(['bucket' => hash('sha256', $scope . "\0" . $key)]);
}

function publicUser(array $user): array
{
    return [
        'id' => (int)$user['id'],
        'full_name' => $user['full_name'],
        'role' => $user['role'],
        'institutional_id' => $user['role'] === 'Student' ? $user['login_identifier'] : '',
        'institutional_email' => $user['role'] !== 'Student' ? $user['login_identifier'] : '',
    ];
}

$configPath = __DIR__ . DIRECTORY_SEPARATOR . 'config.php';
if (!is_file($configPath)) {
    respond(['error' => 'Server setup is incomplete. Configure api/config.php.'], 503);
}
$config = require $configPath;
if (!is_array($config) || !isset($config['dsn'], $config['db_user'], $config['db_pass'])) {
    respond(['error' => 'Server database configuration is incomplete.'], 503);
}

try {
    $pdo = new PDO($config['dsn'], $config['db_user'], $config['db_pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (PDOException $error) {
    error_log('ERHTCI database connection failed: ' . $error->getMessage());
    respond(['error' => 'The database is temporarily unavailable. Check the server configuration.'], 503);
}

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($action === 'bootstrap' && $method === 'GET') {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    $user = $_SESSION['user'] ?? null;
    respond([
        'csrfToken' => $_SESSION['csrf_token'],
        'user' => is_array($user) ? $user : null,
    ]);
}

if ($action === 'image' && $method === 'GET') {
    requireUser();
    $id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
    if (!$id) {
        http_response_code(404);
        exit;
    }

    $statement = $pdo->prepare('SELECT image_path FROM feedback WHERE id = :id LIMIT 1');
    $statement->execute(['id' => $id]);
    $relativePath = $statement->fetchColumn();
    if (!is_string($relativePath) || $relativePath === '') {
        http_response_code(404);
        exit;
    }

    $fileName = basename($relativePath);
    $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    $mimeTypes = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
    $imageDirectory = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads';
    $imagePath = $imageDirectory . DIRECTORY_SEPARATOR . $fileName;
    if (!isset($mimeTypes[$extension]) || !is_file($imagePath)) {
        http_response_code(404);
        exit;
    }

    header('Content-Type: ' . $mimeTypes[$extension]);
    header('Content-Length: ' . filesize($imagePath));
    header('Content-Disposition: inline');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');
    readfile($imagePath);
    exit;
}

if ($method === 'POST') {
    requireCsrf();
    $data = requestData();
} elseif ($method === 'GET' && in_array($action, ['feedbacks', 'profile', 'admin'], true)) {
    $data = [];
} else {
    respond(['error' => 'Method not allowed.'], 405);
}

try {
switch ($action) {
    case 'register':
        $remoteAddress = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        if (!consumeRateLimit($pdo, 'register-ip', $remoteAddress, 10, 3600)) {
            respond(['error' => 'Too many signup attempts. Please try again later.'], 429);
        }
        $name = trim((string)($data['fullName'] ?? ''));
        $role = (string)($data['role'] ?? '');
        $identifier = trim((string)($data['identifier'] ?? ''));
        $password = (string)($data['password'] ?? '');
        $allowedRoles = ['Student', 'Staff', 'Instructor'];
        if ($name === '' || mb_strlen($name, 'UTF-8') > 120 || !in_array($role, $allowedRoles, true)) {
            respond(['error' => 'Enter your name and select Student, Staff, or Instructor.'], 422);
        }
        if ($role === 'Student') {
            if (!preg_match('/^[A-Za-z]{3}\s\d{2}\s\d{3}\s\d{5}\s\d{3}$/', $identifier)) {
                respond(['error' => 'Enter your student ID in the format DMF 12 678 09876 765.'], 422);
            }
        } elseif (!filter_var($identifier, FILTER_VALIDATE_EMAIL)) {
            respond(['error' => 'Enter a valid email address.'], 422);
        }
        if (mb_strlen($password, 'UTF-8') < 12 || strlen($password) > 72) {
            respond(['error' => 'Choose a password with at least 12 characters and no more than 72 UTF-8 bytes.'], 422);
        }
        $normalizedIdentifier = strtolower($identifier);
        if (mb_strlen($normalizedIdentifier, 'UTF-8') > 191) {
            respond(['error' => 'The student ID or email address is too long.'], 422);
        }
        try {
            $statement = $pdo->prepare(
                'INSERT INTO users (full_name, role, login_identifier, password_hash)
                 VALUES (:name, :role, :identifier, :password_hash)'
            );
            $statement->execute([
                'name' => $name,
                'role' => $role,
                'identifier' => $normalizedIdentifier,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            ]);
        } catch (PDOException $error) {
            if ($error->getCode() === '23000') {
                respond(['error' => 'That student ID or email already has an account.'], 409);
            }
            throw $error;
        }
        respond(['message' => 'Account created. You can now log in.'], 201);

    case 'login':
        $identifier = strtolower(trim((string)($data['identifier'] ?? '')));
        $password = (string)($data['password'] ?? '');
        $remoteAddress = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        if (
            !consumeRateLimit($pdo, 'login-ip', $remoteAddress, 30, 900) ||
            !consumeRateLimit($pdo, 'login-identifier', $identifier, 10, 900)
        ) {
            respond(['error' => 'Too many login attempts. Please wait and try again.'], 429);
        }
        $statement = $pdo->prepare(
            'SELECT id, full_name, role, login_identifier, password_hash
             FROM users WHERE login_identifier = :identifier LIMIT 1'
        );
        $statement->execute(['identifier' => $identifier]);
        $user = $statement->fetch();
        if (!$user || !password_verify($password, $user['password_hash'])) {
            respond(['error' => 'The login details were not recognized.'], 401);
        }
        clearRateLimit($pdo, 'login-identifier', $identifier);
        session_regenerate_id(true);
        $_SESSION['user'] = publicUser($user);
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        $_SESSION['session_started'] = time();
        $_SESSION['last_activity'] = time();
        respond(['user' => $_SESSION['user'], 'csrfToken' => $_SESSION['csrf_token']]);

    case 'logout':
        requireUser();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 42000,
                'path' => $params['path'],
                'secure' => $params['secure'],
                'httponly' => $params['httponly'],
                'samesite' => $params['samesite'] ?? 'Lax',
            ]);
        }
        session_destroy();
        respond(['message' => 'Logged out.']);

    case 'feedbacks':
        requireUser();
        $statement = $pdo->query(
            "SELECT f.id, f.message, f.category, f.is_anonymous, f.status, f.image_path,
                    f.admin_response, f.created_at, u.full_name, u.role
             FROM feedback f JOIN users u ON u.id = f.user_id
             ORDER BY f.created_at DESC LIMIT 100"
        );
        $feedbacks = array_map(static function (array $item): array {
            return [
                'id' => (int)$item['id'],
                'name' => $item['is_anonymous'] ? 'Anonymous' : $item['full_name'],
                'submitterRole' => $item['role'],
                'message' => $item['message'],
                'category' => $item['category'],
                'status' => $item['status'],
                'response' => $item['admin_response'],
                'image' => $item['image_path'] ? 'api/index.php?action=image&id=' . (int)$item['id'] : '',
                'date' => date('m/d/Y', strtotime($item['created_at'])),
            ];
        }, $statement->fetchAll());
        respond(['feedbacks' => $feedbacks]);

    case 'profile':
        $sessionUser = requireUser();
        $userStatement = $pdo->prepare(
            'SELECT id, full_name, role, login_identifier
             FROM users WHERE id = :user_id LIMIT 1'
        );
        $userStatement->execute(['user_id' => $sessionUser['id']]);
        $userRecord = $userStatement->fetch();
        if (!$userRecord) {
            respond(['error' => 'This account could not be found. Please log in again.'], 401);
        }
        $user = publicUser($userRecord);
        $statement = $pdo->prepare(
            'SELECT message, category, status, admin_response, created_at
             FROM feedback WHERE user_id = :user_id ORDER BY created_at DESC'
        );
        $statement->execute(['user_id' => $user['id']]);
        $feedbacks = array_map(static function (array $item): array {
            return [
                'message' => $item['message'],
                'category' => $item['category'],
                'status' => $item['status'],
                'response' => $item['admin_response'],
                'date' => date('m/d/Y', strtotime($item['created_at'])),
            ];
        }, $statement->fetchAll());
        respond(['user' => $user, 'feedbacks' => $feedbacks]);

    case 'submit-feedback':
        $user = requireUser();
        $message = trim((string)($data['message'] ?? ''));
        $category = trim((string)($data['category'] ?? ''));
        $anonymous = filter_var($data['anonymous'] ?? true, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        $categories = ['Facilities', 'Instruction', 'Instructor', 'Operations', 'Administration', 'Student', 'Faculty Staff'];
        if ($message === '' || mb_strlen($message) > 500 || !in_array($category, $categories, true) || $anonymous === null) {
            respond(['error' => 'Enter a comment (up to 500 characters) and choose a valid category.'], 422);
        }

        $imagePath = null;
        if (isset($_FILES['image']) && $_FILES['image']['error'] !== UPLOAD_ERR_NO_FILE) {
            $upload = $_FILES['image'];
            if ($upload['error'] !== UPLOAD_ERR_OK || $upload['size'] > 5 * 1024 * 1024) {
                respond(['error' => 'The image upload failed or is larger than 5 MB.'], 422);
            }
            $mime = (new finfo(FILEINFO_MIME_TYPE))->file($upload['tmp_name']);
            $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
            if (!isset($extensions[$mime])) {
                respond(['error' => 'Attach a JPG, PNG, or WebP image.'], 422);
            }
            $uploadDirectory = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads';
            if (!is_dir($uploadDirectory) || !is_writable($uploadDirectory)) {
                respond(['error' => 'Image uploads are not configured on the server.'], 503);
            }
            $fileName = bin2hex(random_bytes(16)) . '.' . $extensions[$mime];
            if (!move_uploaded_file($upload['tmp_name'], $uploadDirectory . DIRECTORY_SEPARATOR . $fileName)) {
                respond(['error' => 'The image could not be saved.'], 500);
            }
            $imagePath = 'uploads/' . $fileName;
        }

        $statement = $pdo->prepare(
            'INSERT INTO feedback (user_id, message, category, is_anonymous, image_path)
             VALUES (:user_id, :message, :category, :anonymous, :image_path)'
        );
        $statement->execute([
            'user_id' => $user['id'],
            'message' => $message,
            'category' => $category,
            'anonymous' => $anonymous ? 1 : 0,
            'image_path' => $imagePath,
        ]);
        respond(['message' => 'Your feedback has been submitted.'], 201);

    case 'admin':
        requireAdmin();
        $statement = $pdo->query(
            'SELECT f.id, f.message, f.category, f.is_anonymous, f.status, f.admin_response,
                    f.image_path, f.created_at, u.full_name, u.role
             FROM feedback f JOIN users u ON u.id = f.user_id
             ORDER BY f.created_at DESC'
        );
        $feedbacks = array_map(static function (array $item): array {
            return [
                'id' => (int)$item['id'],
                'name' => $item['full_name'],
                'submitterRole' => $item['role'],
                'anonymous' => (bool)$item['is_anonymous'],
                'message' => $item['message'],
                'category' => $item['category'],
                'status' => $item['status'],
                'response' => $item['admin_response'],
                'image' => $item['image_path'] ? 'api/index.php?action=image&id=' . (int)$item['id'] : '',
                'date' => date('m/d/Y', strtotime($item['created_at'])),
            ];
        }, $statement->fetchAll());
        $counts = ['Pending' => 0, 'Under Review' => 0, 'Resolved' => 0];
        foreach ($feedbacks as $item) {
            $counts[$item['status']]++;
        }
        respond(['feedbacks' => $feedbacks, 'counts' => $counts, 'total' => count($feedbacks)]);

    case 'status':
        $admin = requireAdmin();
        $id = filter_var($data['id'] ?? null, FILTER_VALIDATE_INT);
        $status = (string)($data['status'] ?? '');
        $validStatuses = ['Pending', 'Under Review', 'Resolved'];
        if (!$id || !in_array($status, $validStatuses, true)) {
            respond(['error' => 'Select a valid report and status.'], 422);
        }
        $pdo->beginTransaction();
        $statement = $pdo->prepare('SELECT status FROM feedback WHERE id = :id FOR UPDATE');
        $statement->execute(['id' => $id]);
        $oldStatus = $statement->fetchColumn();
        if ($oldStatus === false) {
            $pdo->rollBack();
            respond(['error' => 'Feedback report not found.'], 404);
        }
        $statement = $pdo->prepare('UPDATE feedback SET status = :status WHERE id = :id');
        $statement->execute(['status' => $status, 'id' => $id]);
        $statement = $pdo->prepare(
            'INSERT INTO feedback_status_history (feedback_id, changed_by, old_status, new_status)
             VALUES (:feedback_id, :changed_by, :old_status, :new_status)'
        );
        $statement->execute([
            'feedback_id' => $id,
            'changed_by' => $admin['id'],
            'old_status' => $oldStatus,
            'new_status' => $status,
        ]);
        $pdo->commit();
        respond(['message' => 'Status updated.']);

    case 'response':
        requireAdmin();
        $id = filter_var($data['id'] ?? null, FILTER_VALIDATE_INT);
        $response = trim((string)($data['response'] ?? ''));
        if (!$id || mb_strlen($response) > 5000) {
            respond(['error' => 'Enter a valid report and response (up to 5000 characters).'], 422);
        }
        $statement = $pdo->prepare('UPDATE feedback SET admin_response = :response WHERE id = :id');
        $statement->execute(['response' => $response === '' ? null : $response, 'id' => $id]);
        if ($statement->rowCount() === 0) {
            $check = $pdo->prepare('SELECT id FROM feedback WHERE id = :id');
            $check->execute(['id' => $id]);
            if (!$check->fetchColumn()) {
                respond(['error' => 'Feedback report not found.'], 404);
            }
        }
        respond(['message' => 'Response saved.']);

    default:
        respond(['error' => 'Unknown action.'], 404);
}
} catch (PDOException $error) {
    error_log('ERHTCI API database operation failed: ' . $error->getMessage());
    respond(['error' => 'The request could not be completed because of a database error.'], 500);
}
