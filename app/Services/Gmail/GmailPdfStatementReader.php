<?php

namespace App\Services\Gmail;

use RuntimeException;
use Smalot\PdfParser\Parser;
use Throwable;

class GmailPdfStatementReader
{
    private const MAX_PDF_BYTES = 5_000_000;

    /**
     * @return list<list<array{x:float,y:float,text:string}>>
     */
    public function extractPositionedPages(string $contents): array
    {
        if (strlen($contents) > self::MAX_PDF_BYTES || ! str_starts_with($contents, '%PDF-')) {
            throw new RuntimeException('unsupported_schema');
        }

        try {
            $document = (new Parser)->parseContent($contents);
            $pages = $document->getPages();
            if (count($pages) > 60) {
                throw new RuntimeException('pdf_page_limit_exceeded');
            }

            $positionedPages = [];
            foreach ($pages as $page) {
                $elements = [];
                foreach ($page->getDataTm() as $element) {
                    $matrix = $element[0] ?? null;
                    $text = trim((string) ($element[1] ?? ''));
                    if (! is_array($matrix) || $text === '') {
                        continue;
                    }
                    $elements[] = [
                        'x' => (float) ($matrix[4] ?? 0),
                        'y' => (float) ($matrix[5] ?? 0),
                        'text' => $text,
                    ];
                }
                $positionedPages[] = $elements;
            }
        } catch (RuntimeException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new RuntimeException('pdf_parse_failed');
        }

        if (count(array_filter($positionedPages, fn (array $page): bool => $page !== [])) === 0) {
            throw new RuntimeException('pdf_scanned_unsupported');
        }

        return $positionedPages;
    }

    public function extractText(string $contents): string
    {
        if (strlen($contents) > self::MAX_PDF_BYTES || ! str_starts_with($contents, '%PDF-')) {
            throw new RuntimeException('unsupported_schema');
        }

        try {
            $document = (new Parser)->parseContent($contents);
            if (count($document->getPages()) > 60) {
                throw new RuntimeException('pdf_page_limit_exceeded');
            }

            $text = trim(preg_replace('/[\x{00A0}\x{2007}\x{202F}]/u', ' ', $document->getText()) ?? '');
        } catch (RuntimeException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new RuntimeException('pdf_parse_failed');
        }

        if ($text === '') {
            throw new RuntimeException('pdf_scanned_unsupported');
        }

        return $text;
    }
}
