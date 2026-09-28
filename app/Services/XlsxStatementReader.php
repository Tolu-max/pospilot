<?php

namespace App\Services;

use DOMDocument;
use DOMXPath;
use RuntimeException;

final class XlsxStatementReader
{
    public function toCsv(string $archive): string
    {
        if (strlen($archive) > config('gmail_statement.max_attachment_bytes')) {
            throw new RuntimeException('attachment_too_large');
        }
        $entries = $this->entries($archive);
        $workbook = $this->xml($this->entry($archive, $entries, 'xl/workbook.xml'));
        $relationships = $this->xml($this->entry($archive, $entries, 'xl/_rels/workbook.xml.rels'));
        $workbookXPath = new DOMXPath($workbook);
        $sheet = $workbookXPath->query('//*[local-name()="sheets"]/*[local-name()="sheet"]')->item(0);
        if (! $sheet) {
            throw new RuntimeException('unsupported_schema');
        }
        $relationshipId = $sheet->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'id');
        if (! is_string($relationshipId)) {
            throw new RuntimeException('unsupported_schema');
        }
        $relationshipXPath = new DOMXPath($relationships);
        $relationship = null;
        foreach ($relationshipXPath->query('//*[local-name()="Relationship"]') as $candidate) {
            if ($candidate->attributes?->getNamedItem('Id')?->nodeValue === $relationshipId) {
                $relationship = $candidate;
                break;
            }
        }
        $target = $relationship?->attributes?->getNamedItem('Target')?->nodeValue;
        if (! is_string($target) || str_contains($target, '..')) {
            throw new RuntimeException('unsupported_schema');
        }
        $sheetPath = ltrim(str_starts_with($target, '/') ? $target : 'xl/'.$target, '/');
        if (! preg_match('#^xl/worksheets/[A-Za-z0-9_.-]+\.xml$#', $sheetPath)) {
            throw new RuntimeException('unsupported_schema');
        }
        $sharedStrings = [];
        if (isset($entries['xl/sharedStrings.xml'])) {
            $strings = $this->xml($this->entry($archive, $entries, 'xl/sharedStrings.xml'));
            $stringsXPath = new DOMXPath($strings);
            foreach ($stringsXPath->query('//*[local-name()="si"]') as $item) {
                $sharedStrings[] = $this->textNodes($item);
            }
        }
        $sheetXml = $this->xml($this->entry($archive, $entries, $sheetPath));
        $sheetXPath = new DOMXPath($sheetXml);
        $rows = [];
        foreach ($sheetXPath->query('//*[local-name()="sheetData"]/*[local-name()="row"]') as $rowElement) {
            $row = [];
            foreach ($sheetXPath->query('./*[local-name()="c"]', $rowElement) as $cell) {
                if ($sheetXPath->query('./*[local-name()="f"]', $cell)->length > 0) {
                    throw new RuntimeException('unsupported_schema');
                }
                $reference = $cell->attributes?->getNamedItem('r')?->nodeValue ?? '';
                preg_match('/^([A-Z]+)/', $reference, $columnMatch);
                if (! isset($columnMatch[1])) {
                    continue;
                }
                $columnIndex = $this->columnIndex($columnMatch[1]);
                if ($columnIndex >= 100) {
                    throw new RuntimeException('unsupported_schema');
                }
                $type = $cell->attributes?->getNamedItem('t')?->nodeValue;
                $value = $sheetXPath->query('./*[local-name()="v"]', $cell)->item(0)?->textContent ?? '';
                if ($type === 's') {
                    $value = $sharedStrings[(int) $value] ?? '';
                } elseif ($type === 'inlineStr') {
                    $value = $this->textNodes($sheetXPath->query('./*[local-name()="is"]', $cell)->item(0));
                }
                $row[$columnIndex] = $value;
            }
            if (count($rows) >= 5001) {
                throw new RuntimeException('row_limit_exceeded');
            }
            if ($row !== []) {
                ksort($row);
                $rows[] = $row;
            }
        }
        if ($rows === []) {
            throw new RuntimeException('unsupported_schema');
        }
        $output = fopen('php://temp', 'w+b');
        foreach ($rows as $row) {
            $lastColumn = max(array_keys($row));
            $values = array_fill(0, $lastColumn + 1, '');
            foreach ($row as $index => $value) {
                $values[$index] = (string) $value;
            }
            fputcsv($output, $values, ',', '"', '\\');
        }
        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);
        if (! is_string($csv)) {
            throw new RuntimeException('unsupported_schema');
        }

        return $csv;
    }

    /** @return array<string, array<string, int>> */
    private function entries(string $archive): array
    {
        $tail = substr($archive, -min(strlen($archive), 65557));
        $eocd = strrpos($tail, "PK\x05\x06");
        if ($eocd === false) {
            throw new RuntimeException('unsupported_schema');
        }
        $directoryOffset = unpack('V', substr($tail, $eocd + 16, 4))[1] ?? null;
        $entryCount = unpack('v', substr($tail, $eocd + 10, 2))[1] ?? null;
        if (! is_int($directoryOffset) || ! is_int($entryCount) || $entryCount > 200) {
            throw new RuntimeException('unsupported_schema');
        }
        $entries = [];
        $offset = $directoryOffset;
        for ($index = 0; $index < $entryCount; $index++) {
            $header = unpack('Vsignature/vmade/vneeded/vflags/vmethod/vtime/vdate/Vcrc/Vcompressed/Vuncompressed/vname/vextra/vcomment/vdisk/vinternal/Vexternal/Vlocal', substr($archive, $offset, 46));
            if (($header['signature'] ?? null) !== 0x02014B50) {
                throw new RuntimeException('unsupported_schema');
            }
            $name = substr($archive, $offset + 46, $header['name']);
            $entries[$name] = $header;
            $offset += 46 + $header['name'] + $header['extra'] + $header['comment'];
        }

        return $entries;
    }

    private function entry(string $archive, array $entries, string $name): string
    {
        $entry = $entries[$name] ?? null;
        if (! is_array($entry) || $entry['uncompressed'] > config('gmail_statement.max_attachment_bytes') || ($entry['flags'] & 1) === 1) {
            throw new RuntimeException('unsupported_schema');
        }
        $local = unpack('Vsignature/vversion/vflags/vmethod/vtime/vdate/Vcrc/Vcompressed/Vuncompressed/vname/vextra', substr($archive, $entry['local'], 30));
        if (($local['signature'] ?? null) !== 0x04034B50) {
            throw new RuntimeException('unsupported_schema');
        }
        $dataOffset = $entry['local'] + 30 + $local['name'] + $local['extra'];
        $compressed = substr($archive, $dataOffset, $entry['compressed']);
        $data = match ($entry['method']) {
            0 => $compressed,
            8 => gzinflate($compressed),
            default => false,
        };
        if (! is_string($data) || strlen($data) !== $entry['uncompressed']) {
            throw new RuntimeException('unsupported_schema');
        }

        return $data;
    }

    private function xml(string $contents): DOMDocument
    {
        if (stripos($contents, '<!DOCTYPE') !== false || stripos($contents, '<!ENTITY') !== false) {
            throw new RuntimeException('unsupported_schema');
        }
        $document = new DOMDocument;
        if (! $document->loadXML($contents, LIBXML_NONET | LIBXML_NOBLANKS)) {
            throw new RuntimeException('unsupported_schema');
        }

        return $document;
    }

    private function textNodes(?\DOMNode $node): string
    {
        if (! $node) {
            return '';
        }
        $text = '';
        foreach ($node->childNodes as $child) {
            if ($child->nodeName === 't') {
                $text .= $child->textContent;
            }
        }

        return $text;
    }

    private function columnIndex(string $letters): int
    {
        $index = 0;
        foreach (str_split($letters) as $letter) {
            $index = $index * 26 + ord($letter) - 64;
        }

        return $index - 1;
    }
}
