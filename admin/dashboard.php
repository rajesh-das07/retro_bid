<?php
require_once 'header.php';

// 1. Total Syndicate Profit
$profit_stmt = $conn->query("SELECT SUM(amount) as total_profit FROM transaction_ledger WHERE type IN ('fee', 'buyer_fee', 'seller_fee', '')");
$profit_row = $profit_stmt->fetch(PDO::FETCH_ASSOC);
$total_profit = (float)($profit_row['total_profit'] ?? 0);

// 2. Total Trading Volume (Hammer Price equivalent)
$volume_stmt = $conn->query("
    SELECT SUM(tl.amount) as total_volume 
    FROM transaction_ledger tl 
    JOIN auctions a ON tl.auction_id = a.auction_id 
    WHERE tl.type = 'payment' AND tl.user_id != a.seller_id
");
$volume_row = $volume_stmt->fetch(PDO::FETCH_ASSOC);
$total_volume = (float)($volume_row['total_volume'] ?? 0);

// 3. Total Users
$users_stmt = $conn->query("SELECT COUNT(*) as total_users FROM users");
$total_users = (int)($users_stmt->fetch(PDO::FETCH_ASSOC)['total_users'] ?? 0);

// 4. Active Lots
$active_lots_stmt = $conn->query("SELECT COUNT(*) as active_lots FROM auctions WHERE end_time > NOW()");
$active_lots = (int)($active_lots_stmt->fetch(PDO::FETCH_ASSOC)['active_lots'] ?? 0);

// --- CHARTS DATA PREPARATION ---

// 5. Volume Over Time (Last 7 Days)
// We'll get the sum of 'payment' (divided by 2 for hammer volume) grouped by date.
$timeseries_stmt = $conn->query("
    SELECT DATE(tl.created_at) as tx_date, SUM(tl.amount) as daily_volume 
    FROM transaction_ledger tl
    JOIN auctions a ON tl.auction_id = a.auction_id
    WHERE tl.type = 'payment' AND tl.user_id != a.seller_id
    GROUP BY DATE(tl.created_at) 
    ORDER BY tx_date ASC 
    LIMIT 14
");
$timeseries_data = $timeseries_stmt->fetchAll(PDO::FETCH_ASSOC);

$chart_labels = [];
$chart_values = [];
foreach ($timeseries_data as $row) {
    $chart_labels[] = date('M d', strtotime($row['tx_date']));
    $chart_values[] = (float)$row['daily_volume'];
}

// 6. Auction Status Breakdown
$status_stmt = $conn->query("
    SELECT 
        SUM(CASE WHEN end_time > NOW() THEN 1 ELSE 0 END) as active_count,
        SUM(CASE WHEN end_time <= NOW() AND NOT EXISTS (SELECT 1 FROM transaction_ledger WHERE auction_id = a.auction_id AND type='payment') THEN 1 ELSE 0 END) as pending_count,
        SUM(CASE WHEN EXISTS (SELECT 1 FROM transaction_ledger WHERE auction_id = a.auction_id AND type='payment') THEN 1 ELSE 0 END) as settled_count
    FROM auctions a
");
$status_row = $status_stmt->fetch(PDO::FETCH_ASSOC);
$active_count = (int)($status_row['active_count'] ?? 0);
$pending_count = (int)($status_row['pending_count'] ?? 0);
$settled_count = (int)($status_row['settled_count'] ?? 0);

?>

<style>
    .metrics-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
        gap: 20px;
        margin-bottom: 40px;
    }
    .metric-card {
        background: var(--bg-card);
        border: 1px solid var(--border-color);
        padding: 25px;
        box-shadow: var(--shadow-sm);
        transition: background-color 0.35s ease, border-color 0.35s ease;
        text-align: center;
    }
    .metric-title {
        color: var(--text-secondary);
        font-size: 0.9rem;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        margin-bottom: 10px;
    }
    .metric-value {
        color: var(--accent-gold);
        font-size: 2rem;
        font-weight: bold;
    }
    
    .charts-grid {
        display: grid;
        grid-template-columns: 2fr 1fr;
        gap: 20px;
        margin-bottom: 40px;
    }
    @media (max-width: 900px) {
        .charts-grid {
            grid-template-columns: 1fr;
        }
    }
    .chart-card {
        background: var(--bg-card);
        border: 1px solid var(--border-color);
        padding: 20px;
        box-shadow: var(--shadow-sm);
        transition: background-color 0.35s ease, border-color 0.35s ease;
    }
    .chart-header {
        color: var(--text-primary);
        font-size: 1.1rem;
        text-transform: uppercase;
        margin-bottom: 20px;
        border-bottom: 1px dashed var(--border-subtle);
        padding-bottom: 10px;
    }
</style>

<!-- Load Chart.js from CDN -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<div class="section-divider">Platform Executive Summary</div>

<div class="metrics-grid">
    <div class="metric-card">
        <div class="metric-title">Total Syndicate Profit</div>
        <div class="metric-value">$<?php echo number_format($total_profit, 2); ?></div>
    </div>
    <div class="metric-card">
        <div class="metric-title">Total Trading Volume</div>
        <div class="metric-value">$<?php echo number_format($total_volume, 2); ?></div>
    </div>
    <div class="metric-card">
        <div class="metric-title">Registered Members</div>
        <div class="metric-value"><?php echo number_format($total_users); ?></div>
    </div>
    <div class="metric-card">
        <div class="metric-title">Active Lots</div>
        <div class="metric-value"><?php echo number_format($active_lots); ?></div>
    </div>
</div>

<div class="charts-grid">
    <div class="chart-card">
        <div class="chart-header">Trading Volume Over Time</div>
        <canvas id="volumeChart" height="100"></canvas>
    </div>
    <div class="chart-card">
        <div class="chart-header">Auction Status</div>
        <canvas id="statusChart" height="200"></canvas>
    </div>
</div>

<script>
// Chart configuration variables
const chartLabels = <?php echo json_encode($chart_labels); ?>;
const chartValues = <?php echo json_encode($chart_values); ?>;
const statusCounts = [<?php echo $active_count; ?>, <?php echo $pending_count; ?>, <?php echo $settled_count; ?>];

// Dynamically extract theme colors from CSS variables
function getThemeColor(varName) {
    return getComputedStyle(document.documentElement).getPropertyValue(varName).trim();
}

let volumeChartInstance = null;
let statusChartInstance = null;

function renderCharts() {
    const textColor = getThemeColor('--text-primary') || '#e0e0e0';
    const gridColor = getThemeColor('--border-subtle') || '#333333';
    const goldColor = getThemeColor('--accent-gold') || '#d4af37';
    
    // Destroy existing charts if re-rendering
    if (volumeChartInstance) volumeChartInstance.destroy();
    if (statusChartInstance) statusChartInstance.destroy();

    // Configuration for default typography
    Chart.defaults.color = textColor;
    Chart.defaults.font.family = 'monospace';

    // 1. Volume Bar Chart
    const ctxVol = document.getElementById('volumeChart').getContext('2d');
    
    // Create a smooth gradient for the bars
    let gradient = ctxVol.createLinearGradient(0, 0, 0, 400);
    gradient.addColorStop(0, goldColor);
    gradient.addColorStop(1, 'rgba(212, 175, 55, 0.1)');

    volumeChartInstance = new Chart(ctxVol, {
        type: 'bar',
        data: {
            labels: chartLabels.length > 0 ? chartLabels : ['No Data'],
            datasets: [{
                label: 'Settled Volume ($)',
                data: chartValues.length > 0 ? chartValues : [0],
                backgroundColor: gradient,
                borderColor: goldColor,
                borderWidth: 1,
                borderRadius: 4,
            }]
        },
        options: {
            responsive: true,
            scales: {
                y: {
                    beginAtZero: true,
                    grid: { color: gridColor },
                    ticks: { callback: function(value) { return '$' + value; } }
                },
                x: {
                    grid: { display: false }
                }
            },
            plugins: {
                legend: { display: false }
            }
        }
    });

    // 2. Status Pie Chart
    const ctxStatus = document.getElementById('statusChart').getContext('2d');
    statusChartInstance = new Chart(ctxStatus, {
        type: 'doughnut',
        data: {
            labels: ['Active', 'Pending Settlement', 'Settled'],
            datasets: [{
                data: statusCounts,
                backgroundColor: [
                    '#146c2e', // Green for Active
                    '#b8860b', // Dark gold for Pending
                    '#333333'  // Dark grey for Settled
                ],
                borderColor: getThemeColor('--bg-card'),
                borderWidth: 2,
                hoverOffset: 4
            }]
        },
        options: {
            responsive: true,
            cutout: '70%',
            plugins: {
                legend: { position: 'bottom' }
            }
        }
    });
}

// Render immediately
renderCharts();

// Re-render when theme changes to update CSS variable colors in Canvas
const observer = new MutationObserver(function(mutations) {
    mutations.forEach(function(mutation) {
        if (mutation.attributeName === "data-theme") {
            renderCharts();
        }
    });
});
observer.observe(document.documentElement, { attributes: true });

</script>

<?php require_once 'footer.php'; ?>
