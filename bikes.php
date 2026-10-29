<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/layout.php';

$user = require_login();
$uid  = $user['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name  = trim($_POST['name'] ?? '');
    $model = trim($_POST['model'] ?? '');
    $reg   = strtoupper(trim($_POST['reg_no'] ?? ''));
    $odo   = parse_int($_POST['odometer'] ?? '');
    $picks = array_map('intval', (array) ($_POST['presets'] ?? []));

    $fail = function (string $msg) use ($name, $model, $reg) {
        remember_input(['name' => $name, 'model' => $model, 'reg_no' => $reg]);
        flash('error', $msg);
        redirect('bikes.php');
    };

    if (!csrf_valid())                 { $fail('Your session expired. Please try again.'); }
    if ($name === '')                  { $fail('Give your bike a name.'); }
    if (mb_strlen($name) > 100 || mb_strlen($model) > 100 || mb_strlen($reg) > 30) { $fail('One of the fields is too long.'); }
    if ($odo === null || $odo === false) { $fail('Enter the current odometer reading in whole kilometres.'); }

    global $conn;
    $conn->begin_transaction();
    try {
        db_query('INSERT INTO bikes (user_id, name, model, reg_no, odometer) VALUES (?, ?, ?, ?, ?)',
                 'isssi', [$uid, $name, $model, $reg, $odo]);
        $bikeId = (int) $conn->insert_id;

        foreach ($picks as $i) {
            if (!isset(PRESET_REMINDERS[$i])) { continue; }
            [$task, $ik, $id] = PRESET_REMINDERS[$i];
            db_query('INSERT INTO reminders (bike_id, task, interval_km, interval_days, last_done_date, last_done_km)
                      VALUES (?, ?, ?, ?, ?, ?)', 'isiisi', [$bikeId, $task, $ik, $id, today(), $odo]);
        }
        $conn->commit();
    } catch (mysqli_sql_exception $e) {
        $conn->rollback();
        error_log($e->getMessage());
        $fail('We could not save the bike. Please try again.');
    }

    flash('success', $name . ' added. Update each reminder with when it was last done so the dates are accurate.');
    redirect('bike.php?id=' . $bikeId);
}

$bikes = db_all('SELECT * FROM bikes WHERE user_id = ? ORDER BY name', 'i', [$uid]);
$rems  = user_reminders($uid);
$by    = [];
foreach ($rems as $r) { $by[$r['bike_id']][] = $r; }

page_head('My bikes');
app_open('bikes');
?>
<header class="top">
  <div><h1>My bikes</h1><p class="muted">Everything in your garage.</p></div>
</header>

<?php flash_messages(); ?>

<div class="cols">
  <section>
    <?php if (!$bikes): ?>
      <div class="panel-box empty"><p>No bikes yet. Add your first one on the right.</p></div>
    <?php else: ?>
      <div class="bike-grid">
      <?php foreach ($bikes as $b):
          $list = $by[$b['id']] ?? [];
          $due  = count(array_filter($list, fn($r) => $r['calc']['state'] === 'overdue'));
          $soon = count(array_filter($list, fn($r) => $r['calc']['state'] === 'soon')); ?>
        <a class="bike-card" href="bike.php?id=<?= (int) $b['id'] ?>">
          <div class="bc-head"><strong><?= h($b['name']) ?></strong><small><?= h($b['model'] ?: '—') ?></small></div>
          <div class="bc-odo"><?= h(number_format((int) $b['odometer'])) ?><span> km</span></div>
          <div class="bc-foot">
            <?php if ($b['reg_no'] !== ''): ?><span class="plate"><?= h($b['reg_no']) ?></span><?php endif; ?>
            <?php if ($due): ?><span class="pill overdue"><?= $due ?> overdue</span>
            <?php elseif ($soon): ?><span class="pill soon"><?= $soon ?> due soon</span>
            <?php elseif ($list): ?><span class="pill ok">On track</span>
            <?php else: ?><span class="pill">No reminders</span><?php endif; ?>
          </div>
        </a>
      <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>

  <aside class="panel-box">
    <h2>Add a bike</h2>
    <form method="POST" action="bikes.php">
      <?= csrf_field() ?>
      <label for="name">Nickname</label>
      <input id="name" name="name" required maxlength="100" placeholder="e.g. Daily commuter" value="<?= h(old('name')) ?>">

      <label for="model">Make &amp; model</label>
      <input id="model" name="model" maxlength="100" placeholder="e.g. Honda Shine 125" value="<?= h(old('model')) ?>">

      <div class="two">
        <div><label for="reg_no">Registration</label>
          <input id="reg_no" name="reg_no" maxlength="30" placeholder="MH 02 AB 1234" value="<?= h(old('reg_no')) ?>"></div>
        <div><label for="odometer">Odometer (km)</label>
          <input id="odometer" name="odometer" inputmode="numeric" pattern="[0-9]*" required placeholder="12480"></div>
      </div>

      <label>Start with these reminders</label>
      <div class="checks">
        <?php foreach (PRESET_REMINDERS as $i => [$task, $ik, $id]): ?>
          <label class="check">
            <input type="checkbox" name="presets[]" value="<?= $i ?>" checked>
            <span><?= h($task) ?>
              <small><?= h(implode(' / ', array_filter([$ik ? 'every ' . number_format($ik) . ' km' : '', $id ? $id . ' days' : '']))) ?></small></span>
          </label>
        <?php endforeach; ?>
      </div>
      <p class="muted small">You can edit or remove these any time.</p>

      <button class="btn block" type="submit">Add bike</button>
    </form>
  </aside>
</div>
<?php app_close();
