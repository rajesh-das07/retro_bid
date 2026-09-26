<?php
session_start();
require_once 'db_connect.php';

// 1. SECURITY GATE: Kick out anyone who isn't logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id = (int)$_SESSION['user_id'];

// 2. Fetch Core User Data (Balance, Email, etc.)
$stmt = $conn->prepare("SELECT alias, email, available_funds FROM users WHERE user_id = :uid");
$stmt->execute([':uid' => $user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    // Edge Case: If the database record was deleted but the session survived
    session_destroy();
    header("Location: login.php");
    exit();
}

// 2.5 Handle Hammer Action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'hammer') {
    $hammer_auction_id = (int)$_POST['auction_id'];
    
    // Verify this auction belongs to this user and is currently live
    $check = $conn->prepare("SELECT auction_id FROM auctions WHERE auction_id = :aid AND seller_id = :uid AND end_time > NOW() AND start_time <= NOW()");
    $check->execute([':aid' => $hammer_auction_id, ':uid' => $user_id]);
    
    if ($check->fetch()) {
        // Hammer it! Set end time to exactly now.
        $hammer = $conn->prepare("UPDATE auctions SET end_time = NOW() WHERE auction_id = :aid");
        $hammer->execute([':aid' => $hammer_auction_id]);
    }
    
    header("Location: profile.php");
    exit();
}

