<?php
session_start();
require_once 'db_connect.php';

// Kick out unauthenticated users
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// Block selling during maintenance mode
$maintenance_active = false;
$settings_file = __DIR__ . '/settings.json';
if (file_exists($settings_file)) {
    $settings = json_decode(file_get_contents($settings_file), true);
    if (!empty($settings['maintenance_mode'])) {
        $maintenance_active = true;
    }
}

if ($maintenance_active) {
    die('
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Consignments Disabled</title>
        <style>
            body {
                margin: 0; padding: 0; background-color: #11100E; color: #C5A059; font-family: "Georgia", serif;
                height: 100vh; display: flex; flex-direction: column; align-items: center; justify-content: center;
            }
            .container {
                text-align: center; padding: 40px; border: 1px solid rgba(197, 160, 89, 0.2);
                background: rgba(0, 0, 0, 0.4); box-shadow: 0 0 30px rgba(197, 160, 89, 0.05); border-radius: 4px;
                max-width: 600px;
            }
            h1 { font-family: monospace; text-transform: uppercase; letter-spacing: 0.15em; font-size: 1.8rem; margin-bottom: 20px; }
            p { color: #888; font-family: sans-serif; line-height: 1.6; font-size: 0.95rem; margin-bottom: 25px; }
            a { display: inline-block; padding: 10px 20px; background: #C5A059; color: #11100E; text-decoration: none; text-transform: uppercase; letter-spacing: 0.1em; font-weight: bold; font-family: sans-serif; font-size: 0.8rem; }
        </style>
    </head>
    <body>
        <div class="container">
            <h1>Consignments Disabled</h1>
            <p>Consignments and new listings are temporarily disabled during active platform maintenance to ensure data integrity.</p>
            <p>You may continue browsing the live directory.</p>
            <a href="directory.php">Return to Directory</a>
        </div>
    </body>
    </html>
    ');
}

$error_message = '';
$success_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title']);
    $starting_price = (float)$_POST['starting_price'];
    $start_time = $_POST['start_time'];
    $end_time = $_POST['end_time'];
    $description = trim($_POST['description']);
    $user_id = $_SESSION['user_id'];
    
    $image_url = '';

    // Handle File Upload
    if (isset($_FILES['image_upload']) && $_FILES['image_upload']['error'] === UPLOAD_ERR_OK) {
        $fileTmpPath = $_FILES['image_upload']['tmp_name'];
        $fileName = $_FILES['image_upload']['name'];
        
        $uploadFileDir = 'uploads/';
        if (!is_dir($uploadFileDir)) {
            mkdir($uploadFileDir, 0755, true); // Create automatically just in case
        }

        // Sanitize file name and append timestamp
        $newFileName = time() . '_' . preg_replace("/[^a-zA-Z0-9.]/", "", basename($fileName));
        $dest_path = $uploadFileDir . $newFileName;
        
        if (move_uploaded_file($fileTmpPath, $dest_path)) {
            $image_url = $dest_path; 
        } else {
            $error_message = "Vault Error: Could not move the uploaded artifact imagery.";
        }
    } else {
        $error_message = "Artifact imagery is required for authentication.";
    }

    if (empty($error_message) && (empty($title) || empty($image_url) || $starting_price <= 0 || empty($start_time) || empty($end_time) || empty($description))) {
        $error_message = "All fields are strictly required. Verify your inputs.";
    } elseif (empty($error_message)) {
        $selected_categories = isset($_POST['categories']) && is_array($_POST['categories']) ? $_POST['categories'] : [];

        // REROUTED: We now insert into 'submissions', keeping the live floor clean.
        $stmt = $conn->prepare("INSERT INTO submissions (user_id, title, description, image_url, starting_price, start_time, end_time) VALUES (:uid, :title, :desc, :img, :price, :start, :end)");
        try {
            $stmt->execute([
                ':uid' => $user_id,
                ':title' => $title,
                ':desc' => $description,
                ':img' => $image_url,
                ':price' => $starting_price,
                ':start' => $start_time,
                ':end' => $end_time
            ]);
            
            $submission_id = $conn->lastInsertId();
            
            // Insert selected categories
            if (!empty($selected_categories)) {
                try {
                    $cat_stmt = $conn->prepare("INSERT INTO submission_categories (submission_id, category_id) VALUES (:sid, :cid)");
                    foreach ($selected_categories as $cid) {
                        $cat_stmt->execute([':sid' => $submission_id, ':cid' => (int)$cid]);
                    }
                } catch (PDOException $e) {
                    // Ignore if submission_categories table doesn't exist yet
                }
            }
            
            // Insert Gallery Images (Multiple)
            if (isset($_FILES['gallery_upload']) && !empty($_FILES['gallery_upload']['name'][0])) {
                try {
                    $gal_stmt = $conn->prepare("INSERT INTO submission_images (submission_id, image_url) VALUES (:sid, :img)");
                    $fileCount = count($_FILES['gallery_upload']['name']);
                    for ($i = 0; $i < $fileCount; $i++) {
                        if ($_FILES['gallery_upload']['error'][$i] === UPLOAD_ERR_OK) {
                            $gTmp = $_FILES['gallery_upload']['tmp_name'][$i];
                            $gName = $_FILES['gallery_upload']['name'][$i];
                            $newGName = time() . '_' . $i . '_' . preg_replace("/[^a-zA-Z0-9.]/", "", basename($gName));
                            $gDest = $uploadFileDir . $newGName;
                            if (move_uploaded_file($gTmp, $gDest)) {
                                $gal_stmt->execute([':sid' => $submission_id, ':img' => $gDest]);
                            }
                        }
                    }
                } catch (PDOException $e) {
                    // Ignore if submission_images table doesn't exist yet
                }
            }
            
            $success_message = "Artifact submitted. The Syndicate will review the provenance shortly.";
        } catch (PDOException $e) {
            // Un-comment the line below if you are debugging database column issues
            // $error_message = "DATABASE ERROR: " . $e->getMessage();
            $error_message = "Vault Error: Could not process consignment at this time.";
        }
    }
}

// Fetch all available categories
$categories = [];
try {
    $cat_stmt = $conn->query("SELECT * FROM categories ORDER BY name ASC");
    $categories = $cat_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Categories table might not exist yet, handle gracefully
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Retro-bid | Asset Submission</title>
    
    <!-- Instant Theme Initializer -->
    <script>
        (function() {
            var theme = localStorage.getItem('retro_theme') || '<?php echo isset($_SESSION['theme']) ? htmlspecialchars($_SESSION['theme']) : 'dark'; ?>' || 'dark';
            document.documentElement.setAttribute('data-theme', theme);
        })();
    </script>
    <link rel="stylesheet" href="theme.css?v=<?php echo time(); ?>">
    
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }

        html,
        body {
            height: 100vh;
            scroll-snap-type: y mandatory;
            overflow-y: scroll;
            scrollbar-width: none;
        }

        html::-webkit-scrollbar,
        body::-webkit-scrollbar {
            display: none;
        }

        body { 
            background-color: var(--bg-primary); 
            color: var(--text-primary); 
            font-family: sans-serif;
            transition: background-color 0.35s ease, color 0.35s ease;
        }

        /* NAVIGATION */
        header {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            z-index: 1000;
        }

        nav {
            display: flex;
            justify-content: space-between;
            align-items: center;
            background-color: var(--bg-nav);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            padding: 15px 30px;
            border-bottom: 1px solid var(--border-color);
            font-family: sans-serif;
            transition: all 0.3s ease;
        }

        .logo {
            font-size: 24px;
            color: var(--text-primary);
            font-weight: bold;
            letter-spacing: 0.05em;
            text-shadow: 0 2px 10px rgba(0, 0, 0, 0.4);
            transition: color 0.3s ease;
        }

        .logo span {
            color: var(--accent-gold);
        }

        nav ul {
            list-style: none;
            display: flex;
            margin: 0;
            padding: 0;
            align-items: center;
        }

        nav ul li {
            margin: 0 10px;
        }

        nav ul li a {
            color: var(--text-primary);
            text-decoration: none;
            font-size: 0.85rem;
            font-weight: 500;
            transition: color 0.3s;
        }

        nav ul li a:hover {
            color: var(--accent-gold);
        }

        /* --- Refined User Session Styling --- */
        .nav-alias { 
            color: var(--accent-gold) !important; 
            font-family: sans-serif !important;
            font-size: 0.75rem !important; 
            font-weight: 600 !important;
            letter-spacing: 0.15em !important; 
            text-transform: uppercase !important; 
            display: flex !important;
            align-items: center !important; 
            gap: 8px !important; 
            margin-left: 20px !important;
            text-decoration: none !important; 
            transition: opacity 0.3s ease !important;
        }
        
        .nav-alias:hover {
            opacity: 0.8;
        }

        .nav-alias::before {
            content: '';
            display: block;
            width: 6px;
            height: 6px;
            background-color: var(--accent-gold);
            border-radius: 50%;
            box-shadow: 0 0 8px var(--accent-gold);
        }

        .btn-login {
            border: 1px solid var(--border-color) !important;
            padding: 8px 20px !important;
            border-radius: 2px !important;
            color: var(--accent-gold) !important;
            font-size: 0.75rem !important;
            letter-spacing: 0.15em !important;
            text-transform: uppercase !important;
            font-weight: 600 !important;
            margin-left: 20px !important;
            text-decoration: none !important;
            display: inline-block !important;
            transition: all 0.3s ease !important;
        }

        .btn-login:hover {
            background-color: var(--accent-gold-subtle) !important;
            box-shadow: 0 0 15px var(--accent-gold-subtle) !important;
            color: var(--text-primary) !important;
        }

        /* FORM STYLING */
        .form-container {
            max-width: 800px; margin: 0 auto; padding: 100px 20px 40px; width: 100%;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            justify-content: center;
            scroll-snap-align: start;
        }
        h1 {
            text-align: center; font-size: 2.5rem; letter-spacing: 0.2em; text-transform: uppercase; margin-bottom: 50px;
            color: var(--text-primary);
        }

        .alert-box {
            padding: 20px; border: 1px solid; text-align: center; font-size: 0.85rem; letter-spacing: 0.1em;
            text-transform: uppercase; margin-bottom: 40px;
        }
        .alert-error { background-color: rgba(153, 0, 0, 0.05); border-color: #990000; color: #990000; }
        .alert-success { background-color: rgba(20, 108, 46, 0.05); border-color: #146c2e; color: #146c2e; }

        .form-row { display: flex; gap: 30px; margin-bottom: 40px; }
        .form-group { flex: 1; position: relative; }
        
        .luxury-input {
            width: 100%; background: transparent; border: none; border-bottom: 1px solid var(--border-subtle);
            padding: 10px 0; font-size: 0.9rem; color: var(--text-primary); outline: none; transition: border-color 0.3s;
        }
        .luxury-input:focus { border-bottom-color: var(--accent-gold); }
        .luxury-input::placeholder { color: var(--text-secondary); letter-spacing: 0.1em; text-transform: uppercase; font-size: 0.75rem; }
        
        .luxury-input[type="number"]::-webkit-outer-spin-button,
        .luxury-input[type="number"]::-webkit-inner-spin-button {
            -webkit-appearance: none;
            margin: 0;
        }
        .luxury-input[type="number"] {
            -moz-appearance: textfield;
        }
        
        .luxury-input[type="datetime-local"],
        .luxury-input[type="date"],
        .luxury-input[type="time"] {
            color-scheme: dark;
            color: var(--text-primary);
            font-family: inherit;
        }

        .luxury-input[type="datetime-local"]::-webkit-calendar-picker-indicator,
        .luxury-input[type="date"]::-webkit-calendar-picker-indicator,
        .luxury-input[type="time"]::-webkit-calendar-picker-indicator {
            cursor: pointer;
            filter: brightness(0) saturate(100%) invert(74%) sepia(35%) saturate(700%) hue-rotate(5deg) brightness(115%);
            opacity: 0.9;
            transform: scale(1.1);
            transition: opacity 0.2s ease, transform 0.2s ease, filter 0.2s ease;
        }

        .luxury-input[type="datetime-local"]::-webkit-calendar-picker-indicator:hover,
        .luxury-input[type="date"]::-webkit-calendar-picker-indicator:hover,
        .luxury-input[type="time"]::-webkit-calendar-picker-indicator:hover {
            opacity: 1;
            transform: scale(1.25);
            filter: brightness(0) saturate(100%) invert(88%) sepia(50%) saturate(800%) hue-rotate(5deg) brightness(130%);
        }

        [data-theme="light"] .luxury-input[type="datetime-local"],
        [data-theme="light"] .luxury-input[type="date"],
        [data-theme="light"] .luxury-input[type="time"] {
            color-scheme: light;
        }

        [data-theme="light"] .luxury-input[type="datetime-local"]::-webkit-calendar-picker-indicator,
        [data-theme="light"] .luxury-input[type="date"]::-webkit-calendar-picker-indicator,
        [data-theme="light"] .luxury-input[type="time"]::-webkit-calendar-picker-indicator {
            filter: brightness(0) saturate(100%) invert(45%) sepia(40%) saturate(650%) hue-rotate(5deg) brightness(85%);
            opacity: 0.85;
        }

        [data-theme="light"] .luxury-input[type="datetime-local"]::-webkit-calendar-picker-indicator:hover,
        [data-theme="light"] .luxury-input[type="date"]::-webkit-calendar-picker-indicator:hover,
        [data-theme="light"] .luxury-input[type="time"]::-webkit-calendar-picker-indicator:hover {
            opacity: 1;
            transform: scale(1.25);
            filter: brightness(0) saturate(100%) invert(35%) sepia(50%) saturate(750%) hue-rotate(5deg) brightness(75%);
        }

        /* Dual Pane Luxury Popover & Controls */
        .luxury-dt-popover {
            position: absolute;
            top: calc(100% + 6px);
            left: 0;
            z-index: 9999;
            width: 530px;
            max-width: 95vw;
            background: var(--bg-card);
            border: 1px solid var(--accent-gold);
            box-shadow: var(--shadow-card), 0 0 35px rgba(197, 160, 89, 0.25);
            border-radius: 8px;
            padding: 14px 16px;
            backdrop-filter: blur(24px);
            display: none;
            flex-direction: column;
            gap: 10px;
            animation: dtFadeIn 0.22s cubic-bezier(0.16, 1, 0.3, 1) forwards;
        }

        .luxury-dt-container[data-type="end"] .luxury-dt-popover {
            left: auto;
            right: 0;
        }

        @keyframes dtFadeIn {
            from { opacity: 0; transform: translateY(-6px) scale(0.98); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }

        .luxury-dt-popover.open { display: flex; }

        .luxury-dt-presets {
            display: flex;
            flex-wrap: wrap;
            gap: 5px;
            padding-bottom: 8px;
            border-bottom: 1px solid var(--border-subtle);
        }

        .luxury-dt-preset-btn {
            background: rgba(197, 160, 89, 0.1);
            border: 1px solid rgba(197, 160, 89, 0.25);
            color: var(--accent-gold);
            padding: 4px 8px;
            border-radius: 3px;
            font-size: 0.65rem;
            font-weight: 700;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .luxury-dt-preset-btn:hover {
            background: var(--accent-gold);
            color: var(--text-inverse);
            border-color: var(--accent-gold);
            box-shadow: 0 0 10px rgba(197, 160, 89, 0.35);
        }

        .luxury-dt-body {
            display: grid;
            grid-template-columns: 1.15fr 1fr;
            gap: 14px;
            align-items: stretch;
        }

        @media (max-width: 620px) {
            .luxury-dt-popover { width: 320px; }
            .luxury-dt-body { grid-template-columns: 1fr; gap: 12px; }
            .luxury-dt-time-pane {
                padding-left: 0 !important;
                border-left: none !important;
                border-top: 1px solid var(--border-subtle);
                padding-top: 10px;
            }
        }

        .luxury-dt-calendar-pane {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .luxury-dt-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 2px;
        }

        .luxury-dt-month-title {
            font-size: 0.88rem;
            font-weight: 700;
            letter-spacing: 0.1em;
            text-transform: uppercase;
            color: var(--text-primary);
        }

        .luxury-dt-nav-btn {
            background: transparent;
            border: 1px solid var(--border-subtle);
            color: var(--text-primary);
            width: 26px;
            height: 26px;
            border-radius: 4px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s ease;
            font-size: 0.9rem;
        }

        .luxury-dt-nav-btn:hover {
            border-color: var(--accent-gold);
            color: var(--accent-gold);
            background: rgba(197, 160, 89, 0.12);
        }

        .luxury-dt-grid {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 2px;
            text-align: center;
        }

        .luxury-dt-weekday {
            font-size: 0.62rem;
            font-weight: 700;
            color: var(--text-secondary);
            padding: 2px 0;
            text-transform: uppercase;
            letter-spacing: 0.08em;
        }

        .luxury-dt-day {
            height: 26px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.78rem;
            color: var(--text-primary);
            border-radius: 3px;
            cursor: pointer;
            transition: all 0.18s ease;
            user-select: none;
            border: 1px solid transparent;
        }

        .luxury-dt-day:hover:not(.disabled):not(.empty) {
            border-color: var(--accent-gold);
            background: rgba(197, 160, 89, 0.18);
            color: var(--accent-gold);
        }

        .luxury-dt-day.today {
            border-color: rgba(197, 160, 89, 0.6);
            font-weight: 700;
        }

        .luxury-dt-day.selected {
            background: var(--accent-gold) !important;
            color: #11100E !important;
            font-weight: 800 !important;
            box-shadow: 0 0 10px rgba(197, 160, 89, 0.55);
        }

        .luxury-dt-day.disabled {
            color: var(--text-muted);
            opacity: 0.3;
            cursor: not-allowed;
        }

        .luxury-dt-day.empty { cursor: default; }

        .luxury-dt-time-pane {
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            gap: 8px;
            padding-left: 14px;
            border-left: 1px solid var(--border-subtle);
        }

        .luxury-dt-pane-heading {
            font-size: 0.65rem;
            text-transform: uppercase;
            letter-spacing: 0.12em;
            color: var(--text-secondary);
            font-weight: 700;
        }

        .luxury-dt-time-controls {
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .luxury-dt-time-box {
            display: flex;
            align-items: center;
            background: rgba(0, 0, 0, 0.45);
            border: 1px solid var(--border-subtle);
            border-radius: 4px;
            transition: border-color 0.2s ease, box-shadow 0.2s ease;
            overflow: hidden;
        }

        [data-theme="light"] .luxury-dt-time-box {
            background: #FFFFFF;
            border-color: #D1C9BE;
        }

        .luxury-dt-time-box:focus-within {
            border-color: var(--accent-gold);
            box-shadow: 0 0 10px rgba(197, 160, 89, 0.25);
        }

        .luxury-dt-time-input {
            width: 38px;
            height: 32px;
            background: transparent;
            border: none;
            color: var(--text-primary);
            font-size: 0.95rem;
            font-weight: 700;
            text-align: center;
            outline: none;
            font-family: inherit;
            padding: 0;
            -moz-appearance: textfield;
            appearance: textfield;
        }

        .luxury-dt-time-input::-webkit-outer-spin-button,
        .luxury-dt-time-input::-webkit-inner-spin-button {
            -webkit-appearance: none !important;
            margin: 0 !important;
            display: none !important;
        }

        .luxury-dt-time-steppers {
            display: flex;
            flex-direction: column;
            height: 32px;
            border-left: 1px solid var(--border-subtle);
        }

        .luxury-dt-time-step-btn {
            flex: 1;
            width: 18px;
            background: transparent;
            border: none;
            color: var(--text-secondary);
            font-size: 0.5rem;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: background 0.15s ease, color 0.15s ease;
            line-height: 1;
            padding: 0;
            user-select: none;
        }

        .luxury-dt-time-step-btn:first-child {
            border-bottom: 1px solid var(--border-subtle);
        }

        .luxury-dt-time-step-btn:hover {
            background: rgba(197, 160, 89, 0.2);
            color: var(--accent-gold);
        }

        .luxury-dt-time-colon {
            font-weight: 800;
            color: var(--accent-gold);
            font-size: 1.1rem;
            user-select: none;
        }

        .luxury-dt-ampm-btn {
            height: 32px;
            padding: 0 10px;
            background: rgba(197, 160, 89, 0.12);
            border: 1px solid rgba(197, 160, 89, 0.35);
            border-radius: 4px;
            color: var(--accent-gold);
            font-weight: 800;
            font-size: 0.75rem;
            cursor: pointer;
            transition: all 0.2s ease;
            user-select: none;
        }

        .luxury-dt-ampm-btn:hover {
            background: var(--accent-gold);
            color: var(--text-inverse);
            box-shadow: 0 0 10px rgba(197, 160, 89, 0.3);
        }

        .luxury-dt-quick-times-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 4px;
        }

        .luxury-dt-quick-time-btn {
            background: transparent;
            border: 1px solid var(--border-subtle);
            color: var(--text-secondary);
            font-size: 0.62rem;
            padding: 4px 2px;
            border-radius: 3px;
            cursor: pointer;
            text-align: center;
            font-weight: 600;
            letter-spacing: 0.04em;
            transition: all 0.2s ease;
        }

        .luxury-dt-quick-time-btn:hover {
            border-color: var(--accent-gold);
            color: var(--accent-gold);
            background: rgba(197, 160, 89, 0.1);
        }

        .luxury-dt-side-actions {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
            padding-top: 6px;
            border-top: 1px solid var(--border-subtle);
        }

        .luxury-dt-btn-clear {
            background: transparent;
            border: none;
            color: var(--text-secondary);
            font-size: 0.68rem;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            cursor: pointer;
            padding: 4px 6px;
            font-weight: 600;
            transition: color 0.2s;
        }

        .luxury-dt-btn-clear:hover { color: #ff5555; }

        .luxury-dt-btn-apply {
            flex: 1;
            background: var(--accent-gold);
            color: #11100E;
            border: none;
            border-radius: 4px;
            padding: 6px 12px;
            font-size: 0.72rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            cursor: pointer;
            text-align: center;
            transition: all 0.2s ease;
            box-shadow: 0 3px 10px rgba(197, 160, 89, 0.3);
        }

        .luxury-dt-btn-apply:hover {
            background: var(--accent-gold-light);
            box-shadow: 0 4px 14px rgba(197, 160, 89, 0.5);
            transform: translateY(-1px);
        }
        
        .input-label {
            font-size: 0.65rem; color: var(--text-secondary); text-transform: uppercase; letter-spacing: 0.15em;
            margin-bottom: 5px; display: block;
        }

        textarea.luxury-input { resize: vertical; min-height: 100px; margin-top: 10px; }

        /* LUXURY FILE UPLOAD */
        .file-upload-wrapper {
            position: relative;
            display: inline-block;
            width: 100%;
            margin-top: 15px;
        }
        .file-upload-wrapper input[type="file"] {
            position: absolute;
            left: 0;
            top: 0;
            opacity: 0;
            width: 100%;
            height: 100%;
            cursor: pointer;
        }
        .file-upload-btn {
            display: block;
            width: 100%;
            padding: 12px 15px;
            background-color: transparent;
            border: 1px solid var(--border-subtle);
            color: var(--text-secondary);
            font-size: 0.75rem;
            letter-spacing: 0.15em;
            text-transform: uppercase;
            text-align: center;
            transition: all 0.3s ease;
        }
        .file-upload-wrapper:hover .file-upload-btn {
            border-color: var(--accent-gold);
            color: var(--accent-gold);
        }
        .file-upload-name {
            display: block;
            margin-top: 8px;
            font-size: 0.75rem;
            color: var(--text-secondary);
            font-style: italic;
            text-align: center;
        }
        .gallery-file-list {
            list-style: none;
            padding: 0;
            margin: 10px 0 0 0;
            display: flex;
            flex-direction: column;
            gap: 6px;
        }
        .gallery-file-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            padding: 8px 12px;
            border-radius: 2px;
            color: var(--text-primary);
            font-size: 0.8rem;
            letter-spacing: 0.05em;
            box-shadow: var(--shadow-sm);
            transition: all 0.2s ease;
        }
        .gallery-file-item:hover {
            border-color: var(--accent-gold);
            background: var(--accent-gold-subtle);
        }
        .gallery-file-item .file-name-text {
            color: var(--text-primary);
            font-family: inherit;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            max-width: 85%;
        }
        .gallery-file-remove {
            color: #ff5555;
            cursor: pointer;
            font-weight: bold;
            font-size: 1.2rem;
            line-height: 1;
            padding: 0 4px;
            transition: transform 0.2s ease, color 0.2s ease;
            user-select: none;
        }
        .gallery-file-remove:hover {
            color: #ff2222;
            transform: scale(1.25);
        }
        /* CATEGORY PILLS */
        .category-grid {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            margin-top: 15px;
        }

        .category-pill {
            cursor: pointer;
            position: relative;
        }

        .category-pill input {
            position: absolute;
            opacity: 0;
            cursor: pointer;
            height: 0;
            width: 0;
        }

        .pill-text {
            display: inline-block;
            padding: 10px 20px;
            border: 1px solid var(--border-subtle);
            color: var(--text-secondary);
            font-size: 0.75rem;
            letter-spacing: 0.15em;
            text-transform: uppercase;
            transition: all 0.3s ease;
            background: transparent;
        }

        .category-pill:hover input ~ .pill-text {
            border-color: var(--accent-gold);
            color: var(--accent-gold);
        }

        .category-pill input:checked ~ .pill-text {
            background-color: var(--accent-gold);
            border-color: var(--accent-gold);
            color: var(--text-inverse);
        }

        .btn-submit {
            width: 100%; background-color: var(--accent-gold); color: var(--text-inverse); border: none; padding: 20px;
            font-size: 0.85rem; letter-spacing: 0.2em; text-transform: uppercase; cursor: pointer;
            transition: all 0.3s ease; margin-top: 20px; font-weight: bold;
        }
        .btn-submit:hover { opacity: 0.9; box-shadow: 0 0 15px var(--accent-gold-subtle); }

        .luxury-footer {
            background-color: #11100E;
            color: #8A827A;
            padding: 80px 10% 40px;
            scroll-snap-align: start;
        }

        .footer-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid rgba(138, 130, 122, 0.2);
            padding-bottom: 40px;
            margin-bottom: 40px;
        }

        .footer-logo {
            font-size: 2rem;
            color: #F7F4EB;
            letter-spacing: 0.05em;
        }

        .footer-logo span {
            color: #C5A059;
        }

        .footer-links,
        .footer-socials {
            display: flex;
            gap: 30px;
            list-style: none;
            margin: 0;
            padding: 0;
        }

        .footer-links a,
        .footer-socials a {
            color: #8A827A;
            text-transform: uppercase;
            letter-spacing: 0.15em;
            font-size: 0.75rem;
            text-decoration: none;
            transition: color 0.3s ease;
        }

        .footer-links a:hover,
        .footer-socials a:hover {
            color: #C5A059;
        }

        .footer-bottom {
            text-align: center;
            font-size: 0.75rem;
            letter-spacing: 0.1em;
            text-transform: uppercase;
            color: #5A534A;
        }

        @media (max-width: 768px) {
            .footer-content {
                flex-direction: column;
                text-align: center;
                gap: 30px;
            }
        }
    </style>
</head>
<body>
    <header>
        <nav>
            <a href="auction.php" class="logo" style="text-decoration: none;">Retro<span>-bid</span></a>
            <ul>
                <li><a href="auction.php">Home</a></li>
                <li><a href="directory.php">Auctions</a></li>
                <li><a href="sell.php">Sell</a></li>
                <li><a href="contact.php">Contact</a></li>
                <li><a href="about.php">About</a></li>
                <li><a href="profile.php" class="nav-alias"><?php echo htmlspecialchars($_SESSION['alias']); ?></a></li>
            </ul>
        </nav>
    </header>

    <!-- SELL HERO SECTION -->
    <section style="height: 100vh; display: flex; flex-direction: column; justify-content: center; align-items: center; text-align: center; background-image: url('images/sell.png'); background-size: cover; background-position: center; position: relative; scroll-snap-align: start;">
        <div style="position: absolute; top: 0; left: 0; right: 0; bottom: 0; background-color: rgba(28, 26, 23, 0.65); z-index: 1;"></div>
        <div style="position: relative; z-index: 2;">
            <h1 style="font-size: 4rem; text-transform: uppercase; letter-spacing: 0.15em; color: #F7F4EB; margin-bottom: 20px; text-shadow: 0px 4px 15px rgba(0,0,0,0.5);">Consign An Asset</h1>
            <p style="color: #C5A059; font-size: 1rem; letter-spacing: 0.1em; text-transform: uppercase;">Offer your masterpiece to the global elite.</p>
        </div>
    </section>

    <div class="form-container">
        <h2 style="text-align: center; font-size: 2rem; letter-spacing: 0.2em; text-transform: uppercase; margin-bottom: 50px; color: #1C1A17;">Asset Submission</h2>

        <?php if ($error_message): ?>
            <div class="alert-box alert-error"><?php echo htmlspecialchars($error_message); ?></div>
        <?php endif; ?>
        
        <?php if ($success_message): ?>
            <div class="alert-box alert-success"><?php echo htmlspecialchars($success_message); ?></div>
        <?php endif; ?>

        <form method="POST" action="sell.php" enctype="multipart/form-data">
            <div class="form-row">
                <div class="form-group">
                    <input type="text" name="title" class="luxury-input" placeholder="Artifact Title (e.g. 1969 Omega)" required>
                </div>
                <div class="form-group">
                    <span class="input-label">Upload Artifact Imagery</span>
                    <div class="file-upload-wrapper">
                        <input type="file" name="image_upload" accept="image/*" required onchange="const fn = document.getElementById('file-name'); fn.innerText = this.files[0] ? this.files[0].name : 'No file chosen'; fn.style.color = this.files[0] ? 'var(--text-primary)' : 'var(--text-secondary)'; fn.style.fontStyle = this.files[0] ? 'normal' : 'italic';">
                        <span class="file-upload-btn">Browse Local Files</span>
                        <span id="file-name" class="file-upload-name">No file chosen</span>
                    </div>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <input type="number" step="0.01" name="starting_price" class="luxury-input" placeholder="Opening Reserve ($)" required>
                </div>
                <div class="form-group">
                    <span class="input-label">Upload Gallery Imagery (Optional, Multiple)</span>
                    <div class="file-upload-wrapper">
                        <input type="file" name="gallery_upload[]" id="gallery_upload" accept="image/*" multiple>
                        <span class="file-upload-btn">Browse Local Files</span>
                        <div id="gallery-name" class="file-upload-name" style="margin-top: 10px;">
                            <span>No files chosen</span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <span class="input-label">Window Opening (Start Date & Time)</span>
                    <div class="luxury-dt-container" id="container-start-time" data-name="start_time" data-type="start">
                        <input type="hidden" name="start_time" id="start_time" required>
                        <div class="luxury-dt-trigger" tabindex="0">
                            <div class="luxury-dt-value placeholder">
                                <span>Select Opening Window</span>
                            </div>
                            <span class="luxury-dt-icon">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg>
                            </span>
                        </div>
                    </div>
                </div>
                <div class="form-group">
                    <span class="input-label">Window Closing (End Date & Time)</span>
                    <div class="luxury-dt-container" id="container-end-time" data-name="end_time" data-type="end">
                        <input type="hidden" name="end_time" id="end_time" required>
                        <div class="luxury-dt-trigger" tabindex="0">
                            <div class="luxury-dt-value placeholder">
                                <span>Select Closing Window</span>
                            </div>
                            <span class="luxury-dt-icon">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group" style="width: 100%;">
                    <span class="input-label" style="margin-bottom: 10px;">Asset Class Assignment</span>
                    <div class="category-grid">
                        <?php if (empty($categories)): ?>
                            <p style="color: #8A827A; font-size: 0.85rem; font-style: italic;">No classes available.</p>
                        <?php else: ?>
                            <?php foreach ($categories as $cat): ?>
                                <label class="category-pill">
                                    <input type="radio" name="categories[]" value="<?php echo $cat['category_id']; ?>" required>
                                    <span class="pill-text"><?php echo htmlspecialchars($cat['name']); ?></span>
                                </label>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="form-group" style="margin-bottom: 40px;">
                <span class="input-label">Provenance & Details</span>
                <textarea name="description" class="luxury-input" required></textarea>
            </div>

            <button type="submit" class="btn-submit">Submit for Appraisal</button>
        </form>
    </div>

    <footer class="luxury-footer">
        <div class="footer-content">
            <a href="auction.php" class="footer-logo" style="text-decoration: none;">Retro-<span>bid</span></a>

            <ul class="footer-links">
                <li><a href="#">Private Access</a></li>
                <li><a href="#">Terms of Auction</a></li>
                <li><a href="#">Concierge</a></li>
            </ul>

            <div class="footer-socials">
                <a href="#">Instagram</a>
                <a href="#">X</a>
                <a href="mailto:info@retro-bid.com">Email</a>
            </div>
        </div>

        <div class="footer-bottom">
            <p>&copy; 2026 Retro-bid. All rights reserved. Exclusively for the discerning.</p>
        </div>
    </footer>
<script>
    const galleryInput = document.getElementById('gallery_upload');
    const galleryDisplay = document.getElementById('gallery-name');
    let dt = new DataTransfer();

    if (galleryInput && galleryDisplay) {
        galleryInput.addEventListener('change', function(e) {
            // Add new files to the DataTransfer object
            for(let i = 0; i < this.files.length; i++){
                dt.items.add(this.files[i]);
            }
            
            // Overwrite the input's files with our cumulative list
            this.files = dt.files;
            
            // Update UI
            galleryDisplay.innerHTML = '';
            
            if (this.files.length === 0) {
                galleryDisplay.innerHTML = '<span class="file-upload-name">No files chosen</span>';
            } else {
                let list = document.createElement('ul');
                list.className = 'gallery-file-list';
                
                for(let i = 0; i < this.files.length; i++) {
                    let li = document.createElement('li');
                    li.className = 'gallery-file-item';
                    
                    let nameSpan = document.createElement('span');
                    nameSpan.className = 'file-name-text';
                    nameSpan.innerText = this.files[i].name;
                    
                    let removeBtn = document.createElement('span');
                    removeBtn.className = 'gallery-file-remove';
                    removeBtn.innerHTML = '&times;';
                    removeBtn.title = 'Remove image';
                    
                    removeBtn.onclick = function(e) {
                        e.preventDefault();
                        e.stopPropagation();
                        let newDt = new DataTransfer();
                        for(let j = 0; j < dt.files.length; j++) {
                            if (j !== i) newDt.items.add(dt.files[j]);
                        }
                        dt = newDt;
                        galleryInput.files = dt.files;
                        galleryInput.dispatchEvent(new Event('change'));
                    };
                    
                    li.appendChild(nameSpan);
                    li.appendChild(removeBtn);
                    list.appendChild(li);
                }
                galleryDisplay.appendChild(list);
            }
        });
    }

    // =========================================================================
    // RETRO-BID ULTRA-LUXURY DATETIME PICKER SCRIPT
    // =========================================================================
    (function() {
        const MONTH_NAMES = ["January", "February", "March", "April", "May", "June", "July", "August", "September", "October", "November", "December"];
        const DAY_NAMES = ["Sun", "Mon", "Tue", "Wed", "Thu", "Fri", "Sat"];
        const SHORT_MONTHS = ["Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"];

        function padZero(n) {
            return String(n).padStart(2, '0');
        }

        function formatForInput(date) {
            const y = date.getFullYear();
            const m = padZero(date.getMonth() + 1);
            const d = padZero(date.getDate());
            const h = padZero(date.getHours());
            const min = padZero(date.getMinutes());
            return `${y}-${m}-${d}T${h}:${min}`;
        }

        function formatForDisplay(date) {
            const dayName = DAY_NAMES[date.getDay()];
            const monthName = SHORT_MONTHS[date.getMonth()];
            const day = date.getDate();
            const year = date.getFullYear();
            
            let hours = date.getHours();
            const ampm = hours >= 12 ? 'PM' : 'AM';
            hours = hours % 12 || 12;
            const minutes = padZero(date.getMinutes());

            return `<span class="luxury-dt-date-tag">${dayName}, ${monthName} ${day}, ${year}</span> <span class="luxury-dt-time-badge">${padZero(hours)}:${minutes} ${ampm}</span>`;
        }

        class LuxuryDateTimePicker {
            constructor(container) {
                this.container = container;
                this.type = container.dataset.type || 'start'; // 'start' or 'end'
                this.hiddenInput = container.querySelector('input[type="hidden"]');
                this.trigger = container.querySelector('.luxury-dt-trigger');
                this.valueDisplay = container.querySelector('.luxury-dt-value');
                
                // Initialize default date
                const now = new Date();
                if (this.type === 'start') {
                    // Start time defaults to today rounded to next full hour
                    this.selectedDate = new Date(now.getFullYear(), now.getMonth(), now.getDate(), now.getHours() + 1, 0, 0);
                } else {
                    // End time defaults to start + 7 days at 18:00 (6:00 PM)
                    this.selectedDate = new Date(now.getFullYear(), now.getMonth(), now.getDate() + 7, 18, 0, 0);
                }

                this.viewYear = this.selectedDate.getFullYear();
                this.viewMonth = this.selectedDate.getMonth();

                this.initUI();
                this.bindEvents();
                this.setValue(this.selectedDate);
            }

            initUI() {
                // Build Popover HTML
                this.popover = document.createElement('div');
                this.popover.className = 'luxury-dt-popover';

                // Presets
                const presetsHtml = this.type === 'start' ? `
                    <div class="luxury-dt-presets">
                        <button type="button" class="luxury-dt-preset-btn" data-preset="now">Now</button>
                        <button type="button" class="luxury-dt-preset-btn" data-preset="tomorrow-10">Tomorrow 10 AM</button>
                        <button type="button" class="luxury-dt-preset-btn" data-preset="tomorrow-18">Tomorrow 6 PM</button>
                        <button type="button" class="luxury-dt-preset-btn" data-preset="next-mon">Next Mon 10 AM</button>
                    </div>
                ` : `
                    <div class="luxury-dt-presets">
                        <button type="button" class="luxury-dt-preset-btn" data-preset="plus-24h">+24 Hours</button>
                        <button type="button" class="luxury-dt-preset-btn" data-preset="plus-3d">+3 Days (Flash)</button>
                        <button type="button" class="luxury-dt-preset-btn" data-preset="plus-7d">+7 Days (Std)</button>
                        <button type="button" class="luxury-dt-preset-btn" data-preset="plus-14d">+14 Days</button>
                    </div>
                `;

                this.popover.innerHTML = `
                    ${presetsHtml}
                    <div class="luxury-dt-body">
                        <div class="luxury-dt-calendar-pane">
                            <div class="luxury-dt-header">
                                <button type="button" class="luxury-dt-nav-btn prev-month">&lsaquo;</button>
                                <span class="luxury-dt-month-title"></span>
                                <button type="button" class="luxury-dt-nav-btn next-month">&rsaquo;</button>
                            </div>
                            <div class="luxury-dt-grid"></div>
                        </div>
                        <div class="luxury-dt-time-pane">
                            <span class="luxury-dt-pane-heading">Set Time (HH:MM)</span>
                            <div class="luxury-dt-time-controls">
                                <div class="luxury-dt-time-box">
                                    <input type="text" inputmode="numeric" maxlength="2" class="luxury-dt-time-input hours-input" value="12" aria-label="Hour">
                                    <div class="luxury-dt-time-steppers">
                                        <button type="button" class="luxury-dt-time-step-btn up-hours" title="Increase Hour">&#9650;</button>
                                        <button type="button" class="luxury-dt-time-step-btn down-hours" title="Decrease Hour">&#9660;</button>
                                    </div>
                                </div>
                                <span class="luxury-dt-time-colon">:</span>
                                <div class="luxury-dt-time-box">
                                    <input type="text" inputmode="numeric" maxlength="2" class="luxury-dt-time-input minutes-input" value="00" aria-label="Minute">
                                    <div class="luxury-dt-time-steppers">
                                        <button type="button" class="luxury-dt-time-step-btn up-minutes" title="Increase Minute">&#9650;</button>
                                        <button type="button" class="luxury-dt-time-step-btn down-minutes" title="Decrease Minute">&#9660;</button>
                                    </div>
                                </div>
                                <button type="button" class="luxury-dt-ampm-btn ampm-toggle">PM</button>
                            </div>
                            <div class="luxury-dt-quick-times-grid">
                                <button type="button" class="luxury-dt-quick-time-btn" data-time="09:00:AM">09:00 AM</button>
                                <button type="button" class="luxury-dt-quick-time-btn" data-time="12:00:PM">12:00 PM</button>
                                <button type="button" class="luxury-dt-quick-time-btn" data-time="06:00:PM">06:00 PM</button>
                                <button type="button" class="luxury-dt-quick-time-btn" data-time="09:00:PM">09:00 PM</button>
                            </div>
                            <div class="luxury-dt-side-actions">
                                <button type="button" class="luxury-dt-btn-clear">Reset</button>
                                <button type="button" class="luxury-dt-btn-apply">Confirm Window</button>
                            </div>
                        </div>
                    </div>
                `;

                this.container.appendChild(this.popover);

                this.monthTitle = this.popover.querySelector('.luxury-dt-month-title');
                this.grid = this.popover.querySelector('.luxury-dt-grid');
                this.hoursInput = this.popover.querySelector('.hours-input');
                this.minutesInput = this.popover.querySelector('.minutes-input');
                this.upHoursBtn = this.popover.querySelector('.up-hours');
                this.downHoursBtn = this.popover.querySelector('.down-hours');
                this.upMinutesBtn = this.popover.querySelector('.up-minutes');
                this.downMinutesBtn = this.popover.querySelector('.down-minutes');
                this.ampmBtn = this.popover.querySelector('.ampm-toggle');
                this.prevBtn = this.popover.querySelector('.prev-month');
                this.nextBtn = this.popover.querySelector('.next-month');
                this.clearBtn = this.popover.querySelector('.luxury-dt-btn-clear');
                this.applyBtn = this.popover.querySelector('.luxury-dt-btn-apply');
            }

            renderCalendar() {
                this.monthTitle.textContent = `${MONTH_NAMES[this.viewMonth]} ${this.viewYear}`;
                this.grid.innerHTML = '';

                // Weekday Headers
                const weekdays = ['Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa', 'Su'];
                weekdays.forEach(day => {
                    const el = document.createElement('div');
                    el.className = 'luxury-dt-weekday';
                    el.textContent = day;
                    this.grid.appendChild(el);
                });

                // First day of month (0 = Sunday, 1 = Monday)
                const firstDayIndex = new Date(this.viewYear, this.viewMonth, 1).getDay();
                // Adjust for Monday start (0=Mo, 6=Su)
                const startOffset = (firstDayIndex + 6) % 7;

                // Total days in month
                const totalDays = new Date(this.viewYear, this.viewMonth + 1, 0).getDate();

                // Empty padding cells
                for (let i = 0; i < startOffset; i++) {
                    const emptyCell = document.createElement('div');
                    emptyCell.className = 'luxury-dt-day empty';
                    this.grid.appendChild(emptyCell);
                }

                const today = new Date();
                today.setHours(0, 0, 0, 0);

                // Days
                for (let d = 1; d <= totalDays; d++) {
                    const cellDate = new Date(this.viewYear, this.viewMonth, d);
                    const cell = document.createElement('div');
                    cell.className = 'luxury-dt-day';
                    cell.textContent = d;

                    // Check if today
                    if (cellDate.getTime() === today.getTime()) {
                        cell.classList.add('today');
                    }

                    // Check if selected
                    if (
                        this.selectedDate &&
                        cellDate.getFullYear() === this.selectedDate.getFullYear() &&
                        cellDate.getMonth() === this.selectedDate.getMonth() &&
                        cellDate.getDate() === this.selectedDate.getDate()
                    ) {
                        cell.classList.add('selected');
                    }

                    // Disable past days
                    if (cellDate < today) {
                        cell.classList.add('disabled');
                    } else {
                        cell.addEventListener('click', (e) => {
                            e.stopPropagation();
                            this.selectDay(d);
                        });
                    }

                    this.grid.appendChild(cell);
                }
            }

            selectDay(day) {
                let h = parseInt(this.hoursInput.value) || 12;
                const m = parseInt(this.minutesInput.value) || 0;
                const ampm = this.ampmBtn.textContent.trim();

                if (ampm === 'PM' && h < 12) h += 12;
                if (ampm === 'AM' && h === 12) h = 0;

                this.selectedDate = new Date(this.viewYear, this.viewMonth, day, h, m, 0);
                this.setValue(this.selectedDate);
                this.renderCalendar();
            }

            stepHour(delta) {
                let current = parseInt(this.hoursInput.value) || 12;
                current += delta;
                if (current > 12) current = 1;
                if (current < 1) current = 12;
                this.hoursInput.value = padZero(current);
                this.updateTimeFromControls();
            }

            stepMinute(delta) {
                let current = parseInt(this.minutesInput.value) || 0;
                current += delta;
                if (current > 59) current = 0;
                if (current < 0) current = 55;
                this.minutesInput.value = padZero(current);
                this.updateTimeFromControls();
            }

            updateTimeFromControls() {
                if (!this.selectedDate) return;
                let h = parseInt(this.hoursInput.value) || 12;
                let m = parseInt(this.minutesInput.value) || 0;
                const ampm = this.ampmBtn.textContent.trim();

                if (h > 12) h = 12;
                if (h < 1) h = 1;
                if (m > 59) m = 59;
                if (m < 0) m = 0;

                this.hoursInput.value = padZero(h);
                this.minutesInput.value = padZero(m);

                if (ampm === 'PM' && h < 12) h += 12;
                if (ampm === 'AM' && h === 12) h = 0;

                this.selectedDate.setHours(h, m, 0);
                this.setValue(this.selectedDate);
            }

            syncTimeControls() {
                if (!this.selectedDate) return;
                let hours = this.selectedDate.getHours();
                const ampm = hours >= 12 ? 'PM' : 'AM';
                hours = hours % 12 || 12;
                const minutes = this.selectedDate.getMinutes();

                this.hoursInput.value = padZero(hours);
                this.minutesInput.value = padZero(minutes);
                this.ampmBtn.textContent = ampm;
            }

            setValue(date) {
                if (!date) {
                    this.hiddenInput.value = '';
                    this.valueDisplay.className = 'luxury-dt-value placeholder';
                    this.valueDisplay.innerHTML = `<span>${this.type === 'start' ? 'Select Opening Window' : 'Select Closing Window'}</span>`;
                    return;
                }

                this.selectedDate = new Date(date);
                this.hiddenInput.value = formatForInput(this.selectedDate);
                this.valueDisplay.className = 'luxury-dt-value';
                this.valueDisplay.innerHTML = formatForDisplay(this.selectedDate);
                this.syncTimeControls();
                
                // Propagate change event
                this.hiddenInput.dispatchEvent(new Event('change', { bubbles: true }));
            }

            applyPreset(preset) {
                const now = new Date();
                let targetDate = new Date();

                if (preset === 'now') {
                    targetDate = new Date(now.getFullYear(), now.getMonth(), now.getDate(), now.getHours(), Math.ceil(now.getMinutes() / 5) * 5, 0);
                } else if (preset === 'tomorrow-10') {
                    targetDate = new Date(now.getFullYear(), now.getMonth(), now.getDate() + 1, 10, 0, 0);
                } else if (preset === 'tomorrow-18') {
                    targetDate = new Date(now.getFullYear(), now.getMonth(), now.getDate() + 1, 18, 0, 0);
                } else if (preset === 'next-mon') {
                    const daysUntilMon = (8 - now.getDay()) % 7 || 7;
                    targetDate = new Date(now.getFullYear(), now.getMonth(), now.getDate() + daysUntilMon, 10, 0, 0);
                } else if (preset === 'plus-24h') {
                    const baseInput = document.getElementById('start_time');
                    const baseDate = baseInput && baseInput.value ? new Date(baseInput.value) : now;
                    targetDate = new Date(baseDate.getTime() + 24 * 60 * 60 * 1000);
                } else if (preset === 'plus-3d') {
                    const baseInput = document.getElementById('start_time');
                    const baseDate = baseInput && baseInput.value ? new Date(baseInput.value) : now;
                    targetDate = new Date(baseDate.getTime() + 3 * 24 * 60 * 60 * 1000);
                } else if (preset === 'plus-7d') {
                    const baseInput = document.getElementById('start_time');
                    const baseDate = baseInput && baseInput.value ? new Date(baseInput.value) : now;
                    targetDate = new Date(baseDate.getTime() + 7 * 24 * 60 * 60 * 1000);
                } else if (preset === 'plus-14d') {
                    const baseInput = document.getElementById('start_time');
                    const baseDate = baseInput && baseInput.value ? new Date(baseInput.value) : now;
                    targetDate = new Date(baseDate.getTime() + 14 * 24 * 60 * 60 * 1000);
                }

                this.viewYear = targetDate.getFullYear();
                this.viewMonth = targetDate.getMonth();
                this.setValue(targetDate);
                this.renderCalendar();
            }

            open() {
                // Close all other popovers
                document.querySelectorAll('.luxury-dt-popover').forEach(p => p.classList.remove('open'));
                document.querySelectorAll('.luxury-dt-trigger').forEach(t => t.classList.remove('active'));

                if (this.selectedDate) {
                    this.viewYear = this.selectedDate.getFullYear();
                    this.viewMonth = this.selectedDate.getMonth();
                }

                this.renderCalendar();
                this.syncTimeControls();
                this.popover.classList.add('open');
                this.trigger.classList.add('active');
            }

            close() {
                this.popover.classList.remove('open');
                this.trigger.classList.remove('active');
            }

            bindEvents() {
                // Trigger Click
                this.trigger.addEventListener('click', (e) => {
                    e.stopPropagation();
                    if (this.popover.classList.contains('open')) {
                        this.close();
                    } else {
                        this.open();
                    }
                });

                // Prevent clicks inside popover from closing it
                this.popover.addEventListener('click', (e) => {
                    e.stopPropagation();
                });

                // Prev / Next Month
                this.prevBtn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    this.viewMonth--;
                    if (this.viewMonth < 0) {
                        this.viewMonth = 11;
                        this.viewYear--;
                    }
                    this.renderCalendar();
                });

                this.nextBtn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    this.viewMonth++;
                    if (this.viewMonth > 11) {
                        this.viewMonth = 0;
                        this.viewYear++;
                    }
                    this.renderCalendar();
                });

                // Stepper Buttons
                this.upHoursBtn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    this.stepHour(1);
                });
                this.downHoursBtn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    this.stepHour(-1);
                });
                this.upMinutesBtn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    this.stepMinute(5);
                });
                this.downMinutesBtn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    this.stepMinute(-5);
                });

                // Time Inputs - Format & Auto-pad
                const sanitizeInput = (input, max, min) => {
                    input.addEventListener('input', () => {
                        input.value = input.value.replace(/\D/g, '');
                    });
                    input.addEventListener('blur', () => {
                        let v = parseInt(input.value);
                        if (isNaN(v)) v = min;
                        if (v > max) v = max;
                        if (v < min) v = min;
                        input.value = padZero(v);
                        this.updateTimeFromControls();
                    });
                    input.addEventListener('keydown', (e) => {
                        if (e.key === 'ArrowUp') {
                            e.preventDefault();
                            if (input === this.hoursInput) this.stepHour(1);
                            else this.stepMinute(1);
                        } else if (e.key === 'ArrowDown') {
                            e.preventDefault();
                            if (input === this.hoursInput) this.stepHour(-1);
                            else this.stepMinute(-1);
                        } else if (e.key === 'Enter') {
                            input.blur();
                        }
                    });
                    input.addEventListener('wheel', (e) => {
                        e.preventDefault();
                        const delta = e.deltaY < 0 ? 1 : -1;
                        if (input === this.hoursInput) this.stepHour(delta);
                        else this.stepMinute(delta * 5);
                    }, { passive: false });
                };

                sanitizeInput(this.hoursInput, 12, 1);
                sanitizeInput(this.minutesInput, 59, 0);

                // AM / PM Toggle
                this.ampmBtn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    this.ampmBtn.textContent = this.ampmBtn.textContent.trim() === 'AM' ? 'PM' : 'AM';
                    this.updateTimeFromControls();
                });

                // Quick Times
                this.popover.querySelectorAll('.luxury-dt-quick-time-btn').forEach(btn => {
                    btn.addEventListener('click', (e) => {
                        e.stopPropagation();
                        const [h, m, ampm] = btn.dataset.time.split(':');
                        this.hoursInput.value = h;
                        this.minutesInput.value = m;
                        this.ampmBtn.textContent = ampm;
                        this.updateTimeFromControls();
                    });
                });

                // Presets
                this.popover.querySelectorAll('.luxury-dt-preset-btn').forEach(btn => {
                    btn.addEventListener('click', (e) => {
                        e.stopPropagation();
                        this.applyPreset(btn.dataset.preset);
                    });
                });

                // Clear
                this.clearBtn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    this.setValue(null);
                    this.renderCalendar();
                });

                // Apply
                this.applyBtn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    this.close();
                });
            }
        }

        // Initialize pickers
        document.addEventListener('DOMContentLoaded', () => {
            const pickers = [];
            document.querySelectorAll('.luxury-dt-container').forEach(container => {
                pickers.push(new LuxuryDateTimePicker(container));
            });

            // Global click outside listener
            document.addEventListener('click', () => {
                document.querySelectorAll('.luxury-dt-popover').forEach(p => p.classList.remove('open'));
                document.querySelectorAll('.luxury-dt-trigger').forEach(t => t.classList.remove('active'));
            });

            // ESC key to close
            document.addEventListener('keydown', (e) => {
                if (e.key === 'Escape') {
                    document.querySelectorAll('.luxury-dt-popover').forEach(p => p.classList.remove('open'));
                    document.querySelectorAll('.luxury-dt-trigger').forEach(t => t.classList.remove('active'));
                }
            });
        });
    })();
</script>



</body>
</html>


