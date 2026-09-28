<?php

namespace Tests\Unit;

use App\Services\Gmail\GmailPdfStatementReader;
use App\Services\Gmail\PdfStatementTableReader;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class GmailPdfStatementReaderTest extends TestCase
{
    public function test_machine_readable_pdf_table_is_converted_to_sanitized_csv(): void
    {
        $pdf = $this->pdfWithText("Date  Amount  Status  Reference\n2026-09-01  1000.00  SUCCESS  TEST-REF-1");
        $text = (new GmailPdfStatementReader)->extractText($pdf);
        $table = (new PdfStatementTableReader)->toCsv($text);

        $this->assertSame(['Date', 'Amount', 'Status', 'Reference'], $table['headers']);
        $this->assertSame(1, $table['rows']);
        $this->assertSame("Date,Amount,Status,Reference\n2026-09-01,1000.00,SUCCESS,TEST-REF-1\n", $table['csv']);
    }

    public function test_pdf_without_transaction_table_is_not_guessed(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('unsupported_schema');

        (new PdfStatementTableReader)->toCsv("Account summary\nOpening balance 10000.00\nClosing balance 11000.00");
    }

    public function test_non_pdf_bytes_are_rejected_without_parsing(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('unsupported_schema');

        (new GmailPdfStatementReader)->extractText('not-a-pdf');
    }

    private function pdfWithText(string $text): string
    {
        $escapedText = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
        $stream = '';
        foreach (explode("\n", $escapedText) as $lineIndex => $line) {
            $stream .= 'BT /F1 10 Tf 40 '.(750 - ($lineIndex * 15)).' Td ('.$line.') Tj ET ';
        }
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 5 0 R >> >> /Contents 4 0 R >>',
            '<< /Length '.strlen($stream)." >>\nstream\n".$stream."\nendstream",
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        ];
        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objects as $index => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($index + 1)." 0 obj\n".$object."\nendobj\n";
        }
        $xrefOffset = strlen($pdf);
        $pdf .= "xref\n0 6\n0000000000 65535 f \n";
        foreach (array_slice($offsets, 1) as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }
        $pdf .= "trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n".$xrefOffset."\n%%EOF";

        return $pdf;
    }
}
