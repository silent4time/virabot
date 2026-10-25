<?php
require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/icons.php';
if (function_exists('mv_secure_session')) {
  mv_secure_session();
} elseif (session_status() === PHP_SESSION_NONE) {
  session_start();
}

if (!empty($_SESSION['admin_user'])) {
  header('Location: index.php');
  exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $username = trim($_POST['username'] ?? '');
  $password = $_POST['password'] ?? '';
  $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

  if ($username === '' || $password === '') {
    $error = $textbotlang['panel']['loginEnterCredentials'];
  } elseif (!hash_equals($_SESSION['csrf'] ?? '', (string) ($_POST['_csrf'] ?? ''))) {
    $error = $textbotlang['panel']['configInvalidRequest'];
  } elseif (!check_login_rate($ip) || !check_login_rate('user:' . mb_strtolower($username))) {

    $error = $textbotlang['panel']['loginTooManyAttempts'];
    error_log("Login rate limit hit for IP: $ip");
  } else {

    $admin = select("admin", "*", "username", $username, "select");

    // Constant-time check. bcrypt/argon hashes go through password_verify;
    // a legacy plaintext value is compared with hash_equals and rehashed below.
    $dummyHash = '$2y$12$C6UzMDM.H6dfI/f/IKcEeO6wYJwT5DkJv6ZQHq1S7uY6t1VfQ9n1W';
    $storedHash = $admin ? (string) $admin['password'] : $dummyHash;
    $info = password_get_info($storedHash);
    $isHashed = !empty($info['algo']);
    $isCorrect = false;
    if ($isHashed) {
      $isCorrect = password_verify($password, $storedHash);
    } else {
      password_verify($password, $dummyHash); // keep timing similar
      $isCorrect = $admin && hash_equals($storedHash, (string) $password);
    }

    if ($isCorrect && $admin) {

      if (!$isHashed || password_needs_rehash($storedHash, PASSWORD_BCRYPT, ['cost' => 12])) {
        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
        update("admin", "password", $hash, "username", $username);
      }
      clear_login_rate($ip);
      clear_login_rate('user:' . mb_strtolower($username));
      session_regenerate_id(true);
      $_SESSION['admin_user'] = $admin['username'];
      $_SESSION['login_time'] = time();
      flash('success', $textbotlang['panel']['loginWelcomeBack'] . $admin['username']);
      header('Location: index.php');
      exit;
    } else {
      $error = $textbotlang['panel']['loginWrongCredentials'];
      error_log("Failed panel login from IP: $ip");
    }
  }
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl" data-theme="carunit">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1">
  <meta name="theme-color" content="#0d0d0d" id="mtc">
  <title>ورود — ویرابات</title>
  <link rel="stylesheet" href="css/style.css?v=20261011b">
  <link rel="stylesheet" href="css/virabot.css">
  <script>(function () { var t = localStorage.getItem('panel-theme') || 'carunit'; document.documentElement.setAttribute('data-theme', t); var c = { navy: '#0F172A', carunit: '#0d0d0d', purple: '#180D2E', emerald: '#0A1F1C', sunset: '#1A0D0D', slate: '#080808', light: '#F1F5F9', linen: '#FAF7F2', mint: '#F0FDF4', lavender: '#FAF5FF' }; var m = document.getElementById('mtc'); if (m && c[t]) m.content = c[t]; })();</script>
<style>.auth-mark{display:flex;flex-direction:column;align-items:center}.auth-box{position:relative}.brand-line{position:absolute;left:0;right:0;top:-150px;margin:0;font-size:36px;font-weight:800;white-space:nowrap;text-align:center;color:#c8c8c8 !important}</style>
</head>

<body>
  <div class="auth">
    <aside class="auth-aside">
      <div class="auth-mark">
        <img src="virabot-logo.png" alt="Virabot" style="width:min(540px,96%);height:auto;display:block;margin:28vh auto 0">
      </div>
      <div class="auth-foot">© <?= date('Y') ?> · Virabot · <a style="color:#f5c518 !important" href="https://github.com/silent4time/virabot">لینک پروژه در گیتهاب</a></div>
    </aside>
    <main class="auth-main">
      <div class="auth-box" style="animation:fadeUp .5s ease-out">
        <p class="brand-line">صفحه مدیریت پنل ویرابات</p>
        <h1><?= $textbotlang['panel']['loginButton'] ?></h1>
        <p class="lede"><?= $textbotlang['panel']['loginRememberMe'] ?></p>
        <?php if ($error): ?>
          <div class="notice notice-no" style="margin-bottom:20px"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <form class="auth-form" method="POST" autocomplete="on">
          <?php ?>
          <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
          <div class="field">
            <label for="username"><?= $textbotlang['panel']['loginFooter'] ?></label>
            <input type="text" id="username" name="username" class="input" placeholder="admin"
              value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" autocomplete="username" required autofocus
              maxlength="100">
          </div>
          <div class="field">
            <label for="password"><?= $textbotlang['panel']['loginErrorTitle'] ?></label>
            <input type="password" id="password" name="password" class="input" placeholder="••••••••"
              autocomplete="current-password" required maxlength="200">
          </div>
          <button type="submit" class="btn btn-primary" id="loginBtn">
            <span id="loginText"><?= $textbotlang['panel']['loginShowPassword'] ?></span>
            <span id="loginSpin"
              style="display:none;width:16px;height:16px;border:2px solid rgba(255,255,255,.4);border-top-color:#fff;border-radius:50%;animation:spin .6s linear infinite"></span>
          </button>
        </form>
        <div class="auth-bottom"><?= $textbotlang['panel']['loginHidePassword'] ?></div>
      </div>
    </main>
  </div>
  <script src="js/login.js"></script>
</body>

</html>