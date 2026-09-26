<?php
// MANDATORY: Start the session engine so the navigation bar knows if you are logged in
session_start();
require_once 'db_connect.php';

// 1. URL Security Check: Did they actually click a link, or just type the URL?
if (!isset($_GET['id'])) {
    header("Location: directory.php");
    exit();
}

$auction_id = $_GET['id'];

// 2. Fetch specific artifact from the vault and the true max bid from the ledger
$stmt = $conn->prepare("
    SELECT a.*, COALESCE(MAX(b.bid_amount), a.current_high_bid) as true_high_bid 
    FROM auctions a 
    LEFT JOIN bids b ON a.auction_id = b.auction_id 
    WHERE a.auction_id = :id
    GROUP BY a.auction_id
");
$stmt->bindParam(':id', $auction_id);
$stmt->execute();
$auction = $stmt->fetch(PDO::FETCH_ASSOC);

// 3. Does this item actually exist in the database?
if (!$auction) {
    // They typed a fake ID. Kick them out.
    header("Location: directory.php");
    exit();
}

// 3.5 Fetch the Live Ledger (Bid History)
$stmt_bids = $conn->prepare("
    SELECT b.bid_amount, u.alias 
    FROM bids b 
    JOIN users u ON b.user_id = u.user_id 
    WHERE b.auction_id = :id 
    ORDER BY b.bid_amount DESC 
    LIMIT 10
");
$stmt_bids->bindParam(':id', $auction_id);
$stmt_bids->execute();
$bid_history = $stmt_bids->fetchAll(PDO::FETCH_ASSOC);

// Fetch categories
$item_categories = [];
try {
    $cat_stmt = $conn->prepare("SELECT c.name FROM auction_categories ac JOIN categories c ON ac.category_id = c.category_id WHERE ac.auction_id = :id");
    $cat_stmt->execute([':id' => $auction_id]);
    $item_categories = $cat_stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {}

// Fetch gallery images
$gallery_images = [];
try {
    $gal_stmt = $conn->prepare("SELECT image_url FROM auction_images WHERE auction_id = :id");
    $gal_stmt->execute([':id' => $auction_id]);
    $gallery_images = $gal_stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {}
// 4. Calculate Server-Side Time Remaining
$now = time();
$start_time = strtotime($auction['start_time']);
$end_time = strtotime($auction['end_time']);
$is_upcoming = $now < $start_time;
$is_ended = $now >= $end_time;
$is_live = !$is_upcoming && !$is_ended;

$target_time = $is_upcoming ? $start_time : $end_time;
$seconds_left = $target_time - $now;
if ($seconds_left < 0) $seconds_left = 0;

$h = str_pad(floor($seconds_left / 3600), 2, "0", STR_PAD_LEFT);
$m = str_pad(floor(($seconds_left % 3600) / 60), 2, "0", STR_PAD_LEFT);
$s = str_pad($seconds_left % 60, 2, "0", STR_PAD_LEFT);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Retro-bid | <?php echo htmlspecialchars($auction['title']); ?></title>
    
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

        body {
            background-color: var(--bg-primary);
            color: var(--text-primary);
            font-family: sans-serif;
            overflow-x: hidden;
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
        nav ul li a:hover { color: var(--accent-gold); }
        
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

        .btn-logout {
            border: 1px solid var(--border-subtle);
            padding: 6px 16px;
            border-radius: 2px;
            color: var(--text-secondary) !important;
            font-size: 0.75rem;
            letter-spacing: 0.15em;
            text-transform: uppercase;
            transition: all 0.3s ease !important;
        }

        .btn-logout:hover {
            border-color: var(--accent-gold);
            color: var(--accent-gold) !important;
            background-color: var(--accent-gold-subtle);
        }

        /* =========================================
           ARENA LAYOUT (Split Screen)
        ========================================= */
        .arena-container {
            display: flex;
            padding-top: 80px; 
            min-height: 100vh;
            background-color: var(--bg-primary);
            transition: background-color 0.35s ease;
        }

        /* --- Left Side: Sticky Display --- */
        .artifact-display {
            width: 50%;
            position: sticky;
            top: 80px;
            height: calc(100vh - 80px); 
            padding: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .display-frame {
            width: 100%;
            height: 100%;
            position: relative;
            box-shadow: var(--shadow-card); 
            border: 1px solid var(--border-subtle);
            background-color: var(--bg-card);
        }

        .display-frame img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .status-beacon {
            position: absolute;
            top: 30px;
            left: 30px;
            background-color: rgba(28, 26, 23, 0.85); 
            color: var(--accent-gold);
            padding: 8px 16px;
            font-size: 0.75rem;
            letter-spacing: 0.2em;
            text-transform: uppercase;
            backdrop-filter: blur(8px);
            display: flex;
            align-items: center;
            gap: 10px;
            border: 1px solid var(--border-color);
        }

        .pulse-dot {
            width: 8px;
            height: 8px;
            background-color: var(--accent-gold);
            border-radius: 50%;
            animation: pulse 2s infinite; 
        }

        @keyframes pulse {
            0% { box-shadow: 0 0 0 0 rgba(197, 160, 89, 0.7); }
            70% { box-shadow: 0 0 0 10px rgba(197, 160, 89, 0); }
            100% { box-shadow: 0 0 0 0 rgba(197, 160, 89, 0); }
        }

        /* --- Right Side: The Terminal --- */
        .bidding-terminal {
            width: 50%;
            padding: 60px 8%;
            background-color: var(--bg-card); 
            min-height: 100vh;
            border-left: 1px solid var(--border-subtle);
            transition: background-color 0.35s ease, border-color 0.35s ease;
        }

        .breadcrumbs {
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.15em;
            color: var(--text-secondary); 
            margin-bottom: 20px;
        }

        .breadcrumbs span { color: var(--accent-gold); }

        .artifact-title {
            font-size: 2.8rem;
            line-height: 1.2;
            color: var(--text-primary);
            margin-bottom: 20px;
            font-family: Georgia, serif;
        }

        .artifact-description {
            font-size: 1.05rem;
            line-height: 1.8;
            color: var(--text-secondary); 
            margin-bottom: 40px;
        }

        /* --- Live Data Strip --- */
        .data-strip {
            display: flex;
            justify-content: space-between;
            border-top: 1px solid var(--border-subtle); 
            border-bottom: 1px solid var(--border-subtle);
            padding: 25px 0;
            margin-bottom: 40px;
        }

        .data-block h4 {
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            color: var(--text-secondary);
            margin-bottom: 10px;
        }

        .data-block .current-price {
            font-size: 2.5rem;
            color: var(--text-primary);
            font-weight: bold;
        }

        .data-block .time-left {
            font-size: 2.5rem;
            color: var(--accent-gold);
            font-family: monospace; 
        }

        /* --- Bidding Controls --- */
        .bid-controls {
            display: flex;
            flex-direction: column;
            gap: 20px;
            margin-bottom: 50px;
        }

        .quick-bids { display: flex; gap: 15px; }

        .quick-btn {
            flex: 1;
            padding: 12px 0;
            background: transparent;
            border: 1px solid var(--border-color);
            color: var(--text-primary);
            font-size: 0.85rem;
            letter-spacing: 0.1em;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .quick-btn:hover { background: var(--accent-gold-subtle); border-color: var(--accent-gold); }

        .custom-bid-wrapper { display: flex; gap: 15px; }

        .bid-input {
            flex: 2;
            padding: 20px;
            border: 1px solid var(--border-subtle);
            background: var(--bg-primary);
            font-size: 1.2rem;
            color: var(--text-primary);
            outline: none;
            transition: border-color 0.3s ease;
        }

        .bid-input:focus { border-color: var(--accent-gold); }

        .submit-bid-btn {
            flex: 1;
            background-color: var(--accent-gold);
            color: var(--text-inverse);
            border: none;
            text-transform: uppercase;
            letter-spacing: 0.15em;
            font-size: 0.9rem;
            font-weight: bold;
            cursor: pointer;
            transition: background-color 0.3s ease, color 0.3s ease, box-shadow 0.3s ease;
        }

        .submit-bid-btn:hover { background-color: var(--accent-gold); box-shadow: 0 0 15px var(--accent-gold-subtle); }

        /* --- The Live Ledger (Bid History) --- */
        .ledger-title {
            font-size: 1rem;
            text-transform: uppercase;
            letter-spacing: 0.15em;
            margin-bottom: 20px;
            color: var(--text-primary);
        }

        .ledger-feed { list-style: none; border-top: 1px solid var(--border-subtle); }
        .ledger-entry {
            display: flex;
            justify-content: space-between;
            padding: 15px 0;
            border-bottom: 1px solid var(--border-subtle);
            font-size: 0.9rem;
            color: var(--text-primary);
        }

        .ledger-entry.winning { color: var(--accent-gold); font-weight: bold; }
        .ledger-entry.outbid { color: var(--text-secondary); }

        /* --- Luxury Alert Modal --- */
        .alert-overlay {
            position: fixed; top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(28, 26, 23, 0.85);
            backdrop-filter: blur(5px);
            z-index: 9999;
            display: flex; align-items: center; justify-content: center;
            opacity: 0; visibility: hidden; transition: all 0.3s ease;
        }
        .alert-overlay.active { opacity: 1; visibility: visible; }
        
        .alert-box {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            padding: 40px;
            text-align: center;
            max-width: 400px; width: 90%;
            box-shadow: var(--shadow-card);
            transform: translateY(20px); transition: all 0.3s ease;
        }
        .alert-overlay.active .alert-box { transform: translateY(0); }
        
        .alert-box h3 {
            font-family: Georgia, serif; color: var(--text-primary); font-size: 1.5rem; margin-bottom: 15px;
        }
        .alert-box p {
            color: var(--text-secondary); font-size: 0.9rem; line-height: 1.6; margin-bottom: 30px;
        }
        .alert-btn {
            background: var(--accent-gold); color: var(--text-inverse); border: none; padding: 12px 30px;
            text-transform: uppercase; letter-spacing: 0.15em; font-size: 0.75rem; font-weight: bold;
            cursor: pointer; transition: all 0.3s ease;
        }
        .alert-btn:hover { background: var(--accent-gold); opacity: 0.9; }

        /* =========================================
           MOBILE RESPONSIVENESS
        ========================================= */
        @media (max-width: 1024px) {
            .arena-container { flex-direction: column; padding-top: 60px; }
            .artifact-display {
                width: 100%;
                position: relative;
                top: 0;
                height: 60vh;
                padding: 0; 
            }
            .bidding-terminal { width: 100%; padding: 40px 5%; }
            .data-strip { flex-direction: column; gap: 20px; }
            nav { flex-direction: column; padding: 15px; gap: 15px; }
            nav ul li { margin: 0 8px; font-size: 0.85rem; }
        }
    </style>
</head>

<body>

    <!-- CUSTOM LUXURY ALERT MODAL -->
    <div id="luxury-alert-overlay" class="alert-overlay">
        <div class="alert-box">
            <h3 id="luxury-alert-title">Notification</h3>
            <p id="luxury-alert-message"></p>
            <button id="luxury-alert-btn" class="alert-btn">Acknowledge</button>
        </div>
    </div>

    <header>
        <nav>
            <a href="auction.php" class="logo" style="text-decoration: none;">Retro<span>-bid</span></a>
            <ul>
                <li><a href="auction.php">Home</a></li>
                <li><a href="directory.php">Auctions</a></li>
                <li><a href="sell.php">Sell</a></li>
                <li><a href="contact.php">Contact</a></li>
                <li><a href="about.php">About</a></li>
                <!-- DYNAMIC NAVIGATION LOGIC -->
                <?php if (isset($_SESSION['user_id'])): ?>
                    <li><a href="profile.php" class="nav-alias"><?php echo htmlspecialchars($_SESSION['alias'] ?? 'User'); ?></a></li>
                <?php else: ?>
                    <li><a href="login.php" class="btn-login">Enter The Vault</a></li>
                <?php endif; ?>
            </ul>
        </nav>
    </header>

    <main class="arena-container">
        
        <div class="artifact-display" style="flex-direction: column; padding-top: 0; align-items: stretch; justify-content: flex-start; margin-top: 80px;">
            <div class="display-frame" style="flex: 1; min-height: 0;">
                <?php if ($is_upcoming): ?>
                    <div class="status-beacon" style="background-color: rgba(247, 244, 235, 0.9); color: #1C1A17;">
                        Upcoming Arena
                    </div>
                <?php elseif ($is_ended): ?>
                    <div class="status-beacon" style="background-color: rgba(28, 26, 23, 0.9); color: #8A827A;">
                        Settled Arena
                    </div>
                <?php else: ?>
                    <div class="status-beacon">
                        <div class="pulse-dot"></div> Live Arena
                    </div>
                <?php endif; ?>
                <!-- Dynamic Image injected here -->
                <img id="main-artifact-image" src="<?php echo htmlspecialchars($auction['image_url']); ?>" alt="<?php echo htmlspecialchars($auction['title']); ?>">
            </div>
            
            <?php if (!empty($gallery_images)): ?>
                <div class="gallery-thumbnails" style="display: flex; gap: 15px; margin-top: 20px; width: 100%; overflow-x: auto; padding-bottom: 10px; scrollbar-width: none;">
                    <style>
                        .gallery-thumbnails::-webkit-scrollbar { display: none; }
                        .gallery-thumb { width: 80px; height: 80px; min-width: 80px; object-fit: cover; cursor: pointer; border: 2px solid transparent; transition: border-color 0.3s; opacity: 0.6; }
                        .gallery-thumb:hover { opacity: 1; }
                        .gallery-thumb.active { border-color: #C5A059; opacity: 1; }
                    </style>
                    <!-- The cover image as the first thumbnail -->
                    <img src="<?php echo htmlspecialchars($auction['image_url']); ?>" class="gallery-thumb active" onclick="document.getElementById('main-artifact-image').src = this.src; updateActiveThumb(this);">
                    
                    <!-- The rest of the gallery -->
                    <?php foreach ($gallery_images as $img): ?>
                        <img src="<?php echo htmlspecialchars($img); ?>" class="gallery-thumb" onclick="document.getElementById('main-artifact-image').src = this.src; updateActiveThumb(this);">
                    <?php endforeach; ?>
                </div>
                
                <script>
                    function updateActiveThumb(clickedThumb) {
                        const thumbs = clickedThumb.parentElement.querySelectorAll('.gallery-thumb');
                        thumbs.forEach(t => t.classList.remove('active'));
                        clickedThumb.classList.add('active');
                    }
                </script>
            <?php endif; ?>
        </div>

        <div class="bidding-terminal">
            
            <div class="breadcrumbs">
                Auctions / Premium / <span>Lot <?php echo str_pad($auction['auction_id'], 2, "0", STR_PAD_LEFT); ?></span>
            </div>

            <!-- Dynamic Title injected here -->
            <h1 class="artifact-title"><?php echo htmlspecialchars($auction['title']); ?></h1>
            
            <?php if (!empty($item_categories)): ?>
                <div style="margin-bottom: 20px;">
                    <?php foreach ($item_categories as $cat): ?>
                        <span style="display: inline-block; padding: 4px 10px; border: 1px solid #C5A059; color: #C5A059; font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.15em; border-radius: 2px; margin-right: 10px;">
                            <?php echo htmlspecialchars($cat); ?>
                        </span>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            
            <p class="artifact-description">
                <?php echo nl2br(htmlspecialchars($auction['description'] ?? 'No provenance provided for this artifact.')); ?>
            </p>
            
            <!-- Kept the legal warning as a smaller sub-note -->
            <p style="font-size: 0.75rem; color: #8A827A; letter-spacing: 0.05em; margin-bottom: 40px; text-transform: uppercase;">
                Legal Note: Bids placed are binding and immutable. Verify your available funds.
            </p>

            <div class="data-strip">
                <div class="data-block">
                    <h4><?php echo $is_ended ? "Final Hammer Price" : "Current High Bid"; ?></h4>
                    <!-- Dynamic Price injected here, now using true_high_bid -->
                    <div class="current-price">$<?php echo number_format($auction['true_high_bid'], 2); ?></div>
                </div>
                <div class="data-block">
                    <h4><?php echo $is_upcoming ? "Window Opening" : ($is_ended ? "Status" : "Window Closing"); ?></h4>
                    <!-- Dynamic Timer injected here -->
                    <div class="time-left" id="live-timer" style="<?php echo $is_ended ? 'color: #8A827A;' : ''; ?>">
                        <?php echo $is_ended ? "CLOSED" : "$h:$m:$s"; ?>
                    </div>
                </div>
            </div>

            <div class="bid-controls">
                <?php if ($is_live): ?>
                    <div class="quick-bids">
                        <!-- Added data-amount attributes to pass math securely to JS -->
                        <button class="quick-btn" data-amount="5000">+ $5,000</button>
                        <button class="quick-btn" data-amount="10000">+ $10,000</button>
                        <button class="quick-btn" data-amount="25000">+ $25,000</button>
                    </div>
                    <div class="custom-bid-wrapper">
                        <!-- Default value recalculates based on true_high_bid -->
                        <input type="number" class="bid-input" id="bid-input" placeholder="Custom Amount" value="<?php echo floor($auction['true_high_bid'] + 1000); ?>">
                        <button class="submit-bid-btn" id="submit-bid">Place Bid</button>
                    </div>
                <?php elseif ($is_upcoming): ?>
                    <div style="padding: 20px; background: #EFEBE0; text-align: center; color: #8A827A; letter-spacing: 0.15em; text-transform: uppercase; font-size: 0.85rem; border-radius: 2px;">
                        The vault doors are locked. Bidding opens when the countdown concludes.
                    </div>
                <?php else: ?>
                    <div style="padding: 20px; background: #1C1A17; text-align: center; color: #C5A059; letter-spacing: 0.15em; text-transform: uppercase; font-size: 0.85rem; border-radius: 2px;">
                        This auction has officially concluded.
                    </div>
                <?php endif; ?>
            </div>

            <h3 class="ledger-title">Live Transaction Ledger</h3>
            <ul class="ledger-feed">
                <?php if (empty($bid_history)): ?>
                    <li class="ledger-entry outbid">
                        <span>No bids recorded yet.</span>
                        <span>--</span>
                    </li>
                <?php else: ?>
                    <?php foreach ($bid_history as $index => $bid): ?>
                        <!-- The first item ($index === 0) gets the gold 'winning' color. The rest are grayed out. -->
                        <li class="ledger-entry <?php echo $index === 0 ? 'winning' : 'outbid'; ?>">
                            <span><?php echo htmlspecialchars($bid['alias']); ?> <?php echo $index === 0 ? '(Current)' : ''; ?></span>
                            <span>$<?php echo number_format($bid['bid_amount'], 2); ?></span>
                        </li>
                    <?php endforeach; ?>
                <?php endif; ?>
            </ul>

        </div>
    </main>

    <script>
        // 1. The Timer Engine
        let secondsRemaining = <?php echo $seconds_left; ?>;
        const timeDisplay = document.getElementById('live-timer');

        setInterval(() => {
            if (secondsRemaining <= 0) return;
            secondsRemaining--;
            
            let h = Math.floor(secondsRemaining / 3600).toString().padStart(2, '0');
            let m = Math.floor((secondsRemaining % 3600) / 60).toString().padStart(2, '0');
            let s = (secondsRemaining % 60).toString().padStart(2, '0');
            
            timeDisplay.innerText = `${h}:${m}:${s}`;

            if (secondsRemaining === 0) {
                <?php if ($is_upcoming): ?>
                    // If the "starts in" countdown hits 0, refresh the page to unlock bidding!
                    location.reload(); 
                <?php else: ?>
                    timeDisplay.innerText = "CLOSED";
                    timeDisplay.style.color = "#8A827A";
                    
                    // Trigger the final luxury announcement
                    if (typeof window.auctionAnnounced === 'undefined') {
                        window.auctionAnnounced = true;
                        
                        fetch('fetch_live_data.php?id=<?php echo $auction['auction_id']; ?>')
                        .then(res => res.json())
                        .then(finalData => {
                            let msg = "The arena has closed.";
                            if (finalData.history && finalData.history.length > 0) {
                                const winner = finalData.history[0].alias;
                                const amount = parseFloat(finalData.true_high_bid).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
                                msg = `Acquired by ${winner} for $${amount}. Escrow is locked for settlement.`;
                            } else {
                                msg = "The artifact remains unsold.";
                            }
                            showLuxuryAlert("Arena Closed", msg, () => location.reload());
                        }).catch(() => {
                            showLuxuryAlert("Arena Closed", "The auction has concluded.", () => location.reload());
                        });
                    }
                <?php endif; ?>
            }
        }, 1000);

        // 2. The Quick Bid Engine
        const quickBtns = document.querySelectorAll('.quick-btn');
        const bidInput = document.getElementById('bid-input');

        quickBtns.forEach(btn => {
            btn.addEventListener('click', (e) => {
                // Grab the raw number from the data-amount attribute
                const amountToAdd = parseInt(e.target.getAttribute('data-amount'));
                // Add it to whatever is currently in the input box
                const currentInputValue = parseInt(bidInput.value) || 0;
                bidInput.value = currentInputValue + amountToAdd;
            });
        });

        // 3. The Backend Communication Engine (AJAX/Fetch)
        const submitBtn = document.getElementById('submit-bid');
        const minIncrement = <?php 
            $min_inc = 200; 
            if (file_exists('settings.json')) {
                $set_data = json_decode(file_get_contents('settings.json'), true);
                if (isset($set_data['min_bid_increment'])) $min_inc = $set_data['min_bid_increment'];
            }
            echo $min_inc; 
        ?>;

        submitBtn.addEventListener('click', () => {
            const finalBid = parseFloat(bidInput.value);
            const auctionId = <?php echo $auction['auction_id']; ?>;

            // Basic frontend check before bothering the server, now enforcing dynamic minimum
            if (isNaN(finalBid) || finalBid < (<?php echo $auction['true_high_bid']; ?> + minIncrement)) {
                showLuxuryAlert("Transaction Denied", `Your bid must be at least $${minIncrement} higher than the current bid.`);
                return;
            }

            // Disable the button so they can't double-click and send two bids
            submitBtn.innerText = "Processing...";
            submitBtn.disabled = true;

            // Fire the data to our secure PHP processing script
            fetch('process_bid.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    auction_id: auctionId,
                    bid_amount: finalBid
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // It worked. Tell the user and refresh the page to show the new price.
                    showLuxuryAlert("Transaction Successful", data.message, () => location.reload());
                } else {
                    // The server rejected it (e.g., insufficient funds, auction ended)
                    showLuxuryAlert("Transaction Denied", data.message);
                    submitBtn.innerText = "Place Bid";
                    submitBtn.disabled = false;
                }
            })
            .catch(error => {
                showLuxuryAlert("Connection Error", "Could not connect to the transaction vault.");
                submitBtn.innerText = "Place Bid";
                submitBtn.disabled = false;
            });
        });

        // Custom Alert Engine
        const alertOverlay = document.getElementById('luxury-alert-overlay');
        const alertTitle = document.getElementById('luxury-alert-title');
        const alertMessage = document.getElementById('luxury-alert-message');
        const alertBtn = document.getElementById('luxury-alert-btn');
        let alertCallback = null;

        function showLuxuryAlert(title, message, callback = null) {
            alertTitle.innerText = title;
            alertMessage.innerText = message;
            alertCallback = callback;
            alertOverlay.classList.add('active');
        }

        alertBtn.addEventListener('click', () => {
            alertOverlay.classList.remove('active');
            if (alertCallback) {
                alertCallback();
                alertCallback = null;
            }
        });

        // 4. LIVE ARENA SYNC (Silently checks for rival bids every 2 seconds)
        setInterval(() => {
            fetch('fetch_live_data.php?id=<?php echo $auction['auction_id']; ?>')
            .then(response => response.json())
            .then(data => {
                if (data.error) {
                    showLuxuryAlert("Arena Terminated", "This auction has been voided by the Syndicate.", () => {
                        window.location.href = 'directory.php';
                    });
                    return;
                }
                
                // Update the big price display dynamically
                const priceDisplay = document.querySelector('.current-price');
                const formattedPrice = parseFloat(data.true_high_bid).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
                priceDisplay.innerText = '$' + formattedPrice;
                
                // Re-build the live ledger feed if new bids exist
                const ledgerFeed = document.querySelector('.ledger-feed');
                if (data.history.length > 0) {
                    let newHtml = '';
                    data.history.forEach((bid, index) => {
                        // The top bid gets the gold winning style
                        const statusClass = index === 0 ? 'winning' : 'outbid';
                        const currentTag = index === 0 ? ' (Current)' : '';
                        const bidAmount = parseFloat(bid.bid_amount).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
                        
                        newHtml += `<li class="ledger-entry ${statusClass}">
                            <span>${bid.alias}${currentTag}</span>
                            <span>$${bidAmount}</span>
                        </li>`;
                    });
                    ledgerFeed.innerHTML = newHtml;
                }
                
                // --- NEW: TIME SYNCHRONIZATION ---
                // Update the master countdown variable with the absolute server truth
                if (data.seconds_remaining !== undefined) {
                    secondsRemaining = parseInt(data.seconds_remaining);
                }
            })
            .catch(err => console.error("Sync error:", err));
        }, 2000); // 2000 milliseconds = 2 seconds
    </script>
</body>
</html>


