<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/layout.php';

$user  = require_login();
$uid   = $user['id'];

$bikes = db_all('SELECT * FROM bikes WHERE user_id = ? ORDER BY name', 'i', [$uid]);
$rems  = user_reminders($uid);

$counts = ['overdue' => 0, 'soon' => 0, 'ok' => 0];
foreach ($rems as $r) {
    $counts[$r['calc']['state']]++;
}
$attention = array_values(array_filter($rems, fn($r) => $r['calc']['state'] !== 'ok'));

$spent = (float) (db_one(
    'SELECT COALESCE(SUM(s.cost), 0) AS t FROM services s JOIN bikes b ON b.id = s.bike_id
     WHERE b.user_id = ? AND YEAR(s.service_date) = ?',
    'ii', [$uid, (int) date('Y')]
)['t'] ?? 0);

$recent = db_all(
    'SELECT s.*, b.name AS bike_name FROM services s JOIN bikes b ON b.id = s.bike_id
     WHERE b.user_id = ? ORDER BY s.service_date DESC, s.id DESC LIMIT 5',
    'i', [$uid]
);

// Per-bike worst state, for the little status dot on each bike card
$worst = [];
foreach ($rems as $r) {
    $rank = ['ok' => 0, 'soon' => 1, 'overdue' => 2];
    $cur  = $worst[$r['bike_id']] ?? 'ok';
    if ($rank[$r['calc']['state']] > $rank[$cur]) { $cur = $r['calc']['state']; }
    $worst[$r['bike_id']] = $cur;
}

$hour  = (int) date('G');
$greet = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');

page_head('Overview');
app_open('home');
?>
<header class="top">
  <div>
    <h1><?= $greet ?>, <?= h(explode(' ', trim($user['name']))[0] ?: 'rider') ?></h1>
    <p class="muted">
      <?php if (!$bikes): ?>Add your first bike to start tracking.
      <?php elseif ($counts['overdue'] > 0): ?><?= $counts['overdue'] ?> job<?= $counts['overdue'] > 1 ? 's are' : ' is' ?> overdue. Time for the garage.
      <?php elseif ($counts['soon'] > 0): ?>Nothing overdue, but <?= $counts['soon'] ?> job<?= $counts['soon'] > 1 ? 's are' : ' is' ?> coming up.
      <?php else: ?>Everything is on track.<?php endif; ?>
    </p>
  </div>
  <a class="btn" href="service_add.php"><?= icon('plus') ?> Log service</a>
</header>

<?php flash_messages(); ?>

<section class="tiles">
  <div class="tile red"><span class="tile-n"><?= $counts['overdue'] ?></span><span class="tile-l">Overdue</span></div>
  <div class="tile amber"><span class="tile-n"><?= $counts['soon'] ?></span><span class="tile-l">Due soon</span></div>
  <div class="tile"><span class="tile-n"><?= count($bikes) ?></span><span class="tile-l">Bikes</span></div>
  <div class="tile"><span class="tile-n"><?= h(rupees($spent)) ?></span><span class="tile-l">Spent in <?= date('Y') ?></span></div>
</section>

<div class="cols">
  <section class="panel-box">
    <h2>Needs attention</h2>
    <?php if (!$bikes): ?>
      <div class="empty"><p>No bikes yet.</p><a class="btn" href="bikes.php">Add a bike</a></div>
    <?php elseif (!$rems): ?>
      <div class="empty"><p>You have no reminders set up.</p><a class="btn ghost" href="bike.php?id=<?= (int) $bikes[0]['id'] ?>">Add reminders</a></div>
    <?php elseif (!$attention): ?>
      <div class="empty ok-empty"><p>All clear. Your next job is
        <strong><?= h($rems[0]['task']) ?></strong> on <?= h($rems[0]['bike_name']) ?>
        (<?= h($rems[0]['calc']['text']) ?>).</p></div>
    <?php else: ?>
      <ul class="rems"><?php foreach ($attention as $r) { echo reminder_row($r); } ?></ul>
    <?php endif; ?>
  </section>

  <aside class="stack">
    <section class="panel-box">
      <h2>Your bikes</h2>
      <?php if (!$bikes): ?>
        <p class="muted">Nothing here yet.</p>
      <?php else: foreach ($bikes as $b): $w = $worst[$b['id']] ?? 'ok'; ?>
        <a class="bike-mini" href="bike.php?id=<?= (int) $b['id'] ?>">
          <span class="dot <?= h($w) ?>"></span>
          <span class="bm-main"><strong><?= h($b['name']) ?></strong><small><?= h($b['model'] ?: 'No model set') ?></small></span>
          <span class="bm-odo"><?= h(km((int) $b['odometer'])) ?></span>
        </a>
      <?php endforeach; endif; ?>
    </section>

    <section class="panel-box">
      <h2>Recent services</h2>
      <?php if (!$recent): ?>
        <p class="muted">Services you log will show up here.</p>
      <?php else: ?>
        <ul class="timeline">
        <?php foreach ($recent as $s): ?>
          <li><strong><?= h($s['task']) ?></strong>
            <span class="muted"><?= h($s['bike_name']) ?> · <?= h(fmt_date($s['service_date'])) ?></span></li>
        <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </section>
  </aside>
</div>
<?php app_close();
