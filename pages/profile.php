<?php
require_once "../includes/functions.php";
require_login();

$stmt = $pdo->prepare("SELECT username, email, role, created_at, last_login, must_change FROM users WHERE user_id = ?");
$stmt->execute([$_SESSION["user_id"]]);
$account = $stmt->fetch();
$errors = [];

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    csrf_check();
    $current = post("current_password");
    $new = post("new_password");
    $confirm = post("confirm_password");

    $stmt = $pdo->prepare("SELECT password_hash FROM users WHERE user_id = ?");
    $stmt->execute([$_SESSION["user_id"]]);

    if (!password_verify($current, $stmt->fetchColumn()))
        $errors["current_password"] = "Current password is wrong";
    if (!password_ok($new))
        $errors["new_password"] = "Password needs 8+ characters with uppercase, lowercase and a number";
    elseif ($new === $current)
        $errors["new_password"] = "The new password must be different";
    if ($confirm !== $new)
        $errors["confirm_password"] = "Passwords do not match";

    if (!$errors) {
        $pdo->prepare("UPDATE users SET password_hash = ?, must_change = 0 WHERE user_id = ?")
            ->execute([password_hash($new, PASSWORD_DEFAULT), $_SESSION["user_id"]]);
        session_regenerate_id(true);
        log_action("password", "user", $_SESSION["user_id"], "Password changed");
        flash("Password changed.");
        redirect("profile.php");
    }
}

page_start("My Account");
?>

<h1>My Account</h1>

<div class="card">
    <h2>Details</h2>
    <dl>
        <dt>Username</dt><dd><?= h($account["username"]) ?></dd>
        <dt>Email</dt><dd><?= h($account["email"]) ?></dd>
        <dt>Role</dt><dd><?= h($account["role"]) ?></dd>
        <dt>Member since</dt><dd><?= h(substr($account["created_at"], 0, 10)) ?></dd>
        <dt>Last login</dt><dd><?= h($account["last_login"] ?? "-") ?></dd>
    </dl>
</div>

<div class="card narrow">
    <h2>Change Password</h2>
    <form method="post" action="profile.php" class="validate" novalidate>
        <?php csrf_field(); ?>
        <div class="field">
            <label for="current_password">Current Password</label>
            <input type="password" id="current_password" name="current_password" required
                   autocomplete="current-password" data-label="Current password">
            <?php field_error($errors, "current_password"); ?>
        </div>
        <div class="field">
            <label for="new_password">New Password</label>
            <input type="password" id="new_password" name="new_password" required data-rule="password"
                   autocomplete="new-password" data-label="New password">
            <?php field_error($errors, "new_password"); ?>
        </div>
        <div class="field">
            <label for="confirm_password">Confirm New Password</label>
            <input type="password" id="confirm_password" name="confirm_password" required data-match="#new_password"
                   autocomplete="new-password" data-label="Confirm password">
            <?php field_error($errors, "confirm_password"); ?>
        </div>
        <button type="submit" class="btn">Change Password</button>
    </form>
</div>

<?php page_end(); ?>
