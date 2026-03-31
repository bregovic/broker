
// /broker/js/core/DatabaseWriter.js
export default class DatabaseWriter {
  constructor() {
    this.endpoint = 'import-handler.php';
  }

  /**
   * Save transactions to database
   */
  async save(transactions, provider = 'unknown') {
    if (!transactions || transactions.length === 0) {
      return {
        success: false,
        error: 'Žádné transakce k uložení'
      };
    }

    try {
      const response = await fetch(this.endpoint, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json'
        },
        credentials: 'same-origin',
        body: JSON.stringify({
          provider: provider,
          transactions: transactions
        })
      });

      if (!response.ok) {
        throw new Error(`HTTP ${response.status}`);
      }

      const result = await response.json();
      return result;
      
    } catch (error) {
      console.error('Database write error:', error);
      return {
        success: false,
        error: `Chyba při ukládání: ${error.message}`
      };
    }
  }
}