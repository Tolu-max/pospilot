<?php

namespace App\Services\Gmail;

use RuntimeException;
use Smalot\PdfParser\Parser;
use Throwable;

class GmailPdfStatementReader
{
    private const MAX_PDF_BYTES = 5_000_000;

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
