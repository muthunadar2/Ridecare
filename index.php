<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/layout.php';

if (current_user()) {
    redirect('dashboard.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim($_POST['email'] ?? '');
    $password = (string) ($_POST['password'] ?? '');

    if (!csrf_valid()) {
        flash('error', 'Your session expired. Please try again.');
        redirect('index.php');
    }

    $user = $email !== '' ? db_one('SELECT id, name, password FROM users WHERE email = ? LIMIT 1', 's', [$email]) : null;

    if ($user && password_verify($password, $user['password'])) {
        session_regenerate_id(true);
        $_SESSION['user_id']   = (int) $user['id'];
        $_SESSION['user_name'] = $user['name'];
        redirect('dashboard.php');
    }

    // Same message whether the email or the password was wrong
    remember_input(['email' => $email]);
    flash('error', 'Invalid email or password.');
    redirect('index.php');
}

if (isset($_GET['out'])) {
    flash('success', 'You have been signed out.');
}

page_head('Sign in', 'auth-page');
auth_open('Never miss a bike service again.');
?>
    <h2>Sign in</h2>
    <p class="muted">Welcome back, rider.</p>

    <?php flash_messages(); ?>

    <form method="POST" action="index.php">
        <?= csrf_field() ?>
        <label for="email">Email</label>
        <input type="email" id="email" name="email" required autocomplete="email"
               placeholder="you@example.com" value="<?= h(old('email')) ?>">

        <label for="password">Password</label>
        <input type="password" id="password" name="password" required autocomplete="current-password">

        <button class="btn block" type="submit">Sign in</button>
    </form>

    <p class="alt">New here? <a href="register.php">Create an account</a></p>
<?php auth_close();
