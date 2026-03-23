<?php
/**
 * WordPress Auto Installer with DirectAdmin Integration
 * By Avinash — Single file installer
 */

// ─── Configuration ────────────────────────────────────────────────────────────
$wordpress_url   = 'https://wordpress.org/latest.zip';
$plugin_url      = 'https://github.com/avinashpudota/wpsetup/archive/refs/heads/main.zip';
$access_password = 'avi2025';
$da_port         = 2222;

// ─── Session & Auth ───────────────────────────────────────────────────────────
session_start();

$is_authenticated = !empty($_SESSION['authenticated']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password']) && !$is_authenticated) {
    if ($_POST['password'] === $access_password) {
        $_SESSION['authenticated'] = true;
        $is_authenticated = true;
    } else {
        $login_error = 'Incorrect password. Please try again.';
    }
}

if (!$is_authenticated) {
    render_login($login_error ?? null);
    exit;
}

// ─── Handle logout ────────────────────────────────────────────────────────────
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}

// ─── Handle installation POST ─────────────────────────────────────────────────
$install_result = null;
$install_error  = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['folder_name'])) {
    try {
        $install_result = run_installation();
    } catch (Exception $e) {
        $install_error = $e->getMessage();
    }
}

// ─── Show summary page after install ─────────────────────────────────────────
if ($install_result) {
    render_summary($install_result);
    exit;
}

// ─── Main installer form ──────────────────────────────────────────────────────
render_installer_form($install_error);
exit;


// ═══════════════════════════════════════════════════════════════════════════════
//  CORE INSTALLATION LOGIC
// ═══════════════════════════════════════════════════════════════════════════════

function run_installation(): array {
    // Collect & sanitize inputs
    $folder_name  = sanitize_folder_name($_POST['folder_name'] ?? '');
    $da_user      = trim($_POST['da_user'] ?? '');
    $da_pass      = $_POST['da_pass'] ?? '';
    $db_name_raw  = preg_replace('/[^a-zA-Z0-9_]/', '', trim($_POST['db_name'] ?? ''));

    if (empty($folder_name)) throw new Exception('Please enter a valid installation folder.');
    if (empty($da_user))     throw new Exception('DirectAdmin username is required.');
    if (empty($da_pass))     throw new Exception('DirectAdmin password is required.');
    if (empty($db_name_raw)) throw new Exception('Database name is required.');

    // Full DB name: username_dbname
    $db_full_name = $da_user . '_' . $db_name_raw;
    $db_user      = $da_user . '_' . $db_name_raw; // same as DB name per convention
    $db_pass      = generate_password(20);
    $db_host      = 'localhost';

    // DirectAdmin API base
    $da_base = get_da_url($da_port);

    // 1. Create database via DirectAdmin API
    da_create_database($da_base, $da_user, $da_pass, $db_full_name, $db_user, $db_pass);

    // 2. Install WordPress files
    $install_path = create_installation_directory($folder_name);
    install_wordpress($install_path);
    install_custom_plugin($install_path);
    remove_default_plugins($install_path);

    // 3. Write wp-config.php
    $table_prefix = 'wp_';
    write_wp_config($install_path, $db_full_name, $db_user, $db_pass, $db_host, $table_prefix);

    // 4. Build WP setup URL
    $wp_setup_url = get_base_url() . '/' . $folder_name . '/wp-admin/install.php';

    return [
        'wp_setup_url' => $wp_setup_url,
        'wp_url'       => get_base_url() . '/' . $folder_name,
        'folder'       => $folder_name,
        'db_name'      => $db_full_name,
        'db_user'      => $db_user,
        'db_pass'      => $db_pass,
        'db_host'      => $db_host,
        'da_user'      => $da_user,
    ];
}


// ═══════════════════════════════════════════════════════════════════════════════
//  DIRECTADMIN API
// ═══════════════════════════════════════════════════════════════════════════════

