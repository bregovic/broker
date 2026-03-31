<?php
// debug-prices.php - Enhanced Debugging Tool
header('Content-Type: text/html; charset=utf-8');

// Robust Fetcher
function fetchUrl($url, $method = 'GET') {
    if (function_exists('curl_init')) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 8);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');
        
        if ($method === 'HEAD') curl_setopt($ch, CURLOPT_NOBODY, true);
        
        $data = curl_exec($ch);
        $err = curl_error($ch);
        $info = curl_getinfo($ch);
        curl_close($ch);
        
        if ($err) return ['error' => $err, 'code' => 0];
        return ['body' => $data, 'code' => $info['http_code']];
    } else {
        // Stream fallback
        $ctx = stream_context_create(['http'=>['timeout'=>5, 'header'=>"User-Agent: Mozilla/5.0\r\n"]]);
        $data = @file_get_contents($url, false, $ctx);
        if ($data === false) return ['error' => 'Stream failed', 'code' => 0];
        return ['body' => $data, 'code' => 200];
    }
}

$ticker = isset($_GET['ticker']) ? strtoupper(trim($_GET['ticker'])) : 'AAPL';
?>
<!DOCTYPE html>
<html>
<head>
<title>Debug Prices: <?php echo htmlspecialchars($ticker); ?></title>
<style>
body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; padding: 20px; background: #f3f2f1; color: #201f1e; }
.card { background: white; padding: 20px; margin-bottom: 20px; border-radius: 4px; box-shadow: 0 1.6px 3.6px rgba(0,0,0,0.13), 0 0.3px 0.9px rgba(0,0,0,0.11); }
h1 { margin-top: 0; font-size: 24px; font-weight: 600; }
h2 { font-size: 16px; border-bottom: 1px solid #edebe9; padding-bottom: 8px; margin-top: 0; display: flex; justify-content: space-between; align-items: center; }
pre { background: #faf9f8; padding: 10px; border: 1px solid #edebe9; overflow-x: auto; font-size: 11px; max-height: 200px; color: #323130; }
.status-ok { color: #107c10; font-weight: 600; }
.status-err { color: #a4262c; font-weight: 600; }
.badge { padding: 2px 6px; border-radius: 4px; font-size: 10px; background: #eee; }
form { display: flex; gap: 10px; margin-bottom: 20px; align-items: center; }
input { padding: 6px 10px; border: 1px solid #8a8886; border-radius: 2px; width: 200px; }
button { padding: 6px 16px; background: #0078d4; color: white; border: none; border-radius: 2px; cursor: pointer; font-weight: 600; }
button:hover { background: #106ebe; }
.grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(400px, 1fr)); gap: 20px; }
.kv { display: grid; grid-template-columns: 100px 1fr; gap: 5px; font-size: 13px; margin-bottom: 5px; }
.kv label { color: #605e5c; }
</style>
</head>
<body>

<h1>Debug Prices</h1>
<form>
    <label>Ticker:</label>
    <input name="ticker" value="<?php echo htmlspecialchars($ticker); ?>" placeholder="e.g. AAPL, ZM">
    <button>Inspect</button>
</form>

<div class="grid">

    <!-- YAHOO CHART V8 QUERY1 -->
    <div class="card">
        <h2>
            Yahoo Chart v8 (query1)
            <?php 
            $url = "https://query1.finance.yahoo.com/v8/finance/chart/" . urlencode($ticker) . "?interval=1d&range=1d";
            $res = fetchUrl($url);
            echo "<span class='badge'>HTTP {$res['code']}</span>";
            ?>
        </h2>
        <div style='font-size:10px; color:#605e5c; margin-bottom:10px; word-break:break-all;'><?php echo $url; ?></div>
        
        <?php
        if (isset($res['error'])) {
             echo "<p class='status-err'>{$res['error']}</p>";
        } else {
             $json = json_decode($res['body'], true);
             if ($json && !empty($json['chart']['result'])) {
                 $meta = $json['chart']['result'][0]['meta'];
                 echo "<div class='kv'><label>Price:</label> <span><strong>" . ($meta['regularMarketPrice'] ?? 'N/A') . "</strong> " . ($meta['currency'] ?? '') . "</span></div>";
                 echo "<div class='kv'><label>Exchange:</label> <span>" . ($meta['fullExchangeName'] ?? $meta['exchangeName'] ?? 'N/A') . "</span></div>";
                 echo "<div class='kv'><label>Date:</label> <span>" . date('Y-m-d H:i:s', $meta['regularMarketTime'] ?? time()) . "</span></div>";
                 echo "<div class='kv'><label>Prev Close:</label> <span>" . ($meta['chartPreviousClose'] ?? 'N/A') . "</span></div>";
             } elseif ($json && !empty($json['chart']['error'])) {
                 echo "<p class='status-err'>API Error: " . htmlspecialchars($json['chart']['error']['description'] ?? 'Unknown') . "</p>";
             } else {
                 echo "<p class='status-err'>Invalid JSON Structure</p>";
             }
             echo "<pre>" . htmlspecialchars(substr($res['body'], 0, 1000)) . "...</pre>";
        }
        ?>
    </div>

    <!-- YAHOO CHART V8 QUERY2 -->
    <div class="card">
        <h2>
            Yahoo Chart v8 (query2)
            <?php 
            $url = "https://query2.finance.yahoo.com/v8/finance/chart/" . urlencode($ticker) . "?interval=1d&range=1d";
            $res = fetchUrl($url);
            echo "<span class='badge'>HTTP {$res['code']}</span>";
            ?>
        </h2>
        <div style='font-size:10px; color:#605e5c; margin-bottom:10px; word-break:break-all;'><?php echo $url; ?></div>
        
        <?php
        if (isset($res['error'])) {
             echo "<p class='status-err'>{$res['error']}</p>";
        } else {
             $json = json_decode($res['body'], true);
             if ($json && !empty($json['chart']['result'])) {
                 $meta = $json['chart']['result'][0]['meta'];
                 echo "<div class='kv'><label>Price:</label> <span><strong>" . ($meta['regularMarketPrice'] ?? 'N/A') . "</strong> " . ($meta['currency'] ?? '') . "</span></div>";
             } else {
                 echo "<p class='status-err'>Empty/Error</p>";
             }
             echo "<pre>" . htmlspecialchars(substr($res['body'], 0, 500)) . "...</pre>";
        }
        ?>
    </div>

    <!-- YAHOO QUOTE V7 -->
    <div class="card">
        <h2>
            Yahoo Quote v7
            <?php 
            $url = "https://query1.finance.yahoo.com/v7/finance/quote?symbols=" . urlencode($ticker);
            $res = fetchUrl($url);
            echo "<span class='badge'>HTTP {$res['code']}</span>";
            ?>
        </h2>
        <div style='font-size:10px; color:#605e5c; margin-bottom:10px; word-break:break-all;'><?php echo $url; ?></div>
        
        <?php
        if (isset($res['error'])) {
             echo "<p class='status-err'>{$res['error']}</p>";
        } else {
             $json = json_decode($res['body'], true);
             if ($json && !empty($json['quoteResponse']['result'])) {
                 $q = $json['quoteResponse']['result'][0];
                 echo "<div class='kv'><label>Price:</label> <span><strong>" . ($q['regularMarketPrice'] ?? 'N/A') . "</strong> " . ($q['currency'] ?? '') . "</span></div>";
                 echo "<div class='kv'><label>Name:</label> <span>" . ($q['longName'] ?? $q['shortName'] ?? '') . "</span></div>";
                 echo "<div class='kv'><label>Source:</label> <span>" . ($q['quoteSourceName'] ?? '') . "</span></div>";
             } else {
                 echo "<p class='status-err'>Empty/Error</p>";
             }
             echo "<pre>" . htmlspecialchars(substr($res['body'], 0, 500)) . "...</pre>";
        }
        ?>
    </div>

    <!-- GOOGLE FINANCE SEARCH -->
    <div class="card">
        <h2>Google Finance (Scrape: NASDAQ)</h2>
        <?php
        // Try guessing exchange
        $gUrl = "https://www.google.com/finance/quote/" . urlencode($ticker . ":NASDAQ") . "?hl=en";
        $res = fetchUrl($gUrl);
        echo "<span class='badge'>HTTP {$res['code']}</span>";
        ?>
        <div style='font-size:10px; color:#605e5c; margin-bottom:10px; word-break:break-all;'><?php echo $gUrl; ?></div>
        
        <?php
        if (preg_match('~<div[^>]+class="[^"]*YMlKec[^"]*fxKbKc[^"]*"[^>]*>(.*?)</div>~s', $res['body'], $m)) {
             echo "<p class='status-ok'>MATCH: " . htmlspecialchars($m[1]) . "</p>";
        } else {
             echo "<p class='status-err'>No Match via Regex</p>";
        }
        echo "<pre>" . htmlspecialchars(substr($res['body'], 0, 1000)) . "...</pre>";
        ?>
    </div>
    
    <!-- COINGECKO -->
    <div class="card">
        <h2>CoinGecko</h2>
        <?php
        $url = "https://api.coingecko.com/api/v3/simple/price?ids=" . strtolower($ticker) . "&vs_currencies=usd";
        $res = fetchUrl($url);
        echo "<span class='badge'>HTTP {$res['code']}</span>";
        ?>
        <div style='font-size:10px; color:#605e5c; margin-bottom:10px; word-break:break-all;'><?php echo $url; ?></div>
        <pre><?php echo htmlspecialchars($res['body']); ?></pre>
    </div>

</div>

</body>
</html>
