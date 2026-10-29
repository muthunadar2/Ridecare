<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/layout.php';

$user  = require_login();
$uid   = $user['id'];
$bikes = db_all('SELECT * FROM bikes WHERE user_id = ? ORDER BY name', 'i', [$uid]);

if (!$bikes) {
    flash('error', 'Add a bike first, then you can log services.');
    redirect('bikes.php');
}

/* ---------- Save ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $bikeId = (int) ($_POST['bike_id'] ?? 0);
    $remId  = (int) ($_POST['reminder_id'] ?? 0);
    $custom = trim($_POST['task'] ?? '');
    $date   = trim($_POST['service_date'] ?? '');
    $odo    = parse_int($_POST['odometer'] ?? '');
    $costIn = trim($_POST['cost'] ?? '');
    $notes  = trim($_POST['notes'] ?? '');

    $back = 'service_add.php?bike=' . $bikeId . ($remId ? '&reminder=' . $remId : '');
    $fail = function (string $msg) use ($back, $date, $odo, $costIn, $notes, $custom) {
        remember_input(['service_date' => $date, 'odometer' => (string) ($odo ?: ''), 'cost' => $costIn, 'notes' => $notes, 'task' => $custom]);
        flash('error', $msg);
        redirect($back);
    };

    if (!csrf_valid()) { $fail('Your session expired. Please try again.'); }

    $bike = find_bike($bikeId, $uid);
    if (!$bike) { $fail('Choose one of your bikes.'); }

    $task = $custom;
    $rem  = null;
    if ($remId > 0) {
        $rem = db_one('SELECT * FROM reminders WHERE id = ? AND bike_id = ?', 'ii', [$remId, $bikeId]);
        if (!$rem) { $fail('That reminder does not belong to this bike.'); }
        $task = $rem['task'];
    }

    if ($task === '' || mb_strlen($task) > 100)          { $fail('Say what was done (100 characters max).'); }
    if (!valid_date($date) || $date > today())           { $fail('Pick a valid service date that is not in the future.'); }
    if ($odo === null || $odo === false)                 { $fail('Enter the odometer reading in whole kilometres.'); }
    if ($costIn !== '' && !preg_match('/^\d{1,7}(\.\d{1,2})?$/', $costIn)) { $fail('Cost should be a number like 850 or 1250.50.'); }
    if (mb_strlen($notes) > 500)                         { $fail('Notes must be 500 characters or fewer.'); }

    $cost = $costIn === '' ? null : (float) $costIn;

    global $conn;
    $conn->begin_transaction();
    try {
        db_query('INSERT INTO services (bike_id, reminder_id, task, service_date, odometer, cost, notes) VALUES (?, ?, ?, ?, ?, ?, ?)',
                 'iissids', [$bikeId, $rem ? $remId : null, $task, $date, $odo, $cost, $notes]);

        // Restart the reminder clock, but only if this is the newest record for it
        if ($rem && $date >= $rem['last_done_date']) {
            db_query('UPDATE reminders SET last_done_date = ?, last_done_km = ? WHERE id = ?', 'sii', [$date, $odo, $remId]);
        }
        if ($odo > (int) $bike['odometer']) {
            db_query('UPDATE bikes SET odometer = ? WHERE id = ?', 'ii', [$odo, $bikeId]);
        }
        $conn->commit();
    } catch (mysqli_sql_exception $e) {
        $conn->rollback();
        error_log($e->getMessage());
        $fail('We could not save that service. Please try again.');
    }

    flash('success', $task . ' logged for ' . $bike['name'] . '.' . ($rem ? ' Reminder reset.' : ''));
    redirect('bike.php?id=' . $bikeId);
}

/* ---------- Pick the bike ---------- */
$bikeId = (int) ($_GET['bike'] ?? 0);
$bike   = $bikeId ? find_bike($bikeId, $uid) : null;
if (!$bike && count($bikes) === 1) {
    $bike = $bikes[0];
}

page_head('Log service');
app_open('service');

if (!$bike): ?>
<header class="top"><div><h1>Log a service</h1><p class="muted">Which bike was serviced?</p></div></header>
<section class="bike-grid">
  <?php foreach ($bikes as $b): ?>
    <a class="bike-card" href="service_add.php?bike=<?= (int) $b['id'] ?>">
      <div class="bc-head"><strong><?= h($b['name']) ?></strong><small><?= h($b['model'] ?: '—') ?></small></div>
      <div class="bc-odo"><?= h(number_format((int) $b['odometer'])) ?><span> km</span></div>
    </a>
  <?php endforeach; ?>
</section>
<?php app_close(); exit; endif;

$rems   = db_all('SELECT id, task FROM reminders WHERE bike_id = ? ORDER BY task', 'i', [$bike['id']]);
$wanted = (int) ($_GET['reminder'] ?? 0);
?>
<p class="crumb"><a href="bike.php?id=<?= (int) $bike['id'] ?>">← <?= h($bike['name']) ?></a></p>
<header class="top"><div><h1>Log a service</h1><p class="muted"><?= h($bike['name']) ?> · currently <?= h(km((int) $bike['odometer'])) ?></p></div></header>

<?php flash_messages(); ?>

<section class="panel-box narrow">
  <form method="POST" action="service_add.php">
    <?= csrf_field() ?>
    <input type="hidden" name="bike_id" value="<?= (int) $bike['id'] ?>">

    <label for="reminder_id">What was done?</label>
    <select id="reminder_id" name="reminder_id">
      <?php foreach ($rems as $r): ?>
        <option value="<?= (int) $r['id'] ?>" <?= $wanted === (int) $r['id'] ? 'selected' : '' ?>><?= h($r['task']) ?> (resets reminder)</option>
      <?php endforeach; ?>
      <option value="0" <?= (!$wanted && !$rems) ? 'selected' : '' ?>>Something else…</option>
    </select>

    <div id="custom-wrap" hidden>
      <label for="task">Describe it</label>
      <input id="task" name="task" maxlength="100" placeholder="e.g. Puncture repair" value="<?= h(old('task')) ?>">
    </div>

    <div class="two">
      <div><label for="service_date">Date</label>
        <input type="date" id="service_date" name="service_date" required max="<?= h(today()) ?>" value="<?= h(old('service_date', today())) ?>"></div>
      <div><label for="odometer">Odometer (km)</label>
        <input id="odometer" name="odometer" required inputmode="numeric" pattern="[0-9]*" value="<?= h(old('odometer', (string) $bike['odometer'])) ?>"></div>
    </div>

    <label for="cost">Cost in ₹ <span class="muted">(optional)</span></label>
    <input id="cost" name="cost" inputmode="decimal" placeholder="850" value="<?= h(old('cost')) ?>">

    <label for="notes">Notes <span class="muted">(optional)</span></label>
    <textarea id="notes" name="notes" rows="3" maxlength="500" placeholder="Workshop name, parts used, anything to remember"><?= h(old('notes')) ?></textarea>

    <button class="btn" type="submit">Save service</button>
  </form>
</section>

<script src="assets/js/app.js"></script>
<?php app_close();