function da_create_database(string $da_base, string $user, string $pass, string $db, string $db_user, string $db_pass): void {
    // Step 1: Create database
    $res = da_request($da_base, $user, $pass, '/CMD_API_DATABASES', [
        'action' => 'create',
        'name'   => $db,
    ]);
    if (!isset($res['error']) || $res['error'] !== '0') {
        $msg = $res['text'] ?? $res['details'] ?? 'Unknown error';
        // Ignore "already exists" errors
        if (stripos($msg, 'exist') === false) {
            throw new Exception("DirectAdmin DB creation failed: $msg");
        }
    }

    // Step 2: Create DB user and grant access
    $res2 = da_request($da_base, $user, $pass, '/CMD_API_DATABASES', [
        'action'   => 'createuser',
        'name'     => $db,
        'username' => $db_user,
        'passwd'   => $db_pass,
        'passwd2'  => $db_pass,
    ]);
    if (!isset($res2['error']) || $res2['error'] !== '0') {
        $msg = $res2['text'] ?? $res2['details'] ?? 'Unknown error';
        if (stripos($msg, 'exist') === false) {
            throw new Exception("DirectAdmin DB user creation failed: $msg");
        }
    }
}

function da_request(string $base, string $user, string $pass, string $endpoint, array $params): array {
    $url = $base . $endpoint;

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($params),
        CURLOPT_USERPWD        => "$user:$pass",
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_USERAGENT      => 'WP-Installer/2.0',
    ]);

    $body = curl_exec($ch);
    $err  = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === false || !empty($err)) {
        throw new Exception("DirectAdmin connection failed: $err");
    }
    if ($code === 401) {
        throw new Exception("DirectAdmin authentication failed. Check your username and password.");
    }
    if ($code !== 200) {
        throw new Exception("DirectAdmin returned HTTP $code.");
    }

    // DA returns URL-encoded key=value pairs
    parse_str($body, $result);
    return $result;
}


// ═══════════════════════════════════════════════════════════════════════════════
//  WORDPRESS INSTALLATION
// ═══════════════════════════════════════════════════════════════════════════════

function sanitize_folder_name(string $name): string {
    $name = trim($name);
    $name = preg_replace('/[^a-zA-Z0-9\-_\/]/', '', $name);
    $name = preg_replace('/\/+/', '/', $name);
    return trim($name, '/');
}

function create_installation_directory(string $folder_name): string {
    $install_path = __DIR__ . '/' . $folder_name;

    if (!file_exists($install_path)) {
        if (!mkdir($install_path, 0755, true)) {
            throw new Exception("Failed to create directory: $folder_name");
        }
    }

    if (file_exists($install_path . '/wp-config.php')) {
        throw new Exception("WordPress already exists in '$folder_name'. Choose a different folder.");
    }

    return $install_path;
}

function install_wordpress(string $install_path): void {
    global $wordpress_url;

    $wp_zip = $install_path . '/wordpress.zip';
    if (!download_file($wordpress_url, $wp_zip)) {
        throw new Exception("Failed to download WordPress from wordpress.org");
    }

    $zip = new ZipArchive();
    if ($zip->open($wp_zip) !== true) {
        throw new Exception("Failed to open WordPress archive");
    }

    $temp_dir  = $install_path . '/temp_wp';
    $zip->extractTo($temp_dir);
    $zip->close();
    unlink($wp_zip);

    $wp_source = $temp_dir . '/wordpress';
    if (!is_dir($wp_source)) {
        throw new Exception("WordPress extraction failed — unexpected archive structure");
    }

    move_directory_contents($wp_source, $install_path);
    remove_directory($temp_dir);
}

