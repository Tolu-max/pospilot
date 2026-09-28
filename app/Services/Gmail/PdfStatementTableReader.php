<?php

namespace App\Services\Gmail;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use RuntimeException;

class PdfStatementTableReader
{
    private const HEADER_CLUSTER_Y_TOLERANCE = 8.0;

    /**
     * @param  list<list<array{x:float,y:float,text:string}>>  $pages
     * @return array{csv:string,headers:list<string>,rows:int}
     */
    public function toCsvFromPositionedPages(array $pages, string $providerSlug): array
    {
        if (! in_array($providerSlug, ['opay', 'palmpay'], true)) {
            throw new RuntimeException('unsupported_schema');
        }

        $headers = ['Transaction Reference', 'Amount', 'Date', 'Status', 'Transaction Type'];
        $accountIdentifier = $this->accountIdentifier($pages);
        if ($accountIdentifier !== null) {
            $headers[] = 'Provider Account Identifier';
        }
        $rows = [];
        foreach ($pages as $page) {
            $groups = $this->groupByY($page);
            $layout = $this->findHeaderLayout($groups, $providerSlug);
            if ($layout === null) {
                continue;
            }

            foreach ($groups as $y => $elements) {
                if ((float) $y >= $layout['y'] - 1) {
                    continue;
                }

                $cells = $this->positionedCells($elements, $layout['columns']);
                if ($this->isHeaderRow($cells, $providerSlug)) {
                    continue;
                }

                $descriptor = trim(implode(' ', array_filter([
                    $cells['description'] ?? null,
                    $cells['channel'] ?? null,
                    $cells['detail'] ?? null,
                ])));
                if (! $this->isPosActivity($descriptor)) {
                    continue;
                }

                $reference = trim((string) ($cells['reference'] ?? $cells['id'] ?? ''));
                $date = $providerSlug === 'opay'
                    ? $this->normalizeDateTime((string) ($cells['value_date'] ?? ''), (string) ($cells['time'] ?? ''))
                    : $this->normalizeDateTime((string) ($cells['date'] ?? ''), '');
                $amounts = $providerSlug === 'opay'
                    ? ['debit' => $this->decimal($cells['debit'] ?? ''), 'credit' => $this->decimal($cells['credit'] ?? '')]
                    : ['debit' => $this->decimal($cells['money_out'] ?? ''), 'credit' => $this->decimal($cells['money_in'] ?? '')];
                $presentAmounts = array_filter($amounts, fn (?string $amount): bool => $amount !== null && BigDecimal::of($amount)->compareTo(0) > 0);

                if ($reference === '' || $date === null || count($presentAmounts) !== 1) {
                    continue;
                }

                if ($providerSlug === 'opay' && $this->decimal($cells['balance'] ?? '') === null) {
                    continue;
                }

                $direction = array_key_first($presentAmounts);
                $rows[] = [
                    $reference,
                    $presentAmounts[$direction],
                    $date,
                    $this->statusForStatementEntry($descriptor),
                    'pos_'.$direction,
                ];
                if ($accountIdentifier !== null) {
                    $rows[array_key_last($rows)][] = $accountIdentifier;
                }
            }
        }

        if ($rows === []) {
            throw new RuntimeException('unsupported_schema');
        }

        $handle = fopen('php://temp', 'w+b');
        fputcsv($handle, $headers);
        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }
        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return ['csv' => is_string($csv) ? $csv : '', 'headers' => $headers, 'rows' => count($rows)];
    }

    /** @param list<list<array{x:float,y:float,text:string}>> $pages */
    private function accountIdentifier(array $pages): ?string
    {
        $identifiers = [];
        foreach ($pages as $page) {
            foreach ($this->groupByY($page) as $elements) {
                foreach ($elements as $index => $element) {
                    if (! str_contains($this->normalizedHeader($element['text']), 'accountnumber')) {
                        continue;
                    }

                    $value = null;
                    if (preg_match('/account\s*number\s*[:#-]?\s*(.*)$/i', $element['text'], $matches) === 1 && trim($matches[1]) !== '') {
                        $value = $this->normalizeAccountNumber($matches[1]);
                    } else {
                        $following = array_filter($elements, fn (array $candidate, int $candidateIndex): bool => $candidateIndex !== $index && $candidate['x'] > $element['x'], ARRAY_FILTER_USE_BOTH);
                        usort($following, fn (array $left, array $right): int => $left['x'] <=> $right['x']);
                        foreach ($following as $candidate) {
                            if (str_contains($this->normalizedHeader($candidate['text']), 'accountname')) {
                                continue;
                            }
                            $value = $this->normalizeAccountNumber($candidate['text']);
                            if ($value !== null) {
                                break;
                            }
                        }
                    }

                    if ($value !== null) {
                        $identifiers[$value] = true;
                    }
                }
            }
        }

        if (count($identifiers) > 1) {
            throw new RuntimeException('multiple_accounts_in_statement');
        }

        return $identifiers === [] ? null : (string) array_key_first($identifiers);
    }

    private function normalizeAccountNumber(string $value): ?string
    {
        if (! preg_match('/^[0-9 -]{6,40}$/', trim($value))) {
            return null;
        }

        $digits = preg_replace('/\D/', '', $value) ?? '';

        return strlen($digits) >= 6 && strlen($digits) <= 32 ? $digits : null;
    }

    /**
     * @return array{csv:string,headers:list<string>,rows:int}
     */
    public function toCsv(string $text): array
    {
        $lines = preg_split('/\R/u', str_replace(["\r", "\x0C"], ["\n", "\n"], $text)) ?: [];
        $headerIndex = null;
        $headers = [];

        foreach ($lines as $index => $line) {
            $cells = $this->cells($line);
            if (count($cells) < 3 || ! $this->looksLikeHeader($cells)) {
                continue;
            }

            $headerIndex = $index;
            $headers = $cells;
            break;
        }

        if ($headerIndex === null) {
            throw new RuntimeException('unsupported_schema');
        }

        $handle = fopen('php://temp', 'w+b');
        fputcsv($handle, $headers);
        $rows = 0;

        foreach (array_slice($lines, $headerIndex + 1) as $line) {
            $cells = $this->cells($line);
            if (count($cells) !== count($headers) || $this->looksLikeHeader($cells)) {
                continue;
            }
            if (count(array_filter($cells, fn (string $cell): bool => trim($cell) !== '')) === 0) {
                continue;
            }

            fputcsv($handle, $cells);
            $rows++;
        }

        if ($rows === 0) {
            fclose($handle);
            throw new RuntimeException('unsupported_schema');
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return ['csv' => is_string($csv) ? $csv : '', 'headers' => $headers, 'rows' => $rows];
    }

    /** @param list<string> $cells */
    private function looksLikeHeader(array $cells): bool
    {
        $normalized = array_map(fn (string $cell): string => strtolower(preg_replace('/[^a-z0-9]+/i', '', $cell) ?? ''), $cells);
        $hasAmount = count(array_intersect($normalized, ['amount', 'transactionamount', 'credit', 'debit', 'paidin', 'paidout'])) > 0;
        $hasDate = count(array_intersect($normalized, ['date', 'time', 'datetime', 'transactiondate', 'transactiontime', 'transactiondatetime', 'createdat'])) > 0;
        $hasStatusOrReference = count(array_intersect($normalized, ['status', 'transactionstatus', 'reference', 'transactionreference', 'transactionid', 'rrn'])) > 0;

        return $hasAmount && $hasDate && $hasStatusOrReference;
    }

    /** @param list<array{x:float,y:float,text:string}> $elements
     * @return array<string, list<array{x:float,y:float,text:string}>>
     */
    private function groupByY(array $elements): array
    {
        $groups = [];
        foreach ($elements as $element) {
            $key = (string) round($element['y']);
            $groups[$key][] = $element;
        }

        return $groups;
    }

    /**
     * @param  array<string, list<array{x:float,y:float,text:string}>>  $groups
     * @return array{y:float,columns:array<string,float>}|null
     */
    private function findHeaderLayout(array $groups, string $providerSlug): ?array
    {
        $headerGroups = [];
        foreach ($groups as $y => $elements) {
            $columns = [];
            foreach ($elements as $element) {
                $field = $this->headerField($element['text'], $providerSlug);
                if ($field !== null) {
                    $columns[$field] = $element['x'];
                }
            }

            if ($columns !== []) {
                $headerGroups[] = ['y' => (float) $y, 'columns' => $columns];
            }
        }

        $required = $providerSlug === 'opay'
            ? ['time', 'value_date', 'description', 'debit', 'credit', 'balance', 'channel', 'reference']
            : ['date', 'detail', 'money_in', 'money_out', 'id'];
        $best = null;
        $bestAnchorColumnCount = 0;
        foreach ($headerGroups as $anchor) {
            $columns = $anchor['columns'];
            foreach ($headerGroups as $nearby) {
                if (abs($nearby['y'] - $anchor['y']) > self::HEADER_CLUSTER_Y_TOLERANCE) {
                    continue;
                }

                foreach ($nearby['columns'] as $field => $x) {
                    $columns[$field] ??= $x;
                }
            }

            if (count(array_intersect($required, array_keys($columns))) === count($required)) {
                $anchorColumnCount = count($anchor['columns']);
                if ($best === null
                    || count($columns) > count($best['columns'])
                    || (count($columns) === count($best['columns']) && $anchorColumnCount > $bestAnchorColumnCount)) {
                    $best = ['y' => $anchor['y'], 'columns' => $columns];
                    $bestAnchorColumnCount = $anchorColumnCount;
                }
            }
        }

        return $best;
    }

    private function headerField(string $text, string $providerSlug): ?string
    {
        $normalized = $this->normalizedHeader($text);

        if ($providerSlug === 'opay') {
            return match ($normalized) {
                'transtime', 'transactiontime' => 'time',
                'valuedate' => 'value_date',
                'description' => 'description',
                'debit', 'debitngn' => 'debit',
                'credit', 'creditngn' => 'credit',
                'balanceafter' => 'balance',
                'channel' => 'channel',
                'transactionreference', 'reference' => 'reference',
                default => null,
            };
        }

        return match ($normalized) {
            'transactiondate', 'date' => 'date',
            'transactiondetail', 'detail' => 'detail',
            'moneyin', 'moneyinngn' => 'money_in',
            'moneyout', 'moneyoutngn' => 'money_out',
            'transactionid', 'reference' => 'id',
            default => null,
        };
    }

    private function normalizedHeader(string $text): string
    {
        return strtolower(preg_replace('/[^a-z0-9]+/i', '', $text) ?? '');
    }

    /** @param array<string,float> $columns
     * @return array<string,string>
     */
    private function positionedCells(array $elements, array $columns): array
    {
        $cells = [];
        foreach ($elements as $element) {
            $nearestColumn = null;
            $nearestDistance = PHP_FLOAT_MAX;
            foreach ($columns as $field => $x) {
                $distance = abs($element['x'] - $x);
                if ($distance < $nearestDistance) {
                    $nearestColumn = $field;
                    $nearestDistance = $distance;
                }
            }
            if ($nearestColumn !== null && $nearestDistance <= 38) {
                $cells[$nearestColumn][] = ['x' => $element['x'], 'text' => trim($element['text'])];
            }
        }

        $result = [];
        foreach ($cells as $field => $parts) {
            usort($parts, fn (array $left, array $right): int => $left['x'] <=> $right['x']);
            $result[$field] = trim(implode(' ', array_column($parts, 'text')));
        }

        return $result;
    }

    /** @param array<string,string> $cells */
    private function isHeaderRow(array $cells, string $providerSlug): bool
    {
        $needles = $providerSlug === 'opay'
            ? ['trans time', 'value date', 'transaction reference']
            : ['transaction date', 'transaction detail', 'transaction id'];
        $text = strtolower(implode(' ', $cells));

        foreach ($needles as $needle) {
            if (str_contains($text, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function isPosActivity(string $descriptor): bool
    {
        return preg_match('/\bpos\b|\bterminal\b|\bcard\b/i', $descriptor) === 1
            && preg_match('/\b(?:airtime|data purchase|bill payment|utility|commission fee|service fee)\b/i', $descriptor) !== 1;
    }

    private function statusForStatementEntry(string $descriptor): string
    {
        $normalized = strtolower($descriptor);

        return match (true) {
            preg_match('/\b(?:reversal|reversed)\b/i', $normalized) === 1 => 'reversed',
            preg_match('/\b(?:pending|processing)\b/i', $normalized) === 1 => 'pending',
            preg_match('/\b(?:failed|declined)\b/i', $normalized) === 1 => 'failed',
            default => 'successful',
        };
    }

    private function decimal(string $value): ?string
    {
        $value = trim(str_ireplace(['NGN', '₦', ','], '', $value));
        if ($value === '' || in_array($value, ['-', '—', '–'], true)) {
            return null;
        }
        if (! preg_match('/^\d+(?:\.\d+)?$/', $value)) {
            return null;
        }

        try {
            return BigDecimal::of($value)->toScale(2, RoundingMode::Unnecessary)->__toString();
        } catch (\Throwable) {
            return null;
        }
    }

    private function normalizeDateTime(string $date, string $time): ?string
    {
        $date = trim($date);
        $time = trim($time);
        if ($date === '') {
            return null;
        }

        try {
            if (preg_match('/^(\d{1,2}\/\d{1,2}\/\d{4})(?:\s+(.*))?$/', $date, $matches) === 1) {
                $dateParts = array_map('intval', explode('/', $matches[1]));
                $format = $dateParts[0] > 12 ? 'd/m/Y' : ($dateParts[1] > 12 ? 'm/d/Y' : 'm/d/Y');
                $parsed = CarbonImmutable::createFromFormat('!'.$format, $matches[1]);
                $time = $time !== '' ? $time : trim((string) ($matches[2] ?? ''));
            } else {
                $parsed = CarbonImmutable::parse($date);
            }

            if ($time !== '' && preg_match('/^\d{1,2}:\d{2}(?::\d{2})?\s*(?:AM|PM)?$/i', $time) === 1) {
                $timeValue = CarbonImmutable::parse($time);
                $parsed = $parsed->setTime((int) $timeValue->format('H'), (int) $timeValue->format('i'), (int) $timeValue->format('s'));
            }

            return $parsed->toIso8601String();
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return list<string> */
    private function cells(string $line): array
    {
        $line = trim($line);
        if ($line === '') {
            return [];
        }

        $cells = preg_split('/(?:\t+| {2,})/u', $line) ?: [];
        if (count($cells) === 1) {
            $cells = preg_split('/\s+/u', $line) ?: [];
        }

        return array_values(array_map(fn (string $cell): string => trim($cell), $cells));
    }
}
