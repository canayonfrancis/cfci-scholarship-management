<?php

namespace App\Services\Records;

use DOMXPath;
use Illuminate\Validation\ValidationException;
use ZipArchive;

/** Bounded, data-only OOXML/CSV support; never calculates spreadsheet formulas. */
class ScholarSpreadsheet
{
    public const LEGACY_HEADERS = ['Student ID', 'Last Name', 'First Name', 'Middle Name', 'EXT', 'Sex', 'Course', 'Year Level', 'Email', 'Contact'];
    public const HEADERS = [...self::LEGACY_HEADERS, 'LANDBANK Savings Account Number'];

    private const MAIN = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    public function read(string $path, string $extension): array
    {
        try {
            $rows = match ($extension) {
                'csv' => $this->csv($path),
                'xlsx' => $this->xlsx($path),
                default => $this->reject('Use an .xlsx or UTF-8 comma-separated .csv file.'),
            };
            if ($rows === []) {
                $this->reject('The file has no scholar records. Enter records below the header row.');
            }

            return $rows;
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable) {
            $this->reject('The file could not be read. Download a fresh template and save it as .xlsx or UTF-8 CSV.');
        }
    }

    private function csv(string $path): array
    {
        $content = file_get_contents($path);
        if ($content === false || ! mb_check_encoding($content, 'UTF-8') || str_contains($content, "\0")) {
            $this->reject('CSV must use UTF-8 encoding. In Excel, choose CSV UTF-8 (Comma delimited).');
        }
        $content = str_replace(["\r\n", "\r"], "\n", preg_replace('/^\xEF\xBB\xBF/', '', $content));
        $this->csvSyntax($content);
        $stream = fopen('php://temp', 'w+');
        if ($stream === false) {
            throw new \RuntimeException('Cannot open CSV stream.');
        }
        try {
            fwrite($stream, $content);
            rewind($stream);
            $rows = [];
            $line = 1;
            $first = true;
            $columns = count(self::HEADERS);
            while (! feof($stream)) {
                $start = ftell($stream);
                $values = fgetcsv($stream, null, ',', '"', '');
                $end = ftell($stream);
                if ($values === false) {
                    break;
                }
                // Count physical lines, including quoted multiline fields.
                fseek($stream, $start);
                $raw = fread($stream, $end - $start);
                fseek($stream, $end);
                $rowNumber = $line;
                $line += max(1, preg_match_all('/\r\n|\r|\n/', $raw));
                if ($line > config('scholar-imports.max_scanned_rows') + 1) {
                    $this->reject('The file contains too many physical rows. Remove unused rows.');
                }
                if ($first) {
                    $columns = $this->header($values);
                    $first = false;
                } elseif (! $this->blank($values)) {
                    $rows[] = $this->row($rowNumber, $values, columns: $columns);
                    $this->limit($rows);
                }
            }

            return $rows;
        } finally {
            fclose($stream);
        }
    }

