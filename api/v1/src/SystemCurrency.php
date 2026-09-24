<?php
declare(strict_types=1);

namespace Ramza\MobileApi;

/**
 * One source of truth for money displayed by the mobile API.
 *
 * WoWonder stores currency_array as a numeric list on many installations,
 * while currency_symbol_array is keyed by ISO/custom currency code.  Older
 * mobile endpoints incorrectly treated the numeric list index as the code.
 */
final class SystemCurrency
{
    public static function code(): string
    {
        global $wo;
        $code = strtoupper(trim((string)($wo['config']['currency'] ?? '')));
        return self::validCode($code) ? $code : 'USD';
    }

    public static function normalize(?string $code): string
    {
        $value = strtoupper(trim((string)$code));
        return self::validCode($value) ? $value : self::code();
    }

    public static function symbol(?string $code = null): string
    {
        global $wo;
        $currency = self::normalize($code);
        $symbols = (array)($wo['config']['currency_symbol_array'] ?? []);
        foreach ($symbols as $key => $value) {
            if (strtoupper(trim((string)$key)) !== $currency) {
                continue;
            }
            $symbol = self::clean((string)$value);
            if ($symbol !== '') {
                return $symbol;
            }
        }

        if (function_exists('Wo_GetCurrency')) {
            $symbol = self::clean((string)\Wo_GetCurrency($currency));
            // Wo_GetCurrency falls back to "$" for every unknown/custom code.
            // Do not mislabel a non-USD backend currency as US dollars.
            if ($symbol !== '' && !($symbol === '$' && $currency !== 'USD')) {
                return $symbol;
            }
        }
        return $currency;
    }

    /** @return list<array{code:string,name:string,symbol:string}> */
    public static function options(): array
    {
        global $wo;
        $codes = [self::code()];
        foreach ((array)($wo['config']['currency_array'] ?? []) as $key => $value) {
            $candidate = is_string($key) && !ctype_digit($key)
                ? $key
                : (is_array($value) ? ($value['code'] ?? $value['text'] ?? '') : $value);
            $candidate = strtoupper(trim((string)$candidate));
            if (self::validCode($candidate) && !in_array($candidate, $codes, true)) {
                $codes[] = $candidate;
            }
        }

        return array_map(static fn(string $code): array => [
            'code' => $code,
            'name' => $code,
            'symbol' => self::symbol($code),
        ], $codes);
    }

    private static function validCode(string $code): bool
    {
        return $code !== '' && strlen($code) <= 40 && preg_match('/^[A-Z0-9._-]+$/', $code) === 1;
    }

    private static function clean(string $value): string
    {
        return trim(html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
}
