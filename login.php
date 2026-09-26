<?php
session_start();
require_once 'db_connect.php';

$error_message = '';

// If they are already logged in, kick them to the home page
if (isset($_SESSION['user_id'])) {
    header("Location: auction.php");
    exit();
}

// Handle the form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email']);
    $password = $_POST['password'];

    if (empty($email) || empty($password)) {
        $error_message = "All fields are required.";
    } else {
        // Find the user by email
        $stmt = $conn->prepare("SELECT * FROM users WHERE email = :email");
        $stmt->bindParam(':email', $email);
        $stmt->execute();
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        // Verify the user exists AND the password matches the hash
        if ($user && password_verify($password, $user['password_hash'])) {
            // Check if banned
            $is_banned = false;
            try {
                $ban_stmt = $conn->prepare("SELECT banned_until FROM users WHERE user_id = :id AND (banned_until > NOW() OR banned_until = '9999-12-31 00:00:00')");
                $ban_stmt->execute([':id' => $user['user_id']]);
                if ($ban_row = $ban_stmt->fetch(PDO::FETCH_ASSOC)) {
                    $is_banned = true;
                    if ($ban_row['banned_until'] == '9999-12-31 00:00:00') {
                        $error_message = "This account has been permanently suspended.";
                    } else {
                        $error_message = "Account suspended until " . date('M d, Y H:i', strtotime($ban_row['banned_until'])) . ".";
                    }
                }
            } catch (PDOException $e) {
                // Column doesn't exist yet, safely ignore
            }

            if (!$is_banned) {
                // SUCCESS: Lock the identity into the session
                $_SESSION['user_id'] = $user['user_id'];
                $_SESSION['alias'] = $user['alias'];
                if (!empty($user['theme'])) {
                    $_SESSION['theme'] = $user['theme'];
                }
                
                header("Location: auction.php");
                exit();
            }
        } else {
            // Give a generic error so hackers don't know if they guessed a correct email
            $error_message = "Invalid credentials.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Retro-bid | Secure Access</title>
    
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
        
        input[type="email"], input[type="password"] {
            width: 100%; padding: 15px; border: 1px solid var(--border-subtle); background: var(--bg-primary); color: var(--text-primary); font-size: 1rem; outline: none; transition: border-color 0.3s;
        }
        
        input:focus { border-color: var(--accent-gold); }
        
        .btn-submit {
            width: 100%; padding: 15px; 
            background-color: var(--accent-gold); 
            color: var(--text-inverse); border: none;
            text-transform: uppercase; letter-spacing: 0.15em; font-size: 0.85rem; cursor: pointer; font-weight: bold;
            transition: opacity 0.3s, box-shadow 0.3s; margin-top: 10px;
        }
        
        .btn-submit:hover { opacity: 0.9; box-shadow: 0 0 15px var(--accent-gold-subtle); }
        
        .error { color: #e53935; font-size: 0.85rem; margin-bottom: 20px; text-align: center; }

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

        <form method="POST" action="login.php">
            <div class="form-group">
                <label for="email">Syndicate Email</label>
                <input type="email" id="email" name="email" required placeholder="arthur@thesyndicate.com">
            </div>
            <div class="form-group">
                <label for="password">Passphrase</label>
                <input type="password" id="password" name="password" required>
            </div>
            <button type="submit" class="btn-submit">Enter The Vault</button>
        </form>

        <!-- ADDED THE REGISTRATION LINK HERE -->
        <a href="register.php" class="toggle-link">Request Syndicate Access</a>
    </div>
</body>
</html>


