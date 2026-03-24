<?php
/**
 * WordPress Auto Installer by Avinash
 * With DirectAdmin Database Creation
 */

// Configuration
$wordpress_url   = 'https://wordpress.org/latest.zip';
$plugin_url      = 'https://github.com/avinashpudota/wpsetup/archive/refs/heads/main.zip';
$access_password = 'xxxx';
$da_port         = 2222;
$da_user_default = 'xxxx';
$da_pass_default = 'xxxx';

// Password protection
session_start();
$is_authenticated = isset($_SESSION['authenticated']) && $_SESSION['authenticated'] === true;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password']) && !$is_authenticated) {
    if ($_POST['password'] === $access_password) {
        $_SESSION['authenticated'] = true;
        $is_authenticated = true;
    } else {
        $password_error = "Incorrect password. Please try again.";
    }
}

if (!$is_authenticated) {
    show_login_form(isset($password_error) ? $password_error : null);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['folder_name'])) {
    $folder_name = sanitize_folder_name($_POST['folder_name']);
    $da_user     = $da_user_default;
    $da_pass     = $da_pass_default;
    $db_name_raw = preg_replace('/[^a-zA-Z0-9_]/', '', trim($_POST['db_name'] ?? ''));

    if (empty($folder_name)) {
        $error = "Please enter a valid folder name.";
    } elseif (empty($db_name_raw)) {
        $error = "Please enter a database name.";
    } else {
        try {
            $db_full = $da_user . '_' . $db_name_raw;
            $db_pass = generate_password(20);
            $db_host = 'localhost';
            $da_base = get_da_base_url($da_port);

            // Step 1: Create database in DirectAdmin
            da_create_database($da_base, $da_user, $da_pass, $db_name_raw, $db_name_raw, $db_pass);

            // Step 2: Install WordPress files
            $install_path = create_installation_directory($folder_name);
            install_wordpress($install_path);
            install_custom_plugin($install_path);
            remove_default_plugins($install_path);

            // Step 3: Write wp-config.php
            write_wp_config($install_path, $db_full, $db_full, $db_pass, $db_host);

            // Step 4: Save summary in session and redirect
            $_SESSION['install_summary'] = [
                'wp_url'   => get_current_url() . '/' . $folder_name,
                'wp_setup' => get_current_url() . '/' . $folder_name . '/wp-admin/install.php',
                'folder'   => $folder_name,
                'db_name'  => $db_full,
                'db_user'  => $db_full,
                'db_pass'  => $db_pass,
                'db_host'  => $db_host,
            ];

            header("Location: " . $_SERVER['PHP_SELF'] . "?summary=1");
            exit;

        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    }
}

// Show summary page
if (isset($_GET['summary']) && !empty($_SESSION['install_summary'])) {
    show_summary($_SESSION['install_summary']);
    unset($_SESSION['install_summary']);
    exit;
}

// ─────────────────────────────────────────────────────────────────────────────
//  FUNCTIONS
// ─────────────────────────────────────────────────────────────────────────────

function da_create_database($da_base, $da_user, $da_pass, $db_name, $db_user, $db_pass) {
    $auth = $da_user . '|' . $da_user . ':' . $da_pass;
    $url  = $da_base . '/CMD_API_DATABASES';

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL,            $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST,           true);
    curl_setopt($ch, CURLOPT_POSTFIELDS,     http_build_query([
        'action'  => 'create',
        'name'    => $db_name,
        'user'    => $db_user,
        'passwd'  => $db_pass,
        'passwd2' => $db_pass,
    ]));
    curl_setopt($ch, CURLOPT_USERPWD,        $auth);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_TIMEOUT,        30);

    $body = curl_exec($ch);
    $err  = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($err)       throw new Exception("DirectAdmin connection error: $err");
    if ($code===401) throw new Exception("DirectAdmin login failed. Check your username and password.");

    parse_str($body, $res);
    if (isset($res['error']) && $res['error'] !== '0') {
        $msg = $res['details'] ?? $res['text'] ?? $body;
        if (stripos($msg, 'exist') === false) {
            throw new Exception("DirectAdmin error: $msg");
        }
    }
}

