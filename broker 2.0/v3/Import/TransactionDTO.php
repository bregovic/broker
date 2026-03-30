<?php
namespace Broker\V3\Import;

/**
 * Data Transfer Object - Jednotná struktura transakce napříč celým systémem
 */
class TransactionDTO {
    public ?string $ticker = null;
    public ?string $date = null; // YYYY-MM-DD
    public ?string $type = null; // BUY, SELL, DIVIDEND, FEE, TAX
    public float $quantity = 0;
    public float $pricePerUnit = 0;
    public string $currency = 'USD';
    public float $fee = 0;
    public float $totalAmount = 0;
    public ?string $brokerTradeId = null;
    public array $metadata = [];

    public function toArray(): array {
        return [
            'ticker' => $this->ticker,
            'transaction_date' => $this->date,
            'type' => $this->type,
            'quantity' => $this->quantity,
            'price_per_unit' => $this->pricePerUnit,
            'currency' => $this->currency,
            'fee' => $this->fee,
            'total_amount' => $this->totalAmount,
            'broker_trade_id' => $this->brokerTradeId,
            'metadata' => json_encode($this->metadata)
        ];
    }
}