    private function xlsx(string $path): array
    {
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::CHECKCONS) !== true) {
            $this->reject('The Excel file is corrupted, encrypted or is not a valid .xlsx workbook.');
        }
        try {
            $package = (new ScholarWorkbookPackage($zip))->inspect();
            $strings = [];
            if ($package['shared'] !== null) {
                $shared = $package['shared'];
                foreach ($shared->getElementsByTagNameNS(self::MAIN, 'si') as $string) {
                    $strings[] = $this->text($string);
                    if (count($strings) > 20000) {
                        $this->reject('The workbook contains too many text values. Use the provided template.');
                    }
                }
            }
            $data = $package['data'];
            $xpath = new DOMXPath($data);
            $xpath->registerNamespace('s', self::MAIN);
            if ($xpath->query('/s:worksheet/s:mergeCells/s:mergeCell')->length > 0) {
                $this->reject('Merged cells are not supported on the data worksheet. Unmerge them and provide one value per cell.');
            }
            $rows = [];
            $previous = 0;
            $headerFound = false;
            $columns = count(self::HEADERS);
            foreach ($xpath->query('/s:worksheet/s:sheetData/s:row') as $node) {
                $number = filter_var($node->getAttribute('r'), FILTER_VALIDATE_INT);
                if ($number === false || $number <= $previous || $number > config('scholar-imports.max_scanned_rows')) {
                    $this->reject('Worksheet row numbers are invalid or exceed the physical row limit.');
                }
                $previous = $number;
                $values = array_fill(0, count(self::HEADERS), '');
                $issues = [];
                $seen = [];
                foreach ($xpath->query('s:c', $node) as $cell) {
                    if (! preg_match('/\A([A-Z]+)([1-9][0-9]*)\z/D', $cell->getAttribute('r'), $reference) || (int) $reference[2] !== $number) {
                        $this->reject('The workbook contains an invalid cell reference.');
                    }
                    $column = 0;
                    foreach (str_split($reference[1]) as $letter) {
                        $column = $column * 26 + ord($letter) - 64;
                        if ($column > 16384) {
                            $this->reject('The workbook contains an invalid column.');
                        }
                    }
                    if (isset($seen[$column])) {
                        $this->reject('The workbook contains duplicate cell references.');
                    }
                    $seen[$column] = true;
                    $type = $cell->getAttribute('t');
                    $raw = $xpath->query('s:v', $cell)->item(0)?->textContent ?? '';
                    $inline = $type === 'inlineStr' ? $this->text($cell) : '';
                    $hasFormula = $xpath->query('s:f', $cell)->length > 0;
                    if (! $hasFormula && $this->trimValue($raw) === '' && $this->trimValue($inline) === '') {
                        continue; // Excel may retain a type/style on a genuinely empty cell.
                    }
                    $value = $hasFormula ? '[Formula rejected]' : match ($type) {
                        's' => ctype_digit(trim($raw)) && array_key_exists((int) $raw, $strings)
                            ? $strings[(int) $raw]
                            : $this->reject('The workbook contains a missing or invalid shared-string reference. Save a fresh .xlsx copy.'),
                        'inlineStr' => $inline,
                        '', 'n', 'str' => $raw,
                        default => '',
                    };
                    if ($hasFormula) {
                        $issues[] = 'Formulas are not accepted; replace formulas with plain text values.';
                        $value = '[Formula rejected]';
                    }
                    if (! in_array($type, ['', 'n', 's', 'inlineStr', 'str'], true)) {
                        $issues[] = 'Use plain text or numeric values; error, Boolean and date cells are unsupported.';
                    }
                    if ($column > count(self::HEADERS) || ($headerFound && $column > $columns)) {
                        if ($this->trimValue($value) !== '') {
                            $issues[] = 'Only the columns declared in the template header are allowed.';
                        }

                        continue;
                    }
                    if ($number > 1 && in_array($column, [1, 10, 11], true) && $this->trimValue($value) !== '' && ! in_array($type, ['s', 'inlineStr', 'str'], true)) {
                        $issues[] = self::HEADERS[$column - 1].' must be stored as Text in Excel. Re-enter it from the original record to retain leading zeros.';
                    }
                    $values[$column - 1] = $value;
                }
                if ($number === 1) {
                    if ($issues !== []) {
                        $this->reject('The header row must contain plain text and only the supplied template columns.');
                    }
                    $columns = $this->header($values);
                } elseif (! $headerFound) {
                    $this->reject('Column headers must be on worksheet row 1.');
                }
                if ($number === 1) {
                    $headerFound = true;
                } elseif (! $this->blank($values) || $issues !== []) {
                    $rows[] = $this->row($number, array_slice($values, 0, $columns), $issues, $columns);
                    $this->limit($rows);
                }
            }

            return $rows;
        } finally {
            $zip->close();
        }
    }

    private function text(\DOMElement $node): string
    {
        $value = '';
        foreach ($node->getElementsByTagNameNS(self::MAIN, 't') as $text) {
            $value .= $text->textContent;
        }

        return $value;
    }

    private function header(array $values): int
    {
        $values = array_map(fn ($value) => $this->trimValue((string) $value), $values);
        if ($values === self::HEADERS) {
            return count(self::HEADERS);
        }
        // XLSX rows are padded; older workbooks have no optional K header.
        if ($values === self::LEGACY_HEADERS || $values === [...self::LEGACY_HEADERS, '']) {
            return count(self::LEGACY_HEADERS);
        }

        $this->reject('Row 1 must contain these exact, unique columns in order: '.implode(', ', self::HEADERS).'. Older templates ending at Contact are also accepted.');
    }

    private function csvSyntax(string $content): void
    {
        $mode = 0; // field start, unquoted, quoted, closing quote
        $line = 1;
        for ($i = 0, $length = strlen($content); $i < $length; $i++) {
            $character = $content[$i];
            if ($mode === 0) {
                $mode = $character === '"' ? 2 : (in_array($character, [',', "\n"], true) ? 0 : 1);
            } elseif ($mode === 1) {
                if ($character === '"') {
                    $this->reject('Malformed CSV quoting near line '.$line.'. Save a fresh CSV UTF-8 file from the template.');
                }
                if ($character === ',' || $character === "\n") {
                    $mode = 0;
                }
            } elseif ($mode === 2) {
                if ($character === '"') {
                    $mode = 3;
                }
            } elseif ($character === '"') {
                $mode = 2;
            } elseif ($character === ',' || $character === "\n") {
                $mode = 0;
            } elseif ($character !== ' ' && $character !== "\t") {
                $this->reject('Unexpected text after a CSV closing quote near line '.$line.'.');
            }
            if ($character === "\n") {
                $line++;
            }
        }
        if ($mode === 2) {
            $this->reject('The CSV contains an unclosed quoted field near line '.$line.'.');
        }
    }

    private function blank(array $values): bool
    {
        return count(array_filter($values, fn ($value) => $this->trimValue((string) $value) !== '')) === 0;
    }

    private function row(int $number, array $values, array $issues = [], int $columns = 11): array
    {
        $omittedOptionalBank = $columns === count(self::HEADERS) && count($values) === count(self::LEGACY_HEADERS);
        if (count($values) !== $columns && ! $omittedOptionalBank) {
            $issues[] = 'Expected '.$columns.' columns matching the header; check commas and quoted values.';
        }
        $values = array_slice(array_pad($values, count(self::HEADERS), ''), 0, count(self::HEADERS));
        foreach ($values as &$value) {
            $value = $this->trimValue((string) $value);
            if (mb_strlen($value) > 1000 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $value)) {
                $issues[] = 'A cell contains excessive text or unsupported control characters.';
                $value = mb_substr(preg_replace('/[\x00-\x1F\x7F]/u', '', $value), 0, 1000);
            }
        }

        return ['number' => $number, 'values' => $values, 'issues' => array_values(array_unique($issues))];
    }

    private function limit(array $rows): void
    {
        if (count($rows) > config('scholar-imports.max_rows')) {
            $this->reject('A file may contain at most '.config('scholar-imports.max_rows').' scholar records. Split it into smaller batches.');
        }
    }

    private function trimValue(string $value): string
    {
        return preg_replace('/\A[\s\p{Z}\x{FEFF}]+|[\s\p{Z}\x{FEFF}]+\z/u', '', $value) ?? trim($value);
    }

    private function reject(string $message): never
    {
        throw ValidationException::withMessages(['file' => $message]);
    }
}
