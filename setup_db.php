<?php
/**
 * RideCare database check.
 *
 * Open /setup_db.php after deploying to create or verify users, bikes, reminders and services
 * in the connected database. Safe to open any number of times (everything is "IF NOT EXISTS" /
 * only adds what is missing). The app already does this automatically on every request, so this
 * page is just a visible confirmation. It lists table names and status only; the exact MySQL
 * error for any failure goes to the server log (Render > Logs), never to the browser.
 */
define('RIDECARE_SKIP_SCHEMA', true);   // we run the check below ourselves so we can show the result
require_once __DIR__ . '/includes/bootstrap.php';

header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');

$error  = null;
$report = [];
try {
    $report = ensure_schema(true);
} catch (SchemaException $e) {
    $error  = $e->getMessage();
    $report = $e->report;
    http_response_code(503);
}

$labels = ['ok' => 'Ready', 'created' => 'Created just now', 'repaired' => 'Repaired', 'failed' => 'Failed', 'skipped' => 'Not attempted'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Database check · <?= h(APP_NAME) ?></title>
<style>
  body{font-family:system-ui,sans-serif;background:#14161a;color:#eee;margin:0;min-height:100vh;display:grid;place-items:center}
  .b{background:#1d2026;border:1px solid #2c3038;border-radius:10px;padding:28px 32px;width:min(520px,92vw)}
  h1{margin:0 0 12px;font-size:1.3rem} table{width:100%;border-collapse:collapse;margin:16px 0}
  td{padding:8px 4px;border-top:1px solid #2c3038;vertical-align:top} td small{display:block;color:#9aa0aa}
  .ok{color:#5fd38d}.bad{color:#ff6b6b} .created,.repaired{color:#ffc857} a{color:#ff8a45;font-weight:bold}
</style>
</head>
<body>
<div class="b">
  <h1>Database check</h1>
  <?php if ($error === null): ?>
    <p class="ok">Connected. users, bikes, reminders and services are all in place.</p>
  <?php else: ?>
    <p class="bad"><?= h($error) ?></p>
  <?php endif; ?>

  <table>
    <?php foreach ($report as $row): ?>
      <tr>
        <td><strong><?= h($row['table']) ?></strong>
          <?php foreach ($row['notes'] as $note): ?><small><?= h($note) ?></small><?php endforeach; ?></td>
        <td class="<?= in_array($row['status'], ['failed', 'skipped'], true) ? 'bad' : h($row['status']) ?>"><?= h($labels[$row['status']] ?? $row['status']) ?></td>
      </tr>
    <?php endforeach; ?>
  </table>

  <p><a href="index.php">Open <?= h(APP_NAME) ?></a></p>
</div>
</body>
</html>
