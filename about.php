<?php session_start(); ?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Retro-bid | About Us</title>
    
    <!-- Instant Theme Initializer -->
    <script>
        (function() {
            var theme = localStorage.getItem('retro_theme') || '<?php echo isset($_SESSION['theme']) ? htmlspecialchars($_SESSION['theme']) : 'dark'; ?>' || 'dark';
            document.documentElement.setAttribute('data-theme', theme);
        })();
    </script>
    <link rel="stylesheet" href="theme.css">
    
    <style>
        /* =========================================
           GLOBAL RESET & TYPOGRAPHY
        ========================================= */
        *::before,
        *::after,
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        html,
        body {
            height: 100vh;
            scroll-snap-type: y mandatory;
            overflow-y: scroll;
            background-color: var(--bg-primary);
            color: var(--text-primary);
            font-family: sans-serif;
            scrollbar-width: none;
            transition: background-color 0.35s ease, color 0.35s ease;
        }

        ::-webkit-scrollbar {
            display: none;
        }

        /* =========================================
           THE GHOST NAVIGATION
        ========================================= */
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

        .logo { font-size: 24px; color: var(--text-primary); font-weight: bold; letter-spacing: 0.05em; text-shadow: 0 2px 10px rgba(0, 0, 0, 0.4); transition: color 0.3s ease; }
        .logo span { color: var(--accent-gold); }

        nav ul { list-style: none; display: flex; align-items: center; margin: 0; padding: 0; }
        nav ul li { margin: 0 10px; }
        nav ul li a { color: var(--text-primary); text-decoration: none; transition: color 0.3s; font-size: 0.85rem; font-weight: 500;}
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

        .about-hero {
            height: 100vh;
            scroll-snap-align: start;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            text-align: center;
            padding: 0 5%;
            background-color: #1C1A17;
            background-image: url('images/about.png');
            background-size: cover;
            background-position: center;
            position: relative;
        }

        .about-hero::before {
            content: "";
            position: absolute;
            top: 0; left: 0; right: 0; bottom: 0;
            background-color: rgba(28, 26, 23, 0.75);
            z-index: 1;
        }

        .about-hero h1,
        .about-hero p {
            position: relative;
            z-index: 2;
        }

        .about-hero h1 {
            font-size: 3.5rem;
            text-transform: uppercase;
            letter-spacing: 0.15em;
            color: #F7F4EB;
            margin-bottom: 20px;
            text-shadow:
                0px 2px 4px rgba(28, 26, 23, 0.9),
                0px 4px 15px rgba(28, 26, 23, 0.7),
                0px 0px 30px rgba(28, 26, 23, 0.5);
        }

        .about-hero p {
            color: #C5A059;
            font-size: 1rem;
            letter-spacing: 0.1em;
            text-transform: uppercase;
            max-width: 600px;
            line-height: 1.6;
            text-shadow: 0px 2px 5px rgba(0, 0, 0, 0.8);
        }

        .about-section {
            display: flex;
            width: 100%;
            height: 100vh;
            scroll-snap-align: start;
        }

        .about-image {
            flex: 1;
            background-color: #1C1A17;
            background-image: url('images/about1.jpg');
            background-size: cover;
            background-position: center;
        }

        .about-image.img-2 {
            background-image: url('images/about2.jpeg');
        }

        .about-content {
            flex: 1;
            background-color: var(--bg-primary);
            display: flex;
            flex-direction: column;
            justify-content: center;
            padding: 0 10%;
            color: var(--text-primary);
            transition: background-color 0.35s ease, color 0.35s ease;
        }

        .about-content.dark {
            background-color: var(--bg-secondary);
        }

        .about-content h2 {
            font-size: 3rem;
            text-transform: uppercase;
            letter-spacing: 0.15em;
            margin-bottom: 30px;
            color: var(--text-primary);
        }

        .about-content p {
            font-size: 1.25rem;
            line-height: 1.8;
            color: var(--text-secondary);
            max-width: 600px;
        }

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
            .about-section { flex-direction: column; }
            .about-section.reverse { flex-direction: column-reverse; }
            .about-image { flex: 0.4; }
            .about-content { flex: 0.6; padding: 0 5%; }
            .about-content h2 { font-size: 1.8rem; margin-bottom: 20px; }
            nav { flex-direction: column; padding: 15px; gap: 15px; }
            .footer-content { flex-direction: column; text-align: center; gap: 30px; }
        }
    </style>
</head>

<body>
    <header>
        <nav>
            <a href="auction.php" class="logo" style="text-decoration: none;">
                Retro<span>-bid</span>
            </a>
            <ul>
                <li><a href="auction.php">Home</a></li>
                <li><a href="directory.php">Auctions</a></li>
                <li><a href="sell.php">Sell</a></li>
                <li><a href="contact.php">Contact</a></li>
                <li><a href="about.php">About</a></li>
                
                <!-- DYNAMIC NAVIGATION LOGIC -->
                <?php if (isset($_SESSION['user_id'])): ?>
                    <li><a href="profile.php" class="nav-alias"><?php echo htmlspecialchars($_SESSION['alias']); ?></a></li>
                <?php else: ?>
                    <li><a href="login.php" class="btn-login">Enter The Vault</a></li>
                <?php endif; ?>
            </ul>
        </nav>
    </header>

    <section class="about-hero">
        <h1>The Syndicate</h1>
        <p>Curating the exceptional. Preserving the legacy.</p>
    </section>

    <section class="about-section">
        <div class="about-image"></div>
        <div class="about-content">
            <h2>Our Origin</h2>
            <p>Retro-bid was born from a singular vision: to create an uncompromised marketplace where history's most exquisite artifacts meet the global elite. We transcend traditional auction houses by engineering a seamless, cinematic digital experience strictly for discerning collectors.</p>
        </div>
    </section>

    <section class="about-section reverse">
        <div class="about-content dark">
            <h2>Authentication</h2>
            <p>Every asset presented on our platform undergoes a rigorous, uncompromising verification process. We partner with world-renowned heritage experts, horological historians, and master appraisers to ensure absolute provenance and flawless lineage before any item enters the live arena.</p>
        </div>
        <div class="about-image img-2"></div>
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


</body>
</html>


