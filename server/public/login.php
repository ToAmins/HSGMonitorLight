<?php
declare(strict_types=1);

// Anmeldung an der Übersicht (und Abmeldung per POST action=logout).

require __DIR__ . '/../app/bootstrap.php';
start_session();

$isPost = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';

if ($isPost && ($_POST['action'] ?? '') === 'logout') {
    check_csrf();
    $_SESSION = [];
    session_destroy();
    header('Location: login.php', true, 303);
    exit;
}

if (is_logged_in()) {
    header('Location: index.php', true, 303);
    exit;
}

$next = (string) ($_GET['next'] ?? '');
$next = preg_match('/^[a-z]+\.php$/', $next) ? $next : 'index.php';
$configured = (string) config('admin_password_hash') !== '';
$error = null;

if ($isPost && $configured) {
    check_csrf();
    $user = (string) ($_POST['user'] ?? '');
    $password = (string) ($_POST['password'] ?? '');
    if (hash_equals((string) config('admin_user'), $user)
        && password_verify($password, (string) config('admin_password_hash'))) {
        session_regenerate_id(true);
        $_SESSION['user'] = $user;
        header('Location: ' . $next, true, 303);
        exit;
    }
    sleep(2); // bremst das Durchprobieren von Passwörtern
    $error = 'Benutzername oder Passwort stimmt nicht.';
}

page_header('Anmelden');
?>
<section class="card narrow">
  <h1>Anmelden</h1>
  <?php if (!$configured): ?>
    <p>Es ist noch kein Passwort eingerichtet. Wie das geht, zeigt <a href="check.php">check.php</a>.</p>
  <?php else: ?>
    <?php if ($error): ?><p class="note bad"><?= h($error) ?></p><?php endif; ?>
    <form method="post" action="login.php?next=<?= h(rawurlencode($next)) ?>" class="stack">
      <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
      <label for="user">Benutzername</label>
      <input id="user" name="user" autocomplete="username" required value="<?= h($_POST['user'] ?? '') ?>">
      <label for="password">Passwort</label>
      <input id="password" name="password" type="password" autocomplete="current-password" required>
      <button type="submit" class="button">Anmelden</button>
    </form>
  <?php endif; ?>
</section>
<?php
page_footer();
