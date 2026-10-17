<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/layout.php';

$user   = require_login();
$uid    = $user['id'];
$bikeId = (int) ($_GET['id'] ?? $_POST['bike_id'] ?? 0);
$bike   = $bikeId > 0 ? find_bike($bikeId, $uid) : null;

if (!$bike) {
    flash('error', 'That bike was not found.');
    redirect('bikes.php');
}
$self = 'bike.php?id=' . $bikeId;

/** Validate the add/edit reminder form. Returns [error|null, values]. */
function read_reminder_form(array $bike): array
{
    $task = trim($_POST['task'] ?? '');
    $ik   = parse_int($_POST['interval_km'] ?? '', 200000);
    $id   = parse_int($_POST['interval_days'] ?? '', 3650);
    $date = trim($_POST['last_done_date'] ?? '');
    $lkm  = parse_int($_POST['last_done_km'] ?? '');
    $vals = [$task, $ik ?: null, $id ?: null, $date, $lkm];

    if ($task === '' || mb_strlen($task) > 100)       { return ['Give the reminder a name (100 characters max).', $vals]; }
    if ($ik === false || $id === false)               { return ['Intervals must be whole numbers.', $vals]; }
    if (!$ik && !$id)                                 { return ['Set an interval in km, in days, or both.', $vals]; }
    if (!valid_date($date) || $date > today())        { return ['Pick a valid "last done" date that is not in the future.', $vals]; }
    if ($lkm === null || $lkm === false)              { return ['Enter the odometer reading when it was last done.', $vals]; }
    if ($lkm > (int) $bike['odometer'])               { return ['"Last done" km cannot be higher than the current odometer.', $vals]; }
    return [null, $vals];
}

/* ---------- Actions ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    if (!csrf_valid()) {
        flash('error', 'Your session expired. Please try again.');
        redirect($self);
    }

    switch ($action) {
        case 'odometer':
            $new = parse_int($_POST['odometer'] ?? '');
            if ($new === null || $new === false) {
                flash('error', 'Enter the odometer in whole kilometres.');
            } elseif ($new < (int) $bike['odometer']) {
                flash('error', 'The new reading is lower than the current one (' . km((int) $bike['odometer']) . ').');
            } else {
                db_query('UPDATE bikes SET odometer = ? WHERE id = ? AND user_id = ?', 'iii', [$new, $bikeId, $uid]);
                flash('success', 'Odometer updated to ' . km($new) . '.');
            }
            break;

        case 'edit_bike':
            $name  = trim($_POST['name'] ?? '');
            $model = trim($_POST['model'] ?? '');
            $reg   = strtoupper(trim($_POST['reg_no'] ?? ''));
            if ($name === '' || mb_strlen($name) > 100 || mb_strlen($model) > 100 || mb_strlen($reg) > 30) {
                flash('error', 'Check the bike details and try again.');
            } else {
                db_query('UPDATE bikes SET name = ?, model = ?, reg_no = ? WHERE id = ? AND user_id = ?',
                         'sssii', [$name, $model, $reg, $bikeId, $uid]);
                flash('success', 'Bike details saved.');
            }
            break;

        case 'add_reminder':
            [$err, $v] = read_reminder_form($bike);
            if ($err) {
                flash('error', $err);
            } else {
                db_query('INSERT INTO reminders (bike_id, task, interval_km, interval_days, last_done_date, last_done_km)
                          VALUES (?, ?, ?, ?, ?, ?)', 'isiisi', [$bikeId, $v[0], $v[1], $v[2], $v[3], $v[4]]);
                flash('success', 'Reminder added.');
            }
            break;

        case 'edit_reminder':
            $rid = (int) ($_POST['reminder_id'] ?? 0);
            [$err, $v] = read_reminder_form($bike);
            if ($err) {
                flash('error', $err);
            } else {
                db_query('UPDATE reminders SET task = ?, interval_km = ?, interval_days = ?, last_done_date = ?, last_done_km = ?
                          WHERE id = ? AND bike_id = ?', 'siisiii', [$v[0], $v[1], $v[2], $v[3], $v[4], $rid, $bikeId]);
                flash('success', 'Reminder updated.');
            }
            break;

        case 'delete_reminder':
            db_query('DELETE FROM reminders WHERE id = ? AND bike_id = ?', 'ii', [(int) ($_POST['reminder_id'] ?? 0), $bikeId]);
            flash('success', 'Reminder removed. Past service records are kept.');
            break;

        case 'delete_service':
            db_query('DELETE FROM services WHERE id = ? AND bike_id = ?', 'ii', [(int) ($_POST['service_id'] ?? 0), $bikeId]);
            flash('success', 'Service record deleted.');
            break;

        case 'delete_bike':
            db_query('DELETE FROM bikes WHERE id = ? AND user_id = ?', 'ii', [$bikeId, $uid]);
            flash('success', $bike['name'] . ' and all of its records were deleted.');
            redirect('bikes.php');
            break;

        default:
            flash('error', 'That action was not recognised.');
    }
    redirect($self);
}

/* ---------- Data for the page ---------- */
$rems     = user_reminders($uid, $bikeId);
$services = db_all('SELECT * FROM services WHERE bike_id = ? ORDER BY service_date DESC, id DESC LIMIT 20', 'i', [$bikeId]);
$total    = (float) (db_one('SELECT COALESCE(SUM(cost),0) AS t FROM services WHERE bike_id = ?', 'i', [$bikeId])['t'] ?? 0);

