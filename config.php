<?php
declare(strict_types=1);

session_name('boxmail_session');
session_set_cookie_params([
    'httponly' => true,
    'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'samesite' => 'Lax',
]);
session_start();

function db(): PDO
{
    static $connection = null;

    if ($connection instanceof PDO) {
        return $connection;
    }

    $host = getenv('DB_HOST') ?: '127.0.0.1';
    $name = getenv('DB_NAME') ?: 'mailbox';
    $user = getenv('DB_USER') ?: 'root';
    $password = getenv('DB_PASSWORD') ?: '';

    try {
        $connection = new PDO(
            "mysql:host={$host};dbname={$name};charset=utf8mb4",
            $user,
            $password,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );
    } catch (PDOException $exception) {
        error_log('Boxmail database connection failed: ' . $exception->getMessage());
        throw new RuntimeException('Database connection failed.', 0, $exception);
    }

    return $connection;
}

function escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function app_url(string $path): string
{
    $configuredUrl = getenv('APP_URL');
    if ($configuredUrl !== false && $configuredUrl !== '') {
        $parts = parse_url($configuredUrl);
        if (
            $parts === false
            || !isset($parts['scheme'], $parts['host'])
            || !in_array(strtolower($parts['scheme']), ['http', 'https'], true)
            || isset($parts['user'])
            || isset($parts['pass'])
        ) {
            throw new RuntimeException('APP_URL must be an absolute HTTP or HTTPS URL.');
        }

        $baseUrl = rtrim($configuredUrl, '/');
    } else {
        $scheme = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http';
        $host = $_SERVER['SERVER_NAME'] ?? 'localhost';
        if (!preg_match('/\A[a-zA-Z0-9.-]+\z/', $host)) {
            throw new RuntimeException('Invalid server name.');
        }

        $port = (int) ($_SERVER['SERVER_PORT'] ?? 0);
        $portSuffix = $port > 0 && !(($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443))
            ? ':' . $port
            : '';
        $directory = str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/')));
        $baseUrl = $scheme . '://' . $host . $portSuffix . rtrim($directory, '/');
    }

    return $baseUrl . '/' . ltrim($path, '/');
}

function private_document_directory(): string
{
    $directory = getenv('MAIL_STORAGE_PATH');
    if ($directory === false || $directory === '') {
        $directory = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'private'
            . DIRECTORY_SEPARATOR . 'boxmail-documents';
    }

    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Unable to create private document directory.');
    }

    $realDirectory = realpath($directory);
    $documentRoot = isset($_SERVER['DOCUMENT_ROOT']) ? realpath($_SERVER['DOCUMENT_ROOT']) : false;
    if ($realDirectory === false || ($documentRoot !== false && (
        $realDirectory === $documentRoot
        || str_starts_with(
            strtolower($realDirectory . DIRECTORY_SEPARATOR),
            strtolower(rtrim($documentRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)
        )
    ))) {
        throw new RuntimeException('Document storage must be outside the web root.');
    }

    return $realDirectory;
}

function csrf_token(): string
{
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function csrf_is_valid(): bool
{
    return isset($_POST['csrf_token'], $_SESSION['csrf_token'])
        && is_string($_POST['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $_POST['csrf_token']);
}
