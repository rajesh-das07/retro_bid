<?php 
session_start(); 
require_once 'db_connect.php';

$success_message = '';
$error_message = '';

// Catch the form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_inquiry'])) {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $inquiry_type = trim($_POST['inquiry_type'] ?? '');
    $subject = trim($_POST['subject'] ?? '');
    $message = trim($_POST['message'] ?? '');

    if (empty($name) || empty($email) || empty($inquiry_type) || empty($subject) || empty($message)) {
        $error_message = "Transmission Failed: All fields are required to establish a secure connection.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error_message = "Transmission Failed: Invalid email format.";
    } else {
        try {
            // Lock the inquiry into the database
            $stmt = $conn->prepare("INSERT INTO inquiries (name, email, inquiry_type, subject, message) VALUES (:n, :e, :t, :s, :m)");
            $stmt->execute([
                ':n' => $name,
                ':e' => $email,
                ':t' => $inquiry_type,
                ':s' => $subject,
                ':m' => $message
            ]);
            $success_message = "Transmission Secure. A Syndicate Concierge has received your file and will reach out shortly.";
        } catch (PDOException $e) {
            $error_message = "Vault Error: Could not process your transmission at this time.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Retro-bid | Contact</title>
    
    <!-- Instant Theme Initializer -->
    <script>
        (function() {
            var theme = localStorage.getItem('retro_theme') || '<?php echo isset($_SESSION['theme']) ? htmlspecialchars($_SESSION['theme']) : 'dark'; ?>' || 'dark';
            document.documentElement.setAttribute('data-theme', theme);
        })();
    </script>
    <link rel="stylesheet" href="theme.css">
    
    <style>
        *::before, *::after, * { box-sizing: border-box; margin: 0; padding: 0; }
        html, body { height: 100vh; scroll-snap-type: y mandatory; overflow-y: scroll; background-color: var(--bg-primary); color: var(--text-primary); font-family: sans-serif; scrollbar-width: none; transition: background-color 0.35s ease, color 0.35s ease; }
        ::-webkit-scrollbar { display: none; }

        header { position: fixed; top: 0; left: 0; width: 100%; z-index: 1000; }
        nav { display: flex; justify-content: space-between; align-items: center; background-color: var(--bg-nav); backdrop-filter: blur(16px); -webkit-backdrop-filter: blur(16px); padding: 15px 30px; border-bottom: 1px solid var(--border-color); font-family: sans-serif; transition: all 0.3s ease; }
        .logo { font-size: 24px; color: var(--text-primary); font-weight: bold; letter-spacing: 0.05em; text-shadow: 0 2px 10px rgba(0, 0, 0, 0.4); transition: color 0.3s ease; }
        .logo span { color: var(--accent-gold); }
        nav ul { list-style: none; display: flex; align-items: center; margin: 0; padding: 0; }
        nav ul li { margin: 0 10px; }
        nav ul li a { color: var(--text-primary); text-decoration: none; transition: color 0.3s; font-size: 0.85rem; font-weight: 500;}
        nav ul li a:hover { color: var(--accent-gold); }
        
        .nav-alias { color: var(--accent-gold) !important; font-size: 0.75rem !important; font-weight: 600 !important; letter-spacing: 0.15em !important; text-transform: uppercase !important; display: flex !important; align-items: center !important; gap: 8px !important; margin-left: 20px !important; text-decoration: none !important; transition: opacity 0.3s ease !important; }
        .nav-alias:hover { opacity: 0.8; }
        .nav-alias::before { content: ''; display: block; width: 6px; height: 6px; background-color: var(--accent-gold); border-radius: 50%; box-shadow: 0 0 8px var(--accent-gold); }
        
        .btn-login { border: 1px solid var(--border-color) !important; padding: 8px 20px !important; border-radius: 2px !important; color: var(--accent-gold) !important; font-size: 0.75rem !important; letter-spacing: 0.15em !important; text-transform: uppercase !important; font-weight: 600 !important; margin-left: 20px !important; text-decoration: none !important; display: inline-block !important; transition: all 0.3s ease !important; }
        .btn-login:hover { background-color: var(--accent-gold-subtle) !important; box-shadow: 0 0 15px var(--accent-gold-subtle) !important; color: var(--text-primary) !important; }

        .contact-hero { height: 100vh; scroll-snap-align: start; display: flex; flex-direction: column; justify-content: center; align-items: center; text-align: center; padding: 0 5%; background-image: url('images/client.png'); background-size: cover; background-position: center; position: relative; }
        .contact-hero::before { content: ''; position: absolute; top: 0; left: 0; width: 100%; height: 100%; background: rgba(28, 26, 23, 0.75); z-index: 1; }
        .hero-content { z-index: 2; }
        .contact-hero h1 { font-size: 3.5rem; text-transform: uppercase; letter-spacing: 0.15em; color: #F7F4EB; margin-bottom: 20px; }
        .contact-hero p { color: var(--accent-gold); font-size: 1rem; letter-spacing: 0.1em; text-transform: uppercase; }

        .contact-section { background-color: var(--bg-primary); color: var(--text-primary); display: flex; justify-content: center; align-items: center; min-height: 100vh; scroll-snap-align: start; padding: 100px 10%; transition: background-color 0.35s ease, color 0.35s ease; }
        .contact-container { width: 100%; max-width: 600px; text-align: center; }
        .contact-container h2 { font-size: 2.5rem; text-transform: uppercase; letter-spacing: 0.15em; margin-bottom: 15px; color: var(--text-primary); }
        .contact-container p { color: var(--text-secondary); font-size: 1rem; margin-bottom: 50px; }

        .luxury-form { display: flex; flex-direction: column; gap: 30px; }
        .luxury-form input, .luxury-form textarea { width: 100%; background: transparent; border: none; border-bottom: 1px solid var(--border-subtle); padding: 15px 0; font-size: 0.85rem; letter-spacing: 0.15em; color: var(--text-primary); outline: none; transition: border-color 0.3s ease; }
        .luxury-form input:focus, .luxury-form textarea:focus { border-bottom: 2px solid var(--accent-gold); }

        /* --- CUSTOM LUXURY DROPDOWN (Reused from Feedback) --- */
        .custom-select-wrapper { position: relative; width: 100%; text-align: left;}
        .custom-select-trigger { display: flex; justify-content: space-between; align-items: center; width: 100%; background: transparent; border: none; border-bottom: 1px solid var(--border-subtle); padding: 15px 0; font-size: 0.85rem; letter-spacing: 0.15em; color: var(--text-primary); cursor: pointer; transition: border-color 0.3s ease; text-transform: uppercase; }
        .custom-select-trigger:focus, .custom-select-wrapper.open .custom-select-trigger { border-bottom: 2px solid var(--accent-gold); outline: none; }
        .custom-options { position: absolute; display: block; top: 100%; left: 0; right: 0; background-color: var(--bg-card); border: 1px solid var(--border-color); box-shadow: var(--shadow-card); z-index: 10; opacity: 0; visibility: hidden; pointer-events: none; transform: translateY(-10px); transition: all 0.3s ease; }
        .custom-select-wrapper.open .custom-options { opacity: 1; visibility: visible; pointer-events: all; transform: translateY(0); }
        .custom-option { padding: 15px 20px; font-size: 0.75rem; letter-spacing: 0.15em; color: var(--text-secondary); text-transform: uppercase; cursor: pointer; transition: all 0.3s ease; border-bottom: 1px solid var(--border-subtle); }
        .custom-option:last-child { border-bottom: none; }
        .custom-option:hover { color: var(--text-inverse); background-color: var(--accent-gold); }

        .submit-btn { margin-top: 20px; background-color: var(--accent-gold); color: var(--text-inverse); border: none; padding: 20px 40px; text-transform: uppercase; letter-spacing: 0.2em; font-size: 0.85rem; cursor: pointer; transition: opacity 0.4s ease, box-shadow 0.4s ease; font-weight: bold; }
        .submit-btn:hover { opacity: 0.9; box-shadow: 0 0 15px var(--accent-gold-subtle); }

        .office-section { height: 100vh; scroll-snap-align: start; display: flex; justify-content: center; align-items: center; background-color: var(--bg-secondary); color: var(--text-primary); text-align: center; padding: 0 10%; transition: background-color 0.35s ease; }
        .office-grid { display: flex; gap: 60px; max-width: 1000px; }
        .office-card { flex: 1; padding: 40px; border: 1px solid var(--border-card); background-color: var(--bg-card); }
        .office-card h3 { font-size: 1.5rem; color: var(--accent-gold); margin-bottom: 20px; text-transform: uppercase; letter-spacing: 0.15em; }
        .office-card p { color: var(--text-secondary); line-height: 1.8; font-size: 0.9rem; letter-spacing: 0.05em; }

        .luxury-footer { background-color: #11100E; color: #8A827A; padding: 80px 10% 40px; scroll-snap-align: start; }
        .footer-content { display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid rgba(138, 130, 122, 0.2); padding-bottom: 40px; margin-bottom: 40px; }
        .footer-logo { font-size: 2rem; color: #F7F4EB; letter-spacing: 0.05em; font-weight: bold;}
        .footer-logo span { color: #C5A059; }
        .footer-links, .footer-socials { display: flex; gap: 30px; list-style: none; margin: 0; padding: 0; }
        .footer-links a, .footer-socials a { color: #8A827A; text-transform: uppercase; letter-spacing: 0.15em; font-size: 0.75rem; text-decoration: none; transition: color 0.3s ease; }
        .footer-links a:hover, .footer-socials a:hover { color: #C5A059; }
        .footer-bottom { text-align: center; font-size: 0.75rem; letter-spacing: 0.1em; text-transform: uppercase; color: #5A534A; }

        @media (max-width: 768px) {
            nav { flex-direction: column; padding: 15px; gap: 15px; }
            .office-grid { flex-direction: column; gap: 30px; }
            .footer-content { flex-direction: column; text-align: center; gap: 30px; }
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
                
                <?php if (isset($_SESSION['user_id'])): ?>
                    <li><a href="profile.php" class="nav-alias"><?php echo htmlspecialchars($_SESSION['alias']); ?></a></li>
                <?php else: ?>
                    <li><a href="login.php" class="btn-login">Enter The Vault</a></li>
                <?php endif; ?>
            </ul>
        </nav>
    </header>

    <section class="contact-hero">
        <div class="hero-content">
            <h1>Client Relations</h1>
            <p>Connect with our private concierges</p>
        </div>
    </section>

    <section class="contact-section" id="inquiry-form">
        <div class="contact-container">
            <h2>Secure Inquiry</h2>
            <p>Request private access, bid augmentation, or general assistance.</p>

            <?php if ($success_message): ?>
                <div style="padding: 15px; margin-bottom: 30px; background-color: rgba(20, 108, 46, 0.1); color: #146c2e; border: 1px solid #146c2e; font-size: 0.85rem; letter-spacing: 0.1em; text-transform: uppercase; font-weight: bold;">
                    <?php echo htmlspecialchars($success_message); ?>
                </div>
            <?php endif; ?>
            
            <?php if ($error_message): ?>
                <div style="padding: 15px; margin-bottom: 30px; background-color: rgba(153, 0, 0, 0.1); color: #990000; border: 1px solid #990000; font-size: 0.85rem; letter-spacing: 0.1em; text-transform: uppercase; font-weight: bold;">
                    <?php echo htmlspecialchars($error_message); ?>
                </div>
            <?php endif; ?>

            <form class="luxury-form" method="POST" action="contact.php#inquiry-form">
                <input type="hidden" name="submit_inquiry" value="1">
                
                <!-- If user is logged in, auto-fill their alias/email -->
                <input type="text" name="name" placeholder="ALIAS / FULL NAME" value="<?php echo isset($_SESSION['alias']) ? htmlspecialchars($_SESSION['alias']) : ''; ?>" required>
                
                <!-- Email field -->
                <input type="email" name="email" placeholder="SECURE EMAIL" required>

                <!-- THE LUXURY CUSTOM SELECT FOR INQUIRY TYPE -->
                <div class="custom-select-wrapper">
                    <input type="hidden" name="inquiry_type" id="inquiry-input" required>
                    <div class="custom-select-trigger" id="select-trigger">
                        <span style="color: #8A827A;">NATURE OF INQUIRY</span>
                        <span style="font-size: 0.7rem; color: #8A827A;">&#9660;</span>
                    </div>
                    <div class="custom-options">
                        <div class="custom-option" data-value="Private Access Request">Private Access Request</div>
                        <div class="custom-option" data-value="Bid Limit Augmentation">Bid Limit Augmentation</div>
                        <div class="custom-option" data-value="Appraisal & Consignment">Appraisal & Consignment</div>
                        <div class="custom-option" data-value="Technical Support">Technical Support</div>
                        <div class="custom-option" data-value="General Inquiry">General Inquiry</div>
                    </div>
                </div>

                <input type="text" name="subject" placeholder="SUBJECT" required>
                <textarea rows="4" name="message" placeholder="YOUR MESSAGE / DOSSIER" required></textarea>

                <button type="submit" class="submit-btn" id="submit-btn">Send Transmission</button>
            </form>
        </div>
    </section>

    <section class="office-section">
        <div class="office-grid">
            <div class="office-card">
                <h3>Geneva Vault</h3>
                <p>Rue du Rhône 65<br>1204 Geneva, Switzerland<br><br>geneva@retro-bid.com</p>
            </div>
            <div class="office-card">
                <h3>London Exchange</h3>
                <p>Mayfair, W1J 8AQ<br>London, United Kingdom<br><br>london@retro-bid.com</p>
            </div>
        </div>
    </section>

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
        // --- CUSTOM SELECT LOGIC ---
        const selectWrapper = document.querySelector('.custom-select-wrapper');
        const selectTrigger = document.querySelector('.custom-select-trigger');
        const triggerText = selectTrigger.querySelector('span');
        const customOptions = document.querySelectorAll('.custom-option');
        const hiddenInput = document.getElementById('inquiry-input');
        const submitBtn = document.getElementById('submit-btn');

        // Toggle dropdown open/close
        selectTrigger.addEventListener('click', function(e) {
            e.stopPropagation();
            selectWrapper.classList.toggle('open');
        });

        // Handle option selection
        customOptions.forEach(option => {
            option.addEventListener('click', function(e) {
                e.stopPropagation();
                // Update visual text
                triggerText.textContent = this.textContent;
                triggerText.style.color = '#1C1A17'; // Turn dark indicating a selection
                
                // Set hidden input value for PHP
                hiddenInput.value = this.getAttribute('data-value');
                
                // Close menu
                selectWrapper.classList.remove('open');
            });
        });

        // Close dropdown if clicking outside
        document.addEventListener('click', function() {
            selectWrapper.classList.remove('open');
        });

        // Prevent submission if no inquiry type is selected
        submitBtn.addEventListener('click', function(e) {
            if(hiddenInput.value === "") {
                e.preventDefault(); 
                alert("Please select the nature of your inquiry before submitting.");
                selectTrigger.style.borderBottom = "2px solid #990000"; 
                setTimeout(() => { selectTrigger.style.borderBottom = "1px solid #8A827A"; }, 1500);
            }
        });
    </script>


</body>
</html>


