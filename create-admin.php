<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit("Not found.\n");
}

$configPath = __DIR__ . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'config.php';
if (!is_file($configPath)) {
    fwrite(STDERR, "Missing api/config.php. Copy api/config.example.php and configure the database first.\n");
    exit(1);
}

$config = require $configPath;
if (!is_array($config) || !isset($config['dsn'], $config['db_user'], $config['db_pass'])) {
    fwrite(STDERR, "Database configuration is incomplete.\n");
    exit(1);
}

try {
    if (function_exists('stream_isatty') && stream_isatty(STDIN)) {
        fwrite(STDOUT, "Admin full name: ");
        $name = trim((string)fgets(STDIN));
        fwrite(STDOUT, "Admin email: ");
        $email = strtolower(trim((string)fgets(STDIN)));
        fwrite(STDOUT, "Admin password (input will be visible): ");
        $password = rtrim((string)fgets(STDIN), "\r\n");
    } else {
        $input = json_decode(stream_get_contents(STDIN), true);
        if (!is_array($input)) {
            throw new RuntimeException("Setup input is invalid.");
        }
        $name = trim((string)($input['name'] ?? ''));
        $email = strtolower(trim((string)($input['email'] ?? '')));
        $password = (string)($input['password'] ?? '');
    }
    if ($name === '' || mb_strlen($name, 'UTF-8') > 120 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException("Enter a name up to 120 characters and a valid email address.");
    }
    if (mb_strlen($email, 'UTF-8') > 191) {
        throw new RuntimeException("Admin email address must not exceed 191 characters.");
    }
    if (mb_strlen($password, 'UTF-8') < 12 || strlen($password) > 72) {
        throw new RuntimeException("Admin password must have at least 12 characters and no more than 72 UTF-8 bytes.");
    }

    $pdo = new PDO($config['dsn'], $config['db_user'], $config['db_pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    $exists = $pdo->query("SELECT id FROM users WHERE role = 'Admin' LIMIT 1")->fetchColumn();
    if ($exists !== false) {
        throw new RuntimeException("An Admin account already exists; this setup command can create only one.");
    }

    $statement = $pdo->prepare(
        "INSERT INTO users (full_name, role, login_identifier, password_hash)
         VALUES (:name, 'Admin', :identifier, :password_hash)"
    );
    $statement->execute([
        'name' => $name,
        'identifier' => $email,
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
    ]);
    fwrite(STDOUT, "Admin account created. Keep these credentials private.\n");
} catch (PDOException $error) {
    fwrite(STDERR, "Database setup failed. Check api/config.php and confirm the schema is imported.\n");
    exit(1);
} catch (RuntimeException $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
