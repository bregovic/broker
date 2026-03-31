<?php
require_once 'includes/db_connect.php';

try {
    if (!isset($pdo)) {
        die("Database connection failed");
    }

    $pdo->beginTransaction();

    echo "Fixing Barrick Gold mapping...\n";

    // 1. Ensure GOLD mapping exists and uses correct name
    $stmt = $pdo->prepare("INSERT INTO ticker_mapping (ticker, company_name, last_verified, status) VALUES ('GOLD', 'Barrick Gold Corp', NOW(), 'manual') ON DUPLICATE KEY UPDATE company_name='Barrick Gold Corp', status='manual'");
    $stmt->execute();
    echo "Updated GOLD mapping.\n";

    // 2. Map B to GOLD (Alias)
    // First check if B exists
    $stmt = $pdo->prepare("SELECT * FROM ticker_mapping WHERE ticker = 'B'");
    $stmt->execute();
    if ($stmt->fetch()) {
        $pdo->exec("UPDATE ticker_mapping SET alias_of = 'GOLD', company_name = 'Barrick Gold Corp (Alias)', last_verified=NOW() WHERE ticker = 'B'");
    } else {
        $pdo->exec("INSERT INTO ticker_mapping (ticker, alias_of, company_name, last_verified) VALUES ('B', 'GOLD', 'Barrick Gold Corp (Alias)', NOW())");
    }
    echo "Mapped B -> GOLD.\n";

    // 3. Merge Transactions
    $count = $pdo->exec("UPDATE transactions SET ticker = 'GOLD' WHERE ticker = 'B'");
    echo "Moved $count transactions from B to GOLD.\n";

    // 4. Cleanup Live Quotes and Watchlist
    $pdo->exec("DELETE FROM live_quotes WHERE id = 'B'");
    // Delete duplicate history for B
    $pdo->exec("DELETE FROM tickers_history WHERE ticker = 'B'");
    
    // Update Watchlist (ignore if GOLD already watched)
    $pdo->exec("UPDATE IGNORE watch SET ticker = 'GOLD' WHERE ticker = 'B'");
    $pdo->exec("DELETE FROM watch WHERE ticker = 'B'");
    echo "Cleaned up B from live quotes and watchlist.\n";

    $pdo->commit();
    echo "Success! Please refresh prices for GOLD.";
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo "Error: " . $e->getMessage();
}
?>
