<?php
// php/layout.php - Reusable app layout
require_once __DIR__ . '/config.php';
requireLogin();
$user = currentUser();
$notifCount = 0;
try {
    $db = getDB();
    $stmt = $db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
    $stmt->execute([$user['id']]);
    $notifCount = (int)$stmt->fetchColumn();
} catch (Exception $e) {}

$pages = [
    ['href' => 'index.php',         'icon' => '⬡',  'label' => 'Dashboard'],
    ['href' => 'wallet.php',        'icon' => '💳',  'label' => 'Dompet Pribadi'],
    ['href' => 'joint.php',         'icon' => '🤝',  'label' => 'Joint Account'],
    ['href' => 'savings.php',       'icon' => '🎯',  'label' => 'Tabungan Bersama'],
    ['href' => 'transfer.php',      'icon' => '↔️',  'label' => 'Transfer'],
    ['href' => 'report.php',        'icon' => '📊',  'label' => 'Laporan'],
    ['href' => 'notifications.php', 'icon' => '🔔',  'label' => 'Notifikasi' . ($notifCount > 0 ? " ($notifCount)" : '')],
];

$currentPage = basename($_SERVER['PHP_SELF']);
?>
<!-- Mobile toggle -->
<button class="menu-toggle" id="menuToggle">☰</button>
<div class="sidebar-overlay" id="sidebarOverlay"></div>

<!-- SIDEBAR -->
<nav class="sidebar" id="sidebar">
  <div class="sidebar-logo">
    <div class="logo-icon">💸</div>
    <div class="logo-text">Fin<span>Share</span></div>
  </div>

  <nav class="sidebar-nav">
    <div class="nav-section-label">Menu Utama</div>
    <?php foreach ($pages as $page): ?>
      <a href="<?= APP_URL . '/' . $page['href'] ?>"
         class="nav-item <?= $currentPage === $page['href'] ? 'active' : '' ?>">
        <span class="nav-icon"><?= $page['icon'] ?></span>
        <?= sanitize($page['label']) ?>
      </a>
    <?php endforeach; ?>
  </nav>

  <div class="sidebar-user">
    <div class="user-avatar"><?= initials($user['name']) ?></div>
    <div class="user-info">
      <div class="user-name"><?= sanitize($user['name']) ?></div>
      <div class="user-email"><?= sanitize($user['email']) ?></div>
    </div>
    <a href="<?= APP_URL ?>/logout.php" class="sidebar-logout" title="Logout">⎋</a>
  </div>
</nav>