function write_wp_config($install_path, $db_name, $db_user, $db_pass, $db_host) {
    $sample = $install_path . '/wp-config-sample.php';
    if (!file_exists($sample)) throw new Exception("wp-config-sample.php not found");

    $config = file_get_contents($sample);
    $config = str_replace("database_name_here", $db_name, $config);
    $config = str_replace("username_here",      $db_user, $config);
    $config = str_replace("password_here",      $db_pass, $config);
    $config = str_replace("localhost",          $db_host, $config);

    // Fetch fresh salts
    $salts = @file_get_contents('https://api.wordpress.org/secret-key/1.1/salt/');
    if ($salts) {
        $config = preg_replace(
            '/define\( \'AUTH_KEY\'.*?define\( \'NONCE_SALT\'[^\n]+\n/s',
            $salts,
            $config
        );
    }

    file_put_contents($install_path . '/wp-config.php', $config);
}

function sanitize_folder_name($folder_name) {
    $folder_name = trim($folder_name);
    $folder_name = preg_replace('/[^a-zA-Z0-9\-_\/]/', '', $folder_name);
    $folder_name = preg_replace('/\/+/', '/', $folder_name);
    return trim($folder_name, '/');
}

function create_installation_directory($folder_name) {
    $install_path = $_SERVER['DOCUMENT_ROOT'] . '/' . $folder_name;
    if (!file_exists($install_path)) {
        if (!mkdir($install_path, 0755, true)) {
            throw new Exception("Failed to create directory: $folder_name");
        }
    }
    if (file_exists($install_path . '/wp-config.php')) {
        throw new Exception("WordPress already exists in this directory!");
    }
    return $install_path;
}

function install_wordpress($install_path) {
    global $wordpress_url;

    $wp_zip = $install_path . '/wordpress.zip';
    if (!download_file($wordpress_url, $wp_zip)) {
        throw new Exception("Failed to download WordPress");
    }

    $zip = new ZipArchive;
    if ($zip->open($wp_zip) === TRUE) {
        $temp_dir = $install_path . '/temp_wp';
        $zip->extractTo($temp_dir);
        $zip->close();

        $wp_source = $temp_dir . '/wordpress';
        if (is_dir($wp_source)) {
            move_directory_contents($wp_source, $install_path);
            remove_directory($temp_dir);
        } else {
            throw new Exception("WordPress extraction failed - invalid archive structure");
        }
        unlink($wp_zip);
    } else {
        throw new Exception("Failed to extract WordPress archive");
    }
}

function install_custom_plugin($install_path) {
    global $plugin_url;

    $plugins_dir = $install_path . '/wp-content/plugins';
    $plugin_zip  = $plugins_dir . '/wpsetup.zip';

    if (!download_file($plugin_url, $plugin_zip)) {
        throw new Exception("Failed to download custom plugin");
    }

    $zip = new ZipArchive;
    if ($zip->open($plugin_zip) === TRUE) {
        $zip->extractTo($plugins_dir);
        $zip->close();

        $extracted = $plugins_dir . '/wpsetup-main';
        $target    = $plugins_dir . '/wp-quick-setup';
        if (is_dir($extracted)) rename($extracted, $target);
        unlink($plugin_zip);

        $mu_dir = $install_path . '/wp-content/mu-plugins';
        if (!is_dir($mu_dir)) mkdir($mu_dir, 0755, true);

        $plugin_file = $target . '/wp-quick-setup.php';
        if (file_exists($plugin_file)) {
            copy($plugin_file, $mu_dir . '/wp-quick-setup.php');
        }
    } else {
        throw new Exception("Failed to extract custom plugin");
    }
}

function remove_default_plugins($install_path) {
    $plugins_dir = $install_path . '/wp-content/plugins';
    if (is_dir($plugins_dir . '/akismet')) remove_directory($plugins_dir . '/akismet');
    if (file_exists($plugins_dir . '/hello.php')) unlink($plugins_dir . '/hello.php');
}

