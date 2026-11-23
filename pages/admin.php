<?php
// Admin area: users (role, disable, unlock, reset password) and the audit log (admin.php?show=audit).
require_once "../includes/functions.php";
require_admin();

$showAudit = get("show") == "audit";

// ---------- Actions (POST): role, enable/disable, unlock, reset password ----------
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    csrf_check();
    $id = filter_var(post("user_id"), FILTER_VALIDATE_INT);
    $action = post("action");

    $stmt = $pdo->prepare("SELECT user_id, username, is_active FROM users WHERE user_id = ?");
    $stmt->execute([$id ?: 0]);
    $target = $stmt->fetch();

    if (!$target) {
        flash("User not found.", "error");
    } elseif ($target["user_id"] == $_SESSION["user_id"] && in_array($action, ["role", "toggle"])) {
        flash("You cannot change your own role or disable your own account.", "error");   // keeps one admin able to log in
    } elseif ($action == "role" && in_array(post("role"), ["admin", "staff"], true)) {
        $pdo->prepare("UPDATE users SET role = ? WHERE user_id = ?")->execute([post("role"), $id]);
        log_action("role", "user", $id, $target["username"] . " is now " . post("role"));
        flash("Role updated.");
    } elseif ($action == "toggle") {
        $pdo->prepare("UPDATE users SET is_active = 1 - is_active WHERE user_id = ?")->execute([$id]);
        log_action($target["is_active"] ? "disable" : "enable", "user", $id, $target["username"]);
        flash($target["is_active"] ? "Account disabled." : "Account enabled.");
    } elseif ($action == "unlock") {
        $pdo->prepare("UPDATE users SET failed_logins = 0, locked_until = NULL WHERE user_id = ?")->execute([$id]);
        log_action("unlock", "user", $id, $target["username"]);
        flash("Account unlocked.");
    } elseif ($action == "reset") {
        // temporary password that passes the password rule; the user must change it at the next login
        $temp = "Tmp" . random_int(1000, 9999) . bin2hex(random_bytes(2));
        $pdo->prepare("UPDATE users SET password_hash = ?, must_change = 1, failed_logins = 0, locked_until = NULL WHERE user_id = ?")
            ->execute([password_hash($temp, PASSWORD_DEFAULT), $id]);
        log_action("reset_password", "user", $id, $target["username"]);
        flash("Temporary password for " . $target["username"] . ": $temp (shown once, they must change it at next login).");
    }
    redirect("admin.php");
}

