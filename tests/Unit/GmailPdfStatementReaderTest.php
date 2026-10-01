<?php

namespace Tests\Unit;

use App\Services\Gmail\GmailPdfStatementReader;
use App\Services\Gmail\PdfStatementTableReader;
use Carbon\CarbonImmutable;
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

    public function test_opay_positioned_statement_imports_only_referenced_pos_rows(): void
    {
        $pdf = $this->positionedPdf([
            [80, 740, 'Account Number'], [180, 740, '0123456789'],
            [50, 700, 'Trans. Time'], [120, 700, 'Value Date'], [185, 700, 'Description'], [250, 700, 'Debit (NGN)'],
            [300, 700, 'Credit (NGN)'], [340, 706, 'Balance After'], [390, 700, 'Channel'], [455, 700, 'Transaction Reference'],
            [50, 680, '09:15:00 AM'], [120, 680, '09/28/2026'], [185, 680, 'POS purchase'], [250, 680, '-'],
            [300, 680, '5,000.00'], [340, 680, '17,000.00'], [390, 680, 'POS'], [455, 680, 'OP-TEST-REF-1001'],
            [50, 660, '09:30:00 AM'], [120, 660, '09/28/2026'], [185, 660, 'Airtime purchase'], [250, 660, '1,000.00'],
            [300, 660, '-'], [340, 660, '16,000.00'], [390, 660, 'Wallet'], [455, 660, 'OP-TEST-REF-1002'],
        ]);

        $pages = (new GmailPdfStatementReader)->extractPositionedPages($pdf);
        $table = (new PdfStatementTableReader)->toCsvFromPositionedPages($pages, 'opay');
        $rows = array_map('str_getcsv', array_slice(explode("\n", trim($table['csv'])), 1));

        $this->assertSame(2, $table['rows']);
        $this->assertSame('OP-TEST-REF-1001', $rows[0][0]);
        $this->assertSame('5000.00', $rows[0][1]);
        $this->assertSame('2026-09-28 09:15:00', CarbonImmutable::parse($rows[0][2])->format('Y-m-d H:i:s'));
        $this->assertSame('successful', $rows[0][3]);
        $this->assertSame('pos_credit', $rows[0][4]);
        $this->assertSame('pos', $rows[0][5]);
        $this->assertSame('0123456789', $rows[0][7]);
        $this->assertSame('wallet', $rows[1][5]);
        $this->assertSame(['pos' => 1, 'wallet' => 1, 'ambiguous' => 0], array_intersect_key($table['activity_summary'], array_flip(['pos', 'wallet', 'ambiguous'])));
    }

    public function test_opay_wallet_activity_is_classified_without_being_imported_as_pos(): void
    {
        $pdf = $this->positionedPdf([
            [80, 740, 'Account Number'], [180, 740, '0123456789'],
            [50, 700, 'Trans. Time'], [120, 700, 'Value Date'], [185, 700, 'Description'], [250, 700, 'Debit (NGN)'],
            [300, 700, 'Credit (NGN)'], [340, 706, 'Balance After'], [390, 700, 'Channel'], [455, 700, 'Transaction Reference'],
            [50, 680, '09:15:00 AM'], [120, 680, '09/28/2026'], [185, 680, 'Wallet withdrawal'], [250, 680, '5,000.00'],
            [300, 680, '-'], [340, 680, '12,000.00'], [390, 680, 'Wallet'], [455, 680, 'OP-TEST-REF-2001'],
        ]);
        $pages = (new GmailPdfStatementReader)->extractPositionedPages($pdf);

        $table = (new PdfStatementTableReader)->toCsvFromPositionedPages($pages, 'opay');
        $rows = array_map('str_getcsv', array_slice(explode("\n", trim($table['csv'])), 1));

        $this->assertSame(1, $table['activity_summary']['wallet']);
        $this->assertSame('wallet', $rows[0][5]);
        $this->assertSame([], $table['activity_summary']['patterns']);
    }

    public function test_card_word_alone_does_not_prove_pos_terminal_activity(): void
    {
        $pdf = $this->positionedPdf([
            [80, 740, 'Account Number'], [180, 740, '0123456789'],
            [50, 700, 'Trans. Time'], [120, 700, 'Value Date'], [185, 700, 'Description'], [250, 700, 'Debit (NGN)'],
            [300, 700, 'Credit (NGN)'], [340, 706, 'Balance After'], [390, 700, 'Channel'], [455, 700, 'Transaction Reference'],
            [50, 680, '09:15:00 AM'], [120, 680, '09/28/2026'], [185, 680, 'Card purchase'], [250, 680, '-'],
            [300, 680, '5,000.00'], [340, 680, '17,000.00'], [390, 680, 'Wallet'], [455, 680, 'OP-TEST-REF-2002'],
        ]);
        $pages = (new GmailPdfStatementReader)->extractPositionedPages($pdf);

        $table = (new PdfStatementTableReader)->toCsvFromPositionedPages($pages, 'opay');
        $rows = array_map('str_getcsv', array_slice(explode("\n", trim($table['csv'])), 1));

        $this->assertSame('wallet', $rows[0][5]);
    }

    public function test_pos_word_without_a_terminal_channel_or_activity_phrase_stays_ambiguous(): void
    {
        $pdf = $this->positionedPdf([
            [80, 740, 'Account Number'], [180, 740, '0123456789'],
            [50, 700, 'Trans. Time'], [120, 700, 'Value Date'], [185, 700, 'Description'], [250, 700, 'Debit (NGN)'],
            [300, 700, 'Credit (NGN)'], [340, 706, 'Balance After'], [390, 700, 'Channel'], [455, 700, 'Transaction Reference'],
            [50, 680, '09:15:00 AM'], [120, 680, '09/28/2026'], [185, 680, 'POS agent transfer'], [250, 680, '-'],
            [300, 680, '5,000.00'], [340, 680, '17,000.00'], [390, 680, 'Online'], [455, 680, 'OP-TEST-REF-2003'],
        ]);
        $pages = (new GmailPdfStatementReader)->extractPositionedPages($pdf);
        $table = (new PdfStatementTableReader)->toCsvFromPositionedPages($pages, 'opay');
        $rows = array_map('str_getcsv', array_slice(explode("\n", trim($table['csv'])), 1));

        $this->assertSame('ambiguous', $rows[0][5]);
        $this->assertSame(1, $table['activity_summary']['ambiguous']);
        $this->assertStringNotContainsString('POS agent transfer', json_encode($table['activity_summary'], JSON_THROW_ON_ERROR));
    }

    public function test_mobile_app_activity_is_excluded_and_safe_pattern_categories_are_retained(): void
    {
        $pdf = $this->positionedPdf([
            [80, 740, 'Account Number'], [180, 740, '0123456789'],
            [50, 700, 'Trans. Time'], [120, 700, 'Value Date'], [185, 700, 'Description'], [250, 700, 'Debit (NGN)'],
            [300, 700, 'Credit (NGN)'], [340, 706, 'Balance After'], [390, 700, 'Channel'], [455, 700, 'Transaction Reference'],
            [50, 680, '09:15:00 AM'], [120, 680, '09/28/2026'], [185, 680, 'Mobile payment withdrawal'], [250, 680, '5,000.00'],
            [300, 680, '-'], [340, 680, '12,000.00'], [390, 680, 'Mobile App'], [455, 680, 'OP-TEST-REF-2004'],
        ]);

        $table = (new PdfStatementTableReader)->toCsvFromPositionedPages((new GmailPdfStatementReader)->extractPositionedPages($pdf), 'opay');
        $rows = array_map('str_getcsv', array_slice(explode("\n", trim($table['csv'])), 1));

        $this->assertSame('wallet', $rows[0][5]);
        $this->assertSame(1, $table['activity_summary']['wallet']);
        $this->assertSame([], $table['activity_summary']['patterns']);
    }

    public function test_unrecognized_ambiguous_pattern_cannot_be_selected_for_pos_import(): void
    {
        $pdf = $this->positionedPdf([
            [80, 740, 'Account Number'], [180, 740, '0123456789'],
            [50, 700, 'Trans. Time'], [120, 700, 'Value Date'], [185, 700, 'Description'], [250, 700, 'Debit (NGN)'],
            [300, 700, 'Credit (NGN)'], [340, 706, 'Balance After'], [390, 700, 'Channel'], [455, 700, 'Transaction Reference'],
            [50, 680, '09:15:00 AM'], [120, 680, '09/28/2026'], [185, 680, 'Reference ZXCV123456789'], [250, 680, '5,000.00'],
            [300, 680, '-'], [340, 680, '12,000.00'], [390, 680, ''], [455, 680, 'OP-TEST-REF-2005'],
        ]);

        $table = (new PdfStatementTableReader)->toCsvFromPositionedPages((new GmailPdfStatementReader)->extractPositionedPages($pdf), 'opay');

        $this->assertSame(1, $table['activity_summary']['ambiguous']);
        $this->assertFalse($table['activity_summary']['patterns'][0]['selectable']);
        $this->assertStringNotContainsString('ZXCV123456789', json_encode($table['activity_summary'], JSON_THROW_ON_ERROR));
    }

    public function test_palmpay_positioned_statement_normalizes_money_in_and_skips_non_pos_rows(): void
    {
        $pdf = $this->positionedPdf([
            [25, 700, 'Transaction Date'], [135, 700, 'Transaction Detail'], [250, 700, 'Money In (NGN)'],
            [370, 700, 'Money Out (NGN)'], [475, 700, 'Transaction ID'],
            [25, 680, '28/09/2026 10:30:00 AM'], [135, 680, 'POS Card Payment'], [250, 680, '7,250.50'],
            [370, 680, '-'], [475, 680, 'PP-TEST-REF-1001'],
            [25, 660, '28/09/2026 10:35:00 AM'], [135, 660, 'Airtime Purchase'], [250, 660, '500.00'],
            [370, 660, '-'], [475, 660, 'PP-TEST-REF-1002'],
        ]);

        $pages = (new GmailPdfStatementReader)->extractPositionedPages($pdf);
        $table = (new PdfStatementTableReader)->toCsvFromPositionedPages($pages, 'palmpay');
        $rows = array_map('str_getcsv', array_slice(explode("\n", trim($table['csv'])), 1));

        $this->assertSame(2, $table['rows']);
        $this->assertSame('PP-TEST-REF-1001', $rows[0][0]);
        $this->assertSame('7250.50', $rows[0][1]);
        $this->assertSame('2026-09-28 10:30:00', CarbonImmutable::parse($rows[0][2])->format('Y-m-d H:i:s'));
        $this->assertSame('successful', $rows[0][3]);
        $this->assertSame('pos_credit', $rows[0][4]);
        $this->assertSame('pos', $rows[0][5]);
        $this->assertSame('wallet', $rows[1][5]);
    }

    public function test_palmpay_signed_money_out_is_normalized_as_a_positive_pos_debit(): void
    {
        $pdf = $this->positionedPdf([
            [25, 700, 'Transaction Date'], [135, 700, 'Transaction Detail'], [250, 700, 'Money In (NGN)'],
            [370, 700, 'Money Out (NGN)'], [475, 700, 'Transaction ID'],
            [25, 680, '28/09/2026 10:30:00 AM'], [135, 680, 'POS Terminal Withdrawal'], [250, 680, '-'],
            [370, 680, '-5,000.00'], [475, 680, 'PP-TEST-REF-2002'],
        ]);
        $pages = (new GmailPdfStatementReader)->extractPositionedPages($pdf);
        $table = (new PdfStatementTableReader)->toCsvFromPositionedPages($pages, 'palmpay');
        $rows = array_map('str_getcsv', array_slice(explode("\n", trim($table['csv'])), 1));

        $this->assertSame(1, $table['rows']);
        $this->assertSame('5000.00', $rows[0][1]);
        $this->assertSame('pos_debit', $rows[0][4]);
    }

    public function test_statement_row_with_both_money_directions_is_not_imported(): void
    {
        $pdf = $this->positionedPdf([
            [50, 700, 'Trans. Time'], [120, 700, 'Value Date'], [185, 700, 'Description'], [250, 700, 'Debit (NGN)'],
            [300, 700, 'Credit (NGN)'], [340, 706, 'Balance After'], [390, 700, 'Channel'], [455, 700, 'Transaction Reference'],
            [50, 680, '09:15:00 AM'], [120, 680, '09/28/2026'], [185, 680, 'POS purchase'], [250, 680, '1,000.00'],
            [300, 680, '5,000.00'], [340, 680, '17,000.00'], [390, 680, 'POS'], [455, 680, 'OP-TEST-REF-1001'],
        ]);
        $pages = (new GmailPdfStatementReader)->extractPositionedPages($pdf);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('unsupported_schema');
        (new PdfStatementTableReader)->toCsvFromPositionedPages($pages, 'opay');
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

    /** @param list<array{int,int,string}> $elements */
    private function positionedPdf(array $elements): string
    {
        $stream = '';
        foreach ($elements as [$x, $y, $text]) {
            $escapedText = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
            $stream .= 'BT /F1 10 Tf '.$x.' '.$y.' Td ('.$escapedText.') Tj ET ';
        }
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 5 0 R >> >> /Contents 4 0 R >>',
            '<< /Length '.strlen($stream).">>\nstream\n".$stream."\nendstream",
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
