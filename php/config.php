<?php
// ============================================
// FinShare - Configuration
// ============================================

define('DB_HOST', 'localhost');
define('DB_NAME', 'finshare');
define('DB_USER', 'root');
define('DB_PASS', '');
define('APP_NAME', 'FinShare');
define('APP_URL', 'http://localhost/finshare');

// Session lifetime (30 days)
ini_set('session.gc_maxlifetime', 2592000);
session_set_cookie_params(2592000);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ============================================
// DATABASE CONNECTION (PDO)
// ============================================
function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $pdo = new PDO(
                "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
                DB_USER,
                DB_PASS,
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]
            );
        } catch (PDOException $e) {
            die("Database connection failed: " . $e->getMessage());
        }
    }
    return $pdo;
}

// ============================================
// AUTH HELPERS
// ============================================
function isLoggedIn(): bool {
    return isset($_SESSION['user_id']);
}

function requireLogin(): void {
    if (!isLoggedIn()) {
        header('Location: ' . APP_URL . '/login.php');
        exit;
    }
}

function currentUser(): ?array {
    if (!isLoggedIn()) return null;
    static $user = null;
    if ($user === null) {
        $db   = getDB();
        $stmt = $db->prepare("SELECT id, name, email, created_at FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $user = $stmt->fetch();
    }
    return $user;
}

function initials(string $name): string {
    $parts = explode(' ', trim($name));
    $init  = strtoupper(substr($parts[0], 0, 1));
    if (count($parts) > 1) $init .= strtoupper(substr(end($parts), 0, 1));
    return $init;
}

// ============================================
// CSRF PROTECTION
// ============================================
function csrfToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verifyCsrf(): void {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== ($_SESSION['csrf_token'] ?? '')) {
            http_response_code(403);
            die('Invalid CSRF token');
        }
    }
}

// ============================================
// HELPERS
// ============================================
function formatRp(float $amount): string {
    return 'Rp ' . number_format($amount, 0, ',', '.');
}

function generateUUID(): string {
    return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );
}

function generateInviteCode(): string {
    return strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
}

function redirect(string $path): void {
    header("Location: " . APP_URL . "/" . ltrim($path, '/'));
    exit;
}

function flash(string $key, string $msg): void {
    $_SESSION['flash'][$key] = $msg;
}

function getFlash(string $key): ?string {
    if (isset($_SESSION['flash'][$key])) {
        $msg = $_SESSION['flash'][$key];
        unset($_SESSION['flash'][$key]);
        return $msg;
    }
    return null;
}

function sanitize(string $val): string {
    return htmlspecialchars(trim($val), ENT_QUOTES, 'UTF-8');
}

function timeAgo(string $datetime): string {
    $time = strtotime($datetime);
    $diff = time() - $time;
    if ($diff < 60)     return 'Baru saja';
    if ($diff < 3600)   return round($diff / 60) . ' menit lalu';
    if ($diff < 86400)  return round($diff / 3600) . ' jam lalu';
    if ($diff < 604800) return round($diff / 86400) . ' hari lalu';
    return date('d M Y', $time);
}

$CATEGORIES = [
    'Makanan'      => '🍛',
    'Transportasi' => '🚗',
    'Belanja'      => '🛍️',
    'Hiburan'      => '🎮',
    'Gaji'         => '💼',
    'Investasi'    => '📈',
    'Tabungan'     => '🏦',
    'Kesehatan'    => '💊',
    'Pendidikan'   => '📚',
    'Utilitas'     => '⚡',
    'Lainnya'      => '📌',
];