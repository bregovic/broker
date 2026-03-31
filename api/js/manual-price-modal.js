/**
 * Manual Price Entry Modal
 * Detects missing prices and prompts user to enter them manually
 */

class ManualPriceModal {
    constructor() {
        this.missingPrices = [];
        this.currentIndex = 0;
        this.modal = null;
        this.onComplete = null;
    }

    /**
     * Check for missing prices and show modal if needed
     */
    async checkMissingPrices(tickers, onComplete) {
        this.onComplete = onComplete;
        this.missingPrices = tickers || []; // Use server-provided list
        this.currentIndex = 0;

        if (this.missingPrices.length > 0) {
            this.showModal();
        } else if (this.onComplete) {
            this.onComplete();
        }
    }

    /**
     * Show modal dialog for current ticker
     */
    showModal() {
        if (this.currentIndex >= this.missingPrices.length) {
            this.closeModal();
            if (this.onComplete) {
                this.onComplete();
            }
            return;
        }

        const item = this.missingPrices[this.currentIndex];

        // Create modal HTML
        const modalHTML = `
            <div id="manualPriceModal" class="manual-price-modal" style="
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background: rgba(0,0,0,0.6);
                display: flex;
                align-items: center;
                justify-content: center;
                z-index: 9999;
            ">
                <div class="modal-content" style="
                    background: white;
                    border-radius: 12px;
                    padding: 32px;
                    max-width: 500px;
                    width: 90%;
                    box-shadow: 0 10px 40px rgba(0,0,0,0.3);
                ">
                    <h3 style="margin: 0 0 8px 0; color: #1e293b; font-size: 20px;">
                        ⚠️ Cena není dostupná
                    </h3>
                    <p style="margin: 0 0 24px 0; color: #64748b; font-size: 14px;">
                        Ticker <strong>${item.ticker}</strong> (${item.currency}) - ${this.currentIndex + 1} z ${this.missingPrices.length}
                    </p>
                    
                    <div style="margin-bottom: 16px;">
                        <label style="display: block; margin-bottom: 6px; font-size: 13px; font-weight: 600; color: #475569;">
                            Aktuální cena (${item.currency})
                        </label>
                        <input 
                            type="number" 
                            id="manualPriceInput" 
                            step="0.01" 
                            min="0" 
                            placeholder="např. 31.83"
                            style="
                                width: 100%;
                                padding: 10px 12px;
                                border: 2px solid #e2e8f0;
                                border-radius: 6px;
                                font-size: 16px;
                                box-sizing: border-box;
                            "
                            autofocus
                        />
                    </div>
                    
                    <div style="display: flex; gap: 12px; justify-content: flex-end;">
                        <button 
                            onclick="manualPriceModal.skip()" 
                            class="btn btn-secondary"
                            style="
                                padding: 10px 20px;
                                background: #e2e8f0;
                                border: none;
                                border-radius: 6px;
                                cursor: pointer;
                                font-size: 14px;
                                font-weight: 600;
                                color: #475569;
                            "
                        >
                            Přeskočit
                        </button>
                        <button 
                            onclick="manualPriceModal.save()" 
                            class="btn btn-primary"
                            style="
                                padding: 10px 20px;
                                background: #3b82f6;
                                border: none;
                                border-radius: 6px;
                                cursor: pointer;
                                font-size: 14px;
                                font-weight: 600;
                                color: white;
                            "
                        >
                            Uložit a pokračovat
                        </button>
                    </div>
                    
                    <p style="margin: 16px 0 0 0; font-size: 12px; color: #94a3b8;">
                        💡 Pokud cenu přeskočíte, použije se poslední dostupná cena z transakce.
                    </p>
                </div>
            </div>
        `;

        // Remove existing modal if any
        this.closeModal();

        // Add to body
        document.body.insertAdjacentHTML('beforeend', modalHTML);

        // Focus input
        const input = document.getElementById('manualPriceInput');
        if (input) {
            input.focus();

            // Submit on Enter
            input.addEventListener('keypress', (e) => {
                if (e.key === 'Enter') {
                    this.save();
                }
            });
        }
    }

    /**
     * Skip current ticker
     */
    skip() {
        console.log(`[Manual Price] Skipped ${this.missingPrices[this.currentIndex].ticker}`);
        this.currentIndex++;
        this.showModal();
    }

    /**
     * Save manual price
     */
    async save() {
        const input = document.getElementById('manualPriceInput');
        const price = parseFloat(input.value);

        if (!price || price <= 0) {
            alert('Zadejte platnou cenu (větší než 0)');
            return;
        }

        const item = this.missingPrices[this.currentIndex];

        try {
            const response = await fetch('ajax-save-manual-price.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    ticker: item.ticker,
                    price: price,
                    currency: item.currency,
                    company_name: item.company_name || item.ticker
                })
            });

            const result = await response.json();

            if (result.success) {
                console.log(`[Manual Price] Saved ${item.ticker}: ${price} ${item.currency}`);
                this.currentIndex++;
                this.showModal();
            } else {
                alert('Chyba při ukládání: ' + (result.error || 'Neznámá chyba'));
            }
        } catch (error) {
            console.error('Error saving manual price:', error);
            alert('Chyba při ukládání ceny');
        }
    }

    /**
     * Close modal
     */
    closeModal() {
        const modal = document.getElementById('manualPriceModal');
        if (modal) {
            modal.remove();
        }
    }
}

// Global instance
const manualPriceModal = new ManualPriceModal();
