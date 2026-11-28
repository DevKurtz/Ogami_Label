<?php

namespace App\Services;

final class MfgDateFormatter
{
    public static function format(string $value): string
    {
        $value = trim($value);

        if (preg_match('/^(\d{2})[.\/-](\d{1,2})[.\/-](\d{1,2})$/', $value, $parts)) {
            $year = 2000 + (int) $parts[1];
            $month = (int) $parts[2];
            $day = (int) $parts[3];

            return checkdate($month, $day, $year)
                ? sprintf('%04d/%02d/%02d', $year, $month, $day)
                : $value;
        }

        if (preg_match('/^(\d{4})[.\/-](\d{1,2})[.\/-](\d{1,2})$/', $value, $parts)) {
            $year = (int) $parts[1];
            $month = (int) $parts[2];
            $day = (int) $parts[3];

            return checkdate($month, $day, $year)
                ? sprintf('%04d/%02d/%02d', $year, $month, $day)
                : $value;
        }

        if (preg_match('/^(\d{1,2})[.\/-](\d{1,2})[.\/-](\d{4})$/', $value, $parts)) {
            $day = (int) $parts[1];
            $month = (int) $parts[2];
            $year = (int) $parts[3];

            return checkdate($month, $day, $year)
                ? sprintf('%04d/%02d/%02d', $year, $month, $day)
                : $value;
        }

        return $value;
    }
}
