<?php
require_once __DIR__ . '/php/config.php';

if (isLoggedIn()) redirect('index.php');

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (!$email || !$password) {
        $error = 'Email dan password wajib diisi.';
    } else {
        try {
            $db   = getDB();
            $stmt = $db->prepare("SELECT * FROM users WHERE email = ? LIMIT 1");
            $stmt->execute([$email]);
            $u = $stmt->fetch();

            if ($u && password_verify($password, $u['password_hash'])) {
                session_regenerate_id(true);
                $_SESSION['user_id'] = $u['id'];
                // Audit log
                $lid = generateUUID();
                $db->prepare("INSERT INTO audit_log (id,user_id,action,ip_address) VALUES (?,?,?,?)")
                   ->execute([$lid, $u['id'], 'login', $_SERVER['REMOTE_ADDR'] ?? '']);
                redirect('index.php');
            } else {
                $error = 'Email atau password salah.';
            }
        } catch (Exception $e) {
            $error = 'Terjadi kesalahan sistem.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Login – FinShare</title>
  <link rel="stylesheet" href="<?= APP_URL ?>/css/style.css">
</head>
<body>
<div class="auth-wrapper">
  <div class="auth-glow g1"></div>
  <div class="auth-glow g2"></div>

  <div class="auth-card">
    <div class="auth-logo">
      <div class="auth-logo-icon">💸</div>
      <div class="auth-logo-text">Fin<span>Share</span></div>
    </div>

    <h1 class="auth-title">Selamat Datang!</h1>
    <p class="auth-sub">Masuk ke akun FinShare Anda</p>

    <?php if ($error): ?>
      <div class="alert alert-error">⚠ <?= sanitize($error) ?></div>
    <?php endif; ?>
    <?php if ($s = getFlash('success')): ?>
      <div class="alert alert-success">✓ <?= sanitize($s) ?></div>
    <?php endif; ?>

    <form method="POST" action="" data-validate>
      <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">

      <div class="form-group">
        <label class="form-label">Email</label>
        <input class="form-control" type="email" name="email"
               value="<?= sanitize($_POST['email'] ?? '') ?>"
               placeholder="nama@email.com" required>
      </div>

      <div class="form-group">
        <label class="form-label">Password</label>
        <input class="form-control" type="password" name="password"
               placeholder="Masukkan password" required>
      </div>

      <button type="submit" class="btn btn-primary mt-2">
        Masuk ke Akun
      </button>
    </form>

    <div class="auth-footer">
      Belum punya akun? <a href="<?= APP_URL ?>/register.php">Daftar Sekarang</a>
    </div>
  </div>
</div>
<script src="<?= APP_URL ?>/js/app.js"></script>
</body>
</html>