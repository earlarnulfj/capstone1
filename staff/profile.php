<?php
// ===== Dependencies & access control (POS / Staff only) =====
include_once '../config/session.php';
require_once '../config/database.php';

// Load the model class used by this page
require_once '../models/user.php';

requireStaffPage();

// ---- Create dependencies ----
$db   = (new Database())->getConnection();
$user = new User($db);

// ---- Page state ----
$message = '';
$messageType = 'info';

// For sidebar active highlight (your staff sidebar often uses 'profile')
$activePage = 'profile';

// Get user ID from namespaced staff session
$user_id = (int) $_SESSION['staff']['user_id'];

// Fetch user data by ID
if ($user->readOne($user_id)) {
    $username = $user->username;
    $email    = $user->email;
    $phone    = $user->phone;
    // role is fixed to 'staff' for POS users
} else {
    $message = "User data could not be retrieved.";
    $messageType = "danger";
}

// Process form submission for updating user data (POS can change only their own info)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update') {
    ensureCsrf();
    // Collect form data
    $newUsername = trim($_POST['username'] ?? $username ?? '');
    $newEmail    = trim($_POST['email']    ?? $email    ?? '');
    $newPhone    = trim($_POST['phone']    ?? $phone    ?? '');

    // Validate phone: optional, but if provided must be exactly 11 digits
    $invalidPhone = ($newPhone !== '' && !preg_match('/^\d{11}$/', $newPhone));

    if ($invalidPhone) {
        $message = "Phone must be exactly 11 digits.";
        $messageType = "danger";
    } else {
        // Update the user settings
        $user->id       = $user_id;
        $user->username = $newUsername;
        $user->email    = $newEmail;
        $user->phone    = $newPhone;
        $user->role     = 'staff'; // ensure POS remains staff

        if ($user->update()) {
            // Refresh model & update the staff session snapshot (header/sidebar use these)
            $user->readOne($user_id);
            $_SESSION['staff']['username'] = $user->username;
            $_SESSION['staff']['email']    = $user->email;
            $_SESSION['staff']['role']     = 'staff';

            $username = $user->username;
            $email    = $user->email;
            $phone    = $user->phone;

            $message = "Your settings have been updated.";
            $messageType = "success";
        } else {
            $message = "Unable to update your settings.";
            $messageType = "danger";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Settings • POS (Staff)</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet" />
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" />
  <link rel="stylesheet" href="../assets/css/style.css" />
</head>
<body>
<?php include_once 'includes/header.php'; ?>

<div class="container-fluid">
  <div class="row">
    <?php include_once 'includes/sidebar.php'; ?>

    <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 py-4">
      <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
        <h1 class="h2">Settings</h1>
      </div>

      <?php if (!empty($message)): ?>
        <div class="alert alert-<?= htmlspecialchars($messageType) ?> alert-dismissible fade show" role="alert">
          <?= htmlspecialchars($message) ?>
          <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
      <?php endif; ?>

      <h3>Update Your Account</h3>
      <form method="POST">
        <input type="hidden" name="action" value="update" />

        <div class="mb-3">
          <label for="username" class="form-label">Username</label>
          <input
            type="text"
            class="form-control"
            id="username"
            name="username"
            value="<?= htmlspecialchars($username ?? '') ?>"
            required
          />
        </div>

        <div class="mb-3">
          <label for="email" class="form-label">Email</label>
          <input
            type="email"
            class="form-control"
            id="email"
            name="email"
            value="<?= htmlspecialchars($email ?? '') ?>"
            required
          />
        </div>

        <div class="mb-3">
          <label for="phone" class="form-label">Phone</label>
          <input
            type="tel"
            class="form-control"
            id="phone"
            name="phone"
            inputmode="numeric" pattern="[0-9]{11}" maxlength="11"
            value="<?= htmlspecialchars($phone ?? '') ?>"
          />
          <div class="form-text">If provided, phone must be exactly 11 digits.</div>
        </div>

        <!-- Role is fixed to 'staff' for POS; no control shown -->
        <input type="hidden" name="role" value="staff" />

        <button type="submit" class="btn btn-primary">Save Changes</button>
      </form>
    </main>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Enforce numeric-only input and length on phone
const phoneInput = document.getElementById('phone');
if (phoneInput) {
  phoneInput.addEventListener('input', function() {
    this.value = this.value.replace(/\D/g, '').slice(0, 11);
  });
}

// Client-side validation on submit: optional, but if provided must be 11 digits
const form = document.querySelector('form');
if (form && phoneInput) {
  form.addEventListener('submit', function(e) {
    phoneInput.setCustomValidity('');
    const val = phoneInput.value.trim();
    if (val !== '' && !/^\d{11}$/.test(val)) {
      phoneInput.setCustomValidity('Phone must be exactly 11 digits');
      e.preventDefault();
      return false;
    }
    return true;
  });
}
</script>
</body>
</html>
