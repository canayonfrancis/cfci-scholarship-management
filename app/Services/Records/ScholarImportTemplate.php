<?php

namespace App\Services\Records;

use ZipArchive;

class ScholarImportTemplate
{
    private const NS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    public function write(string $path, array $courses): void
    {
        $parts = $this->parts(['Scholars', 'Instructions', 'Courses'], $courses !== [] ? '<definedNames><definedName name="ActiveCourses">Courses!$A$2:$A$'.(count($courses) + 1).'</definedName></definedNames>' : '');
        $last = max(2, config('scholar-imports.max_rows') + 1);
        $body = $this->row(1, ScholarSpreadsheet::HEADERS, 2);
        // Explicit Text formatting on blank cells preserves IDs and contacts when entered.
        for ($n = 2; $n <= $last; $n++) {
            $body .= $this->row($n, array_fill(0, 10, ''), 1);
        }
        $validation = '<dataValidations count="'.($courses !== [] ? '3' : '2').'">';
        $validation .= $this->listValidation('F2:F'.$last, '"Male,Female"');
        $validation .= $this->listValidation('H2:H'.$last, '"'.implode(',', array_keys(ScholarProfile::yearLevels())).'"');
        if ($courses !== []) {
            $validation .= $this->listValidation('G2:G'.$last, 'ActiveCourses');
        }
        $parts['xl/worksheets/sheet1.xml'] = '<worksheet xmlns="'.self::NS.'"><sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews><cols><col min="1" max="1" width="21" customWidth="1" style="1"/><col min="2" max="4" width="24" customWidth="1" style="1"/><col min="5" max="6" width="14" customWidth="1" style="1"/><col min="7" max="7" width="52" customWidth="1" style="1"/><col min="8" max="8" width="16" customWidth="1" style="1"/><col min="9" max="9" width="34" customWidth="1" style="1"/><col min="10" max="10" width="22" customWidth="1" style="1"/></cols><sheetData>'.$body.'</sheetData><autoFilter ref="A1:J'.$last.'"/>'.$validation.'</dataValidations></worksheet>';
        $instructions = [
            'Bulk Upload Scholars - instructions',
            'Enter scholar records on the first worksheet, Scholars. Keep row 1 and all ten column names in exactly the supplied order.',
            'Required: Student ID, Last Name, First Name, Sex, Course, Year Level, Email, Contact. Middle Name may be blank; EXT is optional. JR/Jr/Jr. become Jr.; SR/Sr/Sr. become Sr.; II/III/IV are uppercase. Other legitimate suffixes remain allowed up to 30 characters.',
            'Student ID and Contact must remain Text. Enter the original identifiers, including leading zeros. Do not use formulas or paste numeric cells into these columns.',
            'Sex: Male or Female (case-insensitive), or the existing numeric codes 0 = Male and 1 = Female.',
            'Course: use an exact registered active course name from Courses. The database has course names, not a separate course-code column. Unknown or inactive courses are rejected.',
            'Year Level: '.implode('; ', array_map(fn ($key, $label) => $key.' = '.$label, array_keys(ScholarProfile::yearLevels()), ScholarProfile::yearLevels())).'.',
            'Contact: Philippine mobile 09171234567, 9171234567, +639171234567 or 00639171234567; or an eight-digit landline. Recognized spacing and punctuation are accepted. Leading zeros are retained as text.',
            'Email is a contact address. Import does not create login accounts, send email, assign scholarships or certify enrollment.',
            'Do not include extra data columns, totals, formulas, external data connections, macros or merged data cells. Formatting-only, empty-string and whitespace-only rows are ignored.',
            'Enter email addresses normally. Automatic simple mailto hyperlinks on Email data cells may remain; only cell text is imported. Link targets are never opened. Other external links are not supported.',
            'Only the first worksheet is imported. Instructions and Courses are reference sheets. For CSV, export only Scholars as CSV UTF-8 (Comma delimited).',
            'Limits: '.config('scholar-imports.max_rows').' records, '.config('scholar-imports.max_upload_kb').' KB. Preview expires after '.config('scholar-imports.preview_minutes').' minutes.',
            'Preview does not save records. Correct every invalid/duplicate row before confirming. One error prevents the entire import.',
            'Existing Student IDs are never overwritten or merged. Use the existing profile for later scholarship assignments and semesters.',
            'New records have active administrative profile status. Other official profile fields remain incomplete until edited; no scholarship eligibility is inferred.',
        ];
        $parts['xl/worksheets/sheet2.xml'] = $this->referenceSheet($instructions, 120);
        $parts['xl/worksheets/sheet3.xml'] = $this->referenceSheet(['Registered active course names', ...$courses], 80);
        $this->writeParts($path, $parts);
    }

