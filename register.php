<?php
require_once __DIR__ . '/php/config.php';
if (isLoggedIn()) redirect('index.php');

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $name     = trim($_POST['name'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm  = $_POST['confirm'] ?? '';

    if (!$name || !$email || !$password) {
        $error = 'Semua field wajib diisi.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Format email tidak valid.';
    } elseif (strlen($password) < 8) {
        $error = 'Password minimal 8 karakter.';
    } elseif ($password !== $confirm) {
        $error = 'Konfirmasi password tidak cocok.';
    } else {
        try {
            $db = getDB();
            // Check email unique
            $chk = $db->prepare("SELECT id FROM users WHERE email = ?");
            $chk->execute([$email]);
            if ($chk->fetch()) {
                $error = 'Email sudah terdaftar.';
            } else {
                $uid  = generateUUID();
                $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

                $db->prepare("INSERT INTO users (id,name,email,password_hash) VALUES (?,?,?,?)")
                   ->execute([$uid, $name, $email, $hash]);

                // Create personal account
                $aid = generateUUID();
                $db->prepare("INSERT INTO accounts (id,account_name,account_type,owner_id,balance) VALUES (?,?,?,?,?)")
                   ->execute([$aid, "Dompet {$name}", 'personal', $uid, 0]);

                // Add owner as member
                $mid = generateUUID();
                $db->prepare("INSERT INTO account_members (id,account_id,user_id,role) VALUES (?,?,?,?)")
                   ->execute([$mid, $aid, $uid, 'owner']);

                // Welcome notification
                $nid = generateUUID();
                $db->prepare("INSERT INTO notifications (id,user_id,message,type) VALUES (?,?,?,?)")
                   ->execute([$nid, $uid, "Selamat datang di FinShare, {$name}! Mulai kelola keuangan Anda.", 'welcome']);

                flash('success', 'Registrasi berhasil! Silakan login.');
                redirect('login.php');
            }
        } catch (Exception $e) {
            $error = 'Gagal mendaftar. Coba lagi.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Daftar – FinShare</title>
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

    <h1 class="auth-title">Buat Akun Baru</h1>
    <p class="auth-sub">Mulai kelola keuangan pribadi & bersama</p>

    <?php if ($error): ?>
      <div class="alert alert-error">⚠ <?= sanitize($error) ?></div>
    <?php endif; ?>

    <form method="POST" action="" data-validate>
      <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">

      <div class="form-group">
        <label class="form-label">Nama Lengkap</label>
        <input class="form-control" type="text" name="name"
               value="<?= sanitize($_POST['name'] ?? '') ?>"
               placeholder="Nama Anda" required>
      </div>

      <div class="form-group">
        <label class="form-label">Email</label>
        <input class="form-control" type="email" name="email"
               value="<?= sanitize($_POST['email'] ?? '') ?>"
               placeholder="nama@email.com" required>
      </div>

      <div class="form-group">
        <label class="form-label">Password</label>
        <input class="form-control" type="password" name="password"
               placeholder="Min. 8 karakter" required>
      </div>

      <div class="form-group">
        <label class="form-label">Konfirmasi Password</label>
        <input class="form-control" type="password" name="confirm"
               placeholder="Ulangi password" required>
      </div>

      <button type="submit" class="btn btn-primary mt-2">
        Daftar Sekarang
      </button>
    </form>

    <div class="auth-footer">
      Sudah punya akun? <a href="<?= APP_URL ?>/login.php">Masuk</a>
    </div>
  </div>
</div>
<script src="<?= APP_URL ?>/js/app.js"></script>
</body>
</html>