<?php
/**
 * Vylepšená funkcia pro získání nejčerstvější ceny z různých zdrojů
 * 
 * Priorita:
 * 1. Live quote z live_quotes (dnešní)
 * 2. Porovnání nejnovější ceny z live_quotes vs transakce
 * 3. Použití té čerstvější
 */
function getBestAvailablePrice($pdo, $googleFinance, $ticker, $currency) {
    $today = date('Y-m-d');
    
    // 1. Zkus získat dnešní live quote
    $sql = "SELECT current_price, currency, last_fetched, source
            FROM live_quotes
            WHERE id = ?
              AND DATE(last_fetched) = ?
              AND status = 'active'
              AND current_price IS NOT NULL
            ORDER BY last_fetched DESC
            LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$ticker, $today]);
    $liveToday = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($liveToday && $liveToday['current_price'] > 0) {
        return [
            'price' => (float)$liveToday['current_price'],
            'source' => 'live_today',
            'date' => $today,
            'currency' => $liveToday['currency']
        ];
    }
    
    // 2. Nemáme dnešní - zkus stáhnout novou
    if ($googleFinance) {
        try {
            $freshData = $googleFinance->getQuote($ticker, true);
            if ($freshData !== null && isset($freshData['current_price']) && $freshData['current_price'] > 0) {
                return [
                    'price' => (float)$freshData['current_price'],
                    'source' => 'google_fresh',
                    'date' => $today,
                    'currency' => $freshData['currency'] ?? 'USD'
                ];
            }
        } catch (Exception $e) {
            error_log("getBestAvailablePrice - Google Finance failed for $ticker: " . $e->getMessage());
        }
    }
    
    // 3. Stažení selhalo - najdi nejčerstvější dostupnou cenu
    
    // 3a. Nejnovější z live_quotes (i když stará)
    $sql = "SELECT current_price, currency, last_fetched, source
            FROM live_quotes
            WHERE id = ?
              AND status = 'active'
              AND current_price IS NOT NULL
            ORDER BY last_fetched DESC
            LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$ticker]);
    $liveOld = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // 3b. Poslední z transakcí
    $sql = "SELECT price, currency, date
            FROM transactions
            WHERE id = ?
              AND (trans_type = 'Buy' OR trans_type = 'Sell')
              AND price IS NOT NULL
              AND price > 0
            ORDER BY date DESC, trans_id DESC
            LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$ticker]);
    $transLast = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // 4. Porovnej co je čerstvější
    $candidates = [];
    
    if ($liveOld) {
        $candidates[] = [
            'price' => (float)$liveOld['current_price'],
            'source' => 'live_old_' . $liveOld['source'],
            'date' => substr($liveOld['last_fetched'], 0, 10),
            'currency' => $liveOld['currency'],
            'timestamp' => strtotime($liveOld['last_fetched'])
        ];
    }
    
    if ($transLast) {
        $candidates[] = [
            'price' => (float)$transLast['price'],
            'source' => 'transaction',
            'date' => $transLast['date'],
            'currency' => $transLast['currency'],
            'timestamp' => strtotime($transLast['date'])
        ];
    }
    
    // Vyber nejčerstvější
    if (empty($candidates)) {
        return null;
    }
    
    usort($candidates, function($a, $b) {
        return $b['timestamp'] - $a['timestamp'];
    });
    
    return $candidates[0];
}

// Příklad použití:
// $priceData = getBestAvailablePrice($pdo, $googleFinance, 'CBK', 'EUR');
// if ($priceData) {
//     echo "Cena: {$priceData['price']} {$priceData['currency']}\n";
//     echo "Zdroj: {$priceData['source']}\n";
//     echo "Datum: {$priceData['date']}\n";
// }
