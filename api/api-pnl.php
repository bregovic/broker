<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/config.php';

function resolveUserId() {
    $candidates = ['user_id','uid','userid','id'];
    foreach ($candidates as $k) {
        if (isset($_SESSION[$k]) && is_numeric($_SESSION[$k]) && (int)$_SESSION[$k] > 0) return (int)$_SESSION[$k];
    }
    return 0;
}

/** metadata jsou jsonb, starší řádky ale mohou nést text nebo null. */
function pnl_meta($hodnota): array {
    if (is_array($hodnota)) return $hodnota;
    if (!is_string($hodnota) || trim($hodnota) === '') return [];
    $d = json_decode($hodnota, true);
    return is_array($d) ? $d : [];
}

$userId = resolveUserId();
if (!$userId) {
    echo json_encode(['success'=>false, 'error'=>'Unauthorized']);
    exit;
}

try {
    $pdo = get_pdo();

    // 1. Fetch Sales (ticker instead of id)
    $sql = "SELECT trans_id, date, COALESCE(a.canonical, tr.ticker) AS ticker, amount, price, ex_rate, currency, amount_cur, amount_czk, platform, fees, product_type, metadata
            FROM transactions tr LEFT JOIN ticker_aliases a ON a.alias = tr.ticker
            WHERE tr.user_id = ?
            AND UPPER(tr.trans_type) = 'SELL'
            AND (tr.product_type = 'Stock' OR tr.product_type = 'Crypto')
            ORDER BY tr.date DESC, tr.trans_id DESC LIMIT 2000";
            
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$userId]);
    $sales = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 2. Fetch Years (Postgres version)
    $stmtYears = $pdo->prepare("SELECT DISTINCT EXTRACT(YEAR FROM date) as yr FROM transactions WHERE user_id=? ORDER BY 1 DESC");
    $stmtYears->execute([$userId]);
    $years = $stmtYears->fetchAll(PDO::FETCH_COLUMN);
    
    $data = [];
    $stats = [
        'net_profit' => 0,
        'realized_profit' => 0,
        'realized_loss' => 0,
        // Hrubý výsledek před poplatky. Dřív tu bylo jen `realized_profit`,
        // které je čisté, ale karta v přehledu ho popisovala jako hrubý zisk.
        'gross_profit' => 0,
        'gross_loss' => 0,
        'fx_total' => 0,
        'fx_znamy' => true,
        'fees_total' => 0,
        // Poplatek, který broker vykázal, nemusí vysvětlit celý rozdíl mezi
        // hodnotou obchodu a tím, co se pohnulo na účtu (u Coinbase je v tom
        // ještě spread). Tohle je ten skutečný náklad obchodu.
        'execution_cost_total' => 0,
        'tax_free_profit' => 0,
        'taxable_profit' => 0,
        'winning' => 0,
        'losing' => 0,
        'total_count' => 0,
        // Kvalita dat: kolik obchodů stojí na odvozené či neznámé pořizovací ceně.
        'basis_odvozeny' => 0,
        'basis_unknown' => 0,
    ];

    foreach ($sales as $sale) {
        $ticker = $sale['ticker'];
        
        // Helper calculation for average price (Postgres friendly)
        $sqlBuy = "SELECT tr.date, tr.amount, tr.price, tr.amount_cur, tr.amount_czk, tr.ex_rate, tr.fees, tr.metadata
                   FROM transactions tr LEFT JOIN ticker_aliases a ON a.alias = tr.ticker
                   WHERE tr.user_id = ? AND COALESCE(a.canonical, tr.ticker) = ? AND UPPER(tr.trans_type) = 'BUY'
                   AND tr.date <= ? AND tr.platform = ?
                   ORDER BY tr.date ASC";
        $stmtB = $pdo->prepare($sqlBuy);
        $stmtB->execute([$userId, $ticker, $sale['date'], $sale['platform']]);
        $purchases = $stmtB->fetchAll(PDO::FETCH_ASSOC);

        $totalBought = 0; $totalCostCZK = 0; $totalCostCur = 0; $totalBuyFeesCzk = 0; $firstDate = null;
        $buyExecCzk = 0;
        // Nejhorší stav pořizovací ceny mezi nákupy, které do prodeje vstupují.
        // Převedená pozice svou cenu z výpisu nezná — viz v3/cost_basis.php.
        $basisStatus = 'KNOWN';
        foreach ($purchases as $p) {
            $totalBought    += (float)$p['amount'];
            $totalCostCZK   += abs((float)$p['amount_czk']);
            $totalCostCur   += abs((float)$p['amount_cur']);
            $totalBuyFeesCzk += abs((float)($p['fees'] ?? 0)) * (float)($p['ex_rate'] ?: 1);
            if (!$firstDate) $firstDate = $p['date'];

            $mp = pnl_meta($p['metadata']);
            $buyExecCzk += abs((float)($mp['execution_cost'] ?? 0)) * (float)($p['ex_rate'] ?: 1);
            $stav = strtoupper((string)($mp['basis_status'] ?? ''));
            if ($stav === 'UNKNOWN') $basisStatus = 'UNKNOWN';
            elseif ($stav === 'ODVOZENY' && $basisStatus === 'KNOWN') $basisStatus = 'ODVOZENY';
        }

        $sellQty     = abs((float)$sale['amount']);
        $proceedsCzk = abs((float)$sale['amount_czk']);
        $proceedsCur = abs((float)$sale['amount_cur']);
        $sellRate    = $proceedsCur > 0 ? $proceedsCzk / $proceedsCur : (float)($sale['ex_rate'] ?: 1);

        // Average cost basis (CZK and original currency) for the sold quantity
        $costCzk = $totalBought > 0 ? ($totalCostCZK / $totalBought) * $sellQty : 0;
        $costCur = $totalBought > 0 ? ($totalCostCur / $totalBought) * $sellQty : 0;
        $buyRate = $costCur > 0 ? $costCzk / $costCur : $sellRate;

        // Fees in CZK: sell fees (at sell rate) + buy fees allocated to the sold portion
        $feesCzk = abs((float)($sale['fees'] ?? 0)) * $sellRate
                 + ($totalBought > 0 ? $totalBuyFeesCzk * ($sellQty / $totalBought) : 0);

        // Decompose the realized result (CZK):
        //   gross = proceeds - cost (incl. FX, before fees)
        //   fx    = foreign proceeds * (sell rate - buy rate)  -> currency-move part
        //   net   = gross - fees
        $profitCZK = $proceedsCzk - $costCzk;
        $fxCZK     = $proceedsCur * ($sellRate - $buyRate);
        $netCZK    = $profitCZK - $feesCzk;

        /*
         * Časový test.
         *
         * U cenných papírů platí tříletý test dlouhodobě. U kryptoměny žádné
         * osvobození podle doby držby neexistovalo — zavedl ho až zákon
         * č. 32/2025 Sb. s účinností od 15. 2. 2025. Prodeje krypta před tímhle
         * dnem se proto daní bez ohledu na to, jak dlouho se drželo; dřív tu
         * vycházely „osvobozené“ i obchody z roku 2024.
         *
         * (Daňové posouzení konkrétního případu patří poradci, tohle je jen
         * orientační rozlišení v přehledu.)
         */
        $KRYPTO_TEST_OD = '2025-02-15';
        $taxTestPassed = false;
        if ($firstDate) {
            $d1 = new DateTime($firstDate);
            $d2 = new DateTime($sale['date']);
            $diff = $d1->diff($d2);
            $taxTestPassed = ($diff->days >= 1095);
            if ($taxTestPassed && strcasecmp((string)$sale['product_type'], 'Crypto') === 0
                && $sale['date'] < $KRYPTO_TEST_OD) {
                $taxTestPassed = false;
            }
        }

        // Skutečný náklad obchodu: co broker vykázal jako poplatek nemusí být
        // všechno (u Coinbase je v tom navíc spread). Do zisku se nepromítá
        // znovu — částky obchodu už ho obsahují.
        $metaSale = pnl_meta($sale['metadata']);
        $execCzk = abs((float)($metaSale['execution_cost'] ?? 0)) * $sellRate
                 + ($totalBought > 0 ? $buyExecCzk * ($sellQty / $totalBought) : 0);

        $stats['total_count']++;
        if ($netCZK >= 0) { $stats['realized_profit'] += $netCZK; $stats['winning']++; }
        else { $stats['realized_loss'] += abs($netCZK); $stats['losing']++; }
        if ($profitCZK >= 0) $stats['gross_profit'] += $profitCZK;
        else $stats['gross_loss'] += abs($profitCZK);
        $stats['fx_total']   += $fxCZK;
        $stats['fees_total'] += $feesCzk;
        $stats['execution_cost_total'] += $execCzk;
        if ($taxTestPassed) $stats['tax_free_profit'] += $netCZK;
        else $stats['taxable_profit'] += $netCZK;
        $stats['net_profit'] += $netCZK;
        if ($basisStatus === 'ODVOZENY') $stats['basis_odvozeny']++;
        if ($basisStatus === 'UNKNOWN')  $stats['basis_unknown']++;

        $data[] = [
            'id' => $sale['trans_id'],
            'date' => $sale['date'],
            'ticker' => $ticker,
            'qty' => $sellQty,
            'profit_czk' => $profitCZK,
            'fx_czk' => $fxCZK,
            'fees_czk' => $feesCzk,
            'execution_cost_czk' => $execCzk,
            'net_profit_czk' => $netCZK,
            'tax_test' => $taxTestPassed,
            'basis_status' => $basisStatus,
            'holding_days' => $firstDate ? (new DateTime($firstDate))->diff(new DateTime($sale['date']))->days : 0,
            'platform' => $sale['platform'],
            'currency' => $sale['currency']
        ];
    }

    /*
     * Kurzový rozdíl umíme rozložit jen tam, kde je obchod veden v cizí měně.
     * Coinbase přepočítá všechno rovnou na koruny, takže z jeho výpisu pohyb
     * EUR/CZK mezi vkladem a výběrem vyčíst nejde — a nula by tvrdila, že žádný
     * nebyl. Ať je poznat rozdíl mezi „nula“ a „nevíme“.
     */
    $maCiziMenu = false;
    foreach ($data as $d) {
        if (strcasecmp((string)$d['currency'], 'CZK') !== 0) { $maCiziMenu = true; break; }
    }
    if (!$maCiziMenu && $stats['total_count'] > 0) {
        $stats['fx_znamy'] = false;
        $stats['fx_total'] = null;
    }
    
    echo json_encode([
        'success' => true,
        'stats' => $stats,
        'data' => $data,
        'years' => $years
    ]);
    
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success'=>false, 'error'=>$e->getMessage(), 'trace'=>$e->getTraceAsString()]);
}
