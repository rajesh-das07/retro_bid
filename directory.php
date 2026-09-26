<?php
session_start();
require_once 'db_connect.php';

// Fetch only active and upcoming auctions
$stmt = $conn->prepare("
    SELECT a.*, COALESCE(MAX(b.bid_amount), a.current_high_bid) as true_high_bid 
    FROM auctions a 
    LEFT JOIN bids b ON a.auction_id = b.auction_id 
    WHERE a.end_time > NOW()
    GROUP BY a.auction_id
    ORDER BY a.start_time ASC
");
$stmt->execute();
$all_auctions = $stmt->fetchAll(PDO::FETCH_ASSOC);

$current_time = time();

// Arrays to hold the sorted auctions
$all_categories = [];
$auction_category_map = [];
$categorized_auctions = [];
$uncategorized_auctions = [];

try {
    $stmt_cats = $conn->query("SELECT * FROM categories ORDER BY name ASC");
    $all_categories = $stmt_cats->fetchAll(PDO::FETCH_ASSOC);
    
    $stmt_ac = $conn->query("SELECT auction_id, category_id FROM auction_categories");
    while ($row = $stmt_ac->fetch(PDO::FETCH_ASSOC)) {
        $auction_category_map[$row['auction_id']][] = $row['category_id'];
    }
} catch (PDOException $e) {
    // Categories table might not exist yet, ignore gracefully
}

foreach ($all_auctions as $auction) {
    $auction_id = $auction['auction_id'];
    if (isset($auction_category_map[$auction_id]) && !empty($auction_category_map[$auction_id])) {
        foreach ($auction_category_map[$auction_id] as $cat_id) {
            $categorized_auctions[$cat_id][] = $auction;
        }
    } else {
        $uncategorized_auctions[] = $auction;
    }
}

// Helper function to render an artifact card to keep HTML clean
function renderArtifactCard($auction, $status) {
    global $current_time;
    $start_time = strtotime($auction['start_time']);
    $end_time = strtotime($auction['end_time']);
    
    // Determine badge and timer logic based on status
    if ($status === 'live') {
        $badge_html = '<div class="card-badge badge-live"><div class="pulse-dot"></div> Live | Verified by Retro-bid</div>';
        $timer_id = 'timer-' . $auction['auction_id'];
        $timer_target = $end_time;
        $timer_label = 'Time Remaining';
        $btn_text = 'Enter Arena';
        $image_class = '';
        $price_label = 'Current High Bid';
    } elseif ($status === 'upcoming') {
        $badge_html = '<div class="card-badge badge-upcoming">Upcoming | Verified by Retro-bid</div>';
        $timer_id = 'timer-start-' . $auction['auction_id'];
        $timer_target = $start_time;
        $timer_label = 'Starts In';
        $btn_text = 'View Preview';
        $image_class = '';
        $price_label = 'Opening Reserve';
    } else {
        $badge_html = '<div class="card-badge badge-settled">Settled</div>';
        $timer_id = '';
        $timer_target = 0;
        $timer_label = 'Status';
        $btn_text = 'View Results';
        $image_class = 'grayscale';
        $price_label = 'Final Hammer Price';
    }

    $price = number_format($auction['true_high_bid'], 2);
    $title = htmlspecialchars($auction['title']);
    $image_url = htmlspecialchars($auction['image_url']);
    $auction_id = $auction['auction_id'];

    echo "
    <div class='artifact-card'>
        <div class='card-image-wrapper'>
            $badge_html
            <img src='$image_url' alt='$title' class='$image_class'>
        </div>
        <div class='card-content'>
            <h2 class='card-title'>$title</h2>
            
            <div class='card-data'>
                <div class='data-row'>
                    <span class='data-label'>$price_label</span>
                    <span class='data-value'>$$price</span>
                </div>
                <div class='data-row'>";
                    if ($status === 'settled') {
                        echo "<span class='data-label'>Status</span>
                              <span class='data-value' style='color: #8A827A;'>00:00:00</span>";
                    } else {
                        // We use data attributes so Javascript can grab the targets
                        echo "<span class='data-label'>$timer_label</span>
                              <span class='data-value timer-display' id='$timer_id' data-target='$timer_target'>Loading...</span>";
                    }
    echo "      </div>
            </div>
            
            <a href='auction_item.php?id=$auction_id' class='btn-enter'>$btn_text</a>
        </div>
    </div>";
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Retro-bid | Global Directory</title>
    
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
            scroll-behavior: smooth;
        }

        html::-webkit-scrollbar,
        body::-webkit-scrollbar {
            display: none;
        }

        body {
            background-color: var(--bg-primary);
            color: var(--text-primary);
            font-family: sans-serif;
            overflow-x: hidden;
            transition: background-color 0.35s ease, color 0.35s ease;
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
        /* =========================================
           SECTION LAYOUTS
        ========================================= */
        .auction-section {
            padding: 100px 5% 40px 5%;
            height: 100vh;
            display: flex;
            flex-direction: column;
            justify-content: center;
            scroll-snap-align: start;
            transition: background-color 0.35s ease;
        }

        /* Alternate background colors for visual separation */
        .bg-light {
            background-color: var(--bg-primary);
        }

        .bg-darker {
            background-color: var(--bg-secondary);
        }

        .section-title {
            font-size: 1.2rem;
            text-transform: uppercase;
            letter-spacing: 0.2em;
            color: var(--text-primary);
            margin-bottom: 30px;
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .section-title::before {
            content: '';
            display: block;
            width: 40px;
            height: 1px;
            background-color: var(--accent-gold);
        }

        .grid-container {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 30px;
            width: 100%;
            max-width: 1200px;
            margin: 0 auto;
        }

        .empty-state {
            text-align: center;
            color: var(--text-secondary);
            font-style: italic;
            padding: 40px;
            grid-column: 1 / -1;
        }

        /* =========================================
           ARTIFACT CARDS
        ========================================= */
        .artifact-card {
            background-color: var(--bg-card);
            border: 1px solid var(--border-card);
            display: flex;
            flex-direction: column;
            box-shadow: var(--shadow-soft);
            transition: transform 0.4s ease, box-shadow 0.4s ease, background-color 0.35s ease, border-color 0.35s ease;
        }

        .artifact-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-card);
            border-color: var(--border-color);
        }

        .card-image-wrapper {
            position: relative;
            width: 100%;
            height: 220px;
            overflow: hidden;
            background: #11100E;
        }

        .card-image-wrapper img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.7s ease;
        }

        .artifact-card:hover .card-image-wrapper img {
            transform: scale(1.05);
        }

        .grayscale {
            filter: grayscale(100%) contrast(120%);
            opacity: 0.8;
        }

        .card-badge {
            position: absolute;
            top: 20px;
            left: 20px;
            z-index: 2;
            padding: 6px 12px;
            font-size: 0.65rem;
            letter-spacing: 0.15em;
            text-transform: uppercase;
            backdrop-filter: blur(8px);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .badge-live {
            background-color: rgba(28, 26, 23, 0.85);
            color: var(--accent-gold);
            border: 1px solid var(--border-color);
        }

        .badge-upcoming {
            background-color: var(--bg-card-alt);
            color: var(--text-primary);
            border: 1px solid var(--border-subtle);
        }

        .badge-settled {
            background-color: rgba(28, 26, 23, 0.7);
            color: var(--text-secondary);
            border: 1px solid var(--border-subtle);
        }

        .pulse-dot {
            width: 6px;
            height: 6px;
            background-color: var(--accent-gold);
            border-radius: 50%;
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0% {
                box-shadow: 0 0 0 0 rgba(197, 160, 89, 0.7);
            }

            70% {
                box-shadow: 0 0 0 6px rgba(197, 160, 89, 0);
            }

            100% {
                box-shadow: 0 0 0 0 rgba(197, 160, 89, 0);
            }
        }

        .card-content {
            padding: 20px;
            display: flex;
            flex-direction: column;
            flex-grow: 1;
        }

        .verification-tag {
            font-size: 0.65rem;
            color: var(--text-secondary);
            text-transform: uppercase;
            letter-spacing: 0.15em;
            margin-bottom: 10px;
        }

        .card-title {
            font-family: Georgia, serif;
            font-size: 1.2rem;
            color: var(--text-primary);
            margin-bottom: 15px;
            line-height: 1.3;
        }

        .card-data {
            margin-bottom: 15px;
            border-top: 1px solid var(--border-subtle);
            padding-top: 15px;
            margin-top: auto;
        }

        .data-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }

        .data-label {
            font-size: 0.75rem;
            color: var(--text-secondary);
        }

        .data-value {
            font-size: 0.85rem;
            font-weight: bold;
            color: var(--text-primary);
        }

        .timer-display {
            color: var(--accent-gold);
            font-family: monospace;
            font-size: 1rem;
        }

        .btn-enter {
            display: block;
            width: 100%;
            text-align: center;
            padding: 12px 0;
            background-color: transparent;
            border: 1px solid var(--border-color);
            color: var(--text-primary);
            text-transform: uppercase;
            letter-spacing: 0.15em;
            font-size: 0.75rem;
            text-decoration: none;
            transition: all 0.3s ease;
        }

        .btn-enter:hover {
            background-color: var(--accent-gold);
            color: var(--text-inverse);
            border-color: var(--accent-gold);
        }

        /* Specific style for settled buttons */
        .badge-settled~* .btn-enter {
            border-color: var(--border-subtle);
            color: var(--text-secondary);
            background-color: transparent;
        }

        .badge-settled~* .btn-enter:hover {
            border-color: var(--text-primary);
            color: var(--text-primary);
            background-color: transparent;
        }

        .badge-live~* .btn-enter {
            background-color: var(--bg-card-alt);
            color: var(--accent-gold);
            border-color: var(--border-color);
        }

        .badge-live~* .btn-enter:hover {
            background-color: var(--accent-gold);
            color: var(--text-inverse);
            border-color: var(--accent-gold);
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
            .grid-container {
                grid-template-columns: 1fr;
            }

            .footer-content {
                flex-direction: column;
                text-align: center;
                gap: 30px;
            }

            .auction-section {
                padding-top: 100px;
            }

            nav {
                flex-direction: column;
                gap: 15px;
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
                <?php if (isset($_SESSION['user_id'])): ?>
                <li><a href="profile.php" class="nav-alias">
                        <?php echo htmlspecialchars($_SESSION['alias']); ?>
                    </a></li>
                <?php else: ?>
                <li><a href="login.php" class="btn-login">Enter The Vault</a></li>
                <?php endif; ?>
            </ul>
        </nav>
    </header>

    <!-- DIRECTORY HERO SECTION -->
    <section
        style="height: 100vh; display: flex; flex-direction: column; justify-content: center; align-items: center; text-align: center; background-image: url('images/directory.png'); background-size: cover; background-position: center; position: relative; scroll-snap-align: start;">
        <div
            style="position: absolute; top: 0; left: 0; right: 0; bottom: 0; background-color: rgba(28, 26, 23, 0.65); z-index: 1;">
        </div>
        <div style="position: relative; z-index: 2;">
            <p
                style="color: #C5A059; font-size: 0.85rem; letter-spacing: 0.15em; text-transform: uppercase; margin-bottom: 20px;">
                Now Featuring: "The Study"</p>
            <h1
                style="font-size: 4rem; text-transform: uppercase; letter-spacing: 0.15em; color: #F7F4EB; margin-bottom: 20px; text-shadow: 0px 4px 15px rgba(0,0,0,0.5);">
                Global Directory</h1>
            <p style="color: #C5A059; font-size: 1rem; letter-spacing: 0.1em; text-transform: uppercase;">Explore the
                timeline of our curated acquisitions</p>
        </div>
    </section>

    <?php
    $bg_class = 'bg-light';
    
    if (!empty($all_categories)) {
        foreach ($all_categories as $cat) {
            $cat_id = $cat['category_id'];
            $cat_name = htmlspecialchars($cat['name']);
            $cat_auctions = isset($categorized_auctions[$cat_id]) ? $categorized_auctions[$cat_id] : [];
            
            echo "<section class='auction-section $bg_class' id='category-{$cat_id}'>
                <h1 class='section-title'>$cat_name</h1>
                <div class='grid-container'>";
            
            if (empty($cat_auctions)) {
                echo "<div class='empty-state'>The syndicate is currently curating the next selection of artifacts for this class.</div>";
            } else {
                foreach ($cat_auctions as $auction) {
                    $start_time = strtotime($auction['start_time']);
                    $status = ($current_time < $start_time) ? 'upcoming' : 'live';
                    renderArtifactCard($auction, $status);
                }
            }
            
            echo "</div></section>";
            $bg_class = ($bg_class === 'bg-light') ? 'bg-darker' : 'bg-light';
        }
    }
    
    if (!empty($uncategorized_auctions) || empty($all_categories)) {
        echo "<section class='auction-section $bg_class' id='general-section'>
            <h1 class='section-title'>Unclassified Artifacts</h1>
            <div class='grid-container'>";
        
        if (empty($uncategorized_auctions)) {
            echo "<div class='empty-state'>No general artifacts are currently active.</div>";
        } else {
            foreach ($uncategorized_auctions as $auction) {
                $start_time = strtotime($auction['start_time']);
                $status = ($current_time < $start_time) ? 'upcoming' : 'live';
                renderArtifactCard($auction, $status);
            }
        }
        
        echo "</div></section>";
    }
    ?>

    <!-- 3. SETTLED ARCHIVES -->
    <section class="auction-section bg-light" style="height: auto; padding: 60px 5%; text-align: center;" id="settled-section">
        <h1 class="section-title" style="justify-content: center;">The Vault Archives</h1>
        <p style="color: #8A827A; margin-bottom: 30px; letter-spacing: 0.1em; text-transform: uppercase; font-size: 0.85rem;">
            Explore the historical ledger of settled acquisitions.
        </p>
        <a href="vault.php" class="btn-enter" style="display: inline-block; width: auto; padding: 15px 40px; font-weight: bold; background-color: #1C1A17; color: #C5A059;">
            Unlock The Ledger
        </a>
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

    <!-- Global Timer Script -->
    <script>
        function updateTimers() {
            const now = Math.floor(Date.now() / 1000); // Current client time in seconds

            // Find every element with the class 'timer-display'
            document.querySelectorAll('.timer-display').forEach(timerEl => {
                const targetTime = parseInt(timerEl.getAttribute('data-target'));
                let secondsLeft = targetTime - now;

                if (secondsLeft <= 0) {
                    if (!timerEl.classList.contains('reloading')) {
                        timerEl.classList.add('reloading');
                        timerEl.innerText = "00:00:00";
                        
                        // Seamless luxury transition without reloading the entire page
                        setTimeout(() => {
                            fetch(window.location.href)
                                .then(res => res.text())
                                .then(html => {
                                    const parser = new DOMParser();
                                    const doc = parser.parseFromString(html, 'text/html');
                                    
                                    document.querySelectorAll('.auction-section').forEach(el => {
                                        const newEl = doc.getElementById(el.id);
                                        if (newEl) {
                                            el.innerHTML = newEl.innerHTML;
                                        }
                                    });
                                });
                        }, 1000);
                    }
                } else {
                    let h = Math.floor(secondsLeft / 3600).toString().padStart(2, '0');
                    let m = Math.floor((secondsLeft % 3600) / 60).toString().padStart(2, '0');
                    let s = Math.floor(secondsLeft % 60).toString().padStart(2, '0');
                    timerEl.innerText = `${h}:${m}:${s}`;
                }
            });
        }

        // Run immediately, then every second
        updateTimers();
        setInterval(updateTimers, 1000);
    </script>

    <!-- Force reload on Back Button navigation -->
    <script>
        window.addEventListener('pageshow', function (event) {
            if (event.persisted) {
                window.location.reload();
            }
        });
    </script>
</body>

</html>


