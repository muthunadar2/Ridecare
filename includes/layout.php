<?php
/** Page chrome shared by every screen. */

function page_head(string $title, string $bodyClass = ''): void
{
    $css = 'assets/css/app.css';
    $ver = @filemtime(__DIR__ . '/../' . $css) ?: 1;
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">'
       . '<meta name="viewport" content="width=device-width, initial-scale=1.0">'
       . '<meta name="theme-color" content="#14161a">'
       . '<title>' . h($title) . ' · ' . APP_NAME . '</title>'
       . '<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns=%27http://www.w3.org/2000/svg%27 viewBox=%270 0 100 100%27%3E%3Ctext y=%27.9em%27 font-size=%2790%27%3E%F0%9F%8F%8D%EF%B8%8F%3C/text%3E%3C/svg%3E">'
       . '<link rel="stylesheet" href="' . $css . '?v=' . $ver . '"></head><body'
       . ($bodyClass !== '' ? ' class="' . h($bodyClass) . '"' : '') . '>';
}

/** Small inline icons so there are no external files. */
function icon(string $name): string
{
    $paths = [
        'home'    => '<path d="M3 11l9-8 9 8v9a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1z"/>',
        'bike'    => '<circle cx="5.5" cy="17" r="3.5"/><circle cx="18.5" cy="17" r="3.5"/><path d="M5.5 17L9 8h5l4.5 9M9 8l3 9M14 8l-1-3h3"/>',
        'wrench'  => '<path d="M14.7 6.3a4 4 0 0 0-5 5L3 18l3 3 6.7-6.7a4 4 0 0 0 5-5l-2.4 2.4-2.6-.6-.6-2.6z"/>',
        'list'    => '<path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/>',
        'plus'    => '<path d="M12 5v14M5 12h14"/>',
    ];
    return '<svg class="ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
         . ($paths[$name] ?? '') . '</svg>';
}

/** Dark sidebar (becomes a bottom bar on phones) + content column. */
function app_open(string $active): void
{
    $user  = current_user();
    $items = [
        'home'    => ['dashboard.php',   'Overview', 'home'],
        'bikes'   => ['bikes.php',       'My bikes', 'bike'],
        'service' => ['service_add.php', 'Log service', 'wrench'],
        'history' => ['history.php',     'History', 'list'],
    ];
    echo '<div class="shell"><aside class="side">'
       . '<a class="brand" href="dashboard.php"><span class="brand-mark">R</span><span class="brand-name">' . APP_NAME . '</span></a>'
       . '<nav class="side-nav" aria-label="Main">';
    foreach ($items as $key => [$href, $label, $ic]) {
        $on = $key === $active ? ' class="on" aria-current="page"' : '';
        echo '<a' . $on . ' href="' . $href . '">' . icon($ic) . '<span>' . h($label) . '</span></a>';
    }
    echo '</nav><div class="side-user"><div class="who">' . h($user['name'] ?? '') . '</div>'
       . '<a href="logout.php">Sign out</a></div></aside><div class="content">';
}

function app_close(): void
{
    echo '<footer class="foot">' . APP_NAME . ' · keep it rolling</footer></div></div></body></html>';
}

function flash_messages(): void
{
    foreach (take_flashes() as [$type, $msg]) {
        $role = $type === 'error' ? 'alert' : 'status';
        echo '<p class="note ' . h($type) . '" role="' . $role . '">' . h($msg) . '</p>';
    }
}

/** Sign-in / register frame: dark screen with a speedometer graphic. */
function auth_open(string $tagline): void
{
    echo '<main class="auth"><section class="auth-hero"><div class="brand"><span class="brand-mark">R</span><span class="brand-name">' . APP_NAME . '</span></div>'
       . '<h1>' . h($tagline) . '</h1>'
       . '<div class="gauge" aria-hidden="true"><div class="gauge-arc"></div><div class="gauge-needle"></div><div class="gauge-hub"></div>'
       . '<span class="gauge-label">12,480 km</span></div>'
       . '<p class="hero-note">Log every service. Get reminded by kilometres <em>and</em> by date, whichever comes first.</p>'
       . '</section><section class="auth-panel"><div class="panel">';
}

function auth_close(): void
{
    echo '</div></section></main></body></html>';
}

/** One reminder as a row with a progress bar. */
function reminder_row(array $r, bool $showBike = true, string $extra = ''): string
{
    $c   = $r['calc'];
    $pct = (int) min(100, round($c['pct']));
    $label = ['ok' => 'On track', 'soon' => 'Due soon', 'overdue' => 'Overdue'][$c['state']];

    $out  = '<li class="rem ' . h($c['state']) . '">';
    $out .= '<div class="rem-top"><div><div class="rem-task">' . h($r['task']) . '</div>';
    if ($showBike) {
        $out .= '<a class="rem-bike" href="bike.php?id=' . (int) $r['bike_id'] . '">' . h($r['bike_name']) . '</a>';
    } else {
        $out .= '<div class="rem-bike">Next ' . h($c['next']) . '</div>';
    }
    $out .= '</div><span class="pill ' . h($c['state']) . '">' . $label . '</span></div>';
    $out .= '<div class="bar" role="img" aria-label="' . $pct . '% of interval used"><span style="width:' . $pct . '%"></span></div>';
    $out .= '<div class="rem-bottom"><span>' . h($c['text']) . '</span>'
          . '<a class="link" href="service_add.php?bike=' . (int) $r['bike_id'] . '&amp;reminder=' . (int) $r['id'] . '">Mark done</a></div>';
    return $out . $extra . '</li>';
}
