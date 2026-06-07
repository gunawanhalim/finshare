<?php
require_once __DIR__ . '/php/config.php';
requireLogin();
$user = currentUser();
$db   = getDB();

// Personal account
$stmt = $db->prepare("SELECT a.* FROM accounts a
    JOIN account_members am ON am.account_id = a.id
    WHERE am.user_id = ? AND a.account_type = 'personal' LIMIT 1");
$stmt->execute([$user['id']]);
$personal = $stmt->fetch();

// Joint accounts
$stmt = $db->prepare("SELECT a.*, am.role,
    (SELECT COUNT(*) FROM account_members WHERE account_id = a.id) as member_count
    FROM accounts a JOIN account_members am ON am.account_id = a.id
    WHERE am.user_id = ? AND a.account_type = 'joint'");
$stmt->execute([$user['id']]);
$joints = $stmt->fetchAll();

// Monthly stats for personal account
$thisMonth = date('Y-m');
$stmt = $db->prepare("SELECT
    SUM(CASE WHEN type='income' THEN amount ELSE 0 END) as total_income,
    SUM(CASE WHEN type='expense' THEN amount ELSE 0 END) as total_expense
    FROM transactions
    WHERE account_id = ? AND DATE_FORMAT(created_at,'%Y-%m') = ?");
$stmt->execute([$personal['id'] ?? '', $thisMonth]);
$monthStats = $stmt->fetch();

// Recent transactions (personal)
$stmt = $db->prepare("SELECT t.*, u.name as user_name FROM transactions t
    JOIN users u ON u.id = t.user_id
    WHERE t.account_id = ?
    ORDER BY t.created_at DESC LIMIT 8");
$stmt->execute([$personal['id'] ?? '']);
$recentTx = $stmt->fetchAll();

// Savings goals (all accounts user belongs to)
$stmt = $db->prepare("SELECT sg.*, a.account_name FROM savings_goals sg
    JOIN accounts a ON a.id = sg.account_id
    JOIN account_members am ON am.account_id = a.id
    WHERE am.user_id = ? AND sg.is_completed = 0
    ORDER BY sg.due_date ASC LIMIT 5");
$stmt->execute([$user['id']]);
$savingGoals = $stmt->fetchAll();

// Bar chart: last 6 months
$chartData = [];
for ($i = 5; $i >= 0; $i--) {
    $m = date('Y-m', strtotime("-$i months"));
    $stmt = $db->prepare("SELECT
        SUM(CASE WHEN type='income' THEN amount ELSE 0 END) as income,
        SUM(CASE WHEN type='expense' THEN amount ELSE 0 END) as expense
        FROM transactions WHERE account_id = ? AND DATE_FORMAT(created_at,'%Y-%m') = ?");
    $stmt->execute([$personal['id'] ?? '', $m]);
    $row = $stmt->fetch();
    $chartData[] = [
        'label'   => date('M', strtotime($m . '-01')),
        'income'  => (float)($row['income'] ?? 0),
        'expense' => (float)($row['expense'] ?? 0),
    ];
}

// Expense by category (this month)
$stmt = $db->prepare("SELECT category, SUM(amount) as total
    FROM transactions WHERE account_id = ? AND type='expense'
    AND DATE_FORMAT(created_at,'%Y-%m') = ?
    GROUP BY category ORDER BY total DESC LIMIT 6");
$stmt->execute([$personal['id'] ?? '', $thisMonth]);
$catStats = $stmt->fetchAll();

$catColors = ['#4ade80','#22d3ee','#f472b6','#fbbf24','#a78bfa','#fb923c'];
$donutData = [];
foreach ($catStats as $i => $cat) {
    $donutData[] = [
        'label' => $cat['category'],
        'value' => (float)$cat['total'],
        'color' => $catColors[$i % count($catColors)],
    ];
}

global $CATEGORIES;
require_once __DIR__ . '/php/config.php';
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Dashboard – FinShare</title>
  <link rel="stylesheet" href="<?= APP_URL ?>/css/style.css">
</head>
<body>
<div class="app-layout">
  <?php include __DIR__ . '/php/layout.php'; ?>

  <main class="main-content">
    <div class="page-header">
      <div>
        <h1 class="page-title">Halo, <span><?= sanitize(explode(' ', $user['name'])[0]) ?></span> 👋</h1>
        <p class="page-sub"><?= date('l, d F Y') ?></p>
      </div>
      <a href="<?= APP_URL ?>/wallet.php?action=add" class="btn btn-primary btn-sm">+ Tambah Transaksi</a>
    </div>

    <?php if ($s = getFlash('success')): ?>
      <div class="alert alert-success mb-2">✓ <?= sanitize($s) ?></div>
    <?php endif; ?>

    <!-- STAT CARDS -->
    <div class="stat-grid">
      <div class="stat-card green">
        <div class="stat-icon green">💰</div>
        <div class="stat-label">Saldo Pribadi</div>
        <div class="stat-value" data-counter data-target="<?= $personal['balance'] ?? 0 ?>">
          <?= formatRp((float)($personal['balance'] ?? 0)) ?>
        </div>
        <div class="stat-sub">Dompet Anda</div>
      </div>

      <div class="stat-card cyan">
        <div class="stat-icon cyan">📈</div>
        <div class="stat-label">Pemasukan Bulan Ini</div>
        <div class="stat-value"><?= formatRp((float)($monthStats['total_income'] ?? 0)) ?></div>
        <div class="stat-sub"><?= date('F Y') ?></div>
      </div>

      <div class="stat-card pink">
        <div class="stat-icon pink">📉</div>
        <div class="stat-label">Pengeluaran Bulan Ini</div>
        <div class="stat-value"><?= formatRp((float)($monthStats['total_expense'] ?? 0)) ?></div>
        <div class="stat-sub"><?= date('F Y') ?></div>
      </div>

      <div class="stat-card yellow">
        <div class="stat-icon yellow">🤝</div>
        <div class="stat-label">Joint Accounts</div>
        <div class="stat-value" style="font-size:2rem"><?= count($joints) ?></div>
        <div class="stat-sub">Akun Bersama</div>
      </div>
    </div>

    <!-- MAIN GRID -->
    <div class="dashboard-grid">

      <!-- LEFT COLUMN -->
      <div>
        <!-- Chart -->
        <div class="card mb-2">
          <div class="flex-between mb-2">
            <div class="card-title">Pemasukan vs Pengeluaran</div>
            <div class="flex-gap">
              <span style="font-size:0.72rem;color:var(--accent)">● Pemasukan</span>
              <span style="font-size:0.72rem;color:var(--danger)">● Pengeluaran</span>
            </div>
          </div>
          <div class="bar-chart" id="barChart" style="height:160px"></div>
          <div style="font-size:0.72rem;color:var(--muted);text-align:center;margin-top:0.5rem">6 Bulan Terakhir</div>
        </div>

        <!-- Recent Transactions -->
        <div class="card">
          <div class="flex-between mb-2">
            <div class="card-title">Transaksi Terbaru</div>
            <a href="<?= APP_URL ?>/wallet.php" style="font-size:0.8rem;color:var(--accent)">Lihat Semua →</a>
          </div>
          <?php if (empty($recentTx)): ?>
            <div class="empty-state">
              <div class="empty-icon">💳</div>
              <div class="empty-text">Belum ada transaksi</div>
              <div class="empty-sub">Mulai catat pemasukan atau pengeluaran Anda</div>
            </div>
          <?php else: ?>
            <div class="tx-list">
              <?php
              global $CATEGORIES;
              foreach ($recentTx as $tx):
                $isIncome = in_array($tx['type'], ['income','transfer_in']);
                $icon = $CATEGORIES[$tx['category']] ?? '📌';
              ?>
                <div class="tx-item">
                  <div class="tx-icon <?= $isIncome ? 'income' : 'expense' ?>">
                    <?= $icon ?>
                  </div>
                  <div class="tx-info">
                    <div class="tx-desc"><?= sanitize($tx['description'] ?: $tx['category']) ?></div>
                    <div class="tx-cat"><?= sanitize($tx['category']) ?></div>
                  </div>
                  <div>
                    <div class="tx-amount <?= $isIncome ? 'income' : 'expense' ?>">
                      <?= ($isIncome ? '+' : '-') . formatRp((float)$tx['amount']) ?>
                    </div>
                    <div class="tx-date" style="text-align:right"><?= timeAgo($tx['created_at']) ?></div>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- RIGHT COLUMN -->
      <div>
        <!-- Category Breakdown -->
        <div class="card mb-2">
          <div class="card-title mb-2">Pengeluaran per Kategori</div>
          <?php if (!empty($donutData)): ?>
            <div class="donut-wrap">
              <canvas id="donutChart" class="donut-chart" width="120" height="120"></canvas>
              <div class="legend">
                <?php foreach ($donutData as $i => $d): ?>
                  <div class="legend-item">
                    <div class="legend-dot" style="background:<?= $d['color'] ?>"></div>
                    <div class="legend-name"><?= sanitize($d['label']) ?></div>
                    <div class="legend-val"><?= formatRp($d['value']) ?></div>
                  </div>
                <?php endforeach; ?>
              </div>
            </div>
          <?php else: ?>
            <div class="empty-state" style="padding:1.5rem">
              <div class="empty-icon">📊</div>
              <div class="empty-sub">Belum ada data bulan ini</div>
            </div>
          <?php endif; ?>
        </div>

        <!-- Savings Progress -->
        <div class="card">
          <div class="flex-between mb-2">
            <div class="card-title">Progress Tabungan</div>
            <a href="<?= APP_URL ?>/savings.php" style="font-size:0.8rem;color:var(--accent)">Lihat →</a>
          </div>
          <?php if (empty($savingGoals)): ?>
            <div class="empty-state" style="padding:1.5rem">
              <div class="empty-icon">🎯</div>
              <div class="empty-sub">Belum ada target tabungan</div>
            </div>
          <?php else: ?>
            <?php foreach ($savingGoals as $sg):
              $pct = $sg['target_amount'] > 0 ? min(100, round(($sg['current_amount'] / $sg['target_amount']) * 100)) : 0;
            ?>
              <div class="saving-item">
                <div class="saving-header">
                  <div class="saving-title"><?= sanitize($sg['emoji'] . ' ' . $sg['title']) ?></div>
                  <div class="saving-pct"><?= $pct ?>%</div>
                </div>
                <div class="progress-bar">
                  <div class="progress-fill" data-width="<?= $pct ?>" style="width:0%"></div>
                </div>
                <div class="saving-meta">
                  <?= formatRp((float)$sg['current_amount']) ?> / <?= formatRp((float)$sg['target_amount']) ?>
                  <?php if ($sg['due_date']): ?>
                    · Target: <?= date('d M Y', strtotime($sg['due_date'])) ?>
                  <?php endif; ?>
                </div>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>

        <!-- Joint Accounts Preview -->
        <?php if (!empty($joints)): ?>
        <div class="card mt-2">
          <div class="flex-between mb-2">
            <div class="card-title">Joint Accounts</div>
            <a href="<?= APP_URL ?>/joint.php" style="font-size:0.8rem;color:var(--accent)">Kelola →</a>
          </div>
          <?php foreach (array_slice($joints, 0, 3) as $j): ?>
            <div style="padding:0.75rem 0; border-bottom:1px solid var(--border);">
              <div class="flex-between">
                <div>
                  <div style="font-size:0.88rem;font-weight:600"><?= sanitize($j['account_name']) ?></div>
                  <div style="font-size:0.75rem;color:var(--muted)"><?= $j['member_count'] ?> anggota</div>
                </div>
                <div style="text-align:right">
                  <div style="font-family:var(--font-head);font-size:0.95rem;color:var(--accent2)">
                    <?= formatRp((float)$j['balance']) ?>
                  </div>
                  <div class="badge badge-<?= $j['role']==='owner'?'yellow':'cyan' ?>" style="font-size:0.65rem">
                    <?= $j['role'] === 'owner' ? 'Pemilik' : 'Anggota' ?>
                  </div>
                </div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>

    </div><!-- end dashboard-grid -->
  </main>
</div>

<script>
window.chartData = <?= json_encode($chartData) ?>;
window.donutData = <?= json_encode($donutData) ?>;
</script>
<script src="<?= APP_URL ?>/js/app.js"></script>
<script>
// Render donut with Canvas
const canvas = document.getElementById('donutChart');
if (canvas && window.donutData && window.donutData.length) {
  const ctx = canvas.getContext('2d');
  const data = window.donutData;
  const total = data.reduce((s,d) => s + d.value, 0);
  let start = -Math.PI / 2;
  data.forEach(d => {
    const angle = (d.value / total) * 2 * Math.PI;
    ctx.beginPath();
    ctx.moveTo(60, 60);
    ctx.arc(60, 60, 55, start, start + angle);
    ctx.closePath();
    ctx.fillStyle = d.color;
    ctx.fill();
    start += angle;
  });
  // Donut hole
  ctx.beginPath();
  ctx.arc(60, 60, 30, 0, 2 * Math.PI);
  ctx.fillStyle = getComputedStyle(document.body).getPropertyValue('--bg2').trim() || '#111520';
  ctx.fill();
}
</script>
</body>
</html>