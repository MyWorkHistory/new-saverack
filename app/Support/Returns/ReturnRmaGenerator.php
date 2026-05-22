<?php

namespace App\Support\Returns;

use App\Models\ClientAccountReturn;

final class ReturnRmaGenerator
{
    public static function generateUniqueForAccount(int $clientAccountId): string
    {
        for ($attempt = 0; $attempt < 50; $attempt++) {
            $candidate = self::randomRmaNumber();
            $exists = ClientAccountReturn::query()
                ->where('client_account_id', $clientAccountId)
                ->where('rma_number', $candidate)
                ->exists();
            if (! $exists) {
                return $candidate;
            }
        }

        throw new \RuntimeException('Could not generate a unique RMA number.');
    }

    public static function randomRmaNumber(): string
    {
        $letters = chr(random_int(65, 90)).chr(random_int(65, 90));
        $digits = str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);

        return $letters.$digits;
    }
}
