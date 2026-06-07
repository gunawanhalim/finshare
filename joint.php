<?php
require_once __DIR__ . '/php/config.php';
requireLogin();
$user = currentUser();
$db   = getDB();

$error   = '';
$success = '';

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $paction = $_POST['paction'] ?? '';

    // Create Joint Account
    if ($paction === 'create_joint') {
        $name = trim($_POST['account_name'] ?? '');
        if (!$name) {
            $error = 'Nama akun wajib diisi.';
        } else {
            $aid    = generateUUID();
            $code   = generateInviteCode();
            $db->prepare("INSERT INTO accounts (id,account_name,account_type,owner_id,invite_code,balance) VALUES (?,?,?,?,?,?)")
               ->execute([$aid, $name, 'joint', $user['id'], $code, 0]);
            $mid = generateUUID();
            $db->prepare("INSERT INTO account_members (id,account_id,user_id,role) VALUES (?,?,?,?)")
               ->execute([$mid, $aid, $user['id'], 'owner']);
            flash('success', "Joint Account \"{$name}\" berhasil dibuat. Kode undangan: {$code}");
            redirect('joint.php');
        }
    }

    // Join via invite code
    if ($paction === 'join_joint') {
        $code = strtoupper(trim($_POST['invite_code'] ?? ''));
        if (!$code) {
            $error = 'Masukkan kode undangan.';
        } else {
            $stmt = $db->prepare("SELECT * FROM accounts WHERE invite_code = ? AND account_type = 'joint'");
            $stmt->execute([$code]);
            $acct = $stmt->fetch();
            if (!$acct) {
                $error = 'Kode undangan tidak valid.';
            } else {
                // Check already member
                $chk = $db->prepare("SELECT id FROM account_members WHERE account_id = ? AND user_id = ?");
                $chk->execute([$acct['id'], $user['id']]);
                if ($chk->fetch()) {
                    $error = 'Anda sudah bergabung ke akun ini.';
                } else {
                    $mid = generateUUID();
                    $db->prepare("INSERT INTO account_members (id,account_id,user_id,role) VALUES (?,?,?,?)")
                       ->execute([$mid, $acct['id'], $user['id'], 'member']);

                    // Notify owner
                    $nid = generateUUID();
                    $db->prepare("INSERT INTO notifications (id,user_id,message,type) VALUES (?,?,?,?)")
                       ->execute([$nid, $acct['owner_id'],
                                  "{$user['name']} telah bergabung ke Joint Account \"{$acct['account_name']}\".",
                                  'member_join']);

                    flash('success', "Berhasil bergabung ke \"" . $acct['account_name'] . "\".");
                    redirect('joint.php');
                }
            }
        }
    }

    // Add joint transaction
    if ($paction === 'add_joint_tx') {
        $aid    = $_POST['account_id'] ?? '';
        $type   = $_POST['type'] ?? '';
        $amount = (float)str_replace(['.', ','], ['', '.'], $_POST['amount'] ?? '0');
        $cat    = $_POST['category'] ?? 'Lainnya';
        $desc   = trim($_POST['description'] ?? '');

        // Verify membership
        $chk = $db->prepare("SELECT * FROM account_members WHERE account_id = ? AND user_id = ?");
        $chk->execute([$aid, $user['id']]);
        if ($chk->fetch() && in_array($type, ['income','expense']) && $amount > 0) {
            $tid = generateUUID();
            $db->prepare("INSERT INTO transactions (id,account_id,user_id,type,amount,category,description) VALUES (?,?,?,?,?,?,?)")
               ->execute([$tid, $aid, $user['id'], $type, $amount, $cat, $desc]);
            $balChange = $type === 'income' ? $amount : -$amount;
            $db->prepare("UPDATE accounts SET balance = balance + ? WHERE id = ?")->execute([$balChange, $aid]);
            flash('success', 'Transaksi joint berhasil ditambahkan.');
            redirect('joint.php?view=' . $aid);
        } else {
            $error = 'Data tidak valid.';
        }
    }

    // Remove member (owner only)
    if ($paction === 'remove_member') {
        $aid = $_POST['account_id'] ?? '';
        $uid = $_POST['member_id'] ?? '';
        $stmt = $db->prepare("SELECT * FROM accounts WHERE id = ? AND owner_id = ?");
        $stmt->execute([$aid, $user['id']]);
        if ($stmt->fetch() && $uid !== $user['id']) {
            $db->prepare("DELETE FROM account_members WHERE account_id = ? AND user_id = ?")
               ->execute([$aid, $uid]);
            flash('success', 'Anggota berhasil dikeluarkan.');
        }
        redirect('joint.php?view=' . $aid);
    }
}