// 3. Fetch Participated Auctions (Grouped by auction)
$stmt_auctions = $conn->prepare("
    SELECT 
        a.auction_id, 
        a.title, 
        a.end_time, 
        a.current_high_bid,
        MAX(b.bid_amount) as user_max_bid,
        MAX(b.created_at) as last_interaction
    FROM bids b 
    JOIN auctions a ON b.auction_id = a.auction_id 
    WHERE b.user_id = :uid 
    GROUP BY a.auction_id
    ORDER BY last_interaction DESC
");
$stmt_auctions->execute([':uid' => $user_id]);
$participated_auctions = $stmt_auctions->fetchAll(PDO::FETCH_ASSOC);

// 4. THE MISSING LOGIC: Fetch Pending & Approved Consignments
$stmt_pending = $conn->prepare("SELECT * FROM submissions WHERE user_id = :uid ORDER BY created_at DESC");
$stmt_pending->execute([':uid' => $user_id]);
$pending_items = $stmt_pending->fetchAll(PDO::FETCH_ASSOC);

$stmt_approved = $conn->prepare("SELECT * FROM auctions WHERE seller_id = :uid ORDER BY end_time DESC");
$stmt_approved->execute([':uid' => $user_id]);
$approved_items = $stmt_approved->fetchAll(PDO::FETCH_ASSOC);

// 5. Fetch Categorized Ledger & Stats (Excluding intermediate bid escrow holds/releases)
$ledger_entries = [];
$total_inflow = 0;
$total_outflow = 0;
$count_deposits = 0;
$count_acquisitions = 0;
$count_payouts = 0;
$count_fees = 0;

try {
    $stmt_ledger = $conn->prepare("
        SELECT t.*, a.title, a.seller_id 
        FROM transaction_ledger t 
        LEFT JOIN auctions a ON t.auction_id = a.auction_id 
        WHERE t.user_id = :uid 
          AND t.type NOT IN ('escrow_hold', 'escrow_release')
        ORDER BY t.created_at DESC 
        LIMIT 100
    ");
    $stmt_ledger->execute([':uid' => $user_id]);
    $ledger_entries = $stmt_ledger->fetchAll(PDO::FETCH_ASSOC);

    foreach ($ledger_entries as &$entry) {
        $amt = (float)$entry['amount'];
        $type = $entry['type'];
        
        // Map financial categories & flows
        if ($type === 'deposit') {
            $entry['category'] = 'deposits';
            $entry['flow'] = 'credit';
            $entry['type_label'] = 'Direct Deposit';
            $entry['badge_class'] = 'badge-deposit';
            $total_inflow += $amt;
            $count_deposits++;
        } elseif ($type === 'payment') {
            // Check if user is the seller (receiving payout) or winning buyer (paying acquisition)
            if ($entry['auction_id'] && (int)$entry['seller_id'] === (int)$user_id) {
                $entry['category'] = 'payouts';
                $entry['flow'] = 'credit';
                $entry['type_label'] = 'Consignment Payout';
                $entry['badge_class'] = 'badge-payout';
                $total_inflow += $amt;
                $count_payouts++;
            } else {
                $entry['category'] = 'acquisitions';
                $entry['flow'] = 'debit';
                $entry['type_label'] = 'Won Acquisition';
                $entry['badge_class'] = 'badge-settlement';
                $total_outflow += $amt;
                $count_acquisitions++;
            }
        } elseif ($type === 'fee' || $type === '' || $type === 'buyer_fee' || $type === 'seller_fee') {
            $entry['category'] = 'fees';
            $entry['flow'] = 'debit';
            if ($entry['auction_id'] && (int)$entry['seller_id'] === (int)$user_id) {
                $entry['type_label'] = 'Seller Commission';
            } else {
                $entry['type_label'] = 'Buyer Premium';
            }
            $entry['badge_class'] = 'badge-fee';
            $total_outflow += $amt;
            $count_fees++;
        } elseif ($type === 'withdrawal') {
            $entry['category'] = 'fees';
            $entry['flow'] = 'debit';
            $entry['type_label'] = 'Capital Withdrawal';
            $entry['badge_class'] = 'badge-withdrawal';
            $total_outflow += $amt;
            $count_fees++;
        } else {
            $entry['category'] = 'other';
            $entry['flow'] = 'credit';
            $entry['type_label'] = ucwords(str_replace('_', ' ', $type));
            $entry['badge_class'] = 'badge-fee';
        }
    }
    unset($entry);
} catch (PDOException $e) {}


// Calculate Stats
$total_participated = count($participated_auctions);
$total_won = 0;
$total_consignments = count($pending_items) + count($approved_items);

foreach ($participated_auctions as $auction) {
    $is_ended = time() >= strtotime($auction['end_time']);
    $is_highest = $auction['user_max_bid'] >= $auction['current_high_bid'];
    if ($is_ended && $is_highest) {
        $total_won++;
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Retro-bid | Private Ledger</title>
    
    <!-- Instant Theme Initializer to prevent flash of wrong theme -->
    <script>
        (function() {
            var theme = localStorage.getItem('retro_theme') || '<?php echo isset($_SESSION['theme']) ? htmlspecialchars($_SESSION['theme']) : 'dark'; ?>' || 'dark';
            document.documentElement.setAttribute('data-theme', theme);
        })();
    </script>
    <link rel="stylesheet" href="theme.css">
    
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        
        html, body {
            scrollbar-width: none;
            -ms-overflow-style: none;
        }

        ::-webkit-scrollbar,
        html::-webkit-scrollbar,
        body::-webkit-scrollbar {
            display: none;
        }

        body { 
            background-color: var(--bg-primary); 
            color: var(--text-primary); 
            font-family: sans-serif; 
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            transition: background-color 0.35s ease, color 0.35s ease;
        }

        /* =========================================
           PROFILE LUXURY NAVIGATION BAR
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
            padding: 18px 5%;
            border-bottom: 1px solid var(--border-color);
            font-family: sans-serif;
            transition: background-color 0.35s ease, border-color 0.35s ease;
        }

        .logo { 
            font-size: 22px; 
            color: var(--text-primary); 
            font-family: Georgia, serif;
            letter-spacing: 0.05em; 
            transition: color 0.35s ease;
        }
        .logo span { 
            color: var(--accent-gold); 
            font-style: italic;
        }

        nav ul { 
            list-style: none; 
            display: flex; 
            margin: 0; 
            padding: 0; 
            align-items: center; 
        }
        nav ul li { 
            margin: 0 12px; 
        }
        nav ul li a { 
            color: var(--text-secondary); 
            text-decoration: none; 
            font-size: 0.8rem !important; 
            font-weight: 500 !important; 
            letter-spacing: 0.08em !important;
            text-transform: uppercase !important;
            transition: color 0.3s ease; 
        }
        nav ul li a:hover { 
            color: var(--accent-gold); 
        }
        
        .nav-alias { 
            color: var(--accent-gold) !important; 
            font-size: 0.75rem !important; 
            font-weight: 600 !important;
            letter-spacing: 0.15em !important; 
            text-transform: uppercase !important; 
            display: flex !important;
            align-items: center !important; 
            gap: 8px !important; 
            margin-left: 15px !important;
            padding: 6px 14px !important;
            background: var(--accent-gold-subtle) !important;
            border: 1px solid var(--border-color) !important;
            border-radius: 2px !important;
            text-decoration: none !important; 
            transition: all 0.3s ease !important;
        }
        .nav-alias:hover { 
            background: rgba(197, 160, 89, 0.2) !important;
            box-shadow: 0 0 12px rgba(197, 160, 89, 0.25) !important;
            color: var(--text-primary) !important;
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

        .btn-logout-luxury {
            display: inline-block; padding: 12px 24px; font-size: 0.75rem; letter-spacing: 0.2em;
            text-transform: uppercase; color: var(--text-secondary); background-color: transparent;
            border: 1px solid var(--border-subtle); text-decoration: none; transition: all 0.4s ease;
        }
        .btn-logout-luxury:hover {
            color: var(--text-inverse); background-color: var(--accent-gold); border-color: var(--accent-gold); box-shadow: 0 0 15px var(--accent-gold-subtle);
        }

        /* --- Profile Dashboard Layout --- */
        .dashboard-container {
            padding: 120px 5% 80px 5%;
            max-width: 1400px; margin: 0 auto; width: 100%; flex-grow: 1;
        }

        .dashboard-header {
            margin-bottom: 50px; border-bottom: 1px solid var(--border-subtle); padding-bottom: 30px;
        }
        .dashboard-header h1 { font-size: 2.5rem; font-family: Georgia, serif; color: var(--text-primary); margin-bottom: 10px; }
        .dashboard-header p { color: var(--text-secondary); letter-spacing: 0.1em; text-transform: uppercase; font-size: 0.85rem; }

        .stats-grid {
            display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 30px; margin-bottom: 50px;
        }

        .stat-card { 
            background-color: var(--bg-card); 
            padding: 30px; 
            border: 1px solid var(--border-card); 
            box-shadow: var(--shadow-soft);
            transition: all 0.35s ease;
        }
        .stat-card:hover {
            border-color: var(--border-color);
            transform: translateY(-2px);
        }
        .stat-label { color: var(--text-secondary); font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.15em; margin-bottom: 15px; display: block; }
        .stat-value { color: var(--accent-gold); font-size: 2rem; font-weight: bold; }
        .stat-value.text-val { color: var(--text-primary); font-size: 1.5rem; font-weight: normal; }

        /* =========================================
           LUXURY THEME SWITCHER COMPONENT
        ========================================= */
        .theme-setting-card {
            background-color: var(--bg-card);
            border: 1px solid var(--border-card);
            padding: 35px;
            margin-bottom: 60px;
            box-shadow: var(--shadow-soft);
            transition: all 0.35s ease;
        }
        .theme-setting-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            border-bottom: 1px solid var(--border-subtle);
            padding-bottom: 15px;
            flex-wrap: wrap;
            gap: 15px;
        }
        .theme-setting-header h2 {
            font-family: Georgia, serif;
            font-size: 1.35rem;
            color: var(--text-primary);
            letter-spacing: 0.05em;
            margin-bottom: 5px;
        }
        .theme-setting-header p {
            color: var(--text-secondary);
            font-size: 0.85rem;
            letter-spacing: 0.05em;
        }
        .theme-status-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.15em;
            padding: 6px 14px;
            background: var(--accent-gold-subtle);
            color: var(--accent-gold);
            border: 1px solid var(--border-color);
            font-weight: 600;
        }

        .theme-options-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 25px;
        }

        .theme-option-box {
            border: 1px solid var(--border-subtle);
            background: var(--bg-secondary);
            padding: 20px;
            cursor: pointer;
            transition: all 0.3s ease;
            position: relative;
            user-select: none;
        }
        .theme-option-box:hover {
            border-color: var(--accent-gold);
            transform: translateY(-3px);
            box-shadow: var(--shadow-card);
        }
        .theme-option-box.active {
            border-color: var(--accent-gold);
            background: var(--bg-card-alt);
            box-shadow: 0 0 20px var(--accent-gold-subtle);
        }
        .theme-preview {
            height: 80px;
            border: 1px solid rgba(128,128,128,0.2);
            margin-bottom: 15px;
            padding: 10px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            border-radius: 2px;
            overflow: hidden;
        }
        .theme-preview-dark {
            background-color: #1C1A17;
        }
        .theme-preview-dark .preview-nav {
            height: 10px;
            background: rgba(255,255,255,0.08);
            border-bottom: 1px solid #C5A059;
            width: 100%;
        }
        .theme-preview-dark .preview-chip {
            height: 20px;
            width: 50%;
            background: #11100E;
            border: 1px solid rgba(197, 160, 89, 0.3);
        }
        .theme-preview-light {
            background-color: #F7F4EB;
        }
        .theme-preview-light .preview-nav {
            height: 10px;
            background: #EFEBE0;
            border-bottom: 1px solid #9E7B35;
            width: 100%;
        }
        .theme-preview-light .preview-chip {
            height: 20px;
            width: 50%;
            background: #FFFFFF;
            border: 1px solid rgba(158, 123, 53, 0.3);
        }

        .theme-option-info {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }
        .theme-option-title {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 0.95rem;
            font-weight: 600;
            color: var(--text-primary);
            letter-spacing: 0.05em;
        }
        .radio-dot {
            width: 14px;
            height: 14px;
            border-radius: 50%;
            border: 2px solid var(--text-secondary);
            display: inline-block;
            transition: all 0.2s ease;
        }
        .theme-option-box.active .radio-dot {
            border-color: var(--accent-gold);
            background-color: var(--accent-gold);
            box-shadow: 0 0 8px var(--accent-gold);
        }
        .theme-option-desc {
            font-size: 0.8rem;
            color: var(--text-secondary);
            line-height: 1.4;
        }

        /* --- Table Layouts --- */
        .history-section { margin-bottom: 80px; }
        .history-section h2 { 
            font-size: 1.2rem; 
            text-transform: uppercase; 
            letter-spacing: 0.15em; 
            color: var(--text-primary); 
            margin-bottom: 30px; 
            border-bottom: 1px solid var(--border-subtle);
            padding-bottom: 15px;
        }

        .history-table { width: 100%; border-collapse: collapse; background: var(--bg-card); }
        .history-table th, .history-table td { text-align: left; padding: 16px; border-bottom: 1px solid var(--table-row-border); }
        .history-table th { 
            color: var(--text-secondary); 
            background: var(--table-header-bg);
            font-size: 0.75rem; 
            text-transform: uppercase; 
            letter-spacing: 0.15em; 
            font-weight: normal; 
            border-bottom: 1px solid var(--border-color);
        }
        .history-table td { color: var(--text-primary); font-size: 0.95rem; }
        .history-table td a { color: var(--accent-gold); text-decoration: none; transition: opacity 0.3s; }
        .history-table td a:hover { opacity: 0.7; }
        .history-table tr:hover td { background-color: var(--table-hover); }
        
        .empty-state { color: var(--text-secondary); padding: 30px 0; font-style: italic; }

        /* =========================================
           CATEGORIZED FINANCIAL LEDGER SUITE
        ========================================= */
        .ledger-suite-container {
            background-color: var(--bg-card);
            border: 1px solid var(--border-card);
            padding: 35px;
            box-shadow: var(--shadow-soft);
            transition: all 0.35s ease;
            margin-bottom: 80px;
        }

        .ledger-suite-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 25px;
            flex-wrap: wrap;
            gap: 20px;
        }

        .ledger-title-group h2 {
            font-family: Georgia, serif;
            font-size: 1.4rem;
            color: var(--text-primary);
            letter-spacing: 0.05em;
            margin-bottom: 6px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .ledger-title-group p {
            color: var(--text-secondary);
            font-size: 0.85rem;
            letter-spacing: 0.05em;
        }

        .ledger-summary-pills {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }

        .ledger-summary-pill {
            display: flex;
            flex-direction: column;
            padding: 8px 16px;
            background: var(--bg-secondary);
            border: 1px solid var(--border-subtle);
            border-radius: 2px;
            min-width: 125px;
        }
        .ledger-summary-pill .lbl {
            font-size: 0.65rem;
            text-transform: uppercase;
            letter-spacing: 0.12em;
            color: var(--text-secondary);
            margin-bottom: 3px;
        }
        .ledger-summary-pill .val {
            font-size: 1.05rem;
            font-weight: 700;
            letter-spacing: 0.03em;
        }
        .ledger-summary-pill .val.inflow { color: #2e8540; }
        [data-theme="dark"] .ledger-summary-pill .val.inflow { color: #4cd964; }
        .ledger-summary-pill .val.outflow { color: #b32424; }
        [data-theme="dark"] .ledger-summary-pill .val.outflow { color: #e55050; }
        .ledger-summary-pill .val.net { color: var(--accent-gold); }

        /* Categorization Tabs Bar */
        .ledger-tabs-scroller {
            overflow-x: auto;
            border-bottom: 1px solid var(--border-subtle);
            margin-bottom: 22px;
            padding-bottom: 2px;
            scrollbar-width: none;
        }
        .ledger-tabs-scroller::-webkit-scrollbar { display: none; }

        .ledger-tabs-list {
            display: flex;
            gap: 6px;
            min-width: max-content;
        }

        .ledger-tab-btn {
            background: transparent;
            border: 1px solid transparent;
            border-bottom: 2px solid transparent;
            color: var(--text-secondary);
            padding: 10px 16px;
            font-size: 0.78rem;
            font-weight: 600;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: all 0.25s ease;
            position: relative;
        }
        .ledger-tab-btn:hover {
            color: var(--accent-gold);
            background: var(--accent-gold-subtle);
        }
        .ledger-tab-btn.active {
            color: var(--accent-gold);
            background: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-bottom: 2px solid var(--accent-gold);
        }
        .ledger-tab-count {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 0.7rem;
            padding: 2px 7px;
            border-radius: 10px;
            background: rgba(128,128,128,0.15);
            color: var(--text-primary);
            border: 1px solid var(--border-subtle);
        }
        .ledger-tab-btn.active .ledger-tab-count {
            background: var(--accent-gold);
            color: var(--text-inverse);
            border-color: var(--accent-gold);
            font-weight: 700;
        }

        /* Filter & Search Bar */
        .ledger-filter-toolbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 15px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }

        .ledger-search-box {
            position: relative;
            flex: 1;
            min-width: 250px;
            max-width: 400px;
        }
        .ledger-search-icon {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-secondary);
            pointer-events: none;
            width: 14px;
            height: 14px;
        }
        .ledger-search-input {
            width: 100%;
            background: var(--bg-secondary);
            border: 1px solid var(--border-subtle);
            color: var(--text-primary);
            padding: 9px 14px 9px 38px;
            font-size: 0.85rem;
            outline: none;
            transition: all 0.3s ease;
        }
        .ledger-search-input:focus {
            border-color: var(--accent-gold);
            box-shadow: 0 0 12px var(--accent-gold-subtle);
        }
        .ledger-search-input::placeholder {
            color: var(--text-muted);
        }

        .ledger-toolbar-right {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }

        .ledger-flow-filters {
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .ledger-flow-btn {
            background: var(--bg-secondary);
            border: 1px solid var(--border-subtle);
            color: var(--text-secondary);
            padding: 8px 12px;
            font-size: 0.72rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            cursor: pointer;
            transition: all 0.25s ease;
        }
        .ledger-flow-btn:hover {
            color: var(--accent-gold);
            border-color: var(--border-color);
        }
        .ledger-flow-btn.active {
            background: var(--accent-gold-subtle);
            color: var(--accent-gold);
            border-color: var(--accent-gold);
        }

        .ledger-export-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: transparent;
            border: 1px solid var(--border-color);
            color: var(--accent-gold);
            padding: 8px 14px;
            font-size: 0.72rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        .ledger-export-btn:hover {
            background: var(--accent-gold);
            color: var(--text-inverse);
            box-shadow: 0 0 15px var(--accent-gold-subtle);
        }

        /* Luxury Badges for Categories */
        .ledger-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 10px;
            font-size: 0.72rem;
            font-weight: 600;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            border-radius: 2px;
            border: 1px solid transparent;
            white-space: nowrap;
        }
        .ledger-badge svg { flex-shrink: 0; }

        /* Deposit - Emerald */
        .badge-deposit {
            background: rgba(46, 133, 64, 0.15);
            color: #4cd964;
            border-color: rgba(76, 217, 100, 0.3);
        }
        [data-theme="light"] .badge-deposit {
            background: rgba(46, 133, 64, 0.12);
            color: #1f6b30;
            border-color: rgba(46, 133, 64, 0.3);
        }

        /* Consignment Payout - Emerald/Gold */
        .badge-payout {
            background: rgba(46, 133, 64, 0.2);
            color: #5cdb84;
            border-color: rgba(92, 219, 132, 0.4);
        }
        [data-theme="light"] .badge-payout {
            background: rgba(46, 133, 64, 0.15);
            color: #1e7033;
            border-color: rgba(46, 133, 64, 0.35);
        }

        /* Won Acquisition Settlement - Imperial Gold */
        .badge-settlement {
            background: rgba(229, 169, 60, 0.2);
            color: #ffc857;
            border-color: rgba(255, 200, 87, 0.4);
        }
        [data-theme="light"] .badge-settlement {
            background: rgba(184, 134, 11, 0.15);
            color: #8b6508;
            border-color: rgba(184, 134, 11, 0.3);
        }

        /* Platform Commission / Fee - Muted Bronze */
        .badge-fee {
            background: rgba(138, 130, 122, 0.15);
            color: var(--text-secondary);
            border-color: rgba(138, 130, 122, 0.3);
        }

        /* Withdrawal - Crimson */
        .badge-withdrawal {
            background: rgba(179, 36, 36, 0.15);
            color: #e55050;
            border-color: rgba(229, 80, 80, 0.3);
        }

        /* Table Specifics */
        .ledger-table-wrap {
            overflow-x: auto;
            border: 1px solid var(--border-subtle);
        }
        .ledger-table {
            width: 100%;
            border-collapse: collapse;
            background: var(--bg-card);
        }
        .ledger-table th {
            color: var(--text-secondary);
            background: var(--table-header-bg);
            font-size: 0.72rem;
            text-transform: uppercase;
            letter-spacing: 0.15em;
            font-weight: 600;
            padding: 14px 18px;
            border-bottom: 1px solid var(--border-color);
            text-align: left;
        }
        .ledger-table td {
            padding: 16px 18px;
            border-bottom: 1px solid var(--table-row-border);
            font-size: 0.88rem;
            color: var(--text-primary);
            vertical-align: middle;
        }
        .ledger-table td a {
            color: var(--accent-gold);
            text-decoration: none;
            transition: opacity 0.3s;
        }
        .ledger-table td a:hover {
            opacity: 0.7;
        }
        .ledger-table tr:hover td {
            background-color: var(--table-hover);
        }
        .ledger-table tr.hidden-row {
            display: none !important;
        }

        .txn-ref {
            font-family: monospace;
            color: var(--text-secondary);
            font-size: 0.8rem;
            letter-spacing: 0.05em;
        }

        .txn-amt {
            font-weight: 700;
            font-size: 0.95rem;
            letter-spacing: 0.03em;
        }
        .txn-amt.credit { color: #2e8540; }
        [data-theme="dark"] .txn-amt.credit { color: #4cd964; }
        .txn-amt.debit { color: #b32424; }
        [data-theme="dark"] .txn-amt.debit { color: #e55050; }

        .btn-view-voucher {
            background: transparent;
            border: 1px solid var(--border-subtle);
            color: var(--text-secondary);
            padding: 6px 12px;
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            cursor: pointer;
            transition: all 0.25s ease;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .btn-view-voucher:hover {
            color: var(--accent-gold);
            border-color: var(--accent-gold);
            background: var(--accent-gold-subtle);
        }

        /* Voucher Receipt Modal */
        .voucher-modal-backdrop {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.82);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
            z-index: 10000;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .voucher-modal-backdrop.open {
            display: flex;
        }

        .voucher-modal-card {
            background: var(--bg-card);
            border: 1px solid var(--accent-gold);
            box-shadow: 0 0 35px rgba(197, 160, 89, 0.25), var(--shadow-card);
            max-width: 520px;
            width: 100%;
            padding: 35px;
            position: relative;
            animation: modalFadeIn 0.3s cubic-bezier(0.16, 1, 0.3, 1);
        }

        @keyframes modalFadeIn {
            from { opacity: 0; transform: translateY(20px) scale(0.97); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }

        .voucher-modal-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            border-bottom: 1px solid var(--border-color);
            padding-bottom: 18px;
            margin-bottom: 22px;
        }
        .voucher-modal-header .logo-brand {
            font-family: Georgia, serif;
            font-size: 1.4rem;
            color: var(--text-primary);
        }
        .voucher-modal-header .logo-brand span {
            color: var(--accent-gold);
            font-style: italic;
        }
        .voucher-modal-close {
            background: transparent;
            border: none;
            font-size: 1.6rem;
            color: var(--text-secondary);
            cursor: pointer;
            line-height: 1;
            transition: color 0.2s;
        }
        .voucher-modal-close:hover {
            color: var(--accent-gold);
        }

        .voucher-details-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
            margin-bottom: 22px;
        }
        .voucher-field {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }
        .voucher-field.full-width {
            grid-column: 1 / -1;
        }
        .voucher-field .v-label {
            font-size: 0.68rem;
            text-transform: uppercase;
            letter-spacing: 0.12em;
            color: var(--text-secondary);
        }
        .voucher-field .v-value {
            font-size: 0.95rem;
            color: var(--text-primary);
            font-weight: 500;
        }
        .voucher-field .v-value.amount {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--accent-gold);
        }

        .voucher-seal {
            border-top: 1px dashed var(--border-subtle);
            padding-top: 15px;
            margin-top: 15px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .voucher-seal-text {
            font-size: 0.68rem;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            color: var(--text-muted);
        }
        .voucher-seal-badge {
            font-size: 0.7rem;
            padding: 4px 10px;
            border: 1px solid var(--accent-gold);
            color: var(--accent-gold);
            letter-spacing: 0.15em;
            text-transform: uppercase;
            font-weight: 700;
        }

        .voucher-modal-actions {
            display: flex;
            gap: 12px;
            margin-top: 25px;
        }
        .btn-voucher-print {
            flex: 1;
            padding: 12px;
            background: var(--accent-gold);
            color: var(--text-inverse);
            border: none;
            font-size: 0.8rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.12em;
            cursor: pointer;
            transition: opacity 0.3s;
        }
        .btn-voucher-print:hover { opacity: 0.9; }
        .btn-voucher-close {
            padding: 12px 20px;
            background: transparent;
            border: 1px solid var(--border-subtle);
            color: var(--text-secondary);
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.12em;
            cursor: pointer;
            transition: all 0.3s;
        }
        .btn-voucher-close:hover {
            color: var(--text-primary);
            border-color: var(--text-primary);
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
                <li><a href="profile.php" class="nav-alias"><?php echo htmlspecialchars($user['alias']); ?></a></li>
            </ul>
        </nav>
    </header>

    <main class="dashboard-container">
        

        <div class="dashboard-header" style="display: flex; justify-content: space-between; align-items: flex-end;">
            <div>
                <h1><?php echo htmlspecialchars($user['alias']); ?></h1>
                <p>Authenticated Syndicate Member</p>
            </div>
            <a href="logout.php" class="btn-logout-luxury">Log Out</a>
        </div>

        <div class="stats-grid">
            <div class="stat-card">
                <span class="stat-label">Available Liquid Funds</span>
                <span class="stat-value">$<?php echo number_format($user['available_funds'], 2); ?></span>
                <div style="margin-top: 15px;">
                    <a href="deposit.php" style="display: inline-block; padding: 8px 15px; background: var(--accent-gold); color: var(--text-inverse); text-decoration: none; font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.1em; font-weight: bold; transition: opacity 0.3s;">Add Funds</a>
                </div>
            </div>
            <div class="stat-card">
                <span class="stat-label">Auctions Participated</span>
                <span class="stat-value text-val"><?php echo $total_participated; ?></span>
            </div>
            <div class="stat-card">
                <span class="stat-label">Artifacts Acquired</span>
                <span class="stat-value text-val"><?php echo $total_won; ?></span>
            </div>
            <div class="stat-card">
                <span class="stat-label">Consignments Managed</span>
                <span class="stat-value text-val"><?php echo $total_consignments; ?></span>
            </div>
        </div>

        <!-- =======================================================
             THEME PREFERENCE TOGGLE SECTION
        ======================================================= -->
        <div class="theme-setting-card">
            <div class="theme-setting-header">
                <div>
                    <h2>Display Atmosphere</h2>
                    <p>Select your interface theme preference across the entire platform</p>
                </div>
                <span class="theme-status-badge" id="themeStatusBadge">Active: Obsidian Night</span>
            </div>

            <div class="theme-options-grid">
                <!-- Obsidian Dark Mode Option -->
                <div class="theme-option-box" id="themeBoxDark" onclick="setPlatformTheme('dark')">
                    <div class="theme-preview theme-preview-dark">
                        <div class="preview-nav"></div>
                        <div class="preview-chip"></div>
                    </div>
                    <div class="theme-option-info">
                        <div class="theme-option-title">
                            <span>Obsidian Night (Dark)</span>
                            <span class="radio-dot"></span>
                        </div>
                        <p class="theme-option-desc">Cinematic espresso black & deep obsidian surfaces with luminous satin gold accents.</p>
                    </div>
                </div>

                <!-- Warm Linen Light Mode Option -->
                <div class="theme-option-box" id="themeBoxLight" onclick="setPlatformTheme('light')">
                    <div class="theme-preview theme-preview-light">
                        <div class="preview-nav"></div>
                        <div class="preview-chip"></div>
                    </div>
                    <div class="theme-option-info">
                        <div class="theme-option-title">
                            <span>Warm Linen (Light)</span>
                            <span class="radio-dot"></span>
                        </div>
                        <p class="theme-option-desc">Natural alabaster & warm cream background with tailored antique gold heritage contrast.</p>
                    </div>
                </div>
            </div>
        </div>

        <div class="history-section">
            <h2>Acquisitions & Participation</h2>
            <?php if (empty($participated_auctions)): ?>
                <div class="empty-state">You have not participated in any auctions yet.</div>
            <?php else: ?>
                <table class="history-table">
                    <thead>
                        <tr>
                            <th>Last Interaction</th>
                            <th>Artifact</th>
                            <th>Your Highest Offer</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($participated_auctions as $auction): ?>
                            <tr>
                                <td style="color: var(--text-secondary);"><?php echo date("M d, Y - H:i", strtotime($auction['last_interaction'])); ?></td>
                                <td><a href="auction_item.php?id=<?php echo $auction['auction_id']; ?>"><?php echo htmlspecialchars($auction['title']); ?></a></td>
                                <td>$<?php echo number_format($auction['user_max_bid'], 2); ?></td>
                                
                                <?php
                                    $is_ended = time() >= strtotime($auction['end_time']);
                                    $is_highest = $auction['user_max_bid'] >= $auction['current_high_bid'];
                                    
                                    if ($is_ended) {
                                        $status = $is_highest ? 'Acquired (Won)' : 'Participated';
                                        $color = $is_highest ? 'var(--accent-gold)' : 'var(--text-secondary)';
                                    } else {
                                        $status = $is_highest ? 'Active (Leading)' : 'Participated';
                                        $color = $is_highest ? 'var(--accent-gold)' : 'var(--text-secondary)'; 
                                    }
                                ?>
                                <td style="color: <?php echo $color; ?>; font-weight: 600; letter-spacing: 0.05em; text-transform: uppercase; font-size: 0.85rem;">
                                    <?php echo $status; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <!-- CONSIGNMENT PORTFOLIO SECTION -->
        <div class="history-section">
            <h2>Consignment Portfolio</h2>
            <?php if (empty($pending_items) && empty($approved_items)): ?>
                <div class="empty-state">You have not submitted any artifacts for appraisal.</div>
            <?php else: ?>
                <table class="history-table">
                    <thead>
                        <tr>
                            <th>Artifact</th>
                            <th>Starting Reserve</th>
                            <th>Current Floor</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <!-- Loop through items awaiting Admin Approval -->
                        <?php foreach ($pending_items as $item): ?>
                            <tr>
                                <td style="color: var(--text-secondary);"><?php echo htmlspecialchars($item['title']); ?></td>
                                <td>$<?php echo number_format($item['starting_price'], 2); ?></td>
                                <td style="color: var(--text-secondary);">--</td>
                                <td style="color: var(--text-secondary); font-weight: 600; letter-spacing: 0.05em; text-transform: uppercase; font-size: 0.85rem;">Appraisal Pending</td>
                            </tr>
                        <?php endforeach; ?>

                        <!-- Loop through items approved and on the Live Floor -->
                        <?php foreach ($approved_items as $item): 
                            $is_settled = strtotime($item['end_time']) <= time();
                            $status_text = $is_settled ? 'Auction Closed' : 'Live on Floor';
                            $color = $is_settled ? 'var(--text-secondary)' : 'var(--accent-gold)';
                        ?>
                            <tr>
                                <td><a href="auction_item.php?id=<?php echo $item['auction_id']; ?>"><?php echo htmlspecialchars($item['title']); ?></a></td>
                                <td style="color: var(--text-secondary);">--</td>
                                <td>$<?php echo number_format($item['current_high_bid'], 2); ?></td>
                                <td style="color: <?php echo $color; ?>; font-weight: 600; letter-spacing: 0.05em; text-transform: uppercase; font-size: 0.85rem; display: flex; align-items: center;">
                                    <span><?php echo $status_text; ?></span>
                                    <?php if (!$is_settled): ?>
                                        <form method="POST" action="profile.php" style="display: inline-block; margin-left: 15px;" onsubmit="return confirm('Are you sure you want to end this auction early? The highest bidder will instantly win.');">
                                            <input type="hidden" name="action" value="hammer">
                                            <input type="hidden" name="auction_id" value="<?php echo $item['auction_id']; ?>">
                                            <button type="submit" style="background: transparent; border: 1px solid #990000; color: #990000; padding: 4px 10px; font-size: 0.7rem; text-transform: uppercase; cursor: pointer; border-radius: 2px; font-weight: bold; transition: all 0.3s; letter-spacing: 0.1em;" onmouseover="this.style.background='rgba(153,0,0,0.1)';" onmouseout="this.style.background='transparent';">Hammer Bid</button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <!-- =======================================================
             CATEGORIZED FINANCIAL LEDGER SUITE
        ======================================================= -->
        <div class="ledger-suite-container">
            <div class="ledger-suite-header">
                <div class="ledger-title-group">
                    <h2>
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="var(--accent-gold)" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg>
                        Private Financial Ledger
                    </h2>
                    <p>Cryptographically audited transactions, won acquisitions, seller payouts & settlement vouchers</p>
                </div>
                
                <div class="ledger-summary-pills">
                    <div class="ledger-summary-pill">
                        <span class="lbl">Total Inflows</span>
                        <span class="val inflow">+$<?php echo number_format($total_inflow, 2); ?></span>
                    </div>
                    <div class="ledger-summary-pill">
                        <span class="lbl">Total Outflows</span>
                        <span class="val outflow">-$<?php echo number_format($total_outflow, 2); ?></span>
                    </div>
                    <div class="ledger-summary-pill">
                        <span class="lbl">Current Liquid</span>
                        <span class="val net">$<?php echo number_format($user['available_funds'], 2); ?></span>
                    </div>
                </div>
            </div>

            <!-- Categorization Tabs -->
            <div class="ledger-tabs-scroller">
                <div class="ledger-tabs-list" id="ledgerTabs">
                    <button type="button" class="ledger-tab-btn active" data-category="all">
                        All Transactions <span class="ledger-tab-count"><?php echo count($ledger_entries); ?></span>
                    </button>
                    <button type="button" class="ledger-tab-btn" data-category="deposits">
                        Deposits & Inflows <span class="ledger-tab-count"><?php echo $count_deposits; ?></span>
                    </button>
                    <button type="button" class="ledger-tab-btn" data-category="acquisitions">
                        Won Acquisitions <span class="ledger-tab-count"><?php echo $count_acquisitions; ?></span>
                    </button>
                    <button type="button" class="ledger-tab-btn" data-category="payouts">
                        Consignment Payouts <span class="ledger-tab-count"><?php echo $count_payouts; ?></span>
                    </button>
                    <button type="button" class="ledger-tab-btn" data-category="fees">
                        Fees & Deductions <span class="ledger-tab-count"><?php echo $count_fees; ?></span>
                    </button>
                </div>
            </div>

            <!-- Filter & Search Toolbar -->
            <div class="ledger-filter-toolbar">
                <div class="ledger-search-box">
                    <svg class="ledger-search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                    <input type="text" id="ledgerSearchInput" class="ledger-search-input" placeholder="Search by Artifact, Ref #, or Type...">
                </div>

                <div class="ledger-toolbar-right">
                    <div class="ledger-flow-filters">
                        <button type="button" class="ledger-flow-btn active" data-flow="all">All Flows</button>
                        <button type="button" class="ledger-flow-btn" data-flow="credit">+ Credits</button>
                        <button type="button" class="ledger-flow-btn" data-flow="debit">- Debits</button>
                    </div>

                    <button type="button" class="ledger-export-btn" id="btnExportLedger" title="Export current statement as CSV">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
                        Export Statement (.CSV)
                    </button>
                </div>
            </div>

            <!-- Ledger Table -->
            <?php if (empty($ledger_entries)): ?>
                <div class="empty-state">No financial transactions recorded in your syndicate ledger.</div>
            <?php else: ?>
                <div class="ledger-table-wrap">
                    <table class="ledger-table" id="ledgerTable">
                        <thead>
                            <tr>
                                <th>Ref #</th>
                                <th>Date & Time</th>
                                <th>Category / Type</th>
                                <th>Associated Artifact</th>
                                <th>Amount</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($ledger_entries as $entry): 
                                $amt = (float)$entry['amount'];
                                $is_credit = ($entry['flow'] === 'credit');
                                $amount_str = ($is_credit ? "+$" : "-$") . number_format($amt, 2);
                                $ref_id = "TXN-" . str_pad($entry['transaction_id'], 6, '0', STR_PAD_LEFT);
                                $formatted_date = date("M d, Y", strtotime($entry['created_at']));
                                $formatted_time = date("H:i", strtotime($entry['created_at']));
                                $full_date_time = date("M d, Y - H:i:s", strtotime($entry['created_at']));
                                $artifact_title = $entry['title'] ? ucwords($entry['title']) : 'Platform Treasury / Direct';
                            ?>
                                <tr class="ledger-row" 
                                    data-category="<?php echo htmlspecialchars($entry['category']); ?>" 
                                    data-flow="<?php echo htmlspecialchars($entry['flow']); ?>"
                                    data-search="<?php echo htmlspecialchars(strtolower($ref_id . ' ' . $artifact_title . ' ' . $entry['type_label'] . ' ' . $amount_str)); ?>"
                                    data-txn-id="<?php echo $entry['transaction_id']; ?>"
                                    data-ref-id="<?php echo $ref_id; ?>"
                                    data-date="<?php echo htmlspecialchars($full_date_time); ?>"
                                    data-type="<?php echo htmlspecialchars($entry['type_label']); ?>"
                                    data-artifact="<?php echo htmlspecialchars($artifact_title); ?>"
                                    data-amount="<?php echo htmlspecialchars($amount_str); ?>"
                                    data-flow-val="<?php echo htmlspecialchars($entry['flow']); ?>"
                                >
                                    <td>
                                        <span class="txn-ref">#<?php echo $ref_id; ?></span>
                                    </td>
                                    <td style="color: var(--text-secondary); white-space: nowrap;">
                                        <span><?php echo $formatted_date; ?></span>
                                        <span style="font-size: 0.75rem; color: var(--text-muted); margin-left: 4px;"><?php echo $formatted_time; ?></span>
                                    </td>
                                    <td>
                                        <span class="ledger-badge <?php echo $entry['badge_class']; ?>">
                                            <?php if ($entry['type'] === 'deposit'): ?>
                                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 5v14M19 12l-7 7-7-7"/></svg>
                                            <?php elseif ($entry['category'] === 'payouts'): ?>
                                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                                            <?php elseif ($entry['category'] === 'acquisitions'): ?>
                                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="8" r="7"/><polyline points="8.21 13.89 7 23 12 20 17 23 15.79 13.88"/></svg>
                                            <?php elseif ($entry['type'] === 'withdrawal'): ?>
                                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 19V5M5 12l7-7 7 7"/></svg>
                                            <?php else: ?>
                                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="19" y1="5" x2="5" y2="19"/><circle cx="6.5" cy="6.5" r="2.5"/><circle cx="17.5" cy="17.5" r="2.5"/></svg>
                                            <?php endif; ?>
                                            <?php echo htmlspecialchars($entry['type_label']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($entry['auction_id'] && $entry['title']): ?>
                                            <a href="auction_item.php?id=<?php echo $entry['auction_id']; ?>"><?php echo htmlspecialchars(ucwords($entry['title'])); ?></a>
                                        <?php else: ?>
                                            <span style="color: var(--text-secondary);"><?php echo htmlspecialchars($artifact_title); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="txn-amt <?php echo $entry['flow']; ?>">
                                            <?php echo $amount_str; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <button type="button" class="btn-view-voucher" onclick="openVoucherModal(this)">
                                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg>
                                            Voucher
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div id="ledgerFilterEmptyState" class="empty-state" style="display: none; text-align: center; padding: 40px 0;">
                    No transactions match your current search or category filter. 
                    <br><br>
                    <button type="button" class="btn-view-voucher" onclick="resetLedgerFilters()">Reset Filters</button>
                </div>
            <?php endif; ?>
        </div>

    </main>

    <!-- =======================================================
         LUXURY TRANSACTION VOUCHER MODAL
    ======================================================= -->
    <div class="voucher-modal-backdrop" id="voucherModalBackdrop" onclick="closeVoucherModal(event)">
        <div class="voucher-modal-card" onclick="event.stopPropagation()">
            <div class="voucher-modal-header">
                <div>
                    <div class="logo-brand">Retro-<span>bid</span></div>
                    <div style="font-size: 0.72rem; letter-spacing: 0.15em; text-transform: uppercase; color: var(--text-secondary); margin-top: 4px;">Official Ledger Proof of Transaction</div>
                </div>
                <button type="button" class="voucher-modal-close" onclick="closeVoucherModal()">&times;</button>
            </div>

            <div class="voucher-details-grid">
                <div class="voucher-field">
                    <span class="v-label">Voucher Reference</span>
                    <span class="v-value" id="vRefId" style="font-family: monospace; color: var(--accent-gold);">#TXN-000000</span>
                </div>
                <div class="voucher-field">
                    <span class="v-label">Timestamp (UTC)</span>
                    <span class="v-value" id="vDate">--</span>
                </div>
                <div class="voucher-field">
                    <span class="v-label">Syndicate Member</span>
                    <span class="v-value"><?php echo htmlspecialchars($user['alias']); ?></span>
                </div>
                <div class="voucher-field">
                    <span class="v-label">Classification</span>
                    <span class="v-value" id="vType">--</span>
                </div>
                <div class="voucher-field full-width">
                    <span class="v-label">Associated Artifact / Treasury</span>
                    <span class="v-value" id="vArtifact">--</span>
                </div>
                <div class="voucher-field full-width">
                    <span class="v-label">Total Amount Settled</span>
                    <span class="v-value amount" id="vAmount">$0.00</span>
                </div>
            </div>

            <div class="voucher-seal">
                <div class="voucher-seal-text">
                    Verified by Retro-Bid Vault Engine<br>
                    State: Immutable Ledger Entry
                </div>
                <div class="voucher-seal-badge">AUTHENTICATED</div>
            </div>

            <div class="voucher-modal-actions">
                <button type="button" class="btn-voucher-print" onclick="window.print()">Print Statement</button>
                <button type="button" class="btn-voucher-close" onclick="closeVoucherModal()">Close</button>
            </div>
        </div>
    </div>

    <script>
        // =====================================================
        // THEME PREFERENCE CONTROLLER
        // =====================================================
        function updateThemeUI(theme) {
            var isLight = (theme === 'light');
            var darkBox = document.getElementById('themeBoxDark');
            var lightBox = document.getElementById('themeBoxLight');
            var badge = document.getElementById('themeStatusBadge');

            if (darkBox && lightBox) {
                if (isLight) {
                    darkBox.classList.remove('active');
                    lightBox.classList.add('active');
                } else {
                    lightBox.classList.remove('active');
                    darkBox.classList.add('active');
                }
            }

            if (badge) {
                badge.textContent = isLight ? 'Active: Warm Linen (Light)' : 'Active: Obsidian Night (Dark)';
            }
        }

        function setPlatformTheme(theme) {
            document.documentElement.setAttribute('data-theme', theme);
            localStorage.setItem('retro_theme', theme);
            updateThemeUI(theme);

            // Sync with backend asynchronously
            var xhr = new XMLHttpRequest();
            xhr.open('POST', 'update_theme.php', true);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            xhr.send('theme=' + encodeURIComponent(theme));
        }

        // =====================================================
        // CATEGORIZED FINANCIAL LEDGER FILTER CONTROLLER
        // =====================================================
        let currentCategory = 'all';
        let currentFlow = 'all';
        let searchQuery = '';

        function filterLedgerRows() {
            const rows = document.querySelectorAll('.ledger-row');
            const emptyState = document.getElementById('ledgerFilterEmptyState');
            let visibleCount = 0;

            rows.forEach(row => {
                const category = row.dataset.category;
                const flow = row.dataset.flow;
                const searchContent = row.dataset.search || '';

                const matchesCategory = (currentCategory === 'all' || category === currentCategory);
                const matchesFlow = (currentFlow === 'all' || flow === currentFlow);
                const matchesSearch = (!searchQuery || searchContent.includes(searchQuery));

                if (matchesCategory && matchesFlow && matchesSearch) {
                    row.classList.remove('hidden-row');
                    visibleCount++;
                } else {
                    row.classList.add('hidden-row');
                }
            });

            if (emptyState) {
                emptyState.style.display = (visibleCount === 0 && rows.length > 0) ? 'block' : 'none';
            }
        }

        function resetLedgerFilters() {
            currentCategory = 'all';
            currentFlow = 'all';
            searchQuery = '';
            
            const searchInput = document.getElementById('ledgerSearchInput');
            if (searchInput) searchInput.value = '';

            document.querySelectorAll('.ledger-tab-btn').forEach(btn => {
                btn.classList.toggle('active', btn.dataset.category === 'all');
            });

            document.querySelectorAll('.ledger-flow-btn').forEach(btn => {
                btn.classList.toggle('active', btn.dataset.flow === 'all');
            });

            filterLedgerRows();
        }

        // =====================================================
        // VOUCHER RECEIPT MODAL CONTROLLER
        // =====================================================
        function openVoucherModal(button) {
            const row = button.closest('.ledger-row');
            if (!row) return;

            document.getElementById('vRefId').textContent = '#' + row.dataset.refId;
            document.getElementById('vDate').textContent = row.dataset.date;
            document.getElementById('vType').textContent = row.dataset.type;
            document.getElementById('vArtifact').textContent = row.dataset.artifact;
            
            const vAmountEl = document.getElementById('vAmount');
            vAmountEl.textContent = row.dataset.amount;
            if (row.dataset.flowVal === 'credit') {
                vAmountEl.style.color = '#4cd964';
            } else {
                vAmountEl.style.color = '#e55050';
            }

            document.getElementById('voucherModalBackdrop').classList.add('open');
        }

        function closeVoucherModal(e) {
            if (e && e.target !== document.getElementById('voucherModalBackdrop') && !e.target.classList.contains('voucher-modal-close') && !e.target.classList.contains('btn-voucher-close')) {
                return;
            }
            document.getElementById('voucherModalBackdrop').classList.remove('open');
        }

        // =====================================================
        // CSV EXPORT GENERATOR
        // =====================================================
        function exportLedgerCSV() {
            const rows = document.querySelectorAll('.ledger-row:not(.hidden-row)');
            if (rows.length === 0) {
                alert('No ledger records match the current filter to export.');
                return;
            }

            let csvContent = "data:text/csv;charset=utf-8,";
            csvContent += "Reference ID,Date & Time,Classification,Flow,Associated Artifact,Amount\r\n";

            rows.forEach(row => {
                const ref = '"' + (row.dataset.refId || '') + '"';
                const date = '"' + (row.dataset.date || '') + '"';
                const type = '"' + (row.dataset.type || '') + '"';
                const flow = '"' + (row.dataset.flowVal || '') + '"';
                const artifact = '"' + (row.dataset.artifact || '').replace(/"/g, '""') + '"';
                const amount = '"' + (row.dataset.amount || '') + '"';

                csvContent += `${ref},${date},${type},${flow},${artifact},${amount}\r\n`;
            });

            const encodedUri = encodeURI(csvContent);
            const link = document.createElement("a");
            link.setAttribute("href", encodedUri);
            link.setAttribute("download", "RetroBid_Ledger_Statement.csv");
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        }

        // Initialize on DOM ready
        document.addEventListener('DOMContentLoaded', function() {
            // Theme setup
            var currentTheme = document.documentElement.getAttribute('data-theme') || localStorage.getItem('retro_theme') || 'dark';
            updateThemeUI(currentTheme);

            // Category Tab Buttons
            document.querySelectorAll('.ledger-tab-btn').forEach(btn => {
                btn.addEventListener('click', function() {
                    document.querySelectorAll('.ledger-tab-btn').forEach(b => b.classList.remove('active'));
                    this.classList.add('active');
                    currentCategory = this.dataset.category;
                    filterLedgerRows();
                });
            });

            // Cash Flow Filter Buttons
            document.querySelectorAll('.ledger-flow-btn').forEach(btn => {
                btn.addEventListener('click', function() {
                    document.querySelectorAll('.ledger-flow-btn').forEach(b => b.classList.remove('active'));
                    this.classList.add('active');
                    currentFlow = this.dataset.flow;
                    filterLedgerRows();
                });
            });

            // Real-time Search Input
            const searchInput = document.getElementById('ledgerSearchInput');
            if (searchInput) {
                searchInput.addEventListener('input', function() {
                    searchQuery = this.value.trim().toLowerCase();
                    filterLedgerRows();
                });
            }

            // Export CSV Button
            const exportBtn = document.getElementById('btnExportLedger');
            if (exportBtn) {
                exportBtn.addEventListener('click', exportLedgerCSV);
            }

            // Close modal on Escape key
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    closeVoucherModal();
                }
            });
        });
    </script>
</body>
</html>


