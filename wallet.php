<?php
require_once __DIR__ . '/php/config.php';
requireLogin();
$user = currentUser();
$db   = getDB();

// Get personal account
$stmt = $db->prepare("SELECT a.* FROM accounts a
    JOIN account_members am ON am.account_id = a.id
    WHERE am.user_id = ? AND a.account_type = 'personal' LIMIT 1");
$stmt->execute([$user['id']]);
$account = $stmt->fetch();

$error   = '';
$success = '';
$action  = $_GET['action'] ?? '';

// Handle POST: Add Transaction
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $paction = $_POST['paction'] ?? '';

    if ($paction === 'add_tx') {
        $type   = $_POST['type'] ?? '';
        $amount = (float)str_replace(['.', ','], ['', '.'], $_POST['amount'] ?? '0');
        $cat    = $_POST['category'] ?? 'Lainnya';
        $desc   = trim($_POST['description'] ?? '');

        if (!in_array($type, ['income', 'expense'])) {
            $error = 'Tipe transaksi tidak valid.';
        } elseif ($amount <= 0) {
            $error = 'Nominal harus lebih dari 0.';
        } else {
            $tid = generateUUID();
            $db->prepare("INSERT INTO transactions (id,account_id,user_id,type,amount,category,description) VALUES (?,?,?,?,?,?,?)")
               ->execute([$tid, $account['id'], $user['id'], $type, $amount, $cat, $desc]);

            // Update balance
            $balChange = $type === 'income' ? $amount : -$amount;
            $db->prepare("UPDATE accounts SET balance = balance + ? WHERE id = ?")
               ->execute([$balChange, $account['id']]);

            flash('success', 'Transaksi berhasil ditambahkan.');
            redirect('wallet.php');
        }
    }

    if ($paction === 'delete_tx') {
        $tid = $_POST['tx_id'] ?? '';
        // Get tx first
        $stmt = $db->prepare("SELECT * FROM transactions WHERE id = ? AND account_id = ?");
        $stmt->execute([$tid, $account['id']]);
        $tx = $stmt->fetch();
        if ($tx) {
            $balRevert = in_array($tx['type'], ['income','transfer_in']) ? -$tx['amount'] : $tx['amount'];
            $db->prepare("DELETE FROM transactions WHERE id = ?")->execute([$tid]);
            $db->prepare("UPDATE accounts SET balance = balance + ? WHERE id = ?")->execute([$balRevert, $account['id']]);
            flash('success', 'Transaksi dihapus.');
        }
        redirect('wallet.php');
    }
}

// Filters
$filterType  = $_GET['type'] ?? '';
$filterMonth = $_GET['month'] ?? date('Y-m');
$filterCat   = $_GET['cat'] ?? '';

$where = "t.account_id = ?";
$params = [$account['id'] ?? ''];
if ($filterType)  { $where .= " AND t.type = ?"; $params[] = $filterType; }
if ($filterMonth) { $where .= " AND DATE_FORMAT(t.created_at,'%Y-%m') = ?"; $params[] = $filterMonth; }
if ($filterCat)   { $where .= " AND t.category = ?"; $params[] = $filterCat; }