function download_file($url, $destination) {
    $dir = dirname($destination);
    if (!is_dir($dir)) mkdir($dir, 0755, true);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL,            $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_USERAGENT,      'WordPress Installer');
    curl_setopt($ch, CURLOPT_TIMEOUT,        300);

    $data      = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code !== 200 || $data === false) return false;
    return file_put_contents($destination, $data) !== false;
}

function move_directory_contents($source, $destination) {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($iterator as $item) {
        $target_path = $destination . DIRECTORY_SEPARATOR . $iterator->getSubPathName();
        if ($item->isDir()) {
            if (!is_dir($target_path)) mkdir($target_path, 0755, true);
        } else {
            $target_dir = dirname($target_path);
            if (!is_dir($target_dir)) mkdir($target_dir, 0755, true);
            copy($item, $target_path);
        }
    }
}

function remove_directory($dir) {
    if (!is_dir($dir)) return;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        $item->isDir() ? rmdir($item->getRealPath()) : unlink($item->getRealPath());
    }
    rmdir($dir);
}

function generate_password($length = 20) {
    $chars = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789!@#%^&*';
    $pass  = '';
    for ($i = 0; $i < $length; $i++) {
        $pass .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $pass;
}

function get_current_url() {
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
    $host     = $_SERVER['HTTP_HOST'];
    return $protocol . '://' . $host;
}

function get_da_base_url($port) {
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
    $host     = explode(':', $_SERVER['HTTP_HOST'])[0];
    return $protocol . '://' . $host . ':' . $port;
}

// ─────────────────────────────────────────────────────────────────────────────
//  VIEWS
// ─────────────────────────────────────────────────────────────────────────────

function show_login_form($error = null) { ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Access Required - WordPress Auto Installer</title>
    <style>
        body { font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif; max-width:400px; margin:100px auto; padding:20px; line-height:1.6; background:#f1f1f1; }
        .container { background:white; padding:40px; border-radius:8px; box-shadow:0 2px 10px rgba(0,0,0,0.1); text-align:center; }
        h1 { color:#23282d; margin-bottom:30px; }
        .form-group { margin-bottom:20px; text-align:left; }
        label { display:block; margin-bottom:5px; font-weight:600; color:#555; }
        input[type="password"] { width:100%; padding:12px; border:1px solid #ddd; border-radius:4px; font-size:16px; box-sizing:border-box; }
        input[type="password"]:focus { outline:none; border-color:#0073aa; }
        .btn { background:#0073aa; color:white; padding:12px 24px; border:none; border-radius:4px; cursor:pointer; font-size:16px; width:100%; }
        .btn:hover { background:#005a87; }
        .error { background:#f8d7da; color:#721c24; padding:12px; border-radius:4px; margin-bottom:20px; border:1px solid #f5c6cb; }
        .lock-icon { font-size:48px; margin-bottom:20px; color:#666; }
    </style>
</head>
<body>
    <div class="container">
        <div class="lock-icon">🔒</div>
        <h1>Access Required</h1>
        <?php if ($error): ?>
            <div class="error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        <form method="post">
            <div class="form-group">
                <label for="password">Enter Password:</label>
                <input type="password" id="password" name="password" required autofocus placeholder="Enter access password">
            </div>
            <button type="submit" class="btn">Access Installer</button>
        </form>
    </div>
</body>
</html>
<?php }

function show_summary($r) { ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Installation Complete</title>
    <style>
        body { font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif; max-width:640px; margin:50px auto; padding:20px; background:#f1f1f1; }
        .container { background:white; padding:40px; border-radius:8px; box-shadow:0 2px 10px rgba(0,0,0,0.1); }
        h1 { color:#23282d; text-align:center; margin-bottom:6px; }
        .subtitle { text-align:center; color:#666; margin-bottom:24px; }
        table { width:100%; border-collapse:collapse; margin:20px 0; }
        td { padding:10px 14px; border-bottom:1px solid #eee; font-size:14px; vertical-align:middle; }
        tr:last-child td { border-bottom:none; }
        td:first-child { font-weight:600; color:#555; background:#f8f9fa; width:160px; }
        td code { font-family:monospace; word-break:break-all; }
        .copy-btn { background:none; border:1px solid #ddd; border-radius:4px; padding:2px 8px; font-size:11px; cursor:pointer; margin-left:8px; color:#666; }
        .copy-btn:hover { background:#f1f1f1; }
        .warn { background:#fffbeb; border:1px solid #f6d860; border-radius:6px; padding:14px; font-size:14px; color:#7a5c00; margin:16px 0; }
        .btn { display:block; background:#0073aa; color:white; padding:12px 24px; border:none; border-radius:4px; cursor:pointer; font-size:16px; width:100%; text-align:center; text-decoration:none; margin-top:8px; box-sizing:border-box; }
        .btn:hover { background:#005a87; }
        .btn-secondary { background:#f1f1f1; color:#23282d; border:1px solid #ddd; }
        .btn-secondary:hover { background:#e5e5e5; }
        .icon { text-align:center; font-size:52px; margin-bottom:12px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="icon">🎉</div>
        <h1>WordPress Installed!</h1>
        <p class="subtitle">Save the details below before continuing.</p>
        <table>
            <tr><td>Site URL</td><td><a href="<?php echo htmlspecialchars($r['wp_url']); ?>" target="_blank"><?php echo htmlspecialchars($r['wp_url']); ?></a></td></tr>
            <tr><td>Install Folder</td><td><?php echo htmlspecialchars($r['folder']); ?></td></tr>
            <tr><td>Database Name</td><td><code><?php echo htmlspecialchars($r['db_name']); ?></code><button class="copy-btn" onclick="cp('<?php echo htmlspecialchars($r['db_name']); ?>',this)">Copy</button></td></tr>
            <tr><td>Database User</td><td><code><?php echo htmlspecialchars($r['db_user']); ?></code><button class="copy-btn" onclick="cp('<?php echo htmlspecialchars($r['db_user']); ?>',this)">Copy</button></td></tr>
            <tr><td>DB Password</td><td><code><?php echo htmlspecialchars($r['db_pass']); ?></code><button class="copy-btn" onclick="cp('<?php echo htmlspecialchars($r['db_pass']); ?>',this)">Copy</button></td></tr>
            <tr><td>DB Host</td><td><?php echo htmlspecialchars($r['db_host']); ?></td></tr>
        </table>
        <div class="warn">⚠️ <strong>Save your database password now.</strong> It won't be shown again. wp-config.php is already pre-filled — you won't need to enter DB details during WordPress setup.</div>
        <a href="<?php echo htmlspecialchars($r['wp_setup']); ?>" class="btn" target="_blank">→ Continue WordPress Setup</a>
        <a href="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>" class="btn btn-secondary">← Install Another</a>
    </div>
    <script>
    function cp(text, btn) {
        navigator.clipboard.writeText(text).then(function() {
            btn.textContent = 'Copied!';
            setTimeout(function(){ btn.textContent = 'Copy'; }, 1500);
        });
    }
    </script>
</body>
</html>
<?php }

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>WordPress Auto Installer</title>
    <style>
        body { font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif; max-width:640px; margin:50px auto; padding:20px; line-height:1.6; background:#f1f1f1; }
        .container { background:white; padding:40px; border-radius:8px; box-shadow:0 2px 10px rgba(0,0,0,0.1); }
        h1 { color:#23282d; margin-bottom:30px; text-align:center; }
        .section { font-size:12px; font-weight:700; text-transform:uppercase; letter-spacing:.06em; color:#888; border-bottom:1px solid #eee; padding-bottom:8px; margin:28px 0 16px; }
        .section:first-of-type { margin-top:4px; }
        .form-group { margin-bottom:18px; }
        label { display:block; margin-bottom:5px; font-weight:600; color:#555; font-size:14px; }
        input[type="text"], input[type="password"] { width:100%; padding:11px 14px; border:1px solid #ddd; border-radius:4px; font-size:15px; box-sizing:border-box; }
        input:focus { outline:none; border-color:#0073aa; box-shadow:0 0 0 2px rgba(0,115,170,.15); }
        .hint { font-size:12px; color:#888; margin-top:4px; }
        .two-col { display:grid; grid-template-columns:1fr 1fr; gap:16px; }
        .preview { background:#f8f9fa; border:1px solid #eee; border-radius:4px; padding:9px 12px; font-size:12px; font-family:monospace; color:#555; margin-top:6px; word-break:break-all; }
        .preview span { color:#0073aa; font-weight:600; }
        .pass-wrap { display:flex; }
        .pass-wrap input { border-radius:4px 0 0 4px; flex:1; }
        .pass-toggle { padding:0 14px; border:1px solid #ddd; border-left:none; border-radius:0 4px 4px 0; background:#f8f9fa; cursor:pointer; font-size:13px; color:#666; white-space:nowrap; }
        .info { background:#e8f4fd; color:#0c5460; padding:11px 14px; border-radius:4px; font-size:13px; margin-top:6px; }
        .error { background:#f8d7da; color:#721c24; padding:12px; border-radius:4px; margin-bottom:20px; border:1px solid #f5c6cb; }
        .btn { background:#0073aa; color:white; padding:12px 24px; border:none; border-radius:4px; cursor:pointer; font-size:16px; width:100%; margin-top:8px; }
        .btn:hover { background:#005a87; }
        .loading { display:none; text-align:center; margin-top:20px; }
        .spinner { border:3px solid #f3f3f3; border-top:3px solid #0073aa; border-radius:50%; width:30px; height:30px; animation:spin 1s linear infinite; margin:0 auto 10px; }
        @keyframes spin { 0%{transform:rotate(0deg)} 100%{transform:rotate(360deg)} }
    </style>
</head>
<body>
<div class="container">
    <h1>🚀 WordPress Auto Installer</h1>

    <?php if (isset($error)): ?>
        <div class="error"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <form method="post" id="installForm" autocomplete="on">



        <div class="section">WordPress Installation Path</div>
        <div class="form-group">
            <label for="folder_name">Installation Folder</label>
            <input type="text" id="folder_name" name="folder_name" required
                   placeholder="e.g. demo1 or 03/test or clients/acme"
                   value="<?php echo htmlspecialchars($_POST['folder_name'] ?? ''); ?>"
                   oninput="updatePreviews()">
            <div class="preview">URL: <span id="url_preview"><?php echo htmlspecialchars(get_current_url()); ?>/<em>folder</em></span></div>
        </div>

        <div class="section">Database</div>
        <div class="form-group">
            <label for="db_name">Database Name <small style="font-weight:normal;color:#888">(without username prefix)</small></label>
            <input type="text" id="db_name" name="db_name" required
                   placeholder="e.g. 03test or democlient"
                   value="<?php echo htmlspecialchars($_POST['db_name'] ?? ''); ?>"
                   oninput="updatePreviews()">
            <div class="preview">Will create: <span id="db_preview">username_dbname</span></div>
            <div class="info">🔑 Database password will be auto-generated and shown after installation.</div>
        </div>

        <button type="submit" class="btn" onclick="showLoading()">
            ⚡ Install WordPress
        </button>

        <div class="loading" id="loading">
            <div class="spinner"></div>
            <p>Installing WordPress and setting up database...<br>
            This may take a few minutes. Please don't close this page.</p>
        </div>
    </form>
</div>

<script>
function updatePreviews() {
    var folder = document.getElementById('folder_name').value.trim().replace(/^\/+|\/+$/g,'');
    var db     = document.getElementById('db_name').value.trim();
    var base   = '<?php echo get_current_url(); ?>';
    document.getElementById('url_preview').innerHTML = base + '/' + (folder || '<em>folder</em>');
    document.getElementById('db_preview').textContent = 'wp26highladder_' + (db||'dbname');
}

function showLoading() {
    var form    = document.getElementById('installForm');
    var loading = document.getElementById('loading');
    form.addEventListener('submit', function() {
        setTimeout(function() {
            form.style.display = 'none';
            loading.style.display = 'block';
        }, 100);
    });
}

document.getElementById('folder_name').focus();
</script>
</body>
</html>