function write_wp_config(string $install_path, string $db_name, string $db_user, string $db_pass, string $db_host, string $table_prefix): void {
    $sample = $install_path . '/wp-config-sample.php';
    if (!file_exists($sample)) {
        throw new Exception("wp-config-sample.php not found after WordPress extraction");
    }

    $config = file_get_contents($sample);

    $replacements = [
        "define( 'DB_NAME', 'database_name_here' );"     => "define( 'DB_NAME', '$db_name' );",
        "define( 'DB_USER', 'username_here' );"           => "define( 'DB_USER', '$db_user' );",
        "define( 'DB_PASSWORD', 'password_here' );"       => "define( 'DB_PASSWORD', '$db_pass' );",
        "define( 'DB_HOST', 'localhost' );"               => "define( 'DB_HOST', '$db_host' );",
        "\$table_prefix = 'wp_';"                         => "\$table_prefix = '$table_prefix';",
    ];

    $config = str_replace(array_keys($replacements), array_values($replacements), $config);

    // Inject fresh salts
    $salts = fetch_wp_salts();
    if ($salts) {
        $config = preg_replace(
            "/#@-\*/.*?#@\+/s",
            "#@-*/\n$salts\n#@+",
            $config
        );
    }

    file_put_contents($install_path . '/wp-config.php', $config);
}

function fetch_wp_salts(): string {
    $ch = curl_init('https://api.wordpress.org/secret-key/1.1/salt/');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $salts = curl_exec($ch);
    curl_close($ch);
    return is_string($salts) ? trim($salts) : '';
}

function install_custom_plugin(string $install_path): void {
    global $plugin_url;

    $plugins_dir = $install_path . '/wp-content/plugins';
    $plugin_zip  = $plugins_dir . '/wpsetup.zip';

    if (!download_file($plugin_url, $plugin_zip)) {
        throw new Exception("Failed to download custom plugin");
    }

    $zip = new ZipArchive();
    if ($zip->open($plugin_zip) !== true) {
        throw new Exception("Failed to extract custom plugin");
    }

    $zip->extractTo($plugins_dir);
    $zip->close();
    unlink($plugin_zip);

    $extracted = $plugins_dir . '/wpsetup-main';
    $target    = $plugins_dir . '/wp-quick-setup';
    if (is_dir($extracted)) {
        rename($extracted, $target);
    }

    // Copy to mu-plugins for auto-activation
    $mu_dir = $install_path . '/wp-content/mu-plugins';
    if (!is_dir($mu_dir)) mkdir($mu_dir, 0755, true);

    $plugin_file = $target . '/wp-quick-setup.php';
    if (file_exists($plugin_file)) {
        copy($plugin_file, $mu_dir . '/wp-quick-setup.php');
    }
}

function remove_default_plugins(string $install_path): void {
    $plugins_dir = $install_path . '/wp-content/plugins';

    $akismet = $plugins_dir . '/akismet';
    if (is_dir($akismet)) remove_directory($akismet);

    $hello = $plugins_dir . '/hello.php';
    if (file_exists($hello)) unlink($hello);
}


// ═══════════════════════════════════════════════════════════════════════════════
//  UTILITIES
// ═══════════════════════════════════════════════════════════════════════════════

function download_file(string $url, string $dest): bool {
    $dir = dirname($dest);
    if (!is_dir($dir)) mkdir($dir, 0755, true);

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT      => 'WordPress-Installer/2.0',
        CURLOPT_TIMEOUT        => 300,
    ]);

    $data = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code !== 200 || $data === false) return false;
    return file_put_contents($dest, $data) !== false;
}

function move_directory_contents(string $source, string $dest): void {
    $iter = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iter as $item) {
        $target = $dest . DIRECTORY_SEPARATOR . $iter->getSubPathName();
        if ($item->isDir()) {
            if (!is_dir($target)) mkdir($target, 0755, true);
        } else {
            $tdir = dirname($target);
            if (!is_dir($tdir)) mkdir($tdir, 0755, true);
            copy($item, $target);
        }
    }
}

