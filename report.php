<?php
require_once __DIR__ . '/php/config.php';
requireLogin();
$user = currentUser();
$db   = getDB();

$stmt = $db->prepare("SELECT a.* FROM accounts a JOIN account_members am ON am.account_id = a.id WHERE am.user_id = ? AND a.account_type = 'personal' LIMIT 1");
$stmt->execute([$user['id']]);
$personal = $stmt->fetch();

$period = $_GET['period'] ?? 'monthly';
$month  = $_GET['month']  ?? date('Y-m');
$year   = $_GET['year']   ?? date('Y');

// Build WHERE clause based on period
switch ($period) {
    case 'daily':
        $dateFilter = "DATE(created_at) = CURDATE()";
        $params = [$personal['id'] ?? ''];
        $label = 'Hari Ini';
        break;
    case 'weekly':
        $dateFilter = "YEARWEEK(created_at) = YEARWEEK(NOW())";
        $params = [$personal['id'] ?? ''];
        $label = 'Minggu Ini';
        break;
    case 'yearly':
        $dateFilter = "YEAR(created_at) = ?";
        $params = [$personal['id'] ?? '', $year];
        $label = 'Tahun ' . $year;
        break;
    default: // monthly
        $dateFilter = "DATE_FORMAT(created_at,'%Y-%m') = ?";
        $params = [$personal['id'] ?? '', $month];
        $label = date('F Y', strtotime($month . '-01'));
}

