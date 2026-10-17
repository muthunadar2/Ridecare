<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/layout.php';

if (current_user()) {
    redirect('dashboard.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name     = trim($_POST['name'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $password = (string) ($_POST['password'] ?? '');
    $confirm  = (string) ($_POST['confirm_password'] ?? '');

    $fail = function (string $msg) use ($name, $email) {
        remember_input(['name' => $name, 'email' => $email]);
        flash('error', $msg);
        redirect('register.php');
    };

    if (!csrf_valid())                                          { $fail('Your session expired. Please try again.'); }
    if ($name === '' || $email === '' || $password === '')      { $fail('Please fill in all fields.'); }
    if (mb_strlen($name) > 100)                                 { $fail('Name must be 100 characters or fewer.'); }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 150) { $fail('Enter a valid email address.'); }
    if (strlen($password) < 8)                                  { $fail('Password must be at least 8 characters.'); }
    if ($password !== $confirm)                                 { $fail('Passwords do not match.'); }

    try {
        db_query('INSERT INTO users (name, email, password) VALUES (?, ?, ?)', 'sss',
                 [$name, $email, password_hash($password, PASSWORD_DEFAULT)]);
    } catch (mysqli_sql_exception $e) {
        if ((int) $e->getCode() === 1062) {
            $fail('This email is already registered. Try signing in.');
        }
        error_log($e->getMessage());
        $fail('Registration failed. Please try again.');
    }

    flash('success', 'Account created. Sign in to add your first bike.');
    redirect('index.php');
}

page_head('Create account', 'auth-page');
auth_open('Your garage, organised.');
?>
    <h2>Create account</h2>
    <p class="muted">Free, takes under a minute.</p>

    <?php flash_messages(); ?>

    <form method="POST" action="register.php">
        <?= csrf_field() ?>
        <label for="name">Full name</label>
        <input id="name" name="name" required maxlength="100" autocomplete="name" value="<?= h(old('name')) ?>">

        <label for="email">Email</label>
        <input type="email" id="email" name="email" required maxlength="150" autocomplete="email" value="<?= h(old('email')) ?>">

        <label for="password">Password <span class="muted">(8+ characters)</span></label>
        <input type="password" id="password" name="password" required minlength="8" autocomplete="new-password">

        <label for="confirm_password">Confirm password</label>
        <input type="password" id="confirm_password" name="confirm_password" required minlength="8" autocomplete="new-password">

        <button class="btn block" type="submit">Create account</button>
    </form>

    <p class="alt">Already registered? <a href="index.php">Sign in</a></p>
<?php auth_close();
