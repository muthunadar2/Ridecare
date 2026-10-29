<?php
/**
 * RideCare bootstrap: session, database, schema, helpers and reminder maths.
 * Every page starts with: require_once __DIR__ . '/includes/bootstrap.php';
 * Expects config/db.php to create a mysqli object named $conn.
 */

const APP_NAME = 'RideCare';
const SOON_PCT = 85;   // a reminder is "due soon" once 85% of its interval is used

/** Quick-add reminders offered when a bike is created: [task, every km, every days] */
const PRESET_REMINDERS = [
    ['Engine oil change',   3000, 180],
    ['Chain clean & lube',   500,  30],
    ['General service',     6000, 180],
    ['Air filter',          6000, 365],
    ['Spark plug',          8000, 365],
    ['Brake pad check',    10000, 365],
    ['Insurance renewal',   null, 365],
];

/* ---------- Errors: log details, show a friendly page ---------- */
set_exception_handler(function (Throwable $e) {
    error_log('[RideCare] ' . (string) $e);
    // Database-setup problems carry a safe, table-names-only message worth showing; everything else stays generic.
    $setup = $e instanceof SchemaException;
    if (!headers_sent()) {
        http_response_code($setup ? 503 : 500);
    }
    $title = $setup ? 'Database setup incomplete' : 'Something went wrong';
    $text  = $setup ? $e->getMessage() : 'We could not complete your request. Please try again in a moment.';
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</title>'
       . '<meta name="viewport" content="width=device-width, initial-scale=1">'
       . '<style>body{font-family:system-ui,sans-serif;background:#14161a;display:grid;place-items:center;min-height:100vh;margin:0;color:#eee}'
       . '.b{background:#1d2026;padding:32px;border-radius:10px;max-width:480px;border:1px solid #2c3038}a{color:#ff8a45;font-weight:bold}</style></head>'
       . '<body><div class="b"><h2>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h2><p>' . htmlspecialchars($text, ENT_QUOTES, 'UTF-8') . '</p>'
       . ($setup ? '<p><a href="setup_db.php">Run the database check</a></p>' : '<a href="index.php">Back to home</a>')
       . '</div></body></html>';
    exit;
});

/* ---------- Session (works behind an HTTPS proxy such as Render) ---------- */
$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => $isHttps,
    ]);
    session_start();
}

date_default_timezone_set(getenv('APP_TIMEZONE') ?: 'Asia/Kolkata');

/* ---------- Database ---------- */
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require_once __DIR__ . '/../config/db.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    throw new RuntimeException('config/db.php must create a mysqli connection named $conn.');
}
$conn->set_charset('utf8mb4');

/*
 * Make sure users, bikes, reminders and services exist with every column the app needs.
 * Checked on every request (one cheap query), created/repaired automatically when something is
 * missing, and never swallowed: a table that cannot be created throws SchemaException (see schema.php).
 * setup_db.php defines RIDECARE_SKIP_SCHEMA so it can run the same check itself and display the result.
 */
require_once __DIR__ . '/schema.php';
if (!defined('RIDECARE_SKIP_SCHEMA')) {
    ensure_schema();
}

/* ---------- Query helpers (always prepared statements) ---------- */
function db_query(string $sql, string $types = '', array $params = []): mysqli_stmt
{
    global $conn;
    $stmt = $conn->prepare($sql);
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    return $stmt;
}

function db_all(string $sql, string $types = '', array $params = []): array
{
    $stmt = db_query($sql, $types, $params);
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function db_one(string $sql, string $types = '', array $params = []): ?array
{
    return db_all($sql, $types, $params)[0] ?? null;
}

/* ---------- Basics ---------- */
function h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function redirect(string $to): void
{
    header('Location: ' . $to);
    exit;
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = [$type, $message];
}

function take_flashes(): array
{
    $all = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $all;
}

function remember_input(array $data): void
{
    $_SESSION['old'] = $data;
}

function old(string $key, string $default = ''): string
{
    static $cache = null;
    if ($cache === null) {
        $cache = $_SESSION['old'] ?? [];
        unset($_SESSION['old']);
    }
    return array_key_exists($key, $cache) ? (string) $cache[$key] : $default;
}

/* ---------- CSRF ---------- */
function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">';
}

function csrf_valid(): bool
{
    return isset($_POST['csrf'], $_SESSION['csrf'])
        && hash_equals($_SESSION['csrf'], (string) $_POST['csrf']);
}

/* ---------- Auth ---------- */
function current_user(): ?array
{
    if (empty($_SESSION['user_id'])) {
        return null;
    }
    return ['id' => (int) $_SESSION['user_id'], 'name' => (string) ($_SESSION['user_name'] ?? '')];
}

