<?php

namespace App\Services;

use Illuminate\Support\Carbon;

class ExpenseParserService
{
    /**
     * Parse natural language text into a structured transaction array.
     *
     * @return array{amount: float, description: string, type: string, date: string}|null
     */
    public function parse(string $text): ?array
    {
        $text = trim($text);

        if (empty($text)) {
            return null;
        }

        // Determine transaction type (default: withdrawal / pengeluaran)
        $type = 'withdrawal';
        if (str_contains($text, '+')) {
            $type = 'deposit';
            $text = str_replace('+', '', $text);
        }

        // 1. Extract Date first (so date numbers like 3/9 don't get confused as amounts)
        $date = $this->extractDate($text);

        // 2. Extract Source Account (tf, transfer, trf, qris -> Rekening Bank, default: Dompet)
        $sourceAccount = $this->extractSourceAccount($text);

        // 3. Extract Amount (prioritize suffixed/prefixed amount, fallback to last plain number)
        $numericAmount = $this->extractAmount($text);

        if ($numericAmount === null || $numericAmount <= 0) {
            return null;
        }

        // Clean up remaining description
        $description = trim(preg_replace('/\s+/', ' ', $text));

        // Fallback description if user only entered amount (e.g. "25000")
        if (empty($description)) {
            $description = $type === 'deposit' ? 'Pemasukan' : 'Pengeluaran';
        }

        return [
            'amount' => $numericAmount,
            'description' => $description,
            'type' => $type,
            'date' => $date->format('Y-m-d'),
            'source_account' => $sourceAccount,
        ];
    }

    private function extractSourceAccount(string &$text): string
    {
        $keywords = [
            'transfer' => 'Mandiri',
            'qris' => 'Mandiri',
            'trf' => 'Mandiri',
            'tf' => 'Mandiri',
        ];

        foreach ($keywords as $keyword => $accountName) {
            $pattern = '/\b'.preg_quote($keyword, '/').'\b/i';
            if (preg_match($pattern, $text)) {
                $text = (string) preg_replace($pattern, '', $text);

                return $accountName;
            }
        }

        return 'dompet';
    }

    private function extractAmount(string &$text): ?float
    {
        // 1. Priority: Suffixed or Rp-prefixed amount (e.g. Rp35.000, 40rb, 1.5jt, 25k)
        $suffixedPattern = '/(?:Rp\.?\s*)?(\d+(?:[\.,]\d+)*(?:\s*(?:k|rb|ribu|jt|juta))?)/i';
        if (preg_match_all($suffixedPattern, $text, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $raw = $match[0];
                if (preg_match('/(?:Rp|k|rb|ribu|jt|juta)/i', $raw)) {
                    $amount = $this->convertAmountToNumber($raw);
                    if ($amount > 0) {
                        $text = (string) preg_replace('/'.preg_quote($raw, '/').'/i', '', $text, 1);

                        return $amount;
                    }
                }
            }
        }

        // 2. Fallback: Plain numbers without suffix/prefix. Take the LAST plain number (e.g. in "beli 2 galon 40000")
        $plainPattern = '/\b(\d+(?:[\.,]\d+)*)\b/';
        if (preg_match_all($plainPattern, $text, $matches, PREG_SET_ORDER)) {
            $lastMatch = end($matches);
            $raw = $lastMatch[0];
            $amount = $this->convertAmountToNumber($raw);
            if ($amount > 0) {
                $pos = strrpos($text, $raw);
                if ($pos !== false) {
                    $text = substr_replace($text, '', $pos, strlen($raw));
                }

                return $amount;
            }
        }

        return null;
    }

