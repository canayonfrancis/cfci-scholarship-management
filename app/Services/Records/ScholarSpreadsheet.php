<?php

namespace App\Services\Records;

use DOMDocument;
use DOMXPath;
use Illuminate\Validation\ValidationException;
use ZipArchive;

/** Bounded, data-only OOXML/CSV support; never calculates spreadsheet formulas. */
class ScholarSpreadsheet
{
    public const HEADERS = ['Student ID', 'Last Name', 'First Name', 'Middle Name', 'EXT', 'Sex', 'Course', 'Year Level', 'Email', 'Contact'];

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
                    $this->header($values);
                    $first = false;
                } elseif (! $this->blank($values)) {
                    $rows[] = $this->row($rowNumber, $values);
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
            if ($zip->numFiles > 200) {
                $this->reject('The workbook contains too many internal files. Use the provided template.');
            }
            $total = 0;
            $names = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entry = $zip->statIndex($i);
                $name = $entry['name'];
                $total += $entry['size'];
                if (isset($names[$name]) || str_contains($name, '..') || str_contains($name, '\\')
                    || str_starts_with($name, '/') || preg_match('/(?:vbaProject|embeddings|externalLinks|activeX)/i', $name)
                    || $total > config('scholar-imports.max_uncompressed_bytes')
                    || $entry['size'] > config('scholar-imports.max_xml_bytes')) {
                    $this->reject('The workbook is too complex or contains unsupported embedded content. Use the provided template.');
                }
                $names[$name] = true;
                if (str_ends_with($name, '.rels')) {
                    $relations = $this->xml($this->part($zip, $name));
                    foreach ($relations->getElementsByTagNameNS('http://schemas.openxmlformats.org/package/2006/relationships', 'Relationship') as $relation) {
                        if (strcasecmp($relation->getAttribute('TargetMode'), 'External') === 0) {
                            $this->reject('External workbook links are not supported. Remove linked content before uploading.');
                        }
                    }
                }
            }
            $types = $this->xml($this->part($zip, '[Content_Types].xml'));
            $validWorkbookType = false;
            foreach ($types->getElementsByTagNameNS('http://schemas.openxmlformats.org/package/2006/content-types', 'Override') as $type) {
                if ($type->getAttribute('PartName') === '/xl/workbook.xml') {
                    $validWorkbookType = $type->getAttribute('ContentType') === 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml';
                }
            }
            if (! $validWorkbookType) {
                $this->reject('Upload an ordinary .xlsx workbook. Macro-enabled and other workbook formats are not supported.');
            }
            $workbook = $this->xml($this->part($zip, 'xl/workbook.xml'));
            $sheet = $workbook->getElementsByTagNameNS(self::MAIN, 'sheet')->item(0);
            if ($sheet === null) {
                $this->reject('The workbook has no data worksheet.');
            }
            $relationshipId = $sheet->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'id');
            $relations = $this->xml($this->part($zip, 'xl/_rels/workbook.xml.rels'));
            $sheetPath = null;
            foreach ($relations->getElementsByTagNameNS('http://schemas.openxmlformats.org/package/2006/relationships', 'Relationship') as $relation) {
                if ($relation->getAttribute('Id') === $relationshipId) {
                    $target = $relation->getAttribute('Target');
                    $sheetPath = str_starts_with($target, '/') ? ltrim($target, '/') : 'xl/'.$target;
                }
            }
            if (! is_string($sheetPath) || ! preg_match('#\Axl/worksheets/[A-Za-z0-9_.-]+\.xml\z#D', $sheetPath)) {
                $this->reject('The first worksheet is not a supported data sheet.');
            }
            $strings = [];
            if (isset($names['xl/sharedStrings.xml'])) {
                $shared = $this->xml($this->part($zip, 'xl/sharedStrings.xml'));
                foreach ($shared->getElementsByTagNameNS(self::MAIN, 'si') as $string) {
                    $strings[] = $this->text($string);
                    if (count($strings) > 20000) {
                        $this->reject('The workbook contains too many text values. Use the provided template.');
                    }
                }
            }
            $data = $this->xml($this->part($zip, $sheetPath));
            $xpath = new DOMXPath($data);
            $xpath->registerNamespace('s', self::MAIN);
            if ($xpath->query('/s:worksheet/s:mergeCells/s:mergeCell')->length > 0) {
                $this->reject('Merged cells are not supported on the data worksheet. Unmerge them and provide one value per cell.');
            }
            $rows = [];
            $previous = 0;
            $headerFound = false;
            foreach ($xpath->query('/s:worksheet/s:sheetData/s:row') as $node) {
                $number = filter_var($node->getAttribute('r'), FILTER_VALIDATE_INT);
                if ($number === false || $number <= $previous || $number > config('scholar-imports.max_scanned_rows')) {
                    $this->reject('Worksheet row numbers are invalid or exceed the physical row limit.');
                }
                $previous = $number;
                $values = array_fill(0, 10, '');
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
                    $value = match ($type) {
                        's' => ctype_digit($raw) && array_key_exists((int) $raw, $strings) ? $strings[(int) $raw] : throw new \RuntimeException('Invalid shared string.'),
                        'inlineStr' => $this->text($cell),
                        '', 'n', 'str' => $raw,
                        default => '',
                    };
                    if ($xpath->query('s:f', $cell)->length > 0) {
                        $issues[] = 'Formulas are not accepted; replace formulas with plain text values.';
                        $value = '[Formula rejected]';
                    }
                    if (! in_array($type, ['', 'n', 's', 'inlineStr', 'str'], true)) {
                        $issues[] = 'Use plain text or numeric values; error, Boolean and date cells are unsupported.';
                    }
                    if ($column > 10) {
                        if (trim($value) !== '') {
                            $issues[] = 'Only the ten template columns are allowed.';
                        }

                        continue;
                    }
                    if ($number > 1 && in_array($column, [1, 10], true) && trim($value) !== '' && ! in_array($type, ['s', 'inlineStr', 'str'], true)) {
                        $issues[] = self::HEADERS[$column - 1].' must be stored as Text in Excel. Re-enter it from the original record to retain leading zeros.';
                    }
                    $values[$column - 1] = $value;
                }
                if ($number === 1) {
                    if ($issues !== []) {
                        $this->reject('The header row must contain plain text and exactly the ten template columns.');
                    }
                    $this->header($values);
                } elseif (! $headerFound) {
                    $this->reject('Column headers must be on worksheet row 1.');
                }
                if ($number === 1) {
                    $headerFound = true;
                } elseif (! $this->blank($values) || $issues !== []) {
                    $rows[] = $this->row($number, $values, $issues);
                    $this->limit($rows);
                }
            }

            return $rows;
        } finally {
            $zip->close();
        }
    }

    private function xml(string $content): DOMDocument
    {
        if (stripos($content, '<!DOCTYPE') !== false || stripos($content, '<!ENTITY') !== false) {
            $this->reject('XML document types and entities are not allowed in uploaded workbooks.');
        }
        $previous = libxml_use_internal_errors(true);
        try {
            $document = new DOMDocument;
            if (! $document->loadXML($content, LIBXML_NONET | LIBXML_COMPACT) || $document->doctype !== null) {
                throw new \RuntimeException('Invalid XML.');
            }

            return $document;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private function part(ZipArchive $zip, string $name): string
    {
        $data = $zip->getFromName($name, config('scholar-imports.max_xml_bytes') + 1);
        if (! is_string($data) || strlen($data) > config('scholar-imports.max_xml_bytes')) {
            throw new \RuntimeException('Missing or oversized workbook part.');
        }

        return $data;
    }

    private function text(\DOMNode $node): string
    {
        $value = '';
        foreach ($node->getElementsByTagNameNS(self::MAIN, 't') as $text) {
            $value .= $text->textContent;
        }

        return $value;
    }

    private function header(array $values): void
    {
        if (array_map(fn ($value) => trim((string) $value), $values) !== self::HEADERS) {
            $this->reject('Row 1 must contain these exact, unique columns in order: '.implode(', ', self::HEADERS).'.');
        }
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
        return count(array_filter($values, fn ($value) => trim((string) $value) !== '')) === 0;
    }

    private function row(int $number, array $values, array $issues = []): array
    {
        if (count($values) !== 10) {
            $issues[] = 'Expected exactly ten columns; check commas and quoted values.';
        }
        $values = array_slice(array_pad($values, 10, ''), 0, 10);
        foreach ($values as &$value) {
            $value = trim((string) $value);
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

    private function reject(string $message): never
    {
        throw ValidationException::withMessages(['file' => $message]);
    }
}
