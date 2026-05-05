<?php

namespace App\Exceptions;

class FinancialPeriodClosedException extends \RuntimeException {
    private string $periodKey;

    public function __construct(string $periodKey, ?string $message = null) {
        $this->periodKey = $periodKey;
        parent::__construct($message ?: 'El período financiero ' . $periodKey . ' ya está cerrado. Crea un ajuste en el período actual.');
    }

    public function getPeriodKey(): string {
        return $this->periodKey;
    }
}
