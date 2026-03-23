<?php
/**
 * WordPress Auto Installer by Avinash
 * Creates subdirectories, installs WordPress, and sets up custom plugin
 */

// Configuration
$wordpress_url = 'https://wordpress.org/latest.zip';
$plugin_url = 'https://github.com/avinashpudota/wpsetup/archive/refs/heads/main.zip';
$access_password = 'avi2025';

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
    
    if (empty($folder_name)) {
        $error = "Please enter a valid folder name.";
    } else {
        try {
            $install_path = create_installation_directory($folder_name);
            install_wordpress($install_path);
            install_custom_plugin($install_path);
            remove_default_plugins($install_path);
            
            $wp_setup_url = get_current_url() . '/' . $folder_name . '/wp-admin/install.php';
            header("Location: $wp_setup_url");
            exit;
            
        } catch (Exception $e) {
            $error = "Installation failed: " . $e->getMessage();
        }
    }
}

function sanitize_folder_name($folder_name) {
    // Remove any dangerous characters and normalize
    $folder_name = trim($folder_name);
    $folder_name = preg_replace('/[^a-zA-Z0-9\-_\/]/', '', $folder_name);
    $folder_name = preg_replace('/\/+/', '/', $folder_name); // Remove duplicate slashes
    $folder_name = trim($folder_name, '/'); // Remove leading/trailing slashes
    
    return $folder_name;
}

function create_installation_directory($folder_name) {
    $current_dir = __DIR__;
    $install_path = $current_dir . '/' . $folder_name;
    
    // Create directory structure (supports multi-level)
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
    
    // Download WordPress
    $wp_zip = $install_path . '/wordpress.zip';
    if (!download_file($wordpress_url, $wp_zip)) {
        throw new Exception("Failed to download WordPress");
    }
    
    // Extract WordPress
    $zip = new ZipArchive;
    if ($zip->open($wp_zip) === TRUE) {
        // Extract to temporary directory first
        $temp_dir = $install_path . '/temp_wp';
        $zip->extractTo($temp_dir);
        $zip->close();
        
        // Move contents from wordpress subdirectory to install path
        $wp_source = $temp_dir . '/wordpress';
        if (is_dir($wp_source)) {
            move_directory_contents($wp_source, $install_path);
            // Clean up temporary directory
            remove_directory($temp_dir);
        } else {
            throw new Exception("WordPress extraction failed - invalid archive structure");
        }
        
        // Remove the zip file
        unlink($wp_zip);
    } else {
        throw new Exception("Failed to extract WordPress archive");
    }
}

function install_custom_plugin($install_path) {
    global $plugin_url;
    
    $plugins_dir = $install_path . '/wp-content/plugins';
    
    // Download custom plugin
    $plugin_zip = $plugins_dir . '/wpsetup.zip';
    if (!download_file($plugin_url, $plugin_zip)) {
        throw new Exception("Failed to download custom plugin");
    }
    
    // Extract plugin
    $zip = new ZipArchive;
    if ($zip->open($plugin_zip) === TRUE) {
        $zip->extractTo($plugins_dir);
        $zip->close();
        
        // Rename the extracted folder to a cleaner name
        $extracted_folder = $plugins_dir . '/wpsetup-main';
        $target_folder = $plugins_dir . '/wp-quick-setup';
        
        if (is_dir($extracted_folder)) {
            rename($extracted_folder, $target_folder);
        }
        
        // Remove zip file
        unlink($plugin_zip);
        
        // Move plugin file to mu-plugins for auto-activation
        $mu_plugins_dir = $install_path . '/wp-content/mu-plugins';
        if (!is_dir($mu_plugins_dir)) {
            mkdir($mu_plugins_dir, 0755, true);
        }
        
        $plugin_file = $target_folder . '/wp-quick-setup.php';
        $mu_plugin_file = $mu_plugins_dir . '/wp-quick-setup.php';
        
        if (file_exists($plugin_file)) {
            copy($plugin_file, $mu_plugin_file);
        }
        
    } else {
        throw new Exception("Failed to extract custom plugin");
    }
}

function remove_default_plugins($install_path) {
    $plugins_dir = $install_path . '/wp-content/plugins';
    
    // Remove Akismet
    $akismet_dir = $plugins_dir . '/akismet';
    if (is_dir($akismet_dir)) {
        remove_directory($akismet_dir);
    }
    
    // Remove Hello Dolly
    $hello_file = $plugins_dir . '/hello.php';
    if (file_exists($hello_file)) {
        unlink($hello_file);
    }
}

function download_file($url, $destination) {
    // Create destination directory if it doesn't exist
    $dir = dirname($destination);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    
    // Use cURL for better reliability
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_USERAGENT, 'WordPress Installer');
    curl_setopt($ch, CURLOPT_TIMEOUT, 300); // 5 minutes timeout
    
    $data = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($http_code !== 200 || $data === false) {
        return false;
    }
    
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
            if (!is_dir($target_path)) {
                mkdir($target_path, 0755, true);
            }
        } else {
            $target_dir = dirname($target_path);
            if (!is_dir($target_dir)) {
                mkdir($target_dir, 0755, true);
            }
            copy($item, $target_path);
        }
    }
}

function remove_directory($dir) {
    if (!is_dir($dir)) {
        return;
    }
    
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    
    foreach ($iterator as $item) {
        if ($item->isDir()) {
            rmdir($item->getRealPath());
        } else {
            unlink($item->getRealPath());
        }
    }
    rmdir($dir);
}