function require_login(): array
{
    $user = current_user();
    if (!$user) {
        flash('error', 'Please sign in to continue.');
        redirect('index.php');
    }
    return $user;
}

/** Load a bike only if it belongs to the signed-in user. */
function find_bike(int $bikeId, int $userId): ?array
{
    return db_one('SELECT * FROM bikes WHERE id = ? AND user_id = ? LIMIT 1', 'ii', [$bikeId, $userId]);
}

/* ---------- Input parsing ---------- */
/** '' -> null, digits -> int, anything else (or above $max) -> false */
function parse_int($value, int $max = 2000000)
{
    $value = trim((string) $value);
    if ($value === '') {
        return null;
    }
    if (!ctype_digit($value) || strlen($value) > 9) {
        return false;
    }
    $n = (int) $value;
    return $n > $max ? false : $n;
}

function valid_date(string $s): bool
{
    $d = DateTime::createFromFormat('Y-m-d', $s);
    return $d && $d->format('Y-m-d') === $s;
}

function today(): string
{
    return date('Y-m-d');
}

function days_between(string $from, string $to): int
{
    return (int) (new DateTimeImmutable($from))->diff(new DateTimeImmutable($to))->format('%r%a');
}

function fmt_date(?string $date, string $format = 'd M Y'): string
{
    return $date ? (new DateTimeImmutable($date))->format($format) : '–';
}

function km(int $n): string
{
    return number_format($n) . ' km';
}

function rupees($n): string
{
    return '₹' . number_format((float) $n, 0);
}

/* ---------- Reminder maths ---------- */
/**
 * Work out how close a reminder is to being due.
 * Uses whichever limit (km or days) is closer. Returns:
 *   state: ok | soon | overdue,  pct: 0..n,  text: "420 km left · 35 days left"
 */
function reminder_state(array $r, int $odometer, ?string $today = null): array
{
    $today = $today ?? today();
    $pcts  = [];
    $parts = [];
    $next  = [];

    if (!empty($r['interval_km'])) {
        $used     = $odometer - (int) $r['last_done_km'];
        $left     = (int) $r['interval_km'] - $used;
        $pcts[]   = max(0, $used) / (int) $r['interval_km'] * 100;
        $parts[]  = $left >= 0 ? number_format($left) . ' km left' : 'overdue by ' . number_format(-$left) . ' km';
        $next[]   = 'at ' . number_format((int) $r['last_done_km'] + (int) $r['interval_km']) . ' km';
    }
    if (!empty($r['interval_days'])) {
        $used     = days_between($r['last_done_date'], $today);
        $left     = (int) $r['interval_days'] - $used;
        $pcts[]   = max(0, $used) / (int) $r['interval_days'] * 100;
        if ($left > 1)       { $parts[] = $left . ' days left'; }
        elseif ($left === 1) { $parts[] = 'due tomorrow'; }
        elseif ($left === 0) { $parts[] = 'due today'; }
        else                 { $parts[] = 'overdue by ' . abs($left) . ($left === -1 ? ' day' : ' days'); }
        $nextDate = (new DateTimeImmutable($r['last_done_date']))->modify('+' . (int) $r['interval_days'] . ' days');
        $next[]   = 'by ' . $nextDate->format('d M Y');
    }

    $pct   = $pcts ? max($pcts) : 0;
    $state = $pct >= 100 ? 'overdue' : ($pct >= SOON_PCT ? 'soon' : 'ok');

    return [
        'state' => $state,
        'pct'   => $pct,
        'text'  => implode(' · ', $parts),
        'next'  => implode(' or ', $next),
    ];
}

/** Every reminder of a user (or one bike) with its computed state, most urgent first. */
function user_reminders(int $userId, ?int $bikeId = null): array
{
    $sql = 'SELECT r.*, b.name AS bike_name, b.odometer
            FROM reminders r JOIN bikes b ON b.id = r.bike_id
            WHERE b.user_id = ?';
    $types = 'i';
    $args  = [$userId];
    if ($bikeId !== null) {
        $sql    .= ' AND b.id = ?';
        $types  .= 'i';
        $args[]  = $bikeId;
    }
    $rows  = db_all($sql, $types, $args);
    $today = today();
    foreach ($rows as &$r) {
        $r['calc'] = reminder_state($r, (int) $r['odometer'], $today);
    }
    unset($r);
    usort($rows, fn($a, $b) => $b['calc']['pct'] <=> $a['calc']['pct']);
    return $rows;
}
