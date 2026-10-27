<?php

namespace App\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

class FireflyService
{
    /**
     * Create a new transaction in Firefly III.
     *
     * @param  array{amount: float, description: string, type: string}  $transactionData
     */
    public function storeTransaction(array $transactionData): Response
    {
        $baseUrl = config('services.firefly.url');
        $token = config('services.firefly.token');
        $sourceAccount = $transactionData['source_account'] ?? config('services.firefly.default_source', 'Cash');
        $defaultRevenue = config('services.firefly.default_revenue', 'Income');

        $type = $transactionData['type'] ?? 'withdrawal';
        $amount = (string) $transactionData['amount'];
        $description = $transactionData['description'];
        $date = ! empty($transactionData['date'])
            ? Carbon::parse($transactionData['date'])->toIso8601String()
            : now()->toIso8601String();

        $transactionItem = [
            'type' => $type,
            'date' => $date,
            'amount' => $amount,
            'description' => $description,
        ];

        if ($type === 'deposit') {
            $transactionItem['destination_name'] = $sourceAccount;
            $transactionItem['source_name'] = $defaultRevenue;
        } else {
            $transactionItem['source_name'] = $sourceAccount;
        }

        return Http::withToken($token)
            ->acceptJson()
            ->contentType('application/json')
            ->post("{$baseUrl}/api/v1/transactions", [
                'apply_rules' => true,
                'fire_webhooks' => true,
                'transactions' => [$transactionItem],
            ]);
    }

    /**
     * Delete a transaction in Firefly III by ID.
     */
    public function deleteTransaction(string|int $transactionId): Response
    {
        $baseUrl = config('services.firefly.url');
        $token = config('services.firefly.token');

        return Http::withToken($token)
            ->acceptJson()
            ->delete("{$baseUrl}/api/v1/transactions/{$transactionId}");
    }
}
