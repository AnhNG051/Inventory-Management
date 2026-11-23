<?php
// Login, register and logout. Register is login.php?show=register.
require_once "../includes/functions.php";

// Logout is a POST form with a CSRF token, so other sites cannot log the user out.
if ($_SERVER["REQUEST_METHOD"] == "POST" && post("action") == "logout") {
    csrf_check();
    if (is_logged_in()) log_action("logout", "user", $_SESSION["user_id"], "Signed out");
    $_SESSION = [];
    session_regenerate_id(true);
    flash("You have been logged out.");
    redirect("login.php");
}

if (is_logged_in()) redirect("dashboard.php");

const MAX_FAILS = 5;        // wrong passwords in a row before the account is locked
const LOCK_MINUTES = 15;

$registering = get("show") == "register";
$data = ["username" => "", "email" => ""];
$error = "";      // login error
$errors = [];     // register errors, one per field

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    csrf_check();
    $data["username"] = post("username");
    $password = post("password");

    if ($registering) {
        // ---------- Register ----------
        $data["email"] = post("email");
        $confirm = post("confirm_password");

        if (!preg_match("/^[A-Za-z0-9]{5,15}$/", $data["username"]))
            $errors["username"] = "Username must be 5-15 letters and numbers only";
        if (strlen($data["email"]) > 100 || !filter_var($data["email"], FILTER_VALIDATE_EMAIL))
            $errors["email"] = "Enter a valid email address";
        if (!password_ok($password))
            $errors["password"] = "Password needs 8+ characters with uppercase, lowercase and a number";
        if ($confirm !== $password)
            $errors["confirm_password"] = "Passwords do not match";

        // username and email must be unused
        if (!$errors) {
            $stmt = $pdo->prepare("SELECT username, email FROM users WHERE username = ? OR email = ?");
            $stmt->execute([$data["username"], $data["email"]]);
            foreach ($stmt->fetchAll() as $row) {
                if (strcasecmp($row["username"], $data["username"]) == 0) $errors["username"] = "Username is already taken";
                if (strcasecmp($row["email"], $data["email"]) == 0) $errors["email"] = "Email is already registered";
            }
        }

        if (!$errors) {
            $stmt = $pdo->prepare("INSERT INTO users (username, email, password_hash) VALUES (?, ?, ?)");
            $stmt->execute([$data["username"], $data["email"], password_hash($password, PASSWORD_DEFAULT)]);
            log_action("register", "user", $pdo->lastInsertId(), "New staff account", $data["username"]);
            flash("Account created. You can log in now.");
            redirect("login.php");
        }
    } else {
        // ---------- Login ----------
        // locked_now and minutes_left are worked out by MySQL so PHP and MySQL time zones cannot disagree
        $stmt = $pdo->prepare("SELECT user_id, username, password_hash, role, is_active, must_change, failed_logins,
                                      locked_until > NOW() AS locked_now,
                                      TIMESTAMPDIFF(MINUTE, NOW(), locked_until) + 1 AS minutes_left
                               FROM users WHERE username = ?");
        $stmt->execute([$data["username"]]);
        $user = $stmt->fetch();

        if ($user && $user["locked_now"]) {
            $error = "Too many failed attempts. Try again in " . $user["minutes_left"] . " minute(s).";
        } elseif ($user && password_verify($password, $user["password_hash"])) {
            if (!$user["is_active"]) {
                $error = "This account is disabled. Ask an admin.";
            } else {
                session_regenerate_id(true);   // new session id after login (prevents session fixation)
                $_SESSION["user_id"] = $user["user_id"];
                $_SESSION["username"] = $user["username"];
                $_SESSION["role"] = $user["role"];
                $_SESSION["last_active"] = time();
                $pdo->prepare("UPDATE users SET failed_logins = 0, locked_until = NULL, last_login = NOW() WHERE user_id = ?")
                    ->execute([$user["user_id"]]);
                log_action("login", "user", $user["user_id"], "Signed in");
                redirect($user["must_change"] ? "profile.php" : "dashboard.php");
            }
        } else {
            if ($user) {
                $fails = $user["failed_logins"] + 1;
                if ($fails >= MAX_FAILS) {
                    $pdo->prepare("UPDATE users SET failed_logins = 0, locked_until = DATE_ADD(NOW(), INTERVAL " . LOCK_MINUTES . " MINUTE)
                                   WHERE user_id = ?")->execute([$user["user_id"]]);
                    log_action("locked", "user", $user["user_id"], "Locked after " . MAX_FAILS . " wrong passwords", $data["username"]);
                } else {
                    $pdo->prepare("UPDATE users SET failed_logins = ? WHERE user_id = ?")->execute([$fails, $user["user_id"]]);
                }
            }
            log_action("login_failed", "user", $user["user_id"] ?? null, "Wrong username or password", $data["username"]);
            // same message for a wrong username or a wrong password
            $error = "Invalid username or password";
        }
    }
}

page_start($registering ? "Register" : "Login", true);
?>

<?php if ($registering): ?>
<div class="card narrow">
    <h1>Register</h1>
    <form method="post" action="login.php?show=register" class="validate" novalidate>
        <?php csrf_field(); ?>
        <div class="field">
            <label for="username">Username</label>
            <input type="text" id="username" name="username" value="<?= h($data["username"]) ?>"
                   required minlength="5" maxlength="15" pattern="[A-Za-z0-9]+" autocomplete="username"
                   data-label="Username" data-message="Username must be letters and numbers only">
            <?php field_error($errors, "username"); ?>
        </div>
        <div class="field">
            <label for="email">Email</label>
            <input type="email" id="email" name="email" value="<?= h($data["email"]) ?>"
                   required maxlength="100" autocomplete="email" data-label="Email">
            <?php field_error($errors, "email"); ?>
        </div>
        <div class="field">
            <label for="password">Password</label>
            <input type="password" id="password" name="password" required data-rule="password"
                   autocomplete="new-password" data-label="Password">
            <?php field_error($errors, "password"); ?>
        </div>
        <div class="field">
            <label for="confirm_password">Confirm Password</label>
            <input type="password" id="confirm_password" name="confirm_password" required data-match="#password"
                   autocomplete="new-password" data-label="Confirm Password">
            <?php field_error($errors, "confirm_password"); ?>
        </div>
        <button type="submit" class="btn">Create Account</button>
        <p>Already have an account? <a href="login.php">Log in</a></p>
    </form>
</div>
<?php else: ?>
<div class="card narrow">
    <h1>Login</h1>
    <?php if ($error): ?><div class="alert error"><?= h($error) ?></div><?php endif; ?>
    <form method="post" action="login.php" class="validate" novalidate>
        <?php csrf_field(); ?>
        <div class="field">
            <label for="username">Username</label>
            <input type="text" id="username" name="username" value="<?= h($data["username"]) ?>"
                   required maxlength="15" autocomplete="username" data-label="Username">
            <span class="error"></span>
        </div>
        <div class="field">
            <label for="password">Password</label>
            <input type="password" id="password" name="password" required
                   autocomplete="current-password" data-label="Password">
            <span class="error"></span>
        </div>
        <button type="submit" class="btn">Login</button>
        <p>No account yet? <a href="login.php?show=register">Register</a></p>
    </form>
</div>
<?php endif; ?>

<?php page_end(); ?>
