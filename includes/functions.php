<?php
// Shared helpers: security headers, session, sanitizing, messages, CSRF, login checks, audit log.

const IDLE_LIMIT = 1800;   // log the user out after 30 minutes without activity

// security headers (the pages use no inline scripts or styles, so a strict policy works)
header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");
header("Referrer-Policy: same-origin");
header("Content-Security-Policy: default-src 'self'; frame-ancestors 'none'; form-action 'self'");
header("Cache-Control: no-store");

date_default_timezone_set("America/Vancouver");

session_set_cookie_params(["httponly" => true, "samesite" => "Lax"]);
session_start();

require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/layout.php";

// idle timeout: forget the login when the user was away too long
if (isset($_SESSION["user_id"])) {
    if (time() - ($_SESSION["last_active"] ?? 0) > IDLE_LIMIT) {
        $_SESSION = [];
        flash("You were logged out because you were inactive for 30 minutes.", "error");
    } else {
        $_SESSION["last_active"] = time();
    }
}

// ---------- Sanitizing ----------

// output: print text safely on the page (prevents XSS)
function h($text) {
    return htmlspecialchars((string) $text, ENT_QUOTES, "UTF-8");
}

// input: read one trimmed text value from POST (arrays are ignored)
function post($name) {
    $value = $_POST[$name] ?? "";
    return is_string($value) ? trim($value) : "";
}

// input: read one trimmed text value from the URL (arrays are ignored)
function get($name) {
    $value = $_GET[$name] ?? "";
    return is_string($value) ? trim($value) : "";
}

// true when the text is a real date like 2026-11-30
function valid_date($text) {
    $date = DateTime::createFromFormat("Y-m-d", $text);
    return $date && $date->format("Y-m-d") === $text;
}

// true when $value is the id of one of the rows in $list
function in_ids($value, $list, $key) {
    return in_array($value, array_map("strval", array_column($list, $key)), true);
}

// password rule used by register, profile and the admin reset
function password_ok($password) {
    return preg_match("/^(?=.*[a-z])(?=.*[A-Z])(?=.*[0-9]).{8,}$/", $password) === 1;
}

function money($amount) {
    return "$" . number_format((float) $amount, 2);
}

// order numbers look like PO-0007 or SO-0012
function order_no($prefix, $id) {
    return $prefix . "-" . str_pad((string) $id, 4, "0", STR_PAD_LEFT);
}

// ---------- Navigation and messages ----------

function redirect($page) {
    header("Location: $page");
    exit;
}

// keep a message for the next page load
function flash($message, $type = "success") {
    $_SESSION["flash"] = ["message" => $message, "type" => $type];
}

function show_flash() {
    if (isset($_SESSION["flash"])) {
        $f = $_SESSION["flash"];
        echo '<div class="alert ' . h($f["type"]) . '">' . h($f["message"]) . '</div>';
        unset($_SESSION["flash"]);
    }
}

// print the error text next to a field (JavaScript uses the same span)
function field_error($errors, $name) {
    echo '<span class="error">' . h($errors[$name] ?? "") . '</span>';
}

// current URL query with some values changed (used by sort and page links)
function url_with($changes) {
    return "?" . http_build_query(array_merge($_GET, $changes));
}

// clickable column title that sorts the table
function sort_link($label, $key, $sort, $dir) {
    $nextDir = ($sort == $key && $dir == "ASC") ? "desc" : "asc";
    $arrow = $sort == $key ? ($dir == "ASC" ? " ▲" : " ▼") : "";
    return '<a href="' . h(url_with(["sort" => $key, "dir" => $nextDir, "page" => 1])) . '">' . h($label) . $arrow . '</a>';
}

// page numbers for a list: returns [current page, number of pages, row offset]
function paging($total, $perPage) {
    $pages = max(1, (int) ceil($total / $perPage));
    $page = min(max(1, (int) get("page")), $pages);
    return [$page, $pages, ($page - 1) * $perPage];
}

