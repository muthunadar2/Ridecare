<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/layout.php';

$user = require_login();
$uid  = $user['id'];

$bikes  = db_all('SELECT id, name FROM bikes WHERE user_id = ? ORDER BY name', 'i', [$uid]);
$bikeId = (int) ($_GET['bike'] ?? 0);
if (!in_array($bikeId, array_map('intval', array_column($bikes, 'id')), true)) {
    $bikeId = 0;
}
$year = (int) ($_GET['year'] ?? 0);
if ($year < 1990 || $year > 2100) {
    $year = 0;
}

$where = 'b.user_id = ?';
$types = 'i';
$args  = [$uid];
if ($bikeId) { $where .= ' AND s.bike_id = ?';            $types .= 'i'; $args[] = $bikeId; }
if ($year)   { $where .= ' AND YEAR(s.service_date) = ?'; $types .= 'i'; $args[] = $year; }

$rows = db_all(
    "SELECT s.*, b.name AS bike_name FROM services s JOIN bikes b ON b.id = s.bike_id
     WHERE $where ORDER BY s.service_date DESC, s.id DESC LIMIT 500",
    $types, $args
);

/* ---------- CSV download of the current filter ---------- */
if (isset($_GET['export'])) {
    $safe = fn($v) => preg_match('/^[=+\-@\t\r]/', (string) $v) ? "'" . $v : (string) $v; // block formula injection
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="service-history-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Date', 'Bike', 'Task', 'Odometer (km)', 'Cost', 'Notes']);
    foreach ($rows as $r) {
        fputcsv($out, [$r['service_date'], $safe($r['bike_name']), $safe($r['task']), $r['odometer'], $r['cost'], $safe($r['notes'])]);
    }
    exit;
}

$years = array_column(db_all(
    'SELECT DISTINCT YEAR(s.service_date) AS y FROM services s JOIN bikes b ON b.id = s.bike_id WHERE b.user_id = ? ORDER BY y DESC',
    'i', [$uid]
), 'y');

$sum = 0.0;
foreach ($rows as $r) { $sum += (float) $r['cost']; }

page_head('History');
app_open('history');
?>
<header class="top">
  <div><h1>Service history</h1><p class="muted"><?= count($rows) ?> record<?= count($rows) === 1 ? '' : 's' ?> · <?= h(rupees($sum)) ?> spent</p></div>
  <?php if ($rows): ?>
    <a class="btn ghost" href="?<?= h(http_build_query(array_filter(['bike' => $bikeId, 'year' => $year]) + ['export' => 1])) ?>">Download CSV</a>
  <?php endif; ?>
</header>

<?php flash_messages(); ?>

<section class="panel-box">
  <form class="filters" method="GET">
    <div>
      <label class="sr-only" for="bike">Bike</label>
      <select id="bike" name="bike">
        <option value="0">All bikes</option>
        <?php foreach ($bikes as $b): ?>
          <option value="<?= (int) $b['id'] ?>" <?= $bikeId === (int) $b['id'] ? 'selected' : '' ?>><?= h($b['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label class="sr-only" for="year">Year</label>
      <select id="year" name="year">
        <option value="0">All years</option>
        <?php foreach ($years as $y): ?>
          <option value="<?= (int) $y ?>" <?= $year === (int) $y ? 'selected' : '' ?>><?= (int) $y ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <button class="btn small" type="submit">Apply</button>
    <?php if ($bikeId || $year): ?><a class="link" href="history.php">Clear</a><?php endif; ?>
  </form>

  <?php if (!$rows): ?>
    <div class="empty"><p>No services match.</p><a class="btn" href="service_add.php">Log a service</a></div>
  <?php else: ?>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Date</th><th>Bike</th><th>Task</th><th class="num">Odometer</th><th class="num">Cost</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td class="nowrap"><?= h(fmt_date($r['service_date'])) ?></td>
            <td><a href="bike.php?id=<?= (int) $r['bike_id'] ?>"><?= h($r['bike_name']) ?></a></td>
            <td><strong><?= h($r['task']) ?></strong>
                <?php if ($r['notes'] !== ''): ?><div class="muted small"><?= h($r['notes']) ?></div><?php endif; ?></td>
            <td class="num nowrap"><?= h(number_format((int) $r['odometer'])) ?> km</td>
            <td class="num"><?= $r['cost'] !== null ? h(rupees($r['cost'])) : '–' ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>
<?php app_close();