    private function extractDate(string &$text): Carbon
    {
        // 1. Check relative words
        if (preg_match('/\bkemarin\s+lusa\b/i', $text, $matches)) {
            $text = (string) preg_replace('/\bkemarin\s+lusa\b/i', '', $text);

            return now()->subDays(2);
        }

        if (preg_match('/\bkemarin\b/i', $text, $matches)) {
            $text = (string) preg_replace('/\bkemarin\b/i', '', $text);

            return now()->subDay();
        }

        if (preg_match('/\bhari\s+ini\b/i', $text, $matches)) {
            $text = (string) preg_replace('/\bhari\s+ini\b/i', '', $text);

            return now();
        }

        // 2. Check date with month name (e.g., "3 sept", "3 september 2025")
        $monthPattern = '/\b(\d{1,2})\s+(jan(?:uari)?|feb(?:ruari)?|mar(?:et)?|apr(?:il)?|mei|jun(?:i)?|jul(?:i)?|agustus|agu(?:stus)?|ags|sept(?:ember)?|sep|okt(?:ober)?|nov(?:ember)?|des(?:ember)?)(?:\s+(\d{4}))?\b/i';
        if (preg_match($monthPattern, $text, $matches)) {
            $day = (int) $matches[1];
            $monthStr = strtolower($matches[2]);
            $year = ! empty($matches[3]) ? (int) $matches[3] : null;

            $month = $this->parseMonthName($monthStr);

            if ($month && checkdate($month, $day, $year ?? (int) now()->year)) {
                $text = (string) preg_replace($monthPattern, '', $text);

                return $this->resolveDate($day, $month, $year);
            }
        }

        // 3. Check numeric date (e.g., "3/9", "03-09", "3/9/2025")
        $numericDatePattern = '/\b(\d{1,2})[\/-](\d{1,2})(?:[\/-](\d{4}))?\b/i';
        if (preg_match($numericDatePattern, $text, $matches)) {
            $day = (int) $matches[1];
            $month = (int) $matches[2];
            $year = ! empty($matches[3]) ? (int) $matches[3] : null;

            if ($month >= 1 && $month <= 12 && checkdate($month, $day, $year ?? (int) now()->year)) {
                $text = (string) preg_replace($numericDatePattern, '', $text);

                return $this->resolveDate($day, $month, $year);
            }
        }

        // Default: today
        return now();
    }

    private function resolveDate(int $day, int $month, ?int $year): Carbon
    {
        $now = now();
        $targetYear = $year ?? (int) $now->year;

        $date = Carbon::createFromDate($targetYear, $month, $day)->startOfDay();

        // If no year specified and date is in the future, rollback to previous year
        if ($year === null && $date->isFuture() && ! $date->isToday()) {
            $date->subYear();
        }

        return $date;
    }

    private function parseMonthName(string $monthStr): ?int
    {
        $monthMap = [
            'jan' => 1, 'januari' => 1,
            'feb' => 2, 'februari' => 2,
            'mar' => 3, 'maret' => 3,
            'apr' => 4, 'april' => 4,
            'mei' => 5,
            'jun' => 6, 'juni' => 6,
            'jul' => 7, 'juli' => 7,
            'agu' => 8, 'agustus' => 8, 'ags' => 8,
            'sep' => 9, 'sept' => 9, 'september' => 9,
            'okt' => 10, 'oktober' => 10,
            'nov' => 11, 'november' => 11,
            'des' => 12, 'desember' => 12,
        ];

        return $monthMap[$monthStr] ?? null;
    }

    private function convertAmountToNumber(string $amountStr): float
    {
        $cleaned = strtolower(trim($amountStr));
        $cleaned = (string) preg_replace('/^rp\.?\s*/i', '', $cleaned);

        $multiplier = 1;
        if (preg_match('/(jt|juta)$/i', $cleaned)) {
            $multiplier = 1000000;
            $cleaned = (string) preg_replace('/(jt|juta)$/i', '', $cleaned);
        } elseif (preg_match('/(k|rb|ribu)$/i', $cleaned)) {
            $multiplier = 1000;
            $cleaned = (string) preg_replace('/(k|rb|ribu)$/i', '', $cleaned);
        }

        $cleaned = trim($cleaned);

        if ($multiplier > 1 && str_contains($cleaned, ',')) {
            $cleaned = str_replace(',', '.', $cleaned);
        } elseif ($multiplier == 1) {
            $cleaned = str_replace(['.', ','], '', $cleaned);
        } else {
            if (! preg_match('/^\d+\.\d{1,2}$/', $cleaned)) {
                $cleaned = str_replace(['.', ','], '', $cleaned);
            }
        }

        return (float) $cleaned * $multiplier;
    }
}