function remove_directory(string $dir): void {
    if (!is_dir($dir)) return;

    $iter = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($iter as $item) {
        $item->isDir() ? rmdir($item->getRealPath()) : unlink($item->getRealPath());
    }
    rmdir($dir);
}

function generate_password(int $length = 20): string {
    $chars = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789!@#$%^&*';
    $pass  = '';
    $max   = strlen($chars) - 1;
    for ($i = 0; $i < $length; $i++) {
        $pass .= $chars[random_int(0, $max)];
    }
    return $pass;
}

function get_base_url(): string {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host     = $_SERVER['HTTP_HOST'];
    $dir      = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
    return $protocol . '://' . $host . $dir;
}

function get_da_url(int $port): string {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    // Use hostname without port
    $host = explode(':', $_SERVER['HTTP_HOST'])[0];
    return $protocol . '://' . $host . ':' . $port;
}

function e(string $val): string {
    return htmlspecialchars($val, ENT_QUOTES, 'UTF-8');
}


// ═══════════════════════════════════════════════════════════════════════════════
//  RENDER: LOGIN PAGE
// ═══════════════════════════════════════════════════════════════════════════════

function render_login(?string $error): void { ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Access Required — WP Installer</title>
<?php echo common_styles(); ?>
</head>
<body>
<div class="card" style="max-width:420px;margin:80px auto;">
    <div style="text-align:center;font-size:48px;margin-bottom:16px;">🔒</div>
    <h1 style="text-align:center;margin-bottom:28px;">Access Required</h1>
    <?php if ($error): ?>
        <div class="alert alert-error"><?php echo e($error); ?></div>
    <?php endif; ?>
    <form method="post">
        <div class="form-group">
            <label for="password">Installer Password</label>
            <input type="password" id="password" name="password" required autofocus placeholder="Enter access password">
        </div>
        <button type="submit" class="btn btn-primary">Unlock Installer</button>
    </form>
</div>
</body>
</html>
<?php }


// ═══════════════════════════════════════════════════════════════════════════════
//  RENDER: INSTALLER FORM
// ═══════════════════════════════════════════════════════════════════════════════