function pager($page, $pages) {
    if ($pages <= 1) return;
    echo '<div class="pager">';
    $gap = false;
    for ($i = 1; $i <= $pages; $i++) {
        if ($i != 1 && $i != $pages && abs($i - $page) > 2) {   // hide far pages behind "..."
            if (!$gap) echo '<span>...</span>';
            $gap = true;
            continue;
        }
        $gap = false;
        if ($i == $page) echo '<span class="current">' . $i . '</span>';
        else echo '<a href="' . h(url_with(["page" => $i])) . '">' . $i . '</a>';
    }
    echo '</div>';
}

// ---------- CSRF protection ----------

function csrf_field() {
    if (empty($_SESSION["csrf"])) {
        $_SESSION["csrf"] = bin2hex(random_bytes(32));
    }
    echo '<input type="hidden" name="csrf" value="' . h($_SESSION["csrf"]) . '">';
}

// stop the request if the CSRF token is missing or wrong
function csrf_check() {
    $token = $_POST["csrf"] ?? "";
    if (empty($_SESSION["csrf"]) || !is_string($token) || !hash_equals($_SESSION["csrf"], $token)) {
        http_response_code(403);
        exit("Invalid request (CSRF check failed).");
    }
}

// ---------- Login and roles ----------

function is_logged_in() {
    return isset($_SESSION["user_id"]);
}

function is_admin() {
    return ($_SESSION["role"] ?? "") === "admin";
}

// the user must be logged in, enabled, and (after a password reset) must set a new password first
function require_login() {
    global $pdo;
    if (!is_logged_in()) {
        redirect("login.php");
    }
    $stmt = $pdo->prepare("SELECT role, is_active, must_change FROM users WHERE user_id = ?");
    $stmt->execute([$_SESSION["user_id"]]);
    $user = $stmt->fetch();
    if (!$user || !$user["is_active"]) {
        $_SESSION = [];
        flash("Your account is disabled.", "error");
        redirect("login.php");
    }
    $_SESSION["role"] = $user["role"];   // a role change by an admin applies right away
    if ($user["must_change"] && basename($_SERVER["SCRIPT_NAME"]) !== "profile.php") {
        flash("Set a new password to continue.", "error");
        redirect("profile.php");
    }
}

// only admins can pass (delete actions, users, audit log)
function require_admin() {
    require_login();
    if (!is_admin()) {
        flash("Only an admin can do that.", "error");
        redirect("dashboard.php");
    }
}

// ---------- Audit log ----------

// write one line to the audit log (who did what)
function log_action($action, $entity = "", $entityId = null, $details = "", $username = null) {
    global $pdo;
    $pdo->prepare("INSERT INTO audit_log (user_id, username, action, entity, entity_id, details, ip)
                   VALUES (?, ?, ?, ?, ?, ?, ?)")
        ->execute([$_SESSION["user_id"] ?? null, $username ?? ($_SESSION["username"] ?? ""), $action, $entity,
                   $entityId, mb_substr($details, 0, 255), $_SERVER["REMOTE_ADDR"] ?? ""]);
}

// ---------- CSV download ----------

// a cell that starts with = + - @ could run as a formula in Excel, so put a ' in front of it
function csv_safe($value) {
    return (is_string($value) && preg_match("/^[=+\-@\t\r]/", $value)) ? "'" . $value : $value;
}

// send a statement's rows as a CSV file and stop. $name is the file name, e.g. "products"
function csv_download($name, $header, $stmt) {
    header("Content-Type: text/csv; charset=utf-8");
    header('Content-Disposition: attachment; filename="' . $name . "-" . date("Y-m-d") . '.csv"');

    $out = fopen("php://output", "w");
    fwrite($out, "\xEF\xBB\xBF");   // byte order mark so Excel reads UTF-8 correctly
    fputcsv($out, $header, ",", '"', "\\");
    foreach ($stmt->fetchAll(PDO::FETCH_NUM) as $row) {
        fputcsv($out, array_map("csv_safe", $row), ",", '"', "\\");
    }
    fclose($out);
    log_action("export", $name, null, "CSV downloaded");
    exit;
}