// Fetch user's joint accounts
$stmt = $db->prepare("SELECT a.*, am.role,
    (SELECT COUNT(*) FROM account_members WHERE account_id = a.id) as member_count
    FROM accounts a JOIN account_members am ON am.account_id = a.id
    WHERE am.user_id = ? AND a.account_type = 'joint'
    ORDER BY a.created_at DESC");
$stmt->execute([$user['id']]);
$joints = $stmt->fetchAll();

// View specific joint account
$viewId  = $_GET['view'] ?? '';
$viewing = null;
$members = [];
$jointTx = [];
if ($viewId) {
    $stmt = $db->prepare("SELECT a.*, am.role FROM accounts a
        JOIN account_members am ON am.account_id = a.id
        WHERE a.id = ? AND am.user_id = ?");
    $stmt->execute([$viewId, $user['id']]);
    $viewing = $stmt->fetch();

    if ($viewing) {
        $stmt = $db->prepare("SELECT am.*, u.name, u.email FROM account_members am
            JOIN users u ON u.id = am.user_id WHERE am.account_id = ?");
        $stmt->execute([$viewId]);
        $members = $stmt->fetchAll();

        $stmt = $db->prepare("SELECT t.*, u.name as user_name FROM transactions t
            JOIN users u ON u.id = t.user_id WHERE t.account_id = ?
            ORDER BY t.created_at DESC LIMIT 30");
        $stmt->execute([$viewId]);
        $jointTx = $stmt->fetchAll();
    }
}

global $CATEGORIES;
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Joint Account – FinShare</title>
  <link rel="stylesheet" href="<?= APP_URL ?>/css/style.css">
</head>
<body>
<div class="app-layout">
  <?php include __DIR__ . '/php/layout.php'; ?>

  <main class="main-content">
    <?php if (!$viewId): ?>
    <!-- LIST VIEW -->
    <div class="page-header">
      <div>
        <h1 class="page-title">Joint <span>Account</span></h1>
        <p class="page-sub">Kelola keuangan bersama secara transparan</p>
      </div>
      <div class="flex-gap">
        <button class="btn btn-outline btn-sm" onclick="openModal('modalJoinJoint')">🔗 Gabung</button>
        <button class="btn btn-primary btn-sm" onclick="openModal('modalCreateJoint')">+ Buat Baru</button>
      </div>
    </div>

    <?php if ($s = getFlash('success')): ?>
      <div class="alert alert-success mb-2">✓ <?= sanitize($s) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
      <div class="alert alert-error mb-2">⚠ <?= sanitize($error) ?></div>
    <?php endif; ?>

    <?php if (empty($joints)): ?>
      <div class="card">
        <div class="empty-state">
          <div class="empty-icon">🤝</div>
          <div class="empty-text">Belum ada Joint Account</div>
          <div class="empty-sub">Buat akun bersama atau bergabung dengan kode undangan</div>
          <div class="mt-2 flex-gap" style="justify-content:center">
            <button class="btn btn-primary btn-sm" onclick="openModal('modalCreateJoint')">+ Buat Baru</button>
            <button class="btn btn-outline btn-sm" onclick="openModal('modalJoinJoint')">Gabung dengan Kode</button>
          </div>
        </div>
      </div>
    <?php else: ?>
      <div class="grid-2">
        <?php foreach ($joints as $j): ?>
          <div class="joint-card">
            <div class="joint-card-header">
              <div>
                <div class="joint-name"><?= sanitize($j['account_name']) ?></div>
                <span class="badge badge-<?= $j['role']==='owner'?'yellow':'cyan' ?>">
                  <?= $j['role'] === 'owner' ? '👑 Pemilik' : '👤 Anggota' ?>
                </span>
              </div>
              <div style="text-align:right">
                <div class="joint-balance"><?= formatRp((float)$j['balance']) ?></div>
                <div class="joint-balance-label">Saldo Bersama</div>
              </div>
            </div>

            <div style="font-size:0.78rem;color:var(--muted);margin-bottom:0.5rem">
              🔑 Kode Undangan:
              <span style="font-family:monospace;color:var(--accent2);cursor:pointer"
                    onclick="copyCode('<?= sanitize($j['invite_code']) ?>')">
                <?= sanitize($j['invite_code']) ?> 📋
              </span>
            </div>

            <div style="font-size:0.78rem;color:var(--muted);margin-bottom:1rem">
              <?= $j['member_count'] ?> anggota
            </div>

            <a href="joint.php?view=<?= $j['id'] ?>" class="btn btn-outline btn-sm w-full">
              Lihat Detail →
            </a>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php else: ?>
    <!-- DETAIL VIEW -->
    <?php if ($viewing): ?>
    <div class="page-header">
      <div>
        <a href="joint.php" style="font-size:0.85rem;color:var(--muted);display:block;margin-bottom:0.5rem">← Kembali</a>
        <h1 class="page-title"><?= sanitize($viewing['account_name']) ?></h1>
        <p class="page-sub">
          <span class="badge badge-<?= $viewing['role']==='owner'?'yellow':'cyan' ?>">
            <?= $viewing['role'] === 'owner' ? '👑 Pemilik' : '👤 Anggota' ?>
          </span>
          · Kode:
          <span style="color:var(--accent2);cursor:pointer" onclick="copyCode('<?= sanitize($viewing['invite_code']) ?>')">
            <?= sanitize($viewing['invite_code']) ?> 📋
          </span>
        </p>
      </div>
      <div class="flex-gap">
        <div style="text-align:right">
          <div style="font-family:var(--font-head);font-size:1.75rem;font-weight:700;color:var(--accent)">
            <?= formatRp((float)$viewing['balance']) ?>
          </div>
          <div style="font-size:0.75rem;color:var(--muted)">Saldo Bersama</div>
        </div>
        <button class="btn btn-primary btn-sm" onclick="openModal('modalJointTx')">+ Transaksi</button>
      </div>
    </div>

    <?php if ($s = getFlash('success')): ?>
      <div class="alert alert-success mb-2">✓ <?= sanitize($s) ?></div>
    <?php endif; ?>

    <div class="dashboard-grid">
      <div>
        <!-- Transactions -->
        <div class="card">
          <div class="card-title mb-2">Histori Transaksi</div>
          <?php if (empty($jointTx)): ?>
            <div class="empty-state"><div class="empty-icon">📋</div><div class="empty-sub">Belum ada transaksi</div></div>
          <?php else: ?>
            <div class="tx-list">
              <?php foreach ($jointTx as $tx):
                $isIncome = in_array($tx['type'], ['income','transfer_in']);
                $icon = $CATEGORIES[$tx['category']] ?? '📌';
              ?>
                <div class="tx-item">
                  <div class="tx-icon <?= $isIncome?'income':'expense' ?>"><?= $icon ?></div>
                  <div class="tx-info">
                    <div class="tx-desc"><?= sanitize($tx['description'] ?: $tx['category']) ?></div>
                    <div class="tx-cat"><?= sanitize($tx['category']) ?> · <?= sanitize($tx['user_name']) ?></div>
                  </div>
                  <div>
                    <div class="tx-amount <?= $isIncome?'income':'expense' ?>">
                      <?= ($isIncome?'+':'-') . formatRp((float)$tx['amount']) ?>
                    </div>
                    <div class="tx-date" style="text-align:right"><?= timeAgo($tx['created_at']) ?></div>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <div>
        <!-- Members -->
        <div class="card">
          <div class="card-title mb-2">Anggota (<?= count($members) ?>)</div>
          <?php foreach ($members as $m): ?>
            <div style="display:flex;align-items:center;gap:0.75rem;padding:0.75rem 0;border-bottom:1px solid var(--border)">
              <div class="user-avatar"><?= initials($m['name']) ?></div>
              <div style="flex:1;min-width:0">
                <div style="font-size:0.88rem;font-weight:600"><?= sanitize($m['name']) ?></div>
                <div style="font-size:0.75rem;color:var(--muted)"><?= sanitize($m['email']) ?></div>
              </div>
              <div style="display:flex;align-items:center;gap:0.5rem">
                <span class="badge badge-<?= $m['role']==='owner'?'yellow':'cyan' ?>">
                  <?= $m['role'] === 'owner' ? 'Pemilik' : 'Anggota' ?>
                </span>
                <?php if ($viewing['role'] === 'owner' && $m['user_id'] !== $user['id']): ?>
                  <form method="POST" onsubmit="return confirmDelete(this)">
                    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                    <input type="hidden" name="paction" value="remove_member">
                    <input type="hidden" name="account_id" value="<?= $viewing['id'] ?>">
                    <input type="hidden" name="member_id" value="<?= $m['user_id'] ?>">
                    <button type="submit" class="btn btn-sm btn-danger btn-icon">🗑</button>
                  </form>
                <?php endif; ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
    <?php endif; ?>
    <?php endif; ?>
  </main>
</div>

<!-- Modal: Create Joint -->
<div class="modal-backdrop" id="modalCreateJoint">
  <div class="modal">
    <div class="modal-header">
      <div class="modal-title">Buat Joint Account</div>
      <button class="modal-close" onclick="closeModal('modalCreateJoint')">✕</button>
    </div>
    <form method="POST" data-validate>
      <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
      <input type="hidden" name="paction" value="create_joint">
      <div class="form-group">
        <label class="form-label">Nama Akun</label>
        <input class="form-control" type="text" name="account_name"
               placeholder="Contoh: Keuangan Keluarga, Patungan Kos" required>
      </div>
      <div class="flex-gap mt-2">
        <button type="submit" class="btn btn-primary">Buat Akun</button>
        <button type="button" class="btn btn-outline" onclick="closeModal('modalCreateJoint')">Batal</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal: Join Joint -->
<div class="modal-backdrop" id="modalJoinJoint">
  <div class="modal">
    <div class="modal-header">
      <div class="modal-title">Gabung Joint Account</div>
      <button class="modal-close" onclick="closeModal('modalJoinJoint')">✕</button>
    </div>
    <form method="POST" data-validate>
      <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
      <input type="hidden" name="paction" value="join_joint">
      <div class="form-group">
        <label class="form-label">Kode Undangan</label>
        <input class="form-control" type="text" name="invite_code"
               placeholder="Masukkan kode 8 karakter" maxlength="8" style="text-transform:uppercase" required>
      </div>
      <div class="flex-gap mt-2">
        <button type="submit" class="btn btn-primary">Gabung</button>
        <button type="button" class="btn btn-outline" onclick="closeModal('modalJoinJoint')">Batal</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal: Add Joint Transaction -->
<?php if ($viewing): ?>
<div class="modal-backdrop" id="modalJointTx">
  <div class="modal">
    <div class="modal-header">
      <div class="modal-title">Tambah Transaksi Joint</div>
      <button class="modal-close" onclick="closeModal('modalJointTx')">✕</button>
    </div>
    <form method="POST" data-validate>
      <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
      <input type="hidden" name="paction" value="add_joint_tx">
      <input type="hidden" name="account_id" value="<?= $viewing['id'] ?>">
      <div class="form-group">
        <label class="form-label">Tipe</label>
        <select class="form-control" name="type">
          <option value="income">📈 Pemasukan</option>
          <option value="expense">📉 Pengeluaran</option>
        </select>
      </div>
      <div class="form-group">
        <label class="form-label">Nominal (Rp)</label>
        <input class="form-control" type="text" name="amount" placeholder="500000" data-amount required>
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
        <label class="form-label">Deskripsi</label>
        <input class="form-control" type="text" name="description" placeholder="Catatan...">
      </div>
      <div class="flex-gap mt-2">
        <button type="submit" class="btn btn-primary">Simpan</button>
        <button type="button" class="btn btn-outline" onclick="closeModal('modalJointTx')">Batal</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<script src="<?= APP_URL ?>/js/app.js"></script>
</body>
</html>