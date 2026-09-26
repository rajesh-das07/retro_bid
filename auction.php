<?php 
session_start(); 
require_once 'db_connect.php';

$feedback_success = '';
$feedback_error = '';

// Catch the feedback form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_feedback'])) {
    $name = trim($_POST['name'] ?? '');
    $rating = (int)($_POST['rating'] ?? 0);
    $message = trim($_POST['message'] ?? '');

    if (empty($name) || empty($message) || $rating < 1 || $rating > 5) {
        $feedback_error = "Transmission Failed: All fields are required and a valid rating must be selected.";
    } else {
        try {
            // Lock the feedback into the database
            $stmt = $conn->prepare("INSERT INTO feedback (user_name, rating, message) VALUES (:n, :r, :m)");
            $stmt->execute([
                ':n' => $name,
                ':r' => $rating,
                ':m' => $message
            ]);
            $feedback_success = "Feedback Secured. The Syndicate values your insight.";
        } catch (PDOException $e) {
            $feedback_error = "Vault Error: Could not process feedback at this time.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Retro-bid | Home</title>
    
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
            scrollbar-width: none;
        }

        html::-webkit-scrollbar,
        body::-webkit-scrollbar {
            display: none;
        }

        /* =========================================
           THE GHOST NAVIGATION (UNIFIED SITE-WIDE)
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
            color: #F7F4EB;
            text-decoration: none;
            font-size: 0.85rem;
            font-weight: 500;
            transition: color 0.3s;
        }

        nav ul li a:hover {
            color: #C5A059;
        }

        /* --- Refined User Session Styling --- */
        .nav-alias { 
            color: #C5A059 !important; 
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
            background-color: #C5A059;
            border-radius: 50%;
            box-shadow: 0 0 8px rgba(197, 160, 89, 0.6);
        }

        .btn-login {
            border: 1px solid rgba(197, 160, 89, 0.4) !important;
            padding: 8px 20px !important;
            border-radius: 2px !important;
            color: #C5A059 !important;
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
            background-color: rgba(197, 160, 89, 0.1) !important;
            box-shadow: 0 0 15px rgba(197, 160, 89, 0.2) !important;
            color: #F7F4EB !important;
        }

        .slider-container {
            width: 100%;
            height: 100vh;
            position: relative;
            overflow: hidden;
            scroll-snap-align: start;
        }

        .slider-track {
            width: 100%;
            height: 100%;
        }

        .slide {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #1C1A17;
            font-family: sans-serif;
            opacity: 0;
            visibility: hidden;
            transition: opacity 1.8s cubic-bezier(0.4, 0, 0.2, 1), visibility 1.8s;
        }

        .slide.active {
            opacity: 1;
            visibility: visible;
            z-index: 2;
        }

        .slide::before {
            content: "";
            position: absolute;
            top: 0; left: 0; right: 0; bottom: 0;
            background-color: rgba(28, 26, 23, 0.65);
            z-index: 1;
        }

        .slider-container,
        .about-section,
        .features-section,
        .contact-section {
            height: 100vh;
            scroll-snap-align: start;
        }

        .slide-1-img {
            background-image: url('https://hodinkee.imgix.net/uploads/hero_image/b2082d6f1512259210ef90d9876e75b7?ixlib=rails-1.1.0&fm=jpg&q=55&auto=format&usm=12');
            background-size: cover;
            background-position: center;
            background-repeat: no-repeat;
        }

        .slide-2-img {
            background-image: url('https://wallpapercave.com/wp/wp4766596.jpg');
            background-size: cover;
            background-position: center;
            background-repeat: no-repeat;
        }

        .slide-3-img {
            background-image: url('https://api.bertolamifineart.com/api/lotto/immagine/121869.jpg');
            background-size: cover;
            background-position: center;
            background-repeat: no-repeat;
        }

        .slide h2 {
            position: relative;
            z-index: 2;
            color: #F7F4EB;
            text-transform: uppercase;
            letter-spacing: 0.15em;
            font-size: 3.5rem;
            text-shadow:
                0px 2px 4px rgba(28, 26, 23, 0.9),
                0px 4px 15px rgba(28, 26, 23, 0.7),
                0px 0px 30px rgba(28, 26, 23, 0.5);
            background: transparent;
            backdrop-filter: none;
            border: none;
            padding: 0;
        }

        .slider-controls {
            position: absolute;
            top: 50%;
            left: 0;
            width: 100%;
            display: flex;
            justify-content: space-between;
            padding: 0 5%;
            transform: translateY(-50%);
            z-index: 10;
            pointer-events: none; 
        }

        .nav-btn {
            background: rgba(28, 26, 23, 0.2);
            border: 1px solid rgba(247, 244, 235, 0.2);
            color: rgba(247, 244, 235, 0.7);
            width: 50px;
            height: 50px;
            border-radius: 50%;
            font-size: 1.2rem;
            cursor: pointer;
            pointer-events: auto; 
            transition: all 0.4s ease;
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
        }

        .nav-btn:hover {
            border-color: #C5A059;
            color: #C5A059;
            background: rgba(28, 26, 23, 0.8);
        }

        .slider-indicators {
            position: absolute;
            bottom: 40px;
            left: 50%;
            transform: translateX(-50%);
            display: flex;
            gap: 15px;
            z-index: 10;
        }

        .indicator {
            width: 40px;
            height: 2px;
            background-color: rgba(247, 244, 235, 0.3);
            cursor: pointer;
            transition: background-color 0.4s ease, width 0.4s ease;
        }

        .indicator.active {
            background-color: #C5A059;
            width: 60px; 
        }

        .about-section {
            display: flex;
            width: 100%;
        }

        .about-image {
            flex: 1;
            background-color: #1C1A17;
            background-image: url('https://images.unsplash.com/photo-1600607688969-a5bfcd646154?q=80&w=1000&auto=format&fit=crop');
            background-size: cover;
            background-position: center;
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

        .features-section {
            background-color: var(--bg-secondary);
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            padding: 0 10%;
            transition: background-color 0.35s ease;
        }

        .features-title {
            font-size: 2.5rem;
            text-transform: uppercase;
            letter-spacing: 0.15em;
            color: var(--text-primary);
            margin-bottom: 80px;
            text-align: center;
        }

        .features-grid {
            display: flex;
            gap: 40px;
            width: 100%;
            max-width: 1200px;
        }

        .feature-card {
            flex: 1;
            background-color: var(--bg-card);
            padding: 50px 40px;
            border: 1px solid var(--border-card);
            border-top: 2px solid var(--accent-gold);
            box-shadow: var(--shadow-soft);
            transition: all 0.35s ease;
        }

        .feature-number {
            display: block;
            font-size: 1.2rem;
            color: var(--text-secondary);
            margin-bottom: 25px;
            letter-spacing: 0.1em;
        }

        .feature-card h3 {
            font-size: 1.2rem;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            margin-bottom: 15px;
            color: var(--text-primary);
        }

        .feature-card p {
            font-size: 1rem;
            line-height: 1.7;
            color: var(--text-secondary);
        }

        .reviews-section {
            background-color: #1C1A17;
            color: #F7F4EB;
            display: flex;
            justify-content: center;
            align-items: center;
            text-align: center;
            padding: 0 10%;
            height: 100vh;
            scroll-snap-align: start;
        }

        .review-container {
            max-width: 900px;
        }

        .quote-mark {
            display: block;
            font-size: 6rem;
            color: #C5A059;
            line-height: 0.5;
            margin-bottom: 30px;
            font-family: Georgia, serif;
        }

        .review-text {
            font-size: 2.2rem;
            line-height: 1.6;
            font-style: italic;
            margin-bottom: 50px;
            font-weight: 300;
        }

        .reviewer-name {
            font-size: 1rem;
            text-transform: uppercase;
            letter-spacing: 0.25em;
            margin-bottom: 8px;
            color: #C5A059;
        }

        .reviewer-title {
            font-size: 0.85rem;
            color: #8A827A;
            letter-spacing: 0.15em;
            text-transform: uppercase;
        }

        .contact-section {
            background-color: var(--bg-primary);
            color: var(--text-primary);
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
            scroll-snap-align: start;
            padding: 0 10%;
            transition: background-color 0.35s ease, color 0.35s ease;
        }

        .contact-container {
            width: 100%;
            max-width: 600px;
            text-align: center;
        }

        .contact-container h2 {
            font-size: 2.5rem;
            text-transform: uppercase;
            letter-spacing: 0.15em;
            margin-bottom: 15px;
            color: var(--text-primary);
        }

        .contact-container p {
            color: var(--text-secondary);
            font-size: 1rem;
            margin-bottom: 50px;
        }

        .luxury-form {
            display: flex;
            flex-direction: column;
            gap: 30px;
        }

        .luxury-form input,
        .luxury-form select,
        .luxury-form textarea {
            width: 100%;
            background: transparent;
            border: none;
            border-bottom: 1px solid var(--text-secondary);
            padding: 15px 0;
            font-size: 0.85rem;
            letter-spacing: 0.15em;
            color: var(--text-primary);
            outline: none;
            transition: border-color 0.3s ease;
        }

        .luxury-form input:focus,
        .luxury-form textarea:focus {
            border-bottom: 2px solid var(--accent-gold);
        }

        /* --- CUSTOM LUXURY DROPDOWN --- */
        .custom-select-wrapper {
            position: relative;
            width: 100%;
        }

        .custom-select-trigger {
            display: flex;
            justify-content: space-between;
            align-items: center;
            width: 100%;
            background: transparent;
            border: none;
            border-bottom: 1px solid var(--text-secondary);
            padding: 15px 0;
            font-size: 0.85rem;
            letter-spacing: 0.15em;
            color: var(--text-primary);
            cursor: pointer;
            transition: border-color 0.3s ease;
            text-transform: uppercase;
        }

        .custom-select-trigger:focus, .custom-select-wrapper.open .custom-select-trigger {
            border-bottom: 2px solid var(--accent-gold);
            outline: none;
        }

        .custom-options {
            position: absolute;
            display: block;
            top: 100%;
            left: 0;
            right: 0;
            background-color: var(--bg-card);
            border: 1px solid var(--border-color);
            box-shadow: var(--shadow-card);
            z-index: 10;
            opacity: 0;
            visibility: hidden;
            pointer-events: none;
            transform: translateY(-10px);
            transition: all 0.3s ease;
        }

        .custom-select-wrapper.open .custom-options {
            opacity: 1;
            visibility: visible;
            pointer-events: all;
            transform: translateY(0);
        }

        .custom-option {
            padding: 15px 20px;
            font-size: 0.75rem;
            letter-spacing: 0.15em;
            color: var(--text-secondary);
            text-transform: uppercase;
            cursor: pointer;
            transition: all 0.3s ease;
            border-bottom: 1px solid var(--border-subtle);
        }

        .custom-option:last-child {
            border-bottom: none;
        }

        .custom-option:hover {
            color: var(--text-inverse);
            background-color: var(--accent-gold); /* Syndicate Gold Highlight */
        }
        
        /* The hidden actual input that talks to PHP */
        .hidden-select {
            display: none;
        }

        .submit-btn {
            margin-top: 20px;
            background-color: var(--accent-gold);
            color: var(--text-inverse);
            border: 1px solid var(--accent-gold);
            padding: 20px 40px;
            text-transform: uppercase;
            letter-spacing: 0.2em;
            font-size: 0.85rem;
            font-weight: bold;
            cursor: pointer;
            transition: all 0.35s ease;
            box-shadow: 0 4px 15px var(--accent-gold-subtle);
        }

        .submit-btn:hover {
            background-color: transparent;
            color: var(--accent-gold);
            box-shadow: 0 0 25px var(--accent-gold-subtle);
            transform: translateY(-2px);
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
            .about-image {
                flex: 0.4;
                background-image: url('https://images.unsplash.com/photo-1600607688969-a5bfcd646154?q=80&w=1000&auto=format&fit=crop');
            }

            .slide-1-img, .slide-2-img, .slide-3-img {
                background-image: url('https://m.media-amazon.com/images/I/51TWZSk5+aL._AC_UF894,1000_QL80_.jpg');
            }

            nav {
                flex-direction: column;
                padding: 15px;
                gap: 15px;
            }

            nav ul li {
                margin: 0 8px;
                font-size: 0.85rem;
            }

            .slide h2 {
                font-size: 2rem;
                letter-spacing: 0.15em;
            }

            .about-content h2,
            .features-title,
            .contact-container h2 {
                font-size: 1.8rem;
                margin-bottom: 20px;
            }

            .review-text {
                font-size: 1.4rem;
                margin-bottom: 30px;
            }

            .quote-mark {
                font-size: 4rem;
                margin-bottom: 10px;
            }

            .features-title {
                margin-bottom: 30px;
            }

            .about-section {
                flex-direction: column;
            }

            .about-content {
                flex: 0.6;
                padding: 0 5%;
            }

            .features-grid {
                flex-direction: column;
                gap: 20px;
            }

            .feature-card {
                padding: 25px 20px;
            }

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

    <main class="main">
        <div class="slider-container">
            <div class="slider-track">
                <div class="slide slide-1-img">
                    <h2>Exclusive Micro-Auctions</h2>
                </div>
                <div class="slide slide-2-img">
                    <h2>Premium Digital Collectibles</h2>
                </div>
                <div class="slide slide-3-img">
                    <h2>High-Stakes Live Arenas</h2>
                </div>
            </div>

            <div class="slider-controls">
                <button class="nav-btn" onclick="manualNav(-1)">&#10094;</button>
                <button class="nav-btn" onclick="manualNav(1)">&#10095;</button>
            </div>

            <div class="slider-indicators">
                <span class="indicator" onclick="goToSlide(0)"></span>
                <span class="indicator" onclick="goToSlide(1)"></span>
                <span class="indicator" onclick="goToSlide(2)"></span>
            </div>
        </div>

        <section class="about-section">
            <div class="about-image"></div>

            <div class="about-content">
                <h2>The Heritage</h2>
                <p>At Retro-bid, we believe that true exclusivity lies in the details. Founded at the intersection of
                    historic craftsmanship and the digital frontier, our platform curates high-stakes micro-auctions
                    strictly for the discerning collector. From rare mechanical timepieces and automotive masterpieces
                    to premium digital assets and fine art, every artifact in our arena is meticulously vetted for
                    provenance and prestige. We do not just host auctions; we engineer an elite, cinematic marketplace
                    where heritage and the future are won in real-time.</p>
            </div>
        </section>

        <section class="features-section">
            <h2 class="features-title">The Retro-bid Standard</h2>

            <div class="features-grid">
                <div class="feature-card">
                    <span class="feature-number">01</span>
                    <h3>Curated Provenance</h3>
                    <p>Every asset is rigorously authenticated by global heritage experts before entering the live
                        arena.</p>
                </div>

                <div class="feature-card">
                    <span class="feature-number">02</span>
                    <h3>Micro-Stakes</h3>
                    <p>Fractional ownership models allow discerning investors to hold equity in historic masterpieces.
                    </p>
                </div>

                <div class="feature-card">
                    <span class="feature-number">03</span>
                    <h3>Secure Vaulting</h3>
                    <p>Physical assets are preserved in climate-controlled, military-grade facilities globally.</p>
                </div>
            </div>
        </section>

        <section class="reviews-section">
            <div class="review-container">
                <span class="quote-mark">"</span>
                <blockquote class="review-text">An unprecedented curation of horological history. Retro-bid has
                    redefined the digital acquisition of legacy assets.</blockquote>

                <div class="reviewer-info">
                    <p class="reviewer-name">Arthur Pendelton</p>
                    <p class="reviewer-title">Director, The Geneva Syndicate</p>
                </div>
            </div>
        </section>

        <!-- REDESIGNED CLIENT EXPERIENCE / RATING FORM -->
        <section class="contact-section" id="feedback">
            <div class="contact-container">
                <h2>Client Experience</h2>
                <p>We invite you to rate your experience and provide feedback on our curated arenas.</p>

                <?php if ($feedback_success): ?>
                    <div style="padding: 15px; margin-bottom: 30px; background-color: rgba(20, 108, 46, 0.1); color: #146c2e; border: 1px solid #146c2e; font-size: 0.85rem; letter-spacing: 0.1em; text-transform: uppercase; font-weight: bold;">
                        <?php echo htmlspecialchars($feedback_success); ?>
                    </div>
                <?php endif; ?>
                
                <?php if ($feedback_error): ?>
                    <div style="padding: 15px; margin-bottom: 30px; background-color: rgba(153, 0, 0, 0.1); color: #990000; border: 1px solid #990000; font-size: 0.85rem; letter-spacing: 0.1em; text-transform: uppercase; font-weight: bold;">
                        <?php echo htmlspecialchars($feedback_error); ?>
                    </div>
                <?php endif; ?>

                <!-- Form targets the #feedback anchor so page doesn't jump to the top on submit -->
                <form class="luxury-form" method="POST" action="auction.php#feedback">
                    <input type="hidden" name="submit_feedback" value="1">
                    <input type="text" name="name" placeholder="ALIAS / FULL NAME" required>
                    
                    <!-- THE LUXURY CUSTOM SELECT -->
                    <div class="custom-select-wrapper">
                        <!-- This hidden input is what actually gets sent in the POST request -->
                        <input type="hidden" name="rating" id="rating-input" required>
                        
                        <!-- This is what the user clicks -->
                        <div class="custom-select-trigger" id="select-trigger">
                            <span>SELECT A RATING</span>
                            <span style="font-size: 0.7rem;">&#9660;</span> <!-- Down Arrow -->
                        </div>
                        
                        <!-- The stylized dropdown menu -->
                        <div class="custom-options">
                            <div class="custom-option" data-value="5">5 STARS - FLAWLESS EXPERIENCE</div>
                            <div class="custom-option" data-value="4">4 STARS - EXCEPTIONAL</div>
                            <div class="custom-option" data-value="3">3 STARS - SATISFACTORY</div>
                            <div class="custom-option" data-value="2">2 STARS - SUBPAR</div>
                            <div class="custom-option" data-value="1">1 STAR - UNACCEPTABLE</div>
                        </div>
                    </div>

                    <textarea rows="4" name="message" placeholder="YOUR REVIEW OR FEEDBACK" required></textarea>

                    <button type="submit" class="submit-btn" id="submit-review-btn">Submit Review</button>
                </form>
            </div>
        </section>

    </main>

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
        const slides = document.querySelectorAll('.slide');
        const indicators = document.querySelectorAll('.indicator');
        const slideInterval = 5000; 
        let currentSlide = 0;
        let autoSlideTimer;

        if (slides.length > 0) {
            slides[0].classList.add('active');
            indicators[0].classList.add('active');
        }

        function changeSlide(index) {
            slides[currentSlide].classList.remove('active');
            indicators[currentSlide].classList.remove('active');
            
            currentSlide = index;
            
            if (currentSlide >= slides.length) currentSlide = 0; 
            if (currentSlide < 0) currentSlide = slides.length - 1; 
            
            slides[currentSlide].classList.add('active');
            indicators[currentSlide].classList.add('active');
        }

        function manualNav(direction) {
            changeSlide(currentSlide + direction);
            resetTimer(); 
        }

        function goToSlide(index) {
            changeSlide(index);
            resetTimer(); 
        }

        function startAutoSlide() {
            autoSlideTimer = setInterval(() => {
                changeSlide(currentSlide + 1);
            }, slideInterval);
        }

        function resetTimer() {
            clearInterval(autoSlideTimer); 
            startAutoSlide();              
        }

        startAutoSlide();

        // --- CUSTOM SELECT LOGIC ---
        const selectWrapper = document.querySelector('.custom-select-wrapper');
        const selectTrigger = document.querySelector('.custom-select-trigger');
        const triggerText = selectTrigger.querySelector('span');
        const customOptions = document.querySelectorAll('.custom-option');
        const hiddenInput = document.getElementById('rating-input');
        const submitBtn = document.getElementById('submit-review-btn');

        // Toggle dropdown open/close
        selectTrigger.addEventListener('click', function(e) {
            e.stopPropagation(); // Stop click from bleeding to the document
            selectWrapper.classList.toggle('open');
        });

        // Handle option selection
        customOptions.forEach(option => {
            option.addEventListener('click', function(e) {
                e.stopPropagation();
                // 1. Update the visual text
                triggerText.textContent = this.textContent;
                triggerText.style.color = '#1C1A17'; // Make it look 'filled'
                
                // 2. Set the actual hidden input value for PHP
                hiddenInput.value = this.getAttribute('data-value');
                
                // 3. Close the menu
                selectWrapper.classList.remove('open');
            });
        });

        // Close dropdown if clicking anywhere else on the page
        document.addEventListener('click', function() {
            selectWrapper.classList.remove('open');
        });

        // Prevent submission if no rating is selected
        submitBtn.addEventListener('click', function(e) {
            if(hiddenInput.value === "") {
                e.preventDefault(); // Stop form submission
                alert("Please select a rating before submitting.");
                selectTrigger.style.borderBottom = "2px solid #990000"; // Flash red
                setTimeout(() => { selectTrigger.style.borderBottom = "1px solid #8A827A"; }, 1500);
            }
        });

    </script>
</body>

</html>