$stmt = $db->prepare("SELECT t.*, u.name as user_name FROM transactions t
    JOIN users u ON u.id = t.user_id
    WHERE {$where} ORDER BY t.created_at DESC LIMIT 50");
$stmt->execute($params);
$transactions = $stmt->fetchAll();

// Stats for filter period
$stmt2 = $db->prepare("SELECT
    SUM(CASE WHEN type='income' THEN amount ELSE 0 END) as income,
    SUM(CASE WHEN type='expense' THEN amount ELSE 0 END) as expense,
    COUNT(*) as total_tx
    FROM transactions WHERE account_id = ? AND DATE_FORMAT(created_at,'%Y-%m') = ?");
$stmt2->execute([$account['id'] ?? '', $filterMonth]);
$periodStats = $stmt2->fetch();

global $CATEGORIES;
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Dompet Pribadi – FinShare</title>
  <link rel="stylesheet" href="<?= APP_URL ?>/css/style.css">
</head>
<body>
<div class="app-layout">
  <?php include __DIR__ . '/php/layout.php'; ?>

  <main class="main-content">
    <div class="page-header">
      <div>
        <h1 class="page-title">Dompet <span>Pribadi</span></h1>
        <p class="page-sub">Catat dan pantau keuangan pribadi Anda</p>
      </div>
      <button class="btn btn-primary btn-sm" onclick="openModal('modalAddTx')">+ Tambah Transaksi</button>
    </div>

    <?php if ($s = getFlash('success')): ?>
      <div class="alert alert-success mb-2">✓ <?= sanitize($s) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
      <div class="alert alert-error mb-2">⚠ <?= sanitize($error) ?></div>
    <?php endif; ?>

    <!-- Stats -->
    <div class="stat-grid" style="margin-bottom:1.5rem">
      <div class="stat-card green">
        <div class="stat-icon green">💰</div>
        <div class="stat-label">Saldo Saat Ini</div>
        <div class="stat-value"><?= formatRp((float)($account['balance'] ?? 0)) ?></div>
      </div>
      <div class="stat-card cyan">
        <div class="stat-icon cyan">📈</div>
        <div class="stat-label">Pemasukan</div>
        <div class="stat-value"><?= formatRp((float)($periodStats['income'] ?? 0)) ?></div>
        <div class="stat-sub"><?= date('F Y', strtotime($filterMonth . '-01')) ?></div>
      </div>
      <div class="stat-card pink">
        <div class="stat-icon pink">📉</div>
        <div class="stat-label">Pengeluaran</div>
        <div class="stat-value"><?= formatRp((float)($periodStats['expense'] ?? 0)) ?></div>
        <div class="stat-sub"><?= date('F Y', strtotime($filterMonth . '-01')) ?></div>
      </div>
    </div>

    <!-- Filters -->
    <div class="card mb-2">
      <form method="GET" class="flex-gap" style="flex-wrap:wrap">
        <div>
          <label class="form-label">Bulan</label>
          <input class="form-control" type="month" name="month"
                 value="<?= sanitize($filterMonth) ?>" style="width:auto">
        </div>
        <div>
          <label class="form-label">Tipe</label>
          <select class="form-control" name="type" style="width:auto">
            <option value="">Semua</option>
            <option value="income" <?= $filterType==='income'?'selected':'' ?>>Pemasukan</option>
            <option value="expense" <?= $filterType==='expense'?'selected':'' ?>>Pengeluaran</option>
          </select>
        </div>
        <div>
          <label class="form-label">Kategori</label>
          <select class="form-control" name="cat" style="width:auto">
            <option value="">Semua</option>
            <?php foreach ($CATEGORIES as $cat => $icon): ?>
              <option value="<?= $cat ?>" <?= $filterCat===$cat?'selected':'' ?>><?= $icon ?> <?= $cat ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div style="align-self:flex-end">
          <button type="submit" class="btn btn-outline btn-sm">Filter</button>
          <a href="wallet.php" class="btn btn-outline btn-sm">Reset</a>
        </div>
      </form>
    </div>

    <!-- Transaction Table -->
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>Tanggal</th>
            <th>Kategori</th>
            <th>Deskripsi</th>
            <th>Tipe</th>
            <th>Nominal</th>
            <th>Aksi</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($transactions)): ?>
            <tr>
              <td colspan="6">
                <div class="empty-state">
                  <div class="empty-icon">📋</div>
                  <div class="empty-text">Belum ada transaksi</div>
                  <div class="empty-sub">Klik "+ Tambah Transaksi" untuk memulai</div>
                </div>
              </td>
            </tr>
          <?php else: ?>
            <?php foreach ($transactions as $tx):
              $isIncome = in_array($tx['type'], ['income','transfer_in']);
              $icon = $CATEGORIES[$tx['category']] ?? '📌';
            ?>
              <tr>
                <td class="text-sm text-muted"><?= date('d M Y\nH:i', strtotime($tx['created_at'])) ?></td>
                <td>
                  <span class="badge badge-<?= $isIncome?'green':'red' ?>">
                    <?= $icon ?> <?= sanitize($tx['category']) ?>
                  </span>
                </td>
                <td><?= sanitize($tx['description'] ?: '-') ?></td>
                <td>
                  <span class="badge badge-<?= $isIncome?'green':'red' ?>">
                    <?= $isIncome ? '↑ Masuk' : '↓ Keluar' ?>
                  </span>
                </td>
                <td class="fw-600 <?= $isIncome?'text-accent':'text-danger' ?>">
                  <?= ($isIncome?'+':'-') . formatRp((float)$tx['amount']) ?>
                </td>
                <td>
                  <form method="POST" onsubmit="return confirmDelete(this)">
                    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                    <input type="hidden" name="paction" value="delete_tx">
                    <input type="hidden" name="tx_id" value="<?= sanitize($tx['id']) ?>">
                    <button type="submit" class="btn btn-sm btn-danger btn-icon" title="Hapus">🗑</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </main>
</div>

<!-- Modal: Add Transaction -->
<div class="modal-backdrop <?= ($action==='add')?'open':'' ?>" id="modalAddTx">
  <div class="modal">
    <div class="modal-header">
      <div class="modal-title">Tambah Transaksi</div>
      <button class="modal-close" onclick="closeModal('modalAddTx')">✕</button>
    </div>
    <form method="POST" data-validate>
      <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
      <input type="hidden" name="paction" value="add_tx">

      <div class="form-group">
        <label class="form-label">Tipe Transaksi</label>
        <select class="form-control" name="type" required>
          <option value="income">📈 Pemasukan</option>
          <option value="expense">📉 Pengeluaran</option>
        </select>
      </div>

      <div class="form-group">
        <label class="form-label">Nominal (Rp)</label>
        <input class="form-control" type="text" name="amount"
               placeholder="Contoh: 500000" data-amount required>
      </div>

      <div class="form-group">
        <label class="form-label">Kategori</label>
        <select class="form-control" name="category">
          <?php foreach ($CATEGORIES as $cat => $icon): ?>
            <option value="<?= $cat ?>"><?= $icon ?> <?= $cat ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="form-group">
        <label class="form-label">Deskripsi (opsional)</label>
        <input class="form-control" type="text" name="description" placeholder="Catatan singkat...">
      </div>

      <div class="flex-gap mt-2">
        <button type="submit" class="btn btn-primary">Simpan Transaksi</button>
        <button type="button" class="btn btn-outline" onclick="closeModal('modalAddTx')">Batal</button>
      </div>
    </form>
  </div>
</div>

<script src="<?= APP_URL ?>/js/app.js"></script>
</body>
</html>