function get_current_url() {
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'];
    $script = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
    
    return $protocol . '://' . $host . $script;
}

function show_login_form($error = null) {
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Access Required - WordPress Auto Installer</title>
        <style>
            body {
                font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
                max-width: 400px;
                margin: 100px auto;
                padding: 20px;
                line-height: 1.6;
                background: #f1f1f1;
            }
            .container {
                background: white;
                padding: 40px;
                border-radius: 8px;
                box-shadow: 0 2px 10px rgba(0,0,0,0.1);
                text-align: center;
            }
            h1 {
                color: #23282d;
                margin-bottom: 30px;
            }
            .form-group {
                margin-bottom: 20px;
                text-align: left;
            }
            label {
                display: block;
                margin-bottom: 5px;
                font-weight: 600;
                color: #555;
            }
            input[type="password"] {
                width: 100%;
                padding: 12px;
                border: 1px solid #ddd;
                border-radius: 4px;
                font-size: 16px;
                box-sizing: border-box;
            }
            input[type="password"]:focus {
                outline: none;
                border-color: #0073aa;
            }
            .btn {
                background: #0073aa;
                color: white;
                padding: 12px 24px;
                border: none;
                border-radius: 4px;
                cursor: pointer;
                font-size: 16px;
                width: 100%;
            }
            .btn:hover {
                background: #005a87;
            }
            .error {
                background: #f8d7da;
                color: #721c24;
                padding: 12px;
                border-radius: 4px;
                margin-bottom: 20px;
                border: 1px solid #f5c6cb;
            }
            .lock-icon {
                font-size: 48px;
                margin-bottom: 20px;
                color: #666;
            }
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
                    <input type="password" id="password" name="password" required 
                           placeholder="Enter access password" autofocus>
                </div>
                
                <button type="submit" class="btn">Access Installer</button>
            </form>
        </div>
    </body>
    </html>
    <?php
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>WordPress Auto Installer</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            max-width: 600px;
            margin: 50px auto;
            padding: 20px;
            line-height: 1.6;
            background: #f1f1f1;
        }
        .container {
            background: white;
            padding: 40px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        h1 {
            color: #23282d;
            margin-bottom: 30px;
            text-align: center;
        }
        .form-group {
            margin-bottom: 20px;
        }
        label {
            display: block;
            margin-bottom: 5px;
            font-weight: 600;
            color: #555;
        }
        input[type="text"] {
            width: 100%;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 16px;
            box-sizing: border-box;
        }
        input[type="text"]:focus {
            outline: none;
            border-color: #0073aa;
        }
        .btn {
            background: #0073aa;
            color: white;
            padding: 12px 24px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 16px;
            width: 100%;
        }
        .btn:hover {
            background: #005a87;
        }
        .error {
            background: #f8d7da;
            color: #721c24;
            padding: 12px;
            border-radius: 4px;
            margin-bottom: 20px;
            border: 1px solid #f5c6cb;
        }
        .help-text {
            font-size: 14px;
            color: #666;
            margin-top: 5px;
        }
        .examples {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 4px;
            margin-top: 10px;
        }
        .examples h4 {
            margin: 0 0 10px 0;
            color: #555;
        }
        .examples ul {
            margin: 0;
            padding-left: 20px;
        }
        .examples li {
            margin-bottom: 5px;
            color: #666;
        }
        .loading {
            display: none;
            text-align: center;
            margin-top: 20px;
        }
        .spinner {
            border: 3px solid #f3f3f3;
            border-top: 3px solid #0073aa;
            border-radius: 50%;
            width: 30px;
            height: 30px;
            animation: spin 1s linear infinite;
            margin: 0 auto 10px;
        }
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🚀 WordPress Auto Installer</h1>
        
        <?php if (isset($error)): ?>
            <div class="error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        
        <form method="post" id="installForm">
            <div class="form-group">
                <label for="folder_name">Installation Folder Name:</label>
                <input type="text" id="folder_name" name="folder_name" required 
                       placeholder="Enter folder name (e.g., demo1 or 2024/projects/demo1)">
                <div class="help-text">
                    Enter the folder name where WordPress will be installed. 
                    Supports multi-level folders separated by forward slashes.
                </div>
                
                <div class="examples">
                    <h4>Examples:</h4>
                    <ul>
                        <li><strong>demo1</strong> → <?php echo get_current_url(); ?>/demo1</li>
                        <li><strong>projects/demo1</strong> → <?php echo get_current_url(); ?>/projects/demo1</li>
                        <li><strong>2024/05/demo1</strong> → <?php echo get_current_url(); ?>/2024/05/demo1</li>
                    </ul>
                </div>
            </div>
            
            <button type="submit" class="btn" onclick="showLoading()">
                Install WordPress + Setup Plugin
            </button>
            
            <div class="loading" id="loading">
                <div class="spinner"></div>
                <p>Installing WordPress and setting up plugins...<br>
                This may take a few minutes. Please don't close this page.</p>
            </div>
        </form>
    </div>
    
    <script>
        function showLoading() {
            const form = document.getElementById('installForm');
            const loading = document.getElementById('loading');
            
            form.addEventListener('submit', function() {
                setTimeout(function() {
                    form.style.display = 'none';
                    loading.style.display = 'block';
                }, 100);
            });
        }
        
        // Auto-focus the input field
        document.getElementById('folder_name').focus();
    </script>
</body>
</html>
