<?php
session_start();
require_once 'db_connect.php';

// --- PAGINATION LOGIC ---
$items_per_page = 9; // 3 rows of 3
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $items_per_page;

// 1. Get total number of settled items to calculate pages
$count_stmt = $conn->query("SELECT COUNT(*) FROM auctions WHERE end_time <= NOW()");
$total_items = $count_stmt->fetchColumn();
$total_pages = ceil($total_items / $items_per_page);

// 2. Fetch only the items for the current page
$stmt = $conn->prepare("
    SELECT a.*, COALESCE(MAX(b.bid_amount), a.current_high_bid) as true_high_bid 
    FROM auctions a 
    LEFT JOIN bids b ON a.auction_id = b.auction_id 
    WHERE a.end_time <= NOW()
    GROUP BY a.auction_id
    ORDER BY a.end_time DESC
    LIMIT :limit OFFSET :offset
");
// PDO requires explicit binding for LIMIT/OFFSET
$stmt->bindValue(':limit', $items_per_page, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$settled_auctions = $stmt->fetchAll(PDO::FETCH_ASSOC);

function renderSettledCard($auction) {
    $price = number_format($auction['true_high_bid'], 2);
    $title = htmlspecialchars($auction['title']);
    $image_url = htmlspecialchars($auction['image_url']);
    $auction_id = $auction['auction_id'];

    echo "
    <div class='artifact-card'>
        <div class='card-image-wrapper'>
            <div class='card-badge badge-settled'>Settled</div>
            <img src='$image_url' alt='$title' class='grayscale'>
        </div>
        <div class='card-content'>
            <div class='verification-tag'>Verified Archive</div>
            <h2 class='card-title'>$title</h2>
            
            <div class='card-data'>
                <div class='data-row'>
                    <span class='data-label'>Final Hammer Price</span>
                    <span class='data-value'>$$price</span>
                </div>
                <div class='data-row'>
                    <span class='data-label'>Status</span>
                    <span class='data-value timer-display'>00:00:00</span>
                </div>
            </div>
            <a href='auction_item.php?id=$auction_id' class='btn-enter'>View Results</a>
        </div>
    </div>";
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Retro-bid | The Vault</title>
    
    <!-- Instant Theme Initializer -->
    <script>
        (function() {
            var theme = localStorage.getItem('retro_theme') || '<?php echo isset($_SESSION['theme']) ? htmlspecialchars($_SESSION['theme']) : 'dark'; ?>' || 'dark';
            document.documentElement.setAttribute('data-theme', theme);
        })();
    </script>
    <link rel="stylesheet" href="theme.css">
    
    <style>
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
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            transition: background-color 0.35s ease, color 0.35s ease;
        }

        /* Navigation */
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

        .vault-hero {
            height: 60vh;
            min-height: 400px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            text-align: center;
            padding: 0 5%;
            background-color: #1C1A17;
            background-image: url('https://t3.ftcdn.net/jpg/03/24/85/10/360_F_324851016_d3rowfDjjJSY4qB3w3DCkPEQ26W1500Z.jpg');
            background-size: cover;
            background-position: center;
            position: relative;
        }

        .vault-hero::before {
            content: "";
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: rgba(28, 26, 23, 0.75);
            z-index: 1;
        }

        .vault-hero h1,
        .vault-hero p {
            position: relative;
            z-index: 2;
        }

        .vault-hero h1 {
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

        .vault-hero p {
            color: var(--accent-gold);
            font-size: 1rem;
            letter-spacing: 0.1em;
            text-transform: uppercase;
            max-width: 600px;
            line-height: 1.6;
            text-shadow: 0px 2px 5px rgba(0, 0, 0, 0.8);
        }

        .grid-container {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 30px;
            width: 100%;
            max-width: 1200px;
            margin: 60px auto;
            padding: 0 5%;
            flex-grow: 1;
        }

        /* Artifact card styles */
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
        }

        .badge-settled {
            background-color: rgba(28, 26, 23, 0.7);
            color: var(--text-secondary);
            border: 1px solid var(--border-subtle);
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
            color: var(--text-secondary);
            font-family: monospace;
            font-size: 1rem;
        }

        .btn-enter {
            display: block;
            width: 100%;
            text-align: center;
            padding: 12px 0;
            background-color: var(--bg-primary);
            border: 1px solid var(--border-subtle);
            color: var(--text-secondary);
            text-transform: uppercase;
            letter-spacing: 0.15em;
            font-size: 0.75rem;
            text-decoration: none;
            transition: all 0.3s ease;
        }

        .btn-enter:hover {
            border-color: var(--text-primary);
            color: var(--text-primary);
            background-color: transparent;
        }

        /* Pagination Controls */
        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 20px;
            padding: 40px 0 80px;
        }

        .page-btn {
            padding: 10px 20px;
            border: 1px solid var(--border-color);
            color: var(--text-primary);
            text-decoration: none;
            text-transform: uppercase;
            font-size: 0.75rem;
            letter-spacing: 0.15em;
            transition: all 0.3s ease;
        }

        .page-btn:hover {
            background-color: var(--accent-gold);
            color: var(--text-inverse);
            border-color: var(--accent-gold);
        }

        .page-btn.disabled {
            border-color: var(--border-subtle);
            color: var(--text-secondary);
            pointer-events: none;
            opacity: 0.5;
        }

        .page-info {
            font-size: 0.85rem;
            color: var(--text-secondary);
            letter-spacing: 0.1em;
            text-transform: uppercase;
        }

        @media (max-width: 768px) {
            .grid-container {
                grid-template-columns: 1fr;
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
                <li><a href="profile.php" class="nav-alias"><?php echo htmlspecialchars($_SESSION['alias']); ?></a></li>
                <?php else: ?>
                <li><a href="login.php" class="btn-login">Enter The Vault</a></li>
                <?php endif; ?>
            </ul>
        </nav>
    </header>

    <div class="vault-hero">
        <h1>The Historical Ledger</h1>
        <p>Permanent records of all settled acquisitions</p>
    </div>

    <div class="grid-container">
        <?php 
            if (empty($settled_auctions)) {
                echo "<div style='text-align: center; color: #8A827A; font-style: italic; grid-column: 1 / -1;'>The vault is currently empty.</div>";
            } else {
                foreach ($settled_auctions as $auction) {
                    renderSettledCard($auction);
                }
            }
        ?>
    </div>

    <?php if ($total_pages > 1): ?>
    <div class="pagination">
        <a href="?page=<?php echo $page - 1; ?>"
            class="page-btn <?php echo ($page <= 1) ? 'disabled' : ''; ?>">Previous</a>
        <span class="page-info">Page
            <?php echo $page; ?> of
            <?php echo $total_pages; ?>
        </span>
        <a href="?page=<?php echo $page + 1; ?>"
            class="page-btn <?php echo ($page >= $total_pages) ? 'disabled' : ''; ?>">Next</a>
    </div>
    <?php endif; ?>
</body>

</html>


