<?php
// transfer.php
require_once __DIR__ . '/php/config.php';
requireLogin();
$user = currentUser();
$db   = getDB();

$error = '';

// Personal account
$stmt = $db->prepare("SELECT a.* FROM accounts a JOIN account_members am ON am.account_id = a.id WHERE am.user_id = ? AND a.account_type = 'personal' LIMIT 1");
$stmt->execute([$user['id']]);
$personal = $stmt->fetch();

// Joint accounts
$stmt = $db->prepare("SELECT a.* FROM accounts a JOIN account_members am ON am.account_id = a.id WHERE am.user_id = ? AND a.account_type = 'joint'");
$stmt->execute([$user['id']]);
$joints = $stmt->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $from   = $_POST['from_account'] ?? '';
    $to     = $_POST['to_account'] ?? '';
    $amount = (float)str_replace(['.', ','], ['', '.'], $_POST['amount'] ?? '0');
    $note   = trim($_POST['note'] ?? '');

    if ($from === $to)      { $error = 'Akun asal dan tujuan tidak boleh sama.'; }
    elseif ($amount <= 0)   { $error = 'Nominal harus lebih dari 0.'; }
    else {
        // Verify ownership of from account
        $chkFrom = $db->prepare("SELECT a.* FROM accounts a JOIN account_members am ON am.account_id = a.id WHERE a.id = ? AND am.user_id = ?");
        $chkFrom->execute([$from, $user['id']]);
        $fromAcc = $chkFrom->fetch();

        $chkTo = $db->prepare("SELECT a.* FROM accounts a JOIN account_members am ON am.account_id = a.id WHERE a.id = ? AND am.user_id = ?");
        $chkTo->execute([$to, $user['id']]);
        $toAcc = $chkTo->fetch();

        if (!$fromAcc || !$toAcc) {
            $error = 'Akun tidak valid.';
        } elseif ($fromAcc['balance'] < $amount) {
            $error = 'Saldo tidak mencukupi. Saldo tersedia: ' . formatRp((float)$fromAcc['balance']);
        } else {
            // Debit from
            $t1 = generateUUID();
            $db->prepare("INSERT INTO transactions (id,account_id,user_id,type,amount,category,description) VALUES (?,?,?,?,?,?,?)")
               ->execute([$t1, $from, $user['id'], 'transfer_out', $amount, 'Transfer', "Transfer ke {$toAcc['account_name']}: {$note}"]);
            $db->prepare("UPDATE accounts SET balance = balance - ? WHERE id = ?")->execute([$amount, $from]);

            // Credit to
            $t2 = generateUUID();
            $db->prepare("INSERT INTO transactions (id,account_id,user_id,type,amount,category,description) VALUES (?,?,?,?,?,?,?)")
               ->execute([$t2, $to, $user['id'], 'transfer_in', $amount, 'Transfer', "Transfer dari {$fromAcc['account_name']}: {$note}"]);
            $db->prepare("UPDATE accounts SET balance = balance + ? WHERE id = ?")->execute([$amount, $to]);

            flash('success', 'Transfer berhasil! ' . formatRp($amount) . ' dipindahkan.');
            redirect('transfer.php');
        }
    }
}

$allAccounts = array_merge([$personal], $joints);
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Transfer – FinShare</title>
  <link rel="stylesheet" href="<?= APP_URL ?>/css/style.css">
</head>
<body>
<div class="app-layout">
  <?php include __DIR__ . '/php/layout.php'; ?>
  <main class="main-content">
    <div class="page-header">
      <div>
        <h1 class="page-title">Transfer <span>Internal</span></h1>
        <p class="page-sub">Pindahkan dana antara akun pribadi dan joint account</p>
      </div>
    </div>

    <?php if ($s = getFlash('success')): ?>
      <div class="alert alert-success mb-2">✓ <?= sanitize($s) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
      <div class="alert alert-error mb-2">⚠ <?= sanitize($error) ?></div>
    <?php endif; ?>

    <div style="max-width:500px">
      <div class="card">
        <div class="card-title mb-2">Form Transfer Dana</div>

        <!-- Account balances -->
        <div style="display:flex;flex-direction:column;gap:0.5rem;margin-bottom:1.5rem">
          <?php foreach ($allAccounts as $a): if (!$a) continue; ?>
            <div style="display:flex;justify-content:space-between;align-items:center;background:var(--bg3);padding:0.75rem 1rem;border-radius:10px">
              <span style="font-size:0.85rem"><?= $a['account_type']==='personal'?'👤':'🤝' ?> <?= sanitize($a['account_name']) ?></span>
              <span style="font-family:var(--font-head);font-size:0.9rem;color:var(--accent)"><?= formatRp((float)$a['balance']) ?></span>
            </div>
          <?php endforeach; ?>
        </div>

        <form method="POST" data-validate>
          <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
          <div class="form-group">
            <label class="form-label">Dari Akun</label>
            <select class="form-control" name="from_account" required>
              <?php foreach ($allAccounts as $a): if (!$a) continue; ?>
                <option value="<?= $a['id'] ?>">
                  <?= $a['account_type']==='personal'?'👤':'🤝' ?> <?= sanitize($a['account_name']) ?> (<?= formatRp((float)$a['balance']) ?>)
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Ke Akun</label>
            <select class="form-control" name="to_account" required>
              <?php foreach ($allAccounts as $a): if (!$a) continue; ?>
                <option value="<?= $a['id'] ?>">
                  <?= $a['account_type']==='personal'?'👤':'🤝' ?> <?= sanitize($a['account_name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Nominal (Rp)</label>
            <input class="form-control" type="text" name="amount" placeholder="500000" data-amount required>
          </div>
          <div class="form-group">
            <label class="form-label">Catatan (opsional)</label>
            <input class="form-control" type="text" name="note" placeholder="Keperluan transfer...">
          </div>
          <button type="submit" class="btn btn-primary w-full mt-2">Proses Transfer →</button>
        </form>
      </div>
    </div>
  </main>
</div>
<script src="<?= APP_URL ?>/js/app.js"></script>
</body>
</html>