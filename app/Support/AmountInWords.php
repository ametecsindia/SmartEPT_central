<?php

namespace App\Support;

/**
 * Rupee amounts in words, INDIAN numbering (crore/lakh/thousand — not
 * million/billion), as required on Indian tax invoices.
 * 43542.00  → "Rupees Forty Three Thousand Five Hundred Forty Two Only"
 * 12345678.50 → "Rupees One Crore Twenty Three Lakh Forty Five Thousand
 *               Six Hundred Seventy Eight and Fifty Paise Only"
 */
final class AmountInWords
{
    private const ONES = [
        '', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine',
        'Ten', 'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen',
        'Seventeen', 'Eighteen', 'Nineteen',
    ];

    private const TENS = [
        '', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety',
    ];

    public static function convert(float $amount): string
    {
        // Split into rupees + paise ONCE, on the rounded value, so 42.999
        // never becomes "Forty Two and One Hundred Paise".
        $amount = round(abs($amount), 2);
        $rupees = (int) floor($amount);
        $paise = (int) round(($amount - $rupees) * 100);

        $out = 'Rupees ' . ($rupees > 0 ? self::number($rupees) : 'Zero');

        if ($paise > 0) {
            $out .= ' and ' . self::number($paise) . ' Paise';
        }

        return $out . ' Only';
    }

    /** Whole number in words with Indian 2-2-3 digit grouping. */
    private static function number(int $n): string
    {
        if ($n === 0) {
            return 'Zero';
        }

        $parts = [];

        if ($n >= 10000000) {
            // Recurse for the crore part so 100+ crore reads "One Hundred Crore".
            $parts[] = self::number(intdiv($n, 10000000)) . ' Crore';
            $n %= 10000000;
        }
        if ($n >= 100000) {
            $parts[] = self::upToNinetyNine(intdiv($n, 100000)) . ' Lakh';
            $n %= 100000;
        }
        if ($n >= 1000) {
            $parts[] = self::upToNinetyNine(intdiv($n, 1000)) . ' Thousand';
            $n %= 1000;
        }
        if ($n >= 100) {
            $parts[] = self::ONES[intdiv($n, 100)] . ' Hundred';
            $n %= 100;
        }
        if ($n > 0) {
            $parts[] = self::upToNinetyNine($n);
        }

        return implode(' ', $parts);
    }

    private static function upToNinetyNine(int $n): string
    {
        if ($n < 20) {
            return self::ONES[$n];
        }

        return trim(self::TENS[intdiv($n, 10)] . ' ' . self::ONES[$n % 10]);
    }
}