function render_installer_form(?string $error): void {
    $base = get_base_url();
    $da_url_preview = get_da_url(2222);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>WordPress Auto Installer</title>
<?php echo common_styles(); ?>
<style>
.section-header {
    font-size: 13px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .07em;
    color: var(--muted);
    margin: 28px 0 14px;
    padding-bottom: 8px;
    border-bottom: 1px solid var(--border);
}
.section-header:first-of-type { margin-top: 4px; }
.two-col { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
.preview-box {
    background: var(--bg);
    border: 1px solid var(--border);
    border-radius: 6px;
    padding: 10px 14px;
    font-family: monospace;
    font-size: 13px;
    color: var(--muted);
    margin-top: 6px;
    word-break: break-all;
}
.preview-box span { color: var(--primary); font-weight: 600; }
.badge {
    display: inline-block;
    background: #e8f4fd;
    color: #0073aa;
    font-size: 11px;
    font-weight: 700;
    padding: 2px 8px;
    border-radius: 20px;
    vertical-align: middle;
    margin-left: 8px;
}
.logout-link {
    float: right;
    font-size: 13px;
    color: var(--muted);
    text-decoration: none;
    margin-top: 4px;
}
.logout-link:hover { color: var(--danger); }
</style>
</head>
<body>
<div class="card" style="max-width:680px;margin:40px auto;">

    <div style="overflow:hidden;">
        <h1 style="margin:0;">🚀 WordPress Installer</h1>
        <a href="?logout=1" class="logout-link">Logout →</a>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-error" style="margin-top:20px;"><?php echo e($error); ?></div>
    <?php endif; ?>

    <form method="post" id="installForm" autocomplete="on">

        <!-- ── DirectAdmin Credentials ── -->
        <div class="section-header">DirectAdmin Account <span class="badge">Required</span></div>

        <div class="form-group">
            <label for="da_user">DirectAdmin Username</label>
            <input type="text" id="da_user" name="da_user" required
                   placeholder="your_da_username"
                   value="<?php echo e($_POST['da_user'] ?? ''); ?>"
                   autocomplete="username"
                   oninput="updatePreviews()">
            <div class="hint">Your DirectAdmin login username (also used as DB prefix)</div>
        </div>

        <div class="form-group">
            <label for="da_pass">DirectAdmin Password</label>
            <div class="input-with-toggle">
                <input type="password" id="da_pass" name="da_pass" required placeholder="DirectAdmin password" autocomplete="current-password">
                <button type="button" class="toggle-pass" onclick="togglePass('da_pass', this)">Show</button>
            </div>
            <div class="hint">Used only to call the DirectAdmin API to create your database</div>
        </div>

        <div class="preview-box">
            DA Panel: <span><?php echo e($da_url_preview); ?></span>
        </div>

        <!-- ── WordPress Installation Path ── -->
        <div class="section-header">WordPress Installation Path</div>

        <div class="form-group">
            <label for="folder_name">Installation Folder</label>
            <input type="text" id="folder_name" name="folder_name" required
                   placeholder="e.g. demo1 or 03/test or clients/acme"
                   value="<?php echo e($_POST['folder_name'] ?? ''); ?>"
                   oninput="updatePreviews()">
            <div class="hint">Subdirectory under <code><?php echo e($base); ?>/</code> — supports nested folders</div>
        </div>

        <div class="preview-box" id="url_preview">
            URL: <span id="url_preview_val"><?php echo e($base); ?>/<em>folder</em></span>
        </div>

        <!-- ── Database Details ── -->
        <div class="section-header">Database Details</div>

        <div class="two-col">
            <div class="form-group">
                <label for="db_name">Database Name <span class="badge">No prefix needed</span></label>
                <input type="text" id="db_name" name="db_name" required
                       placeholder="e.g. demo1"
                       value="<?php echo e($_POST['db_name'] ?? ''); ?>"
                       oninput="updatePreviews()">
                <div class="hint">Your DA username will be prepended automatically</div>
            </div>
            <div class="form-group">
                <label>Full DB Name (auto)</label>
                <div class="preview-box" style="margin-top:0;padding:11px 14px;" id="db_name_preview">
                    <span id="db_preview_val">username_dbname</span>
                </div>
                <div class="hint">Created in DirectAdmin automatically</div>
            </div>
        </div>

        <div class="alert" style="background:#fffbeb;border:1px solid #f6d860;color:#7a5c00;margin-top:4px;">
            🔑 <strong>Database password will be auto-generated</strong> — a strong random password will be created and shown to you after installation.
        </div>

        <!-- ── Submit ── -->
        <button type="submit" class="btn btn-primary" style="margin-top:24px;" onclick="handleSubmit()">
            ⚡ Install WordPress
        </button>

        <div class="loading" id="loading">
            <div class="spinner"></div>
            <p><strong>Installing WordPress…</strong><br>
            Downloading WP, creating database, writing wp-config.<br>
            This may take 1–3 minutes. Please don't close this page.</p>
        </div>
    </form>
</div>

<script>
function updatePreviews() {
    const folder = document.getElementById('folder_name').value.trim().replace(/^\/+|\/+$/g, '');
    const user   = document.getElementById('da_user').value.trim();
    const db     = document.getElementById('db_name').value.trim();
    const base   = <?php echo json_encode($base); ?>;

    document.getElementById('url_preview_val').innerHTML =
        base + '/' + (folder || '<em>folder</em>');

    document.getElementById('db_preview_val').textContent =
        (user || 'username') + '_' + (db || 'dbname');
}

function togglePass(fieldId, btn) {
    const f = document.getElementById(fieldId);
    if (f.type === 'password') { f.type = 'text'; btn.textContent = 'Hide'; }
    else                       { f.type = 'password'; btn.textContent = 'Show'; }
}

function handleSubmit() {
    setTimeout(() => {
        document.getElementById('installForm').querySelectorAll('button, input').forEach(el => el.disabled = true);
        document.getElementById('loading').style.display = 'block';
    }, 80);
}
</script>
</body>
</html>
<?php }


// ═══════════════════════════════════════════════════════════════════════════════
//  RENDER: SUMMARY / SUCCESS PAGE
// ═══════════════════════════════════════════════════════════════════════════════

function render_summary(array $r): void { ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Installation Complete — WP Installer</title>
<?php echo common_styles(); ?>
<style>
.summary-grid {
    display: grid;
    grid-template-columns: 180px 1fr;
    gap: 0;
    border: 1px solid var(--border);
    border-radius: 8px;
    overflow: hidden;
    margin: 20px 0;
}
.summary-grid .row {
    display: contents;
}
.summary-grid .label,
.summary-grid .value {
    padding: 11px 16px;
    border-bottom: 1px solid var(--border);
    font-size: 14px;
}
.summary-grid .row:last-child .label,
.summary-grid .row:last-child .value { border-bottom: none; }
.summary-grid .label { background: var(--bg); font-weight: 600; color: var(--muted); }
.summary-grid .value { font-family: monospace; word-break: break-all; }
.copy-btn {
    background: none;
    border: 1px solid var(--border);
    border-radius: 4px;
    padding: 2px 8px;
    font-size: 11px;
    cursor: pointer;
    margin-left: 8px;
    color: var(--muted);
}
.copy-btn:hover { background: var(--bg); }
.success-icon { text-align:center; font-size:52px; margin-bottom:12px; }
.cta-buttons { display:flex; gap:12px; margin-top:24px; flex-wrap:wrap; }
.warn-box {
    background: #fffbeb;
    border: 1px solid #f6d860;
    border-radius: 8px;
    padding: 14px 18px;
    font-size: 14px;
    color: #7a5c00;
    margin-top: 16px;
}
</style>
</head>
<body>
<div class="card" style="max-width:680px;margin:40px auto;">

    <div class="success-icon">🎉</div>
    <h1 style="text-align:center;margin-bottom:6px;">WordPress Installed!</h1>
    <p style="text-align:center;color:var(--muted);margin-bottom:0;">
        Your WordPress site is ready. Complete setup by clicking the button below.
    </p>

    <div class="summary-grid">
        <div class="row">
            <div class="label">Site URL</div>
            <div class="value">
                <a href="<?php echo e($r['wp_url']); ?>" target="_blank"><?php echo e($r['wp_url']); ?></a>
            </div>
        </div>
        <div class="row">
            <div class="label">Install Folder</div>
            <div class="value"><?php echo e($r['folder']); ?></div>
        </div>
        <div class="row">
            <div class="label">Database Name</div>
            <div class="value">
                <?php echo e($r['db_name']); ?>
                <button class="copy-btn" onclick="copy('<?php echo e($r['db_name']); ?>', this)">Copy</button>
            </div>
        </div>
        <div class="row">
            <div class="label">Database User</div>
            <div class="value">
                <?php echo e($r['db_user']); ?>
                <button class="copy-btn" onclick="copy('<?php echo e($r['db_user']); ?>', this)">Copy</button>
            </div>
        </div>
        <div class="row">
            <div class="label">Database Password</div>
            <div class="value">
                <code><?php echo e($r['db_pass']); ?></code>
                <button class="copy-btn" onclick="copy('<?php echo e($r['db_pass']); ?>', this)">Copy</button>
            </div>
        </div>
        <div class="row">
            <div class="label">DB Host</div>
            <div class="value"><?php echo e($r['db_host']); ?></div>
        </div>
        <div class="row">
            <div class="label">DA User</div>
            <div class="value"><?php echo e($r['da_user']); ?></div>
        </div>
    </div>

    <div class="warn-box">
        ⚠️ <strong>Save your database password now.</strong> It won't be shown again. The wp-config.php is already pre-filled — you won't need to enter these during WP setup.
    </div>

    <div class="cta-buttons">
        <a href="<?php echo e($r['wp_setup_url']); ?>" class="btn btn-primary" target="_blank">
            → Continue WordPress Setup
        </a>
        <a href="<?php echo strtok($_SERVER['REQUEST_URI'], '?'); ?>" class="btn" style="background:var(--bg);color:var(--text);border:1px solid var(--border);">
            ← Install Another
        </a>
    </div>

</div>
<script>
function copy(text, btn) {
    navigator.clipboard.writeText(text).then(() => {
        btn.textContent = 'Copied!';
        setTimeout(() => btn.textContent = 'Copy', 1500);
    });
}
</script>
</body>
</html>
<?php }


// ═══════════════════════════════════════════════════════════════════════════════
//  SHARED STYLES
// ═══════════════════════════════════════════════════════════════════════════════

function common_styles(): string {
    return <<<CSS
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

:root {
    --primary: #0073aa;
    --primary-h: #005c8a;
    --danger: #c0392b;
    --text: #1e1e1e;
    --muted: #666;
    --border: #ddd;
    --bg: #f6f7f8;
    --radius: 8px;
}

body {
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    background: var(--bg);
    color: var(--text);
    line-height: 1.6;
    padding: 20px;
}

.card {
    background: #fff;
    border-radius: 12px;
    box-shadow: 0 2px 16px rgba(0,0,0,.09);
    padding: 40px;
}

h1 { font-size: 22px; color: #23282d; }

.form-group { margin-bottom: 18px; }

label {
    display: block;
    font-weight: 600;
    font-size: 14px;
    color: #333;
    margin-bottom: 6px;
}

input[type="text"],
input[type="password"] {
    width: 100%;
    padding: 10px 14px;
    border: 1px solid var(--border);
    border-radius: 6px;
    font-size: 15px;
    transition: border-color .15s;
    background: #fff;
}
input:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(0,115,170,.12); }

.hint { font-size: 12px; color: var(--muted); margin-top: 5px; }
.hint code { background: var(--bg); padding: 1px 5px; border-radius: 3px; font-size: 11px; }

.input-with-toggle { position: relative; display: flex; }
.input-with-toggle input { flex: 1; border-radius: 6px 0 0 6px; }
.toggle-pass {
    padding: 0 14px;
    border: 1px solid var(--border);
    border-left: none;
    border-radius: 0 6px 6px 0;
    background: var(--bg);
    cursor: pointer;
    font-size: 13px;
    color: var(--muted);
}
.toggle-pass:hover { background: #eee; }

.btn {
    display: inline-block;
    padding: 11px 22px;
    border-radius: 6px;
    border: none;
    font-size: 15px;
    font-weight: 600;
    cursor: pointer;
    text-decoration: none;
    transition: background .15s;
    width: 100%;
    text-align: center;
}
.btn-primary { background: var(--primary); color: #fff; }
.btn-primary:hover { background: var(--primary-h); }

.alert {
    padding: 12px 16px;
    border-radius: 6px;
    font-size: 14px;
    margin-bottom: 16px;
}
.alert-error { background: #fdf0ef; border: 1px solid #e8b4b0; color: #7b2626; }

.loading {
    display: none;
    text-align: center;
    padding: 30px 0 10px;
    color: var(--muted);
    font-size: 14px;
}
.spinner {
    width: 34px; height: 34px;
    border: 3px solid #e0e0e0;
    border-top-color: var(--primary);
    border-radius: 50%;
    animation: spin .8s linear infinite;
    margin: 0 auto 14px;
}
@keyframes spin { to { transform: rotate(360deg); } }
</style>
CSS;
}