if ($showAudit) {
    // ---------- Filters ----------
    $user = get("user");
    $action = get("action");
    $entity = get("entity");
    $from = valid_date(get("from")) ? get("from") : "";
    $to = valid_date(get("to")) ? get("to") : "";

    $where = [];
    $params = [];
    if ($user !== "")   { $where[] = "username LIKE ?"; $params[] = "%$user%"; }
    if ($action !== "") { $where[] = "action = ?"; $params[] = $action; }
    if ($entity !== "") { $where[] = "entity = ?"; $params[] = $entity; }
    if ($from !== "")   { $where[] = "DATE(created_at) >= ?"; $params[] = $from; }
    if ($to !== "")     { $where[] = "DATE(created_at) <= ?"; $params[] = $to; }
    $whereSql = $where ? "WHERE " . implode(" AND ", $where) : "";

    $perPage = 20;
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM audit_log $whereSql");
    $stmt->execute($params);
    $total = (int) $stmt->fetchColumn();
    [$page, $pages, $offset] = paging($total, $perPage);

    $stmt = $pdo->prepare("SELECT created_at, username, action, entity, entity_id, details, ip
                           FROM audit_log $whereSql ORDER BY log_id DESC LIMIT $perPage OFFSET $offset");
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $actions = $pdo->query("SELECT DISTINCT action FROM audit_log ORDER BY action")->fetchAll(PDO::FETCH_COLUMN);
    $entities = $pdo->query("SELECT DISTINCT entity FROM audit_log WHERE entity <> '' ORDER BY entity")->fetchAll(PDO::FETCH_COLUMN);
} else {
    $users = $pdo->query("SELECT user_id, username, email, role, is_active, last_login, created_at,
                                 locked_until > NOW() AS locked_now
                          FROM users ORDER BY username")->fetchAll();
}

page_start("Admin");
?>

<h1>Admin</h1>

<div class="tabs">
    <a href="admin.php"<?= $showAudit ? "" : ' class="current"' ?>>Users</a>
    <a href="admin.php?show=audit"<?= $showAudit ? ' class="current"' : "" ?>>Audit Log</a>
</div>

<?php if ($showAudit): ?>
<div class="card">
    <form method="get" action="admin.php" class="filters filters-6">
        <input type="hidden" name="show" value="audit">
        <div class="field">
            <label for="user">User</label>
            <input type="text" id="user" name="user" value="<?= h($user) ?>" placeholder="Username">
        </div>
        <div class="field">
            <label for="action">Action</label>
            <select id="action" name="action">
                <option value="">All</option>
                <?php foreach ($actions as $a): ?>
                    <option value="<?= h($a) ?>" <?= $action === $a ? "selected" : "" ?>><?= h($a) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label for="entity">Record type</label>
            <select id="entity" name="entity">
                <option value="">All</option>
                <?php foreach ($entities as $e): ?>
                    <option value="<?= h($e) ?>" <?= $entity === $e ? "selected" : "" ?>><?= h($e) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label for="from">From</label>
            <input type="date" id="from" name="from" value="<?= h($from) ?>">
        </div>
        <div class="field">
            <label for="to">To</label>
            <input type="date" id="to" name="to" value="<?= h($to) ?>">
        </div>
        <div>
            <button type="submit" class="btn">Filter</button>
            <a href="admin.php?show=audit" class="btn light">Reset</a>
        </div>
    </form>
</div>

<div class="card">
    <div class="table-wrap">
        <table>
            <tr><th>Time</th><th>User</th><th>Action</th><th>Record</th><th>Details</th><th class="hide-sm">IP</th></tr>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td><?= h($r["created_at"]) ?></td>
                    <td><?= h($r["username"]) ?></td>
                    <td><?= h($r["action"]) ?></td>
                    <td><?= h($r["entity"] . ($r["entity_id"] ? " #" . $r["entity_id"] : "")) ?></td>
                    <td><?= h($r["details"]) ?></td>
                    <td class="hide-sm"><?= h($r["ip"]) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?><tr><td colspan="6" class="empty">No log entries found.</td></tr><?php endif; ?>
        </table>
    </div>
    <p><?= h($total) ?> entr<?= $total == 1 ? "y" : "ies" ?> found</p>
    <?php pager($page, $pages); ?>
</div>
<?php else: ?>
<div class="card">
    <div class="table-wrap">
        <table>
            <tr>
                <th>Username</th>
                <th class="hide-sm">Email</th>
                <th>Role</th>
                <th>Status</th>
                <th class="hide-sm">Last Login</th>
                <th>Actions</th>
            </tr>
            <?php foreach ($users as $u): ?>
                <?php $self = $u["user_id"] == $_SESSION["user_id"]; ?>
                <tr>
                    <td><?= h($u["username"]) ?><?= $self ? " (you)" : "" ?></td>
                    <td class="hide-sm"><?= h($u["email"]) ?></td>
                    <td><?= h($u["role"]) ?></td>
                    <td><?= $u["locked_now"] ? "Locked" : ($u["is_active"] ? "Active" : "Disabled") ?></td>
                    <td class="hide-sm"><?= h($u["last_login"] ?? "-") ?></td>
                    <td class="actions wrap">
                        <?php if (!$self): ?>
                            <form method="post" action="admin.php">
                                <?php csrf_field(); ?>
                                <input type="hidden" name="user_id" value="<?= h($u["user_id"]) ?>">
                                <input type="hidden" name="action" value="role">
                                <select name="role" aria-label="Role for <?= h($u["username"]) ?>">
                                    <option value="staff" <?= $u["role"] == "staff" ? "selected" : "" ?>>staff</option>
                                    <option value="admin" <?= $u["role"] == "admin" ? "selected" : "" ?>>admin</option>
                                </select>
                                <button type="submit" class="btn small">Set role</button>
                            </form>
                            <form method="post" action="admin.php" data-confirm="<?= $u["is_active"] ? "Disable" : "Enable" ?> this account?">
                                <?php csrf_field(); ?>
                                <input type="hidden" name="user_id" value="<?= h($u["user_id"]) ?>">
                                <input type="hidden" name="action" value="toggle">
                                <button type="submit" class="btn small"><?= $u["is_active"] ? "Disable" : "Enable" ?></button>
                            </form>
                        <?php endif; ?>
                        <?php if ($u["locked_now"]): ?>
                            <form method="post" action="admin.php">
                                <?php csrf_field(); ?>
                                <input type="hidden" name="user_id" value="<?= h($u["user_id"]) ?>">
                                <input type="hidden" name="action" value="unlock">
                                <button type="submit" class="btn small">Unlock</button>
                            </form>
                        <?php endif; ?>
                        <form method="post" action="admin.php" data-confirm="Reset the password for <?= h($u["username"]) ?>?">
                            <?php csrf_field(); ?>
                            <input type="hidden" name="user_id" value="<?= h($u["user_id"]) ?>">
                            <input type="hidden" name="action" value="reset">
                            <button type="submit" class="btn small">Reset password</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </table>
    </div>
</div>
<?php endif; ?>

<?php page_end(); ?>