    /** Reuse the template's OOXML package, escaping and text cells for internal tabular reports. */
    public function writeReport(string $path, array $information, array $headers, array $rows, array $widths, array $currencyColumns): void
    {
        $parts = $this->parts(['Scholar list']);
        $body = '';
        $number = 1;
        $lastColumn = chr(64 + count($headers));
        $merges = '';
        foreach ($information as $values) {
            $merges .= count($values) === 1
                ? '<mergeCell ref="A'.$number.':'.$lastColumn.$number.'"/>'
                : '<mergeCell ref="A'.$number.':C'.$number.'"/><mergeCell ref="D'.$number.':'.$lastColumn.$number.'"/>';
            $body .= $this->row($number++, count($values) === 1 ? $values : [$values[0], '', '', $values[1]], 1);
        }
        $headerRow = $number;
        $body .= $this->row($number++, $headers, 2);
        foreach ($rows as $values) {
            $body .= $this->row($number++, $values, 3, $currencyColumns);
        }
        $columns = '';
        foreach ($widths as $index => $width) {
            $columns .= '<col min="'.($index + 1).'" max="'.($index + 1).'" width="'.$width.'" customWidth="1" style="1"/>';
        }
        $parts['xl/worksheets/sheet1.xml'] = '<worksheet xmlns="'.self::NS.'"><sheetViews><sheetView workbookViewId="0"><pane ySplit="2" topLeftCell="A3" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews><cols>'.$columns.'</cols><sheetData>'.$body.'</sheetData><autoFilter ref="A'.$headerRow.':'.$lastColumn.max($headerRow, $number - 1).'"/><mergeCells count="'.substr_count($merges, '<mergeCell ').'">'.$merges.'</mergeCells></worksheet>';
        $this->writeParts($path, $parts);
    }

    private function parts(array $sheets, string $definedNames = ''): array
    {
        $numbers = range(1, count($sheets));

        return [
            '[Content_Types].xml' => '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="'.ScholarWorkbookPackage::WORKBOOK_TYPE.'"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'.implode('', array_map(fn ($n) => '<Override PartName="/xl/worksheets/sheet'.$n.'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>', $numbers)).'</Types>',
            '_rels/.rels' => '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>',
            'xl/workbook.xml' => '<workbook xmlns="'.self::NS.'" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>'.implode('', array_map(fn ($name, $n) => '<sheet name="'.$this->escape($name).'" sheetId="'.$n.'" r:id="rId'.$n.'"/>', $sheets, $numbers)).'</sheets>'.$definedNames.'</workbook>',
            'xl/_rels/workbook.xml.rels' => '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.implode('', array_map(fn ($n) => '<Relationship Id="rId'.$n.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.$n.'.xml"/>', $numbers)).'<Relationship Id="rId'.(count($sheets) + 1).'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>',
            'xl/styles.xml' => '<styleSheet xmlns="'.self::NS.'"><numFmts count="1"><numFmt numFmtId="164" formatCode="&quot;PHP&quot; #,##0.00"/></numFmts><fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font></fonts><fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FFA80E0E"/><bgColor indexed="64"/></patternFill></fill></fills><borders count="1"><border/></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="5"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="49" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"><alignment vertical="top"/></xf><xf numFmtId="49" fontId="1" fillId="2" borderId="0" xfId="0" applyFill="1" applyFont="1" applyNumberFormat="1"><alignment vertical="center" wrapText="1"/></xf><xf numFmtId="49" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"><alignment vertical="top" wrapText="1"/></xf><xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"><alignment vertical="top"/></xf></cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>',
        ];
    }

    private function writeParts(string $path, array $parts): void
    {
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Cannot create scholar workbook.');
        }
        try {
            foreach ($parts as $name => $xml) {
                if (! $zip->addFromString($name, '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'.$xml)) {
                    throw new \RuntimeException('Cannot write workbook part.');
                }
            }
        } finally {
            if (! $zip->close()) {
                throw new \RuntimeException('Cannot finalize scholar workbook.');
            }
        }
    }

    private function row(int $number, array $values, int $style, array $currencyColumns = []): string
    {
        $cells = '';
        foreach ($values as $i => $value) {
            if (in_array($i, $currencyColumns, true) && $value !== null && preg_match('/^\d+(?:\.\d{1,2})?$/D', (string) $value)) {
                $cells .= '<c r="'.chr(65 + $i).$number.'" s="4"><v>'.$this->escape((string) $value).'</v></c>';
                continue;
            }
            $cells .= '<c r="'.chr(65 + $i).$number.'" t="inlineStr" s="'.$style.'"><is><t xml:space="preserve">'.$this->escape((string) $value).'</t></is></c>';
        }

        $height = $style === 2 ? 30 : ($style === 3 ? min(409, max(30, (int) ceil(mb_strlen(implode(' ', $values)) / 95) * 16 + 10)) : null);

        return '<row r="'.$number.'"'.($height !== null ? ' ht="'.$height.'" customHeight="1"' : '').'>'.$cells.'</row>';
    }

    private function referenceSheet(array $values, int $width): string
    {
        $rows = '';
        foreach ($values as $i => $value) {
            $rows .= $this->row($i + 1, [$value], $i === 0 ? 2 : 3);
        }

        return '<worksheet xmlns="'.self::NS.'"><cols><col min="1" max="1" width="'.$width.'" customWidth="1" style="1"/></cols><sheetData>'.$rows.'</sheetData></worksheet>';
    }

    private function listValidation(string $range, string $formula): string
    {
        return '<dataValidation type="list" allowBlank="0" showErrorMessage="1" errorTitle="Check the instructions" error="Choose an accepted value." sqref="'.$range.'"><formula1>'.$this->escape($formula).'</formula1></dataValidation>';
    }

    private function escape(string $value): string
    {
        $value = preg_replace('/[^\x09\x0A\x0D\x20-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $value) ?? '';

        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
