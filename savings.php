<?php
require_once __DIR__ . '/php/config.php';
requireLogin();
$user = currentUser();
$db   = getDB();

$error = '';

// Get all accounts user belongs to
$stmt = $db->prepare("SELECT a.*, am.role FROM accounts a
    JOIN account_members am ON am.account_id = a.id
    WHERE am.user_id = ? ORDER BY a.account_type, a.account_name");
$stmt->execute([$user['id']]);
$userAccounts = $stmt->fetchAll();

// Handle POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $paction = $_POST['paction'] ?? '';

    // Create savings goal
    if ($paction === 'create_goal') {
        $aid    = $_POST['account_id'] ?? '';
        $title  = trim($_POST['title'] ?? '');
        $emoji  = $_POST['emoji'] ?? '🎯';
        $target = (float)str_replace(['.', ','], ['', '.'], $_POST['target_amount'] ?? '0');
        $due    = $_POST['due_date'] ?? null;

        // Verify membership
        $chk = $db->prepare("SELECT id FROM account_members WHERE account_id = ? AND user_id = ?");
        $chk->execute([$aid, $user['id']]);

        if (!$chk->fetch()) {
            $error = 'Akun tidak valid.';
        } elseif (!$title || $target <= 0) {
            $error = 'Nama dan target nominal wajib diisi.';
        } else {
            $gid = generateUUID();
            $db->prepare("INSERT INTO savings_goals (id,account_id,title,emoji,target_amount,due_date) VALUES (?,?,?,?,?,?)")
               ->execute([$gid, $aid, $title, $emoji, $target, $due ?: null]);
            flash('success', "Target tabungan \"{$title}\" berhasil dibuat!");
            redirect('savings.php');
        }
    }

    // Contribute to goal
    if ($paction === 'contribute') {
        $gid    = $_POST['goal_id'] ?? '';
        $amount = (float)str_replace(['.', ','], ['', '.'], $_POST['amount'] ?? '0');
        $note   = trim($_POST['note'] ?? '');

        // Validate goal exists and user belongs to that account
        $stmt = $db->prepare("SELECT sg.*, a.id as aid FROM savings_goals sg
            JOIN accounts a ON a.id = sg.account_id
            JOIN account_members am ON am.account_id = a.id
            WHERE sg.id = ? AND am.user_id = ? AND sg.is_completed = 0");
        $stmt->execute([$gid, $user['id']]);
        $goal = $stmt->fetch();

        if (!$goal || $amount <= 0) {
            $error = 'Data tidak valid.';
        } else {
            $cid = generateUUID();
            $db->prepare("INSERT INTO savings_contributions (id,goal_id,user_id,amount,note) VALUES (?,?,?,?,?)")
               ->execute([$cid, $gid, $user['id'], $amount, $note]);

            $db->prepare("UPDATE savings_goals SET current_amount = current_amount + ? WHERE id = ?")
               ->execute([$amount, $gid]);

            // Check if goal reached
            $stmt2 = $db->prepare("SELECT * FROM savings_goals WHERE id = ?");
            $stmt2->execute([$gid]);
            $updated = $stmt2->fetch();
            if ($updated['current_amount'] >= $updated['target_amount']) {
                $db->prepare("UPDATE savings_goals SET is_completed = 1 WHERE id = ?")->execute([$gid]);
                // Notify all members
                $members2 = $db->prepare("SELECT user_id FROM account_members WHERE account_id = ?");
                $members2->execute([$goal['aid']]);
                foreach ($members2->fetchAll() as $m) {
                    $nid = generateUUID();
                    $db->prepare("INSERT INTO notifications (id,user_id,message,type) VALUES (?,?,?,?)")
                       ->execute([$nid, $m['user_id'],
                                  "🎉 Target tabungan \"{$goal['title']}\" telah tercapai!",
                                  'savings_complete']);
                }
            }

            flash('success', 'Kontribusi berhasil ditambahkan!');
            redirect('savings.php');
        }
    }

    // Withdraw
    if ($paction === 'withdraw') {
        $gid    = $_POST['goal_id'] ?? '';
        $amount = (float)str_replace(['.', ','], ['', '.'], $_POST['amount'] ?? '0');

        $stmt = $db->prepare("SELECT sg.* FROM savings_goals sg
            JOIN account_members am ON am.account_id = sg.account_id
            WHERE sg.id = ? AND am.user_id = ? AND am.role = 'owner'");
        $stmt->execute([$gid, $user['id']]);
        $goal = $stmt->fetch();

        if ($goal && $amount > 0 && $amount <= $goal['current_amount']) {
            $db->prepare("UPDATE savings_goals SET current_amount = current_amount - ? WHERE id = ?")
               ->execute([$amount, $gid]);
            flash('success', 'Penarikan berhasil.');
        } else {
            flash('success', 'Tidak dapat menarik dana.');
        }
        redirect('savings.php');
    }
}

// Fetch all savings goals
$stmt = $db->prepare("SELECT sg.*, a.account_name, a.account_type, am.role,
    (SELECT COUNT(*) FROM savings_contributions WHERE goal_id = sg.id) as contrib_count,
    (SELECT name FROM users WHERE id = (
        SELECT user_id FROM savings_contributions WHERE goal_id = sg.id
        GROUP BY user_id ORDER BY SUM(amount) DESC LIMIT 1
    )) as top_contributor
    FROM savings_goals sg
    JOIN accounts a ON a.id = sg.account_id
    JOIN account_members am ON am.account_id = a.id AND am.user_id = ?
    ORDER BY sg.is_completed ASC, sg.due_date ASC");
$stmt->execute([$user['id']]);
$goals = $stmt->fetchAll();

$emojis = ['🎯','🏖️','💒','🏠','🚗','✈️','💊','📚','🎮','💍','🏦','🎁'];
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Tabungan Bersama – FinShare</title>
  <link rel="stylesheet" href="<?= APP_URL ?>/css/style.css">
</head>
<body>
<div class="app-layout">
  <?php include __DIR__ . '/php/layout.php'; ?>

  <main class="main-content">
    <div class="page-header">
      <div>
        <h1 class="page-title">Tabungan <span>Bersama</span></h1>
        <p class="page-sub">Wujudkan impian bersama dengan tabungan tujuan</p>
      </div>
      <button class="btn btn-primary btn-sm" onclick="openModal('modalCreateGoal')">+ Buat Target</button>
    </div>

    <?php if ($s = getFlash('success')): ?>
      <div class="alert alert-success mb-2">✓ <?= sanitize($s) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
      <div class="alert alert-error mb-2">⚠ <?= sanitize($error) ?></div>
    <?php endif; ?>

    <!-- Active Goals -->
    <?php
    $active    = array_filter($goals, fn($g) => !$g['is_completed']);
    $completed = array_filter($goals, fn($g) =>  $g['is_completed']);
    ?>

    <?php if (empty($goals)): ?>
      <div class="card">
        <div class="empty-state">
          <div class="empty-icon">🎯</div>
          <div class="empty-text">Belum ada target tabungan</div>
          <div class="empty-sub">Buat target tabungan untuk liburan, dana nikah, renovasi rumah, dan lainnya</div>
          <button class="btn btn-primary btn-sm mt-2" onclick="openModal('modalCreateGoal')">Buat Target Pertama</button>
        </div>
      </div>
    <?php else: ?>

      <?php if (!empty($active)): ?>
      <h2 style="font-family:var(--font-head);font-size:1rem;font-weight:600;margin-bottom:1rem;color:var(--muted)">
        🎯 Target Aktif (<?= count($active) ?>)
      </h2>
      <div class="grid-2 mb-2">
        <?php foreach ($active as $g):
          $pct = $g['target_amount'] > 0 ? min(100, round(($g['current_amount'] / $g['target_amount']) * 100)) : 0;
          $daysLeft = $g['due_date'] ? max(0, round((strtotime($g['due_date']) - time()) / 86400)) : null;
        ?>
          <div class="saving-card">
            <div class="saving-card-header">
              <div>
                <div class="saving-emoji"><?= $g['emoji'] ?></div>
                <div class="saving-card-title"><?= sanitize($g['title']) ?></div>
                <div style="font-size:0.75rem;color:var(--muted);margin-top:0.25rem">
                  📁 <?= sanitize($g['account_name']) ?>
                </div>
              </div>
              <div class="saving-progress-text">
                <div class="saving-amount-curr"><?= $pct ?>%</div>
                <div class="saving-amount-target">tercapai</div>
              </div>
            </div>

            <div class="progress-bar" style="height:8px;margin-bottom:0.75rem">
              <div class="progress-fill" data-width="<?= $pct ?>" style="width:0%"></div>
            </div>

            <div class="flex-between mb-1">
              <span style="font-size:0.82rem;color:var(--accent2);font-weight:600">
                <?= formatRp((float)$g['current_amount']) ?>
              </span>
              <span style="font-size:0.82rem;color:var(--muted)">
                / <?= formatRp((float)$g['target_amount']) ?>
              </span>
            </div>

            <div style="font-size:0.75rem;color:var(--muted);margin-bottom:1rem">
              <?php if ($daysLeft !== null): ?>
                📅 <?= $daysLeft > 0 ? "{$daysLeft} hari lagi" : 'Sudah lewat target!' ?>
              <?php endif; ?>
              · <?= $g['contrib_count'] ?> kontribusi
              <?php if ($g['top_contributor']): ?>
                · 🏆 <?= sanitize($g['top_contributor']) ?>
              <?php endif; ?>
            </div>

            <div class="flex-gap">
              <button class="btn btn-primary btn-sm" style="flex:1"
                      onclick="openContrib('<?= $g['id'] ?>', '<?= sanitize($g['title']) ?>')">
                + Setor Dana
              </button>
              <?php if ($g['role'] === 'owner'): ?>
                <button class="btn btn-outline btn-sm"
                        onclick="openWithdraw('<?= $g['id'] ?>', '<?= sanitize($g['title']) ?>')">
                  Tarik
                </button>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <?php if (!empty($completed)): ?>
      <h2 style="font-family:var(--font-head);font-size:1rem;font-weight:600;margin-bottom:1rem;color:var(--muted)">
        ✅ Tercapai (<?= count($completed) ?>)
      </h2>
      <div class="grid-2">
        <?php foreach ($completed as $g): ?>
          <div class="saving-card" style="opacity:0.7">
            <div style="font-size:2rem;margin-bottom:0.5rem"><?= $g['emoji'] ?></div>
            <div class="saving-card-title"><?= sanitize($g['title']) ?></div>
            <div style="font-size:0.75rem;color:var(--muted);margin:0.25rem 0">
              <?= sanitize($g['account_name']) ?>
            </div>
            <div class="progress-bar" style="margin:0.75rem 0">
              <div class="progress-fill" style="width:100%"></div>
            </div>
            <span class="badge badge-green">🎉 Tercapai! <?= formatRp((float)$g['target_amount']) ?></span>
          </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    <?php endif; ?>
  </main>
</div>

<!-- Modal: Create Goal -->
<div class="modal-backdrop" id="modalCreateGoal">
  <div class="modal">
    <div class="modal-header">
      <div class="modal-title">Buat Target Tabungan</div>
      <button class="modal-close" onclick="closeModal('modalCreateGoal')">✕</button>
    </div>
    <form method="POST" data-validate>
      <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
      <input type="hidden" name="paction" value="create_goal">

      <div class="form-group">
        <label class="form-label">Akun</label>
        <select class="form-control" name="account_id" required>
          <?php foreach ($userAccounts as $a): ?>
            <option value="<?= $a['id'] ?>">
              <?= $a['account_type'] === 'personal' ? '👤' : '🤝' ?> <?= sanitize($a['account_name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="form-group">
        <label class="form-label">Emoji</label>
        <div style="display:flex;flex-wrap:wrap;gap:0.5rem">
          <?php foreach ($emojis as $em): ?>
            <label style="cursor:pointer">
              <input type="radio" name="emoji" value="<?= $em ?>" style="display:none">
              <span class="badge badge-cyan" style="font-size:1.25rem;cursor:pointer;padding:0.4rem 0.6rem"
                    onclick="selectEmoji(this,'<?= $em ?>')"><?= $em ?></span>
            </label>
          <?php endforeach; ?>
        </div>
        <input type="hidden" name="emoji" id="selectedEmoji" value="🎯">
      </div>

      <div class="form-group">
        <label class="form-label">Nama Target</label>
        <input class="form-control" type="text" name="title"
               placeholder="Liburan Bali, Dana Nikah, ..." required>
      </div>

      <div class="form-group">
        <label class="form-label">Target Nominal (Rp)</label>
        <input class="form-control" type="text" name="target_amount"
               placeholder="10000000" data-amount required>
      </div>

      <div class="form-group">
        <label class="form-label">Target Tanggal (opsional)</label>
        <input class="form-control" type="date" name="due_date"
               min="<?= date('Y-m-d') ?>">
      </div>

      <div class="flex-gap mt-2">
        <button type="submit" class="btn btn-primary">Buat Target</button>
        <button type="button" class="btn btn-outline" onclick="closeModal('modalCreateGoal')">Batal</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal: Contribute -->
<div class="modal-backdrop" id="modalContrib">
  <div class="modal">
    <div class="modal-header">
      <div class="modal-title">Setor Dana – <span id="contribTitle"></span></div>
      <button class="modal-close" onclick="closeModal('modalContrib')">✕</button>
    </div>
    <form method="POST" data-validate>
      <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
      <input type="hidden" name="paction" value="contribute">
      <input type="hidden" name="goal_id" id="contribGoalId">
      <div class="form-group">
        <label class="form-label">Nominal (Rp)</label>
        <input class="form-control" type="text" name="amount" placeholder="100000" data-amount required>
      </div>
      <div class="form-group">
        <label class="form-label">Catatan (opsional)</label>
        <input class="form-control" type="text" name="note" placeholder="Tabungan minggu ini...">
      </div>
      <div class="flex-gap mt-2">
        <button type="submit" class="btn btn-primary">Setor</button>
        <button type="button" class="btn btn-outline" onclick="closeModal('modalContrib')">Batal</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal: Withdraw -->
<div class="modal-backdrop" id="modalWithdraw">
  <div class="modal">
    <div class="modal-header">
      <div class="modal-title">Tarik Dana – <span id="withdrawTitle"></span></div>
      <button class="modal-close" onclick="closeModal('modalWithdraw')">✕</button>
    </div>
    <form method="POST" data-validate>
      <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
      <input type="hidden" name="paction" value="withdraw">
      <input type="hidden" name="goal_id" id="withdrawGoalId">
      <div class="alert alert-info">⚠ Hanya pemilik akun yang dapat menarik dana tabungan.</div>
      <div class="form-group">
        <label class="form-label">Nominal (Rp)</label>
        <input class="form-control" type="text" name="amount" placeholder="100000" data-amount required>
      </div>
      <div class="flex-gap mt-2">
        <button type="submit" class="btn btn-danger">Tarik Dana</button>
        <button type="button" class="btn btn-outline" onclick="closeModal('modalWithdraw')">Batal</button>
      </div>
    </form>
  </div>
</div>

<script src="<?= APP_URL ?>/js/app.js"></script>
<script>
function openContrib(id, title) {
  document.getElementById('contribGoalId').value = id;
  document.getElementById('contribTitle').textContent = title;
  openModal('modalContrib');
}
function openWithdraw(id, title) {
  document.getElementById('withdrawGoalId').value = id;
  document.getElementById('withdrawTitle').textContent = title;
  openModal('modalWithdraw');
}
function selectEmoji(el, emoji) {
  document.getElementById('selectedEmoji').value = emoji;
  document.querySelectorAll('[onclick^="selectEmoji"]').forEach(e => {
    e.style.outline = 'none';
  });
  el.style.outline = '2px solid var(--accent)';
}
</script>
</body>
</html>