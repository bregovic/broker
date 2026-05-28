<?php
/**
 * api-dividend-comparison.php
 * Aggregates 5-year dividend metrics for all tickers to feed the comparison UI,
 * converting payouts to CZK using historical/latest exchange rates.
 */

session_start();
header('Content-Type: application/json; charset=utf-8');

function resolveUserId() {
    $candidates = ['user_id','uid','userid','id'];
    foreach ($candidates as $k) {
        if (isset($_SESSION[$k]) && is_numeric($_SESSION[$k]) && (int)$_SESSION[$k] > 0) return (int)$_SESSION[$k];
    }
    if (isset($_SESSION['user'])) {
        $u = $_SESSION['user'];
        if (is_array($u)) { foreach ($candidates as $k) if (isset($u[$k]) && is_numeric($u[$k])) return (int)$u[$k]; }
        elseif (is_object($u)) { foreach ($candidates as $k) if (isset($u->$k) && is_numeric($u->$k)) return (int)$u->$k; }
    }
    return 0;
}

$userId = resolveUserId();
if (!$userId) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/setup_dividend_db.php';

// Helper to get latest exchange rate (dual compatible)
function getLatestFxRateLocal($pdo, $currency, $asOfDate) {
    if (!$currency || $currency === 'CZK') {
        return 1.0;
    }
    if (!$asOfDate) {
        $asOfDate = date('Y-m-d');
    }

    try {
        // First check broker_exrates (MySQL structure)
        $sql = "SELECT rate, amount FROM broker_exrates WHERE currency = ? AND date <= ? ORDER BY date DESC LIMIT 1";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$currency, $asOfDate]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            $amount = (int)$row['amount'];
            if ($amount <= 0) $amount = 1;
            return (float)$row['rate'] / $amount;
        }

        // Fallback for fx_rates (PostgreSQL / v3 structure if present)
        $sqlFallback = "SELECT rate FROM fx_rates WHERE currency_from = ? AND currency_to = 'CZK' AND rate_date <= ? ORDER BY rate_date DESC LIMIT 1";
        $stmt = $pdo->prepare($sqlFallback);
        $stmt->execute([$currency, $asOfDate]);
        $rate = $stmt->fetchColumn();
        if ($rate !== false) {
            return (float)$rate;
        }
    } catch (Exception $e) {
        // Fallback silently if table doesn't exist
    }

    // Default static fallbacks for offline or missing rates
    $defaults = ['USD' => 23.5, 'EUR' => 25.2, 'GBP' => 29.5, 'CAD' => 17.2, 'CHF' => 26.0];
    return $defaults[$currency] ?? 1.0;
}

try {
    $pdo = get_pdo();
    ensure_dividend_db_setup($pdo);

    // Get list of all tickers in dividend history or active quotes
    $sql = "SELECT DISTINCT dh.ticker, lq.company_name, lq.current_price, lq.currency as quote_currency,
                   lq.ex_dividend_date, lq.dividend_rate, lq.dividend_yield, lq.dividend_frequency, lq.five_year_avg_yield
            FROM dividend_history dh
            LEFT JOIN live_quotes lq ON dh.ticker = lq.ticker
            ORDER BY dh.ticker ASC";

    $stmt = $pdo->query($sql);
    $tickers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $results = [];
    $currentYear = (int)date('Y');

    foreach ($tickers as $t) {
        $ticker = $t['ticker'];
        
        // Fetch all dividend events for calculations
        $stmtDivs = $pdo->prepare("SELECT ex_date, amount, currency FROM dividend_history WHERE ticker = ? ORDER BY ex_date DESC");
        $stmtDivs->execute([$ticker]);
        $divs = $stmtDivs->fetchAll(PDO::FETCH_ASSOC);

        if (!$divs) continue;

        $currency = $divs[0]['currency'];
        $exDividendDate = $t['ex_dividend_date'] ?? $divs[0]['ex_date'];
        
        // Calculate TTM Dividend Rate
        $dividendRate = (float)($t['dividend_rate'] ?? 0.0);
        if ($dividendRate <= 0) {
            $oneYearAgo = strtotime('-365 days');
            foreach ($divs as $d) {
                if (strtotime($d['ex_date']) >= $oneYearAgo) {
                    $dividendRate += (float)$d['amount'];
                }
            }
        }

        // Calculate Yield
        $currentPrice = (float)($t['current_price'] ?? 0.0);
        $dividendYield = (float)($t['dividend_yield'] ?? 0.0);
        if ($dividendYield <= 0 && $currentPrice > 0) {
            $dividendYield = ($dividendRate / $currentPrice) * 100;
        }

        // Get FX rates
        $fxRate = getLatestFxRateLocal($pdo, $currency, date('Y-m-d'));
        $priceCzk = $currentPrice * $fxRate;
        $dividendRateCzk = $dividendRate * $fxRate;

        // Calculate consistency & payouts by calendar year
        $yearlyPayouts = array_fill(0, 5, 0.0);
        foreach ($divs as $d) {
            $year = (int)date('Y', strtotime($d['ex_date']));
            $index = $currentYear - $year;
            if ($index >= 0 && $index < 5) {
                $yearlyPayouts[$index] += (float)$d['amount'];
            }
        }

        // Active payout years out of last 5 years
        $consistencyScore = 0;
        foreach ($yearlyPayouts as $payout) {
            if ($payout > 0) {
                $consistencyScore++;
            }
        }

        // Estimate growth rate (comparing year -4 with year 0, or most distant positive payout with latest)
        $growthRate5y = 0.0;
        $latestPayout = $yearlyPayouts[0];
        $earliestPayout = 0.0;
        
        // Find earliest payout in the 5-year window
        for ($i = 4; $i > 0; $i--) {
            if ($yearlyPayouts[$i] > 0) {
                $earliestPayout = $yearlyPayouts[$i];
                break;
            }
        }

        if ($earliestPayout > 0 && $latestPayout > 0) {
            $growthRate5y = (($latestPayout - $earliestPayout) / $earliestPayout) * 100;
        }

        $results[] = [
            'ticker' => $ticker,
            'company_name' => $t['company_name'] ?? $ticker,
            'currency' => $currency,
            'current_price' => $currentPrice,
            'current_price_czk' => $priceCzk,
            'dividend_rate' => $dividendRate,
            'dividend_rate_czk' => $dividendRateCzk,
            'dividend_yield' => $dividendYield,
            'dividend_frequency' => $t['dividend_frequency'] ?? 'Irregular',
            'five_year_avg_yield' => (float)($t['five_year_avg_yield'] ?? 0.0),
            'ex_dividend_date' => $exDividendDate,
            'consistency_score' => $consistencyScore,
            'growth_rate_5y' => $growthRate5y,
            'payouts_by_year' => array_reverse($yearlyPayouts) // ascending chronological order [2022, 2023, 2024, 2025, 2026]
        ];
    }

    echo json_encode([
        'success' => true,
        'data' => $results
    ]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