/** The reminder form fields, used for both "add" and "edit". */
function reminder_fields(?array $r, array $bike): string
{
    $v = fn($k, $d = '') => h($r[$k] ?? $d);
    return '<label>Task</label><input name="task" required maxlength="100" value="' . $v('task') . '" placeholder="e.g. Engine oil change">'
         . '<div class="two"><div><label>Every (km)</label><input name="interval_km" inputmode="numeric" pattern="[0-9]*" value="' . $v('interval_km') . '" placeholder="3000"></div>'
         . '<div><label>Every (days)</label><input name="interval_days" inputmode="numeric" pattern="[0-9]*" value="' . $v('interval_days') . '" placeholder="180"></div></div>'
         . '<div class="two"><div><label>Last done on</label><input type="date" name="last_done_date" required max="' . h(today()) . '" value="' . $v('last_done_date', today()) . '"></div>'
         . '<div><label>Odometer then (km)</label><input name="last_done_km" required inputmode="numeric" pattern="[0-9]*" value="' . $v('last_done_km', (string) $bike['odometer']) . '"></div></div>';
}

page_head($bike['name']);
app_open('bikes');
?>
<p class="crumb"><a href="bikes.php">← All bikes</a></p>

<header class="top">
  <div>
    <h1><?= h($bike['name']) ?></h1>
    <p class="muted"><?= h($bike['model'] ?: 'No model set') ?>
      <?php if ($bike['reg_no'] !== ''): ?> · <span class="plate"><?= h($bike['reg_no']) ?></span><?php endif; ?></p>
  </div>
  <form class="odo-form" method="POST" action="<?= h($self) ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="odometer">
    <div class="odo-read"><?= h(number_format((int) $bike['odometer'])) ?><span> km</span></div>
    <div class="odo-set">
      <input name="odometer" inputmode="numeric" pattern="[0-9]*" required aria-label="New odometer reading" placeholder="New reading">
      <button class="btn small" type="submit">Update</button>
    </div>
  </form>
</header>

<?php flash_messages(); ?>

<div class="cols">
  <section class="panel-box">
    <div class="row-between">
      <h2>Reminders</h2>
      <a class="btn small" href="service_add.php?bike=<?= $bikeId ?>"><?= icon('plus') ?> Log service</a>
    </div>

    <?php if (!$rems): ?>
      <p class="muted">No reminders yet. Add one below.</p>
    <?php else: ?>
      <ul class="rems">
      <?php foreach ($rems as $r):
          $edit = '<details class="more"><summary>Edit</summary>'
                . '<form method="POST" action="' . h($self) . '">' . csrf_field()
                . '<input type="hidden" name="action" value="edit_reminder"><input type="hidden" name="reminder_id" value="' . (int) $r['id'] . '">'
                . reminder_fields($r, $bike)
                . '<div class="row-between"><button class="btn small" type="submit">Save</button></div></form>'
                . '<form method="POST" action="' . h($self) . '" onsubmit="return confirm(\'Remove this reminder?\')">' . csrf_field()
                . '<input type="hidden" name="action" value="delete_reminder"><input type="hidden" name="reminder_id" value="' . (int) $r['id'] . '">'
                . '<button class="link danger" type="submit">Remove reminder</button></form></details>';
          echo reminder_row($r, false, $edit);
      endforeach; ?>
      </ul>
    <?php endif; ?>

    <details class="add-box" <?= $rems ? '' : 'open' ?>>
      <summary>+ Add a reminder</summary>
      <form method="POST" action="<?= h($self) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="add_reminder">
        <?= reminder_fields(null, $bike) ?>
        <button class="btn" type="submit">Add reminder</button>
      </form>
    </details>
  </section>

  <aside class="stack">
    <section class="panel-box">
      <div class="row-between"><h2>Service history</h2><span class="muted small">Total <?= h(rupees($total)) ?></span></div>
      <?php if (!$services): ?>
        <p class="muted">Nothing logged yet.</p>
      <?php else: ?>
        <ul class="timeline">
        <?php foreach ($services as $s): ?>
          <li>
            <strong><?= h($s['task']) ?></strong>
            <span class="muted"><?= h(fmt_date($s['service_date'])) ?> · <?= h(km((int) $s['odometer'])) ?>
              <?= $s['cost'] !== null ? ' · ' . h(rupees($s['cost'])) : '' ?></span>
            <?php if ($s['notes'] !== ''): ?><span class="note-line"><?= h($s['notes']) ?></span><?php endif; ?>
            <form method="POST" action="<?= h($self) ?>" onsubmit="return confirm('Delete this record?')">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="delete_service">
              <input type="hidden" name="service_id" value="<?= (int) $s['id'] ?>">
              <button class="link danger small" type="submit">Delete</button>
            </form>
          </li>
        <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </section>

    <section class="panel-box">
      <details>
        <summary class="h2-like">Bike details</summary>
        <form method="POST" action="<?= h($self) ?>">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="edit_bike">
          <label>Nickname</label><input name="name" required maxlength="100" value="<?= h($bike['name']) ?>">
          <label>Make &amp; model</label><input name="model" maxlength="100" value="<?= h($bike['model']) ?>">
          <label>Registration</label><input name="reg_no" maxlength="30" value="<?= h($bike['reg_no']) ?>">
          <button class="btn small" type="submit">Save details</button>
        </form>
        <form class="danger-zone" method="POST" action="<?= h($self) ?>"
              onsubmit="return confirm('Delete this bike and ALL its reminders and service records? This cannot be undone.')">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="delete_bike">
          <button class="btn danger" type="submit">Delete this bike</button>
        </form>
      </details>
    </section>
  </aside>
</div>
<?php app_close();
