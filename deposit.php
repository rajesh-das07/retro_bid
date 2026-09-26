<?php
session_start();
require_once 'db_connect.php';

// Security Gate
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id = (int)$_SESSION['user_id'];
$message = '';
$error = '';

// Fetch current user details
$stmt = $conn->prepare("SELECT alias, available_funds FROM users WHERE user_id = :uid");
$stmt->execute([':uid' => $user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['deposit_amount'])) {
    $amount = (float)$_POST['deposit_amount'];
    
    if ($amount <= 0) {
        $error = "Deposit amount must be greater than zero.";
    } elseif ($amount > 1000000) {
        $error = "Wire transfer limit exceeded. Maximum single deposit is $1,000,000.";
    } else {
        try {
            $conn->beginTransaction();
            
            // Add funds
            $update = $conn->prepare("UPDATE users SET available_funds = available_funds + :amt WHERE user_id = :uid");
            $update->execute([':amt' => $amount, ':uid' => $user_id]);
            
            // Log in ledger
            $log = $conn->prepare("INSERT INTO transaction_ledger (user_id, type, amount) VALUES (:uid, 'deposit', :amt)");
            $log->execute([':uid' => $user_id, ':amt' => $amount]);
            
            $conn->commit();
            
            $message = "$" . number_format($amount, 2) . " successfully wired to your account.";
            
            // Refresh user data for display
            $stmt->execute([':uid' => $user_id]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
        } catch (Exception $e) {
            $conn->rollBack();
            $error = "Transaction failed: " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Retro-bid | Secure Deposit</title>
    
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
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: background-color 0.35s ease, color 0.35s ease;
        }

        .deposit-container {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            padding: 50px;
            max-width: 500px;
            width: 100%;
            box-shadow: var(--shadow-card);
            text-align: center;
            transition: background-color 0.35s ease, border-color 0.35s ease;
        }

        h1 {
            font-family: Georgia, serif;
            color: var(--accent-gold);
            font-size: 2rem;
            margin-bottom: 10px;
            letter-spacing: 0.05em;
        }

        p.subtitle {
            color: var(--text-secondary);
            font-size: 0.9rem;
            margin-bottom: 40px;
            text-transform: uppercase;
            letter-spacing: 0.1em;
        }
        
        .balance-display {
            background: var(--bg-card-alt);
            border: 1px solid var(--border-subtle);
            padding: 20px;
            margin-bottom: 40px;
        }
        .balance-display span {
            display: block;
            color: var(--text-secondary);
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.15em;
            margin-bottom: 5px;
        }
        .balance-display strong {
            font-size: 1.5rem;
            color: var(--text-primary);
        }

        .form-group {
            margin-bottom: 30px;
            text-align: left;
        }

        label {
            display: block;
            color: var(--text-secondary);
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            margin-bottom: 10px;
        }

        .input-wrapper {
            position: relative;
        }

        .input-wrapper::before {
            content: '$';
            position: absolute;
            left: 20px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--accent-gold);
            font-size: 1.2rem;
            font-weight: bold;
        }

        input[type="number"] {
            width: 100%;
            padding: 20px 20px 20px 45px;
            background: var(--bg-primary);
            border: 1px solid var(--border-subtle);
            color: var(--accent-gold);
            font-size: 1.5rem;
            font-weight: bold;
            outline: none;
            transition: border-color 0.3s;
        }

        input[type="number"]:focus {
            border-color: var(--accent-gold);
        }

        button {
            width: 100%;
            background: var(--accent-gold);
            color: var(--text-inverse);
            border: none;
            padding: 20px;
            font-size: 0.9rem;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.2em;
            cursor: pointer;
            transition: all 0.3s;
        }

        button:hover {
            opacity: 0.9;
            box-shadow: 0 0 20px var(--accent-gold-subtle);
        }

        .alert-success {
            background: rgba(20, 108, 46, 0.1);
            border: 1px solid #146c2e;
            color: #146c2e;
            padding: 15px;
            margin-bottom: 30px;
            font-size: 0.9rem;
        }

        .alert-error {
            background: rgba(153, 0, 0, 0.1);
            border: 1px solid #990000;
            color: #990000;
            padding: 15px;
            margin-bottom: 30px;
            font-size: 0.9rem;
        }
        
        .back-link {
            display: inline-block;
            margin-top: 30px;
            color: var(--text-secondary);
            text-decoration: none;
            text-transform: uppercase;
            font-size: 0.75rem;
            letter-spacing: 0.1em;
            transition: color 0.3s;
        }
        .back-link:hover { color: var(--accent-gold); }

    </style>
</head>
<body>

    <div class="deposit-container">
        <h1>Secure Deposit</h1>
        <p class="subtitle">Wire Transfer Simulator</p>
        
        <?php if ($message): ?>
            <div class="alert-success"><?php echo $message; ?></div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="alert-error"><?php echo $error; ?></div>
        <?php endif; ?>

        <div class="balance-display">
            <span>Current Liquid Balance</span>
            <strong>$<?php echo number_format($user['available_funds'], 2); ?></strong>
        </div>

        <form method="POST">
            <div class="form-group">
                <label>Amount to Wire</label>
                <div class="input-wrapper">
                    <input type="number" name="deposit_amount" step="0.01" min="1" max="1000000" required placeholder="0.00">
                </div>
            </div>
            
            <button type="submit">Authorize Transfer</button>
        </form>
        
        <a href="profile.php" class="back-link">← Return to Ledger</a>
    </div>



</body>
</html>