$stmt = $db->prepare("SELECT
    SUM(CASE WHEN type='income' THEN amount ELSE 0 END) as total_income,
    SUM(CASE WHEN type='expense' THEN amount ELSE 0 END) as total_expense,
    COUNT(*) as tx_count
    FROM transactions WHERE account_id = ? AND {$dateFilter}");
$stmt->execute($params);
$summary = $stmt->fetch();
$net = (float)($summary['total_income'] ?? 0) - (float)($summary['total_expense'] ?? 0);

// Category breakdown
$stmt2 = $db->prepare("SELECT category, type, SUM(amount) as total, COUNT(*) as cnt
    FROM transactions WHERE account_id = ? AND {$dateFilter}
    GROUP BY category, type ORDER BY total DESC");
$stmt2->execute($params);
$byCategory = $stmt2->fetchAll();

$expenseCats = array_filter($byCategory, fn($c) => $c['type'] === 'expense');
$incomeCats  = array_filter($byCategory, fn($c) => $c['type'] === 'income');

// Monthly trend (last 6 months)
$trend = [];
for ($i = 5; $i >= 0; $i--) {
    $m = date('Y-m', strtotime("-$i months"));
    $st = $db->prepare("SELECT SUM(CASE WHEN type='income' THEN amount ELSE 0 END) as inc,
        SUM(CASE WHEN type='expense' THEN amount ELSE 0 END) as exp
        FROM transactions WHERE account_id = ? AND DATE_FORMAT(created_at,'%Y-%m') = ?");
    $st->execute([$personal['id'] ?? '', $m]);
    $row = $st->fetch();
    $trend[] = ['label' => date('M', strtotime($m.'-01')), 'income' => (float)($row['inc']??0), 'expense' => (float)($row['exp']??0)];
}

global $CATEGORIES;
$catColors = ['#4ade80','#22d3ee','#f472b6','#fbbf24','#a78bfa','#fb923c','#38bdf8','#e879f9'];
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Laporan – FinShare</title>
  <link rel="stylesheet" href="<?= APP_URL ?>/css/style.css">
</head>
<body>
<div class="app-layout">
  <?php include __DIR__ . '/php/layout.php'; ?>
  <main class="main-content">
    <div class="page-header">
      <div>
        <h1 class="page-title">Laporan <span>Keuangan</span></h1>
        <p class="page-sub">Analisis pola keuangan Anda</p>
      </div>
    </div>

    <!-- Period Filter -->
    <div class="card mb-2">
      <form method="GET" class="flex-gap" style="flex-wrap:wrap;align-items:flex-end">
        <div>
          <label class="form-label">Periode</label>
          <select class="form-control" name="period" onchange="this.form.submit()">
            <?php foreach(['daily'=>'Harian','weekly'=>'Mingguan','monthly'=>'Bulanan','yearly'=>'Tahunan'] as $val=>$lbl): ?>
              <option value="<?= $val ?>" <?= $period===$val?'selected':'' ?>><?= $lbl ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php if ($period === 'monthly'): ?>
        <div>
          <label class="form-label">Bulan</label>
          <input class="form-control" type="month" name="month" value="<?= sanitize($month) ?>" onchange="this.form.submit()">
        </div>
        <?php elseif ($period === 'yearly'): ?>
        <div>
          <label class="form-label">Tahun</label>
          <select class="form-control" name="year" onchange="this.form.submit()">
            <?php for($y=date('Y');$y>=date('Y')-5;$y--): ?>
              <option value="<?= $y ?>" <?= $year==$y?'selected':'' ?>><?= $y ?></option>
            <?php endfor; ?>
          </select>
        </div>
        <?php endif; ?>
      </form>
    </div>

    <!-- Summary -->
    <div class="stat-grid mb-2">
      <div class="stat-card green">
        <div class="stat-icon green">📈</div>
        <div class="stat-label">Total Pemasukan</div>
        <div class="stat-value"><?= formatRp((float)($summary['total_income']??0)) ?></div>
        <div class="stat-sub"><?= $label ?></div>
      </div>
      <div class="stat-card pink">
        <div class="stat-icon pink">📉</div>
        <div class="stat-label">Total Pengeluaran</div>
        <div class="stat-value"><?= formatRp((float)($summary['total_expense']??0)) ?></div>
        <div class="stat-sub"><?= $label ?></div>
      </div>
      <div class="stat-card <?= $net >= 0 ? 'cyan' : 'yellow' ?>">
        <div class="stat-icon <?= $net >= 0 ? 'cyan' : 'yellow' ?>"><?= $net >= 0 ? '💹' : '⚠️' ?></div>
        <div class="stat-label">Selisih Bersih</div>
        <div class="stat-value" style="color:<?= $net>=0?'var(--accent)':'var(--danger)' ?>"><?= ($net>=0?'+':'') . formatRp(abs($net)) ?></div>
        <div class="stat-sub"><?= $summary['tx_count'] ?> transaksi</div>
      </div>
    </div>

    <div class="dashboard-grid">
      <div>
        <!-- Bar Chart -->
        <div class="card mb-2">
          <div class="flex-between mb-2">
            <div class="card-title">Tren 6 Bulan</div>
            <div class="flex-gap">
              <span style="font-size:0.72rem;color:var(--accent)">● Masuk</span>
              <span style="font-size:0.72rem;color:var(--danger)">● Keluar</span>
            </div>
          </div>
          <div class="bar-chart" id="barChart" style="height:160px"></div>
        </div>

        <!-- Expense by Category Table -->
        <div class="card">
          <div class="card-title mb-2">Pengeluaran per Kategori</div>
          <?php if (empty($expenseCats)): ?>
            <div class="empty-state" style="padding:1.5rem"><div class="empty-sub">Belum ada data</div></div>
          <?php else: ?>
            <?php
            $totalExp = array_sum(array_column(iterator_to_array((function() use ($expenseCats) { foreach ($expenseCats as $c) { yield $c; } })(), false), 'total'));
            foreach ($expenseCats as $i => $c):
              $pct = $totalExp > 0 ? round(($c['total'] / $totalExp) * 100) : 0;
              $icon = $CATEGORIES[$c['category']] ?? '📌';
              $color = $catColors[$i % count($catColors)];
            ?>
              <div style="margin-bottom:0.75rem">
                <div class="flex-between mb-1">
                  <span style="font-size:0.85rem"><?= $icon ?> <?= sanitize($c['category']) ?></span>
                  <span style="font-size:0.85rem;font-weight:600"><?= formatRp((float)$c['total']) ?> <span style="color:var(--muted);font-weight:400">(<?= $pct ?>%)</span></span>
                </div>
                <div class="progress-bar" style="height:5px">
                  <div class="progress-fill" data-width="<?= $pct ?>" style="width:0%;background:<?= $color ?>"></div>
                </div>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>

      <div>
        <!-- Donut Chart -->
        <div class="card mb-2">
          <div class="card-title mb-2">Distribusi Pengeluaran</div>
          <?php if (!empty($expenseCats)): ?>
            <div class="donut-wrap">
              <canvas id="donutChart" width="120" height="120" style="border-radius:50%;flex-shrink:0"></canvas>
              <div class="legend">
                <?php foreach (array_slice(array_values(array_filter($byCategory, fn($c) => $c['type']==='expense')), 0, 6) as $i => $c): ?>
                  <div class="legend-item">
                    <div class="legend-dot" style="background:<?= $catColors[$i % count($catColors)] ?>"></div>
                    <div class="legend-name"><?= sanitize($c['category']) ?></div>
                    <div class="legend-val" style="font-size:0.75rem"><?= round(($c['total'] / max(1, array_sum(array_column(array_filter($byCategory, fn($x)=>$x['type']==='expense'), 'total')))) * 100) ?>%</div>
                  </div>
                <?php endforeach; ?>
              </div>
            </div>
          <?php else: ?>
            <div class="empty-state" style="padding:1.5rem"><div class="empty-sub">Belum ada pengeluaran</div></div>
          <?php endif; ?>
        </div>

        <!-- Income by Category -->
        <div class="card">
          <div class="card-title mb-2">Sumber Pemasukan</div>
          <?php if (empty($incomeCats)): ?>
            <div class="empty-state" style="padding:1.5rem"><div class="empty-sub">Belum ada data</div></div>
          <?php else: ?>
            <?php foreach ($incomeCats as $c):
              $icon = $CATEGORIES[$c['category']] ?? '📌';
            ?>
              <div style="display:flex;justify-content:space-between;align-items:center;padding:0.6rem 0;border-bottom:1px solid var(--border)">
                <span style="font-size:0.85rem"><?= $icon ?> <?= sanitize($c['category']) ?></span>
                <span style="color:var(--accent);font-weight:600;font-size:0.85rem">+<?= formatRp((float)$c['total']) ?></span>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </main>
</div>

<script>
window.chartData = <?= json_encode($trend) ?>;
<?php
$donutRaw = array_values(array_slice(array_filter($byCategory, fn($c) => $c['type']==='expense'), 0, 6));
$donutJS = [];
foreach ($donutRaw as $i => $c) {
    $donutJS[] = ['label' => $c['category'], 'value' => (float)$c['total'], 'color' => $catColors[$i % count($catColors)]];
}
?>
window.donutData = <?= json_encode($donutJS) ?>;
</script>
<script src="<?= APP_URL ?>/js/app.js"></script>
<script>
const canvas2 = document.getElementById('donutChart');
if (canvas2 && window.donutData && window.donutData.length) {
  const ctx = canvas2.getContext('2d');
  const total = window.donutData.reduce((s,d) => s + d.value, 0);
  let start = -Math.PI / 2;
  window.donutData.forEach(d => {
    const angle = (d.value / total) * 2 * Math.PI;
    ctx.beginPath(); ctx.moveTo(60,60);
    ctx.arc(60,60,55,start,start+angle); ctx.closePath();
    ctx.fillStyle = d.color; ctx.fill();
    start += angle;
  });
  ctx.beginPath(); ctx.arc(60,60,30,0,2*Math.PI);
  ctx.fillStyle = '#111520'; ctx.fill();
}
</script>
</body>
</html>