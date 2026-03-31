/**
 * Asynchronous Live Price Loader with Batch Processing
 * Prevents server timeouts by fetching prices in small chunks.
 */

(function () {
    'use strict';

    function loadLivePrices() {
        // Check url param override
        var useLiveParam = new URLSearchParams(window.location.search).get('live');
        if (useLiveParam === '1') {
            console.log('Live prices already loaded via PHP (sync mode)');
            return;
        }

        var tbody = document.querySelector('#txTable tbody');
        if (!tbody) return;

        var rows = Array.from(tbody.querySelectorAll('tr[data-id]'));
        if (rows.length === 0) return;

        // Collect unique tickers
        var tickers = [];
        var seen = new Set();

        rows.forEach(function (row) {
            var ticker = row.getAttribute('data-id');
            var currency = row.getAttribute('data-currency');
            // Check if PHP already served a fresh price
            var isFresh = row.getAttribute('data-is-fresh') === '1';

            if (ticker && !seen.has(ticker) && !isFresh) {
                seen.add(ticker);
                tickers.push({
                    ticker: ticker,
                    currency: currency || 'CZK'
                });
            }
        });

        if (tickers.length === 0) {
            console.log('All prices are fresh (loaded from DB cache).');
            if (headerTitle) {
                var existing = document.getElementById('live-price-loader');
                if (existing) existing.remove();

                var check = document.createElement('span');
                check.style.cssText = 'color:#10b981;font-size:14px;font-weight:bold;margin-left:12px';
                check.innerHTML = '✓ Data aktuální';
                headerTitle.appendChild(check);
                setTimeout(function () { check.remove(); }, 3000);
            }
            return;
        }

        // Show loading indicator
        var headerTitle = document.querySelector('h3');
        var loader = null;
        if (headerTitle) {
            // Remove existing loader if any
            var existing = document.getElementById('live-price-loader');
            if (existing) existing.remove();

            loader = document.createElement('span');
            loader.id = 'live-price-loader';
            loader.style.cssText = 'color:#ef4444;font-size:16px;font-weight:700;margin-left:12px'; // Larger font
            loader.innerHTML = '⟳ Načítám aktuální ceny (0/' + tickers.length + ')...';
            headerTitle.appendChild(loader);
        }

        var sumCurrentEl = document.getElementById('summary-total-current');
        if (sumCurrentEl) {
            sumCurrentEl.style.color = '#ef4444'; // Red while loading
        }

        // Configuration
        var BATCH_SIZE = 5; // tickers per request
        var processedCount = 0;

        // Batch Processor
        function processBatch(startIndex) {
            if (startIndex >= tickers.length) {
                // All done
                if (loader) {
                    loader.innerHTML = '✓ Ceny aktualizovány';
                    loader.style.color = '#10b981';
                    setTimeout(function () { loader.remove(); }, 4000);
                }

                // Color reset for total value
                if (sumCurrentEl) {
                    sumCurrentEl.style.color = '';
                }

                recalculateTotals();

                // Check for missing prices logic check
                if (typeof window.runMissingPriceCheck === 'function') {
                    setTimeout(window.runMissingPriceCheck, 1000);
                }
                return;
            }

            var batch = tickers.slice(startIndex, startIndex + BATCH_SIZE);

            fetch('ajax-live-prices.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ tickers: batch })
            })
                .then(function (res) { return res.json(); })
                .then(function (prices) {
                    var anyUpdated = false;
                    rows.forEach(function (row) {
                        var ticker = row.getAttribute('data-id');
                        if (prices[ticker] && prices[ticker].success) {
                            updateRowPrice(row, prices[ticker]);
                            anyUpdated = true;
                        }
                    });
                    if (anyUpdated) recalculateTotals(); // Update totals after each batch for live feel
                })
                .catch(function (err) {
                    console.error('Batch fetch error:', err);
                })
                .finally(function () {
                    processedCount += batch.length;
                    if (loader) {
                        loader.innerHTML = '⟳ Načítám ceny (' + Math.min(processedCount, tickers.length) + '/' + tickers.length + ')...';
                    }
                    // Chain next batch with small delay
                    setTimeout(function () {
                        processBatch(startIndex + BATCH_SIZE);
                    }, 500);
                });
        }

        // Helper: Update single row
        function updateRowPrice(row, priceData) {
            var livePriceOrig = parseFloat(priceData.price);
            if (isNaN(livePriceOrig) || livePriceOrig <= 0) return;

            var qty = parseFloat(row.getAttribute('data-qty')) || 0;
            var costTotalCzk = parseFloat(row.getAttribute('data-cost_total_czk')) || 0;

            var livePriceCzk;
            var fxRate = 1.0;

            if (priceData.price_converted && parseFloat(priceData.price_converted) > 0) {
                livePriceCzk = parseFloat(priceData.price_converted);
                fxRate = livePriceCzk / livePriceOrig;
            } else {
                // Fallback: Try to deduce FX rate from invalid/old prices or default to 1
                var lastPriceOrig = parseFloat(row.getAttribute('data-last_price_orig')) || 0;
                var lastPriceCzk = parseFloat(row.getAttribute('data-last_price_czk')) || 0;

                if (lastPriceOrig > 0 && lastPriceCzk > 0) {
                    fxRate = lastPriceCzk / lastPriceOrig;
                }
                livePriceCzk = livePriceOrig * fxRate;
            }

            var currentValueCzk = qty * livePriceCzk;
            var unrealizedCzk = currentValueCzk - costTotalCzk;
            var unrealizedPct = costTotalCzk > 0 ? (unrealizedCzk / costTotalCzk) * 100.0 : 0.0;

            var avgPriceOrig = parseFloat(row.getAttribute('data-avg_price_orig')) || 0;
            var unrealizedOrig = (livePriceOrig - avgPriceOrig) * qty;
            var costTotalOrig = parseFloat(row.getAttribute('data-cost_total_orig')) || 0;
            var unrealizedPctOrig = costTotalOrig > 0 ? (unrealizedOrig / costTotalOrig) * 100.0 : 0.0;

            var pricePlCzk = unrealizedOrig * fxRate;
            var fxPlCzk = unrealizedCzk - pricePlCzk;

            // Update DOM attributes
            row.setAttribute('data-last_price_orig', livePriceOrig);
            row.setAttribute('data-last_price_czk', livePriceCzk);
            row.setAttribute('data-current_value_czk', currentValueCzk);
            row.setAttribute('data-unrealized_orig', unrealizedOrig);
            row.setAttribute('data-unrealized_pct_orig', unrealizedPctOrig);
            row.setAttribute('data-fx_pl_czk', fxPlCzk);
            row.setAttribute('data-unrealized_czk', unrealizedCzk);
            row.setAttribute('data-unrealized_pct', unrealizedPct);

            // Update Table Cells (Assuming standard layout)
            var cells = row.querySelectorAll('td');
            if (cells.length >= 14) {
                // 7: Last Price Orig
                updateCell(cells[7], livePriceOrig.toFixed(2));
                // 8: Current Value CZK
                updateCell(cells[8], fmtNum(currentValueCzk, 0));

                // 9: Unrealized Orig
                updateCell(cells[9], fmtNum(unrealizedOrig, 2), unrealizedOrig);
                // 10: Unrealized % Orig
                updateCell(cells[10], (unrealizedPctOrig >= 0 ? '+' : '') + unrealizedPctOrig.toFixed(2) + '%', unrealizedPctOrig);

                // 11: FX P&L
                updateCell(cells[11], fmtNum(fxPlCzk, 0), fxPlCzk);

                // 12: P&L CZK
                updateCell(cells[12], fmtNum(unrealizedCzk, 0), unrealizedCzk);
                // 13: P&L %
                updateCell(cells[13], (unrealizedPct >= 0 ? '+' : '') + unrealizedPct.toFixed(2) + '%', unrealizedPct);
            }
        }

        function updateCell(td, text, valForColor) {
            td.textContent = text;
            td.style.backgroundColor = '#dcfce7'; // light green highlight

            if (typeof valForColor !== 'undefined') {
                td.className = td.className.replace(/positive|negative/, '').trim();
                td.classList.add(valForColor >= 0 ? 'positive' : 'negative');
            }

            setTimeout(function () {
                td.style.backgroundColor = '';
            }, 1500);
        }

        function fmtNum(n, d) {
            return n.toFixed(d).replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
        }

        // Helper: Recalculate HEADER stats
        function recalculateTotals() {
            var totalCurrentCzk = 0;
            var totalCostCzk = 0;
            var totalUnrealizedCzk = 0;

            rows.forEach(function (row) {
                totalCurrentCzk += parseFloat(row.getAttribute('data-current_value_czk')) || 0;
                totalCostCzk += parseFloat(row.getAttribute('data-cost_total_czk')) || 0;
                totalUnrealizedCzk += parseFloat(row.getAttribute('data-unrealized_czk')) || 0;
            });

            var totalPct = totalCostCzk > 0 ? (totalUnrealizedCzk / totalCostCzk) * 100.0 : 0.0;

            var sumCurrentEl = document.getElementById('summary-total-current');
            if (sumCurrentEl) sumCurrentEl.textContent = fmtNum(totalCurrentCzk, 2) + ' Kč';

            var sumUnrealizedEl = document.getElementById('summary-total-unrealized');
            if (sumUnrealizedEl) {
                sumUnrealizedEl.className = 'stat-value ' + (totalUnrealizedCzk >= 0 ? 'positive' : 'negative');
                sumUnrealizedEl.textContent = fmtNum(totalUnrealizedCzk, 2) + ' Kč';
            }

            var sumPctEl = document.getElementById('summary-total-pct');
            if (sumPctEl) {
                sumPctEl.textContent = 'Zhodnocení otevřených pozic: ' + totalPct.toFixed(2).replace('.', ',') + ' %';
            }
        }

        // Start it
        processBatch(0);
    }

    // Expose to window if needed
    window.loadLivePricesBatched = loadLivePrices;

    // Run on load
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', loadLivePrices);
    } else {
        loadLivePrices();
    }

})();
