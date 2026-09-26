<?php
session_start();
require_once 'db_connect.php';

$error_message = '';
$success_message = '';

// If they are already logged in, kick them to the directory
if (isset($_SESSION['user_id'])) {
    header("Location: auction.php");
    exit();
}

// Handle the form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $alias = trim($_POST['alias']);
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];

    if (empty($alias) || empty($email) || empty($password) || empty($confirm_password)) {
        $error_message = "All fields are required.";
    } elseif ($password !== $confirm_password) {
        $error_message = "Passphrases do not match.";
    } else {
        // Check if email or alias already exists
        $stmt = $conn->prepare("SELECT user_id FROM users WHERE email = :email OR alias = :alias");
        $stmt->execute([':email' => $email, ':alias' => $alias]);
        
        if ($stmt->fetch()) {
            $error_message = "An account with this email or alias already exists.";
        } else {
            // Hash the password securely
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            
            // Insert the new user and give them $100,000 in starting funds
            $insert_stmt = $conn->prepare("INSERT INTO users (alias, email, password_hash, available_funds) VALUES (:alias, :email, :pass, 100000.00)");
            
            try {
                $insert_stmt->execute([
                    ':alias' => $alias,
                    ':email' => $email,
                    ':pass' => $hashed_password
                ]);
                
                // AUTOMATIC LOGIN: Grab their new database ID and set the session
                $_SESSION['user_id'] = $conn->lastInsertId();
                $_SESSION['alias'] = $alias;
                
                // Bypass the success message and drop them straight into the vault
                header("Location: auction.php");
                exit();
                
            } catch (PDOException $e) {
                $error_message = "Database error: Could not create account.";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Retro-bid | Apply</title>
    
    <!-- Instant Theme Initializer -->
    <script>
        (function() {
            var theme = localStorage.getItem('retro_theme') || '<?php echo isset($_SESSION['theme']) ? htmlspecialchars($_SESSION['theme']) : 'dark'; ?>' || 'dark';
            document.documentElement.setAttribute('data-theme', theme);
        })();
    </script>
    <link rel="stylesheet" href="theme.css">
    
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { 
            background-color: var(--bg-primary); 
            color: var(--text-primary); 
            font-family: sans-serif; 
            display: flex; 
            justify-content: center; 
            align-items: center; 
            height: 100vh;
            background-image: url('https://images.unsplash.com/photo-1600585154340-be6161a56a0c?q=80&w=1920&auto=format&fit=crop');
            background-size: cover;
            background-position: center;
            transition: background-color 0.35s ease, color 0.35s ease;
        }
        .overlay {
            position: absolute; top: 0; left: 0; right: 0; bottom: 0;
            background-color: rgba(28, 26, 23, 0.85);
            z-index: 1;
        }
        .login-panel {
            position: relative;
            z-index: 2;
            background-color: var(--bg-card);
            color: var(--text-primary);
            border: 1px solid var(--border-color);
            padding: 60px 50px;
            width: 100%;
            max-width: 450px;
            box-shadow: 0px 20px 50px rgba(0,0,0,0.5);
            transition: background-color 0.35s ease, color 0.35s ease, border-color 0.35s ease;
        }
        .logo { font-size: 24px; color: var(--text-primary); text-align: center; margin-bottom: 40px; text-transform: uppercase; letter-spacing: 0.2em;}
        .logo span { color: var(--accent-gold); }
        
        .form-group { margin-bottom: 25px; }
        label { display: block; font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.15em; color: var(--text-secondary); margin-bottom: 10px; }
        input[type="text"], input[type="email"], input[type="password"] {
            width: 100%; padding: 15px; border: 1px solid var(--border-subtle); background: var(--bg-primary); color: var(--text-primary); font-size: 1rem; outline: none; transition: border-color 0.3s;
        }
        input:focus { border-color: var(--accent-gold); }
        
        .btn-submit {
            width: 100%; padding: 15px; background-color: var(--accent-gold); color: var(--text-inverse); border: none;
            text-transform: uppercase; letter-spacing: 0.15em; font-size: 0.85rem; cursor: pointer; font-weight: bold;
            transition: opacity 0.3s, box-shadow 0.3s; margin-top: 10px;
        }
        .btn-submit:hover { opacity: 0.9; box-shadow: 0 0 15px var(--accent-gold-subtle); }
        
        .error { color: #e53935; font-size: 0.85rem; margin-bottom: 20px; text-align: center; }
        .success { color: #146c2e; font-size: 0.85rem; margin-bottom: 20px; text-align: center; font-weight: bold; }
        
        .toggle-link {
            display: block; text-align: center; margin-top: 25px; font-size: 0.75rem; color: var(--text-secondary); 
            text-decoration: none; text-transform: uppercase; letter-spacing: 0.1em; transition: color 0.3s;
        }
        .toggle-link:hover { color: var(--accent-gold); }
    </style>
</head>
<body>
    <div class="overlay"></div>
    <div class="login-panel">
        <a href="auction.php" class="logo" style="text-decoration: none;">Retro<span>-bid</span></a>
        
        <?php if ($error_message): ?>
            <div class="error"><?php echo htmlspecialchars($error_message); ?></div>
        <?php endif; ?>
        <?php if ($success_message): ?>
            <div class="success"><?php echo htmlspecialchars($success_message); ?></div>
        <?php endif; ?>

        <form method="POST" action="register.php">
            <div class="form-group">
                <label for="alias">Public Alias</label>
                <input type="text" id="alias" name="alias" required placeholder="Pendelton_44">
            </div>
            <div class="form-group">
                <label for="email">Secure Email</label>
                <input type="email" id="email" name="email" required placeholder="arthur@thesyndicate.com">
            </div>
            <div class="form-group">
                <label for="password">Passphrase</label>
                <input type="password" id="password" name="password" required>
            </div>
            <div class="form-group">
                <label for="confirm_password">Confirm Passphrase</label>
                <input type="password" id="confirm_password" name="confirm_password" required>
            </div>
            <button type="submit" class="btn-submit">Submit Application</button>
        </form>

        <a href="login.php" class="toggle-link">Already a member? Enter the Vault</a>
    </div>
</body>
</html>


