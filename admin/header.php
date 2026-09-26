<?php
ob_start(); // Buffer output to prevent "headers already sent" errors during redirects
if (session_status() === PHP_SESSION_NONE) {
    session_name('RETRO_ADMIN');
    session_start();
}
require_once __DIR__ . '/../db_connect.php';

// Hardcoded Admin Passcode for local security (Fallback)
$master_passcode = "admin";

// Read from settings.json if it exists
$settings_file_path = __DIR__ . '/../settings.json';
if (file_exists($settings_file_path)) {
    $settings = json_decode(file_get_contents($settings_file_path), true);
    if (isset($settings['admin_passcode'])) {
        $master_passcode = $settings['admin_passcode'];
    }
}

// Handle Login
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['admin_login'])) {
    if (isset($_POST['passcode']) && $_POST['passcode'] === $master_passcode) {
        $_SESSION['is_admin'] = true;
        header("Location: " . $_SERVER['REQUEST_URI']);
        exit();
    } else {
        $login_error = "Access Denied.";
    }
}

// Handle Logout
if (isset($_GET['logout'])) {
    unset($_SESSION['is_admin']);
    header("Location: index.php");
    exit();
}

$is_admin = isset($_SESSION['is_admin']) && $_SESSION['is_admin'] === true;

// Determine current page for navigation active state
$current_page = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Retro-bid | Admin Syndicate</title>
    
    <!-- Instant Theme Initializer -->
    <script>
        (function() {
            var theme = localStorage.getItem('retro_theme') || '<?php echo isset($_SESSION['theme']) ? htmlspecialchars($_SESSION['theme']) : 'dark'; ?>' || 'dark';
            document.documentElement.setAttribute('data-theme', theme);
        })();
    </script>
    <link rel="stylesheet" href="../theme.css">
    
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        
        /* Globally Hide Scrollbar */
        html, body {
            -ms-overflow-style: none;  /* IE and Edge */
            scrollbar-width: none;  /* Firefox */
        }
        ::-webkit-scrollbar {
            display: none; /* Chrome, Safari and Opera */
        }

        body { 
            background-color: var(--bg-primary); 
            color: var(--text-primary); 
            font-family: monospace; 
            padding: 50px; 
            transition: background-color 0.35s ease, color 0.35s ease;
        }
        
        .login-box {
            max-width: 400px; 
            margin: 100px auto; 
            background: var(--bg-card); 
            padding: 40px; 
            border: 1px solid var(--border-color);
            box-shadow: var(--shadow-card);
            transition: background-color 0.35s ease, border-color 0.35s ease;
        }
        h1 { color: var(--accent-gold); text-transform: uppercase; font-size: 1.5rem; margin-bottom: 30px; letter-spacing: 0.1em; }
        input[type="password"], input[type="text"], input[type="email"], select, textarea {
            width: 100%; padding: 15px; background: var(--bg-primary); border: 1px solid var(--border-subtle); color: var(--text-primary); margin-bottom: 20px; font-family: monospace; outline: none; transition: border-color 0.3s;
        }
        input:focus, select:focus, textarea:focus { border-color: var(--accent-gold); }
        button {
            width: 100%; padding: 15px; background: var(--accent-gold); color: var(--text-inverse); border: none; text-transform: uppercase;
            font-weight: bold; cursor: pointer; transition: opacity 0.3s, box-shadow 0.3s;
        }
        button:hover { opacity: 0.9; box-shadow: 0 0 15px var(--accent-gold-subtle); }
        
        .dashboard { max-width: 1200px; margin: 0 auto; }
        .header { display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--border-subtle); padding-bottom: 20px; margin-bottom: 40px; }
        .logout { color: var(--text-secondary); text-decoration: none; text-transform: uppercase; transition: color 0.3s; font-size: 0.85rem; }
        .logout:hover { color: var(--text-primary); }
        
        .admin-theme-btn {
            background: var(--bg-card);
            color: var(--text-primary);
            border: 1px solid var(--border-color);
            padding: 8px 14px;
            font-size: 0.75rem;
            letter-spacing: 0.1em;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
            text-transform: uppercase;
            font-family: monospace;
            font-weight: bold;
            transition: all 0.3s ease;
            box-shadow: var(--shadow-sm);
        }
        .admin-theme-btn:hover {
            border-color: var(--accent-gold);
            color: var(--accent-gold);
        }
        
        .admin-nav { display: flex; gap: 20px; margin-bottom: 30px; border-bottom: 1px solid var(--border-subtle); padding-bottom: 10px; flex-wrap: wrap; }
        .admin-nav a { color: var(--text-secondary); text-decoration: none; text-transform: uppercase; font-weight: bold; font-size: 0.9rem; padding: 10px 0; transition: color 0.3s; }
        .admin-nav a:hover { color: var(--text-primary); }
        .admin-nav a.active { color: var(--accent-gold); border-bottom: 2px solid var(--accent-gold); }

        .item-card { 
            background: var(--bg-card); 
            border: 1px solid var(--border-color); 
            padding: 20px; 
            margin-bottom: 20px; 
            display: flex; 
            gap: 30px; 
            box-shadow: var(--shadow-sm);
            transition: background-color 0.35s ease, border-color 0.35s ease;
        }
        .item-img { width: 150px; height: 100px; object-fit: cover; background: var(--bg-primary); border: 1px solid var(--border-subtle); }
        .item-details { flex-grow: 1; }
        .item-title { font-size: 1.2rem; margin-bottom: 10px; color: var(--text-primary); }
        .item-meta { color: var(--text-secondary); font-size: 0.85rem; margin-bottom: 10px; }
        .item-meta span { color: var(--accent-gold); }
        
        .actions { display: flex; gap: 10px; align-items: center; }
        .btn-approve, .btn-reject { padding: 10px 20px; border: none; cursor: pointer; text-transform: uppercase; font-weight: bold; font-size: 0.8rem; transition: opacity 0.3s; }
        .btn-approve { background: #146c2e; color: #fff; }
        .btn-reject { background: #990000; color: #fff; }
        .btn-approve:hover, .btn-reject:hover { opacity: 0.9; }
        
        .empty { text-align: center; color: var(--text-secondary); padding: 50px; font-style: italic; }

        .section-divider { border-top: 1px solid var(--border-subtle); margin: 40px 0 20px 0; padding-top: 20px; color: var(--accent-gold); font-size: 1.2rem; text-transform: uppercase; font-weight: bold; }
        .message-card { 
            background: var(--bg-card); 
            border: 1px solid var(--border-color); 
            padding: 20px; 
            margin-bottom: 20px; 
            box-shadow: var(--shadow-sm);
            transition: background-color 0.35s ease, border-color 0.35s ease;
        }
        .msg-header { margin-bottom: 15px; border-bottom: 1px dashed var(--border-subtle); padding-bottom: 10px; }
        .msg-title { font-size: 1.2rem; color: var(--text-primary); margin-bottom: 5px; text-transform: uppercase; }
        .msg-meta { color: var(--text-secondary); font-size: 0.85rem; }
        .msg-meta span { color: var(--accent-gold); }
        .msg-body { color: var(--text-primary); line-height: 1.6; margin-bottom: 15px; font-size: 0.95rem; }
        .btn-dismiss { background: #990000; color: #fff; padding: 8px 15px; border: none; cursor: pointer; text-transform: uppercase; font-size: 0.75rem; font-weight: bold; transition: opacity 0.3s; }
        .btn-dismiss:hover { opacity: 0.9; }
    </style>
    
    <script>
        function updateAdminThemeUI(theme) {
            var icon = document.getElementById('adminThemeIcon');
            var text = document.getElementById('adminThemeText');
            if (icon && text) {
                if (theme === 'light') {
                    icon.textContent = '☀️';
                    text.textContent = 'LIGHT';
                } else {
                    icon.textContent = '🌙';
                    text.textContent = 'DARK';
                }
            }
        }

        function toggleAdminTheme() {
            var current = document.documentElement.getAttribute('data-theme') === 'light' ? 'light' : 'dark';
            var next = current === 'light' ? 'dark' : 'light';
            document.documentElement.setAttribute('data-theme', next);
            localStorage.setItem('retro_theme', next);
            updateAdminThemeUI(next);
            
            // Sync with backend asynchronously
            fetch('../update_theme.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'theme=' + encodeURIComponent(next)
            }).catch(function(){});
        }

        document.addEventListener('DOMContentLoaded', function() {
            var t = document.documentElement.getAttribute('data-theme') || 'dark';
            updateAdminThemeUI(t);
        });
    </script>
</head>
<body>
    <?php if (!$is_admin): ?>
        <div class="login-box">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <h1 style="margin-bottom: 0;">Retro-bid Syndicate</h1>
                <button type="button" class="admin-theme-btn" onclick="toggleAdminTheme()" style="width: auto; padding: 6px 10px;">
                    <span id="adminThemeIcon">🌙</span>
                </button>
            </div>
            <?php if(isset($login_error)) echo "<p style='color:red; margin-bottom:15px;'>$login_error</p>"; ?>
            <form method="POST" action="<?php echo htmlspecialchars($_SERVER['REQUEST_URI']); ?>">
                <input type="hidden" name="admin_login" value="1">
                <input type="password" name="passcode" placeholder="ENTER MASTER PASSCODE" required autofocus>
                <button type="submit">Authenticate</button>
            </form>
        </div>
    </body>
    </html>
    <?php 
        exit(); 
    endif; 
    ?>
    
    <div class="dashboard">
        <div class="header">
            <h1>Retro-bid Syndicate Dashboard</h1>
            <div style="display: flex; align-items: center; gap: 20px;">
                <button type="button" class="admin-theme-btn" onclick="toggleAdminTheme()">
                    <span id="adminThemeIcon">🌙</span>
                    <span id="adminThemeText">DARK</span>
                </button>
                <a href="?logout=true" class="logout">Lock Vault (Logout)</a>
            </div>
        </div>
        
        <div class="admin-nav">
            <a href="dashboard.php" class="<?php echo $current_page === 'dashboard.php' || $current_page === 'index.php' ? 'active' : ''; ?>">Total Dashboard</a>
            <a href="approvals.php" class="<?php echo $current_page === 'approvals.php' ? 'active' : ''; ?>">Pending Consignments</a>
            <a href="auctions.php" class="<?php echo $current_page === 'auctions.php' || $current_page === 'auction_live.php' ? 'active' : ''; ?>">Active Auctions</a>
            <a href="settlements.php" class="<?php echo $current_page === 'settlements.php' ? 'active' : ''; ?>">Settlements</a>
            <a href="inquiries.php" class="<?php echo $current_page === 'inquiries.php' ? 'active' : ''; ?>">Client Inquiries</a>
            <a href="feedback.php" class="<?php echo $current_page === 'feedback.php' ? 'active' : ''; ?>">Platform Feedback</a>
            <a href="reports.php" class="<?php echo $current_page === 'reports.php' ? 'active' : ''; ?>">Reports</a>
            <a href="management.php" class="<?php echo $current_page === 'management.php' ? 'active' : ''; ?>">Management</a>
            <a href="settings.php" class="<?php echo $current_page === 'settings.php' ? 'active' : ''; ?>">Settings</a>
        </div>
