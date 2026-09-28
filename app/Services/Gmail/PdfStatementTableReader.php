<?php

namespace App\Services\Gmail;

use RuntimeException;

class PdfStatementTableReader
{
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
