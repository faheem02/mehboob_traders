<?php
session_start();
require_once 'config/db.php';

if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: login.php");
    exit;
}

if (isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';

    if ($username && $password) {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? AND status = 1 LIMIT 1");
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if ($user && $password === $user['password']) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_name'] = $user['full_name'];
            $_SESSION['user_role'] = $user['role'];
            $_SESSION['branch_id'] = $user['branch_id'];
            header("Location: index.php");
            exit;
        } else {
            $error = 'Invalid username or password';
        }
    } else {
        $error = 'Please enter username and password';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
  <title>Login | Mehboob Traders</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/all.min.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/4.6.2/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="bg-gradient-custom">

<div class="container">
  <div class="row justify-content-center align-items-center" style="min-height:100vh;">
    <div class="col-xl-5 col-lg-6 col-md-8 col-sm-10">
      <div class="card shadow" style="border-radius:12px;overflow:hidden;">
        <div class="card-body p-5">
          <div class="text-center mb-4">
            <div style="width:64px;height:64px;background:var(--primary);border-radius:16px;display:inline-flex;align-items:center;justify-content:center;margin-bottom:1rem;">
              <i class="fas fa-boxes fa-2x text-white"></i>
            </div>
            <h4 class="font-weight-bold" style="color:#0f172a;">Mehboob Traders</h4>
            <p class="text-muted small">Wholesale Management System</p>
          </div>

          <?php if ($error): ?>
            <div class="alert alert-danger py-2 small"><?= $error ?></div>
          <?php endif; ?>

          <form method="post">
            <div class="form-group">
              <label class="form-label">Username</label>
              <div class="input-group">
                <div class="input-group-prepend">
                  <span class="input-group-text"><i class="fas fa-user"></i></span>
                </div>
                <input type="text" name="username" class="form-control" placeholder="Enter username" required>
              </div>
            </div>
            <div class="form-group">
              <label class="form-label">Password</label>
              <div class="input-group">
                <div class="input-group-prepend">
                  <span class="input-group-text"><i class="fas fa-lock"></i></span>
                </div>
                <input type="password" name="password" class="form-control" placeholder="Password" required>
              </div>
            </div>
            <button type="submit" class="btn btn-primary btn-block mt-4 py-2">
              <i class="fas fa-sign-in-alt"></i> Sign In
            </button>
          </form>

          <hr class="my-4">
          <p class="text-center text-muted small mb-0">
            &copy; <?= date('Y') ?> Mehboob Traders
          </p>
        </div>
      </div>
    </div>
  </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/4.6.2/js/bootstrap.bundle.min.js"></script>
</body>
</html>