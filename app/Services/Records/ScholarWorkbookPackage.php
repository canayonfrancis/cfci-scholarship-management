<?php

namespace App\Services\Records;

use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use ZipArchive;

/** Inspects OPC metadata only. Relationships are never opened or followed over a network. */
class ScholarWorkbookPackage
{
    public const WORKBOOK_TYPE = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml';

    private const TYPES = 'http://schemas.openxmlformats.org/package/2006/content-types';

    private const PACKAGE_REL = 'http://schemas.openxmlformats.org/package/2006/relationships';

    private const OFFICE_REL = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    private const MAIN = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    private array $names = [];

    private array $relationships = [];

    private array $defaults = [];

    private array $overrides = [];

    public function __construct(private ZipArchive $zip) {}

    public function inspect(): array
    {
        $this->index();
        $this->contentTypes();
        $this->relationshipParts();
        $root = $this->relationships[''] ?? [];
        $workbookPath = $this->relatedPart('', $root, 'officeDocument', true);
        $type = $this->contentType($workbookPath);
        if ($type === '') {
            $this->reject('The workbook package does not declare a content type for its workbook part. Save a fresh ordinary .xlsx copy.');
        }
        if (str_contains($type, 'macroenabled') || str_contains($type, 'vbaproject')) {
            $this->reject('Macro-enabled workbooks are not supported. Save a macro-free ordinary .xlsx workbook.');
        }
        if ($type !== self::WORKBOOK_TYPE) {
            $this->reject('Unsupported workbook format. Save the file as an ordinary Excel Workbook (.xlsx), not a template, binary or macro-enabled workbook.');
        }
        $workbook = $this->xml($workbookPath);
        $this->root($workbook, self::MAIN, 'workbook');
        $xpath = new DOMXPath($workbook);
        $xpath->registerNamespace('s', self::MAIN);
        $sheet = $xpath->query('/s:workbook/s:sheets/s:sheet')->item(0);
        if ($sheet === null) {
            $this->reject('The workbook has no data worksheet.');
        }
        $id = $sheet->getAttributeNS(self::OFFICE_REL, 'id');
        $workbookRelations = $this->relationships[$workbookPath] ?? [];
        $relationship = $workbookRelations[$id] ?? null;
        if ($relationship === null || $relationship['type'] !== self::OFFICE_REL.'/worksheet' || $relationship['external']) {
            $this->reject('The first workbook sheet is not a supported internal data worksheet.');
        }
        $sheetPath = $this->resolve($workbookPath, $relationship['target']);
        if ($this->contentType($sheetPath) !== 'application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml') {
            $this->reject('The first sheet does not declare a supported Excel worksheet content type.');
        }
        $data = $this->xml($sheetPath);
        $this->root($data, self::MAIN, 'worksheet');
        $this->externalRelationships($sheetPath, $data);
        $sharedPath = $this->relatedPart($workbookPath, $workbookRelations, 'sharedStrings');
        $shared = null;
        if ($sharedPath !== null) {
            if ($this->contentType($sharedPath) !== 'application/vnd.openxmlformats-officedocument.spreadsheetml.sharedstrings+xml') {
                $this->reject('The workbook has an invalid shared-string content type. Save a fresh .xlsx copy.');
            }
            $shared = $this->xml($sharedPath);
            $this->root($shared, self::MAIN, 'sst');
        }

        return ['data' => $data, 'shared' => $shared];
    }

    private function index(): void
    {
        if ($this->zip->numFiles > 200) {
            $this->reject('The workbook contains too many internal files. Use the provided template.');
        }
        $total = 0;
        for ($i = 0; $i < $this->zip->numFiles; $i++) {
            $entry = $this->zip->statIndex($i);
            if ($entry === false) {
                $this->reject('The workbook ZIP directory is corrupted. Save a fresh .xlsx copy.');
            }
            $name = $entry['name'];
            $total += $entry['size'];
            if (preg_match('/vbaproject/i', $name)) {
                $this->reject('Macro/VBA content is not allowed. Save a macro-free ordinary .xlsx workbook.');
            }
            if (preg_match('#(?:externalLinks|(?:^|/)connections\.xml)#i', $name)) {
                $this->reject('External workbook data connections are not supported. Remove linked workbook data or connections.');
            }
            if (preg_match('/(?:embeddings|activeX)/i', $name)) {
                $this->reject('Embedded executable/OLE/ActiveX content is not supported in scholar uploads.');
            }
            if (isset($this->names[$name]) || str_contains($name, '..') || str_contains($name, '\\')
                || str_starts_with($name, '/') || preg_match('/[\x00-\x1F\x7F]/', $name)
                || ! mb_check_encoding($name, 'UTF-8')) {
                $this->reject('The workbook ZIP contains unsafe or duplicate internal paths. Save a fresh .xlsx copy.');
            }
            if ($total > config('scholar-imports.max_uncompressed_bytes') || $entry['size'] > config('scholar-imports.max_xml_bytes')) {
                $this->reject('The workbook exceeds internal decompression limits. Simplify it or split it into smaller files.');
            }
            if (($entry['encryption_method'] ?? 0) !== 0) {
                $this->reject('Encrypted or password-protected workbook contents are not supported.');
            }
            $this->names[$name] = true;
        }
    }

    private function contentTypes(): void
    {
        $document = $this->xml('[Content_Types].xml');
        $this->root($document, self::TYPES, 'Types');
        foreach ($document->documentElement->childNodes as $node) {
            if (! $node instanceof \DOMElement) {
                continue;
            }
            $type = strtolower(trim($node->getAttribute('ContentType')));
            if ($node->namespaceURI !== self::TYPES || $type === '') {
                $this->reject('The workbook content-type metadata is malformed. Save a fresh ordinary .xlsx copy.');
            }
            if (str_contains($type, 'macroenabled') || str_contains($type, 'vbaproject')) {
                $this->reject('Macro-enabled workbook content is not supported. Save a macro-free ordinary .xlsx workbook.');
            }
            if (str_contains($type, 'externallink') || str_contains($type, '.connections')) {
                $this->reject('External workbook data connections are not supported. Remove linked workbook data or connections.');
            }
            if (str_contains($type, 'activex') || str_contains($type, 'oleobject')) {
                $this->reject('Embedded executable/OLE/ActiveX content is not supported in scholar uploads.');
            }
            if ($node->localName === 'Default') {
                $extension = strtolower(trim($node->getAttribute('Extension')));
                if ($extension === '' || str_contains($extension, '.') || str_contains($extension, '/')
                    || str_contains($extension, '\\') || preg_match('/\s/u', $extension) || isset($this->defaults[$extension])) {
                    $this->reject('The workbook has missing or duplicate default content-type declarations.');
                }
                $this->defaults[$extension] = $type;
            } elseif ($node->localName === 'Override') {
                $name = $node->getAttribute('PartName');
                if (! str_starts_with($name, '/')) {
                    $this->reject('A workbook content-type part name is invalid.');
                }
                $part = $this->resolve('', $name);
                if (isset($this->overrides[$part])) {
                    $this->reject('The workbook has duplicate content-type overrides.');
                }
                $this->overrides[$part] = $type;
            } else {
                $this->reject('The workbook content-type metadata contains an unsupported declaration.');
            }
        }
    }

    private function contentType(string $part): string
    {
        return $this->overrides[$part] ?? $this->defaults[strtolower(pathinfo($part, PATHINFO_EXTENSION))] ?? '';
    }

    private function relationshipParts(): void
    {
        foreach (array_keys($this->names) as $name) {
            if (! str_ends_with($name, '.rels')) {
                continue;
            }
            if ($this->contentType($name) !== 'application/vnd.openxmlformats-package.relationships+xml') {
                $this->reject('A workbook relationship part has a missing or invalid content type.');
            }
            if ($name === '_rels/.rels') {
                $source = '';
            } elseif (preg_match('#\A(?:(.+)/)?_rels/([^/]+)\.rels\z#D', $name, $match)) {
                $source = (isset($match[1]) && $match[1] !== '' ? $match[1].'/' : '').$match[2];
                if (! isset($this->names[$source])) {
                    $this->reject('The workbook contains orphaned relationship metadata. Save a fresh .xlsx copy.');
                }
            } else {
                $this->reject('The workbook contains an invalid relationship-part path.');
            }
            $document = $this->xml($name);
            $this->root($document, self::PACKAGE_REL, 'Relationships');
            $items = [];
            foreach ($document->documentElement->childNodes as $node) {
                if (! $node instanceof \DOMElement) {
                    continue;
                }
                if ($node->namespaceURI !== self::PACKAGE_REL || $node->localName !== 'Relationship') {
                    $this->reject('The workbook relationship metadata is malformed.');
                }
                $id = trim($node->getAttribute('Id'));
                $type = trim($node->getAttribute('Type'));
                $target = $node->getAttribute('Target');
                $mode = trim($node->getAttribute('TargetMode'));
                if ($id === '' || isset($items[$id]) || $type === '' || $target === '' || ! in_array($mode, ['', 'Internal', 'External'], true)) {
                    $this->reject('The workbook has incomplete, duplicated or invalid relationships.');
                }
                if (preg_match('#/(?:externalLink|externalLinkPath|connections)$#i', $type)) {
                    $this->reject('External workbook data connections are not supported.');
                }
                if (preg_match('#/(?:vbaProject|activeXControl|oleObject)$#i', $type)) {
                    $this->reject('Macro or executable embedded workbook relationships are not supported.');
                }
                $external = $mode === 'External';
                if (! $external) {
                    $this->resolve($source, $target);
                }
                $items[$id] = compact('id', 'type', 'target', 'external');
            }
            $this->relationships[$source] = $items;
        }
    }

    private function relatedPart(string $source, array $relationships, string $kind, bool $required = false): ?string
    {
        $matches = array_values(array_filter($relationships, fn ($relationship) => $relationship['type'] === self::OFFICE_REL.'/'.$kind));
        if (count($matches) > 1 || ($required && $matches === [])) {
            $this->reject('The workbook is missing required package relationships or contains ambiguous workbook parts.');
        }
        if ($matches === []) {
            return null;
        }
        if ($matches[0]['external']) {
            $this->reject('External workbook data connections are not supported.');
        }

        return $this->resolve($source, $matches[0]['target']);
    }

    private function xml(string $part): DOMDocument
    {
        if (! isset($this->names[$part])) {
            $this->reject('The workbook is missing required XML package metadata. Save a fresh .xlsx copy.');
        }
        $content = $this->zip->getFromName($part, config('scholar-imports.max_xml_bytes') + 1);
        if (! is_string($content)) {
            $this->reject('An internal workbook XML part is unreadable or corrupted. Save a fresh .xlsx copy.');
        }
        if (strlen($content) > config('scholar-imports.max_xml_bytes')) {
            $this->reject('An internal workbook XML part exceeds the allowed size. Simplify the workbook or split it into smaller files.');
        }
        if (stripos($content, '<!DOCTYPE') !== false || stripos($content, '<!ENTITY') !== false) {
            $this->reject('Unsafe XML document types or entities are not allowed in uploaded workbooks.');
        }
        $previous = libxml_use_internal_errors(true);
        try {
            $document = new DOMDocument;
            if (! $document->loadXML($content, LIBXML_NONET | LIBXML_COMPACT)) {
                $this->reject('The workbook contains malformed XML. Save a fresh ordinary .xlsx copy.');
            }
            // DOM handles XML declarations, BOMs and supported encodings, including UTF-16.
            if ($document->doctype !== null) {
                $this->reject('Unsafe XML document types or entities are not allowed in uploaded workbooks.');
            }

            return $document;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private function root(DOMDocument $document, string $namespace, string $name): void
    {
        $root = $document->documentElement;
        if ($root === null || $root->namespaceURI !== $namespace || $root->localName !== $name) {
            $this->reject('The workbook has an invalid or unsupported '.$name.' XML structure. Save a fresh ordinary .xlsx copy.');
        }
    }

    private function resolve(string $source, string $target): string
    {
        $uri = parse_url($target);
        if ($uri === false || isset($uri['scheme']) || isset($uri['host'])
            || isset($uri['query']) || isset($uri['fragment']) || ! isset($uri['path'])
            || str_starts_with($target, '//') || preg_match('/%(?![0-9a-f]{2})/i', $target)) {
            $this->reject('The workbook contains an unsafe or invalid internal part target.');
        }
        $path = rawurldecode($uri['path']);
        if (str_contains($path, '\\') || preg_match('/[\x00-\x1F\x7F]/', $path) || ! mb_check_encoding($path, 'UTF-8')) {
            $this->reject('The workbook contains an unsafe internal part path.');
        }
        $segments = str_starts_with($path, '/') || $source === '' ? [] : explode('/', dirname($source));
        if ($segments === ['.']) {
            $segments = [];
        }
        foreach (explode('/', ltrim($path, '/')) as $segment) {
            if ($segment === '.') {
                continue;
            }
            if ($segment === '..') {
                if ($segments === []) {
                    $this->reject('An internal workbook target escapes the package root.');
                }
                array_pop($segments);
            } elseif ($segment === '') {
                $this->reject('The workbook contains an empty internal part path segment.');
            } else {
                $segments[] = $segment;
            }
        }
        $part = implode('/', $segments);
        if (! isset($this->names[$part])) {
            $this->reject('The workbook package is missing a referenced internal part. Save a fresh .xlsx copy.');
        }

        return $part;
    }

    private function externalRelationships(string $sheetPath, DOMDocument $data): void
    {
        $xpath = new DOMXPath($data);
        $xpath->registerNamespace('s', self::MAIN);
        $used = [];
        foreach ($xpath->query('/s:worksheet/s:hyperlinks/s:hyperlink') as $hyperlink) {
            $id = $hyperlink->getAttributeNS(self::OFFICE_REL, 'id');
            if ($id === '') {
                continue; // Internal location metadata is never navigated either.
            }
            $relationship = $this->relationships[$sheetPath][$id] ?? null;
            if ($relationship === null || $relationship['type'] !== self::OFFICE_REL.'/hyperlink') {
                $this->reject('The data worksheet has invalid hyperlink relationship metadata.');
            }
            if ($relationship['external']) {
                $reference = $hyperlink->getAttribute('ref');
                if (! preg_match('/\A\$?I\$?([1-9][0-9]*)\z/D', $reference, $match)
                    || (int) $match[1] < 2 || (int) $match[1] > config('scholar-imports.max_scanned_rows')
                    || $hyperlink->getAttribute('location') !== '') {
                    $this->reject('Only automatic mailto hyperlinks on individual Email data cells are supported. Other external links must be removed.');
                }
                $used[$id] = true;
            }
        }
        foreach ($this->relationships as $source => $relationships) {
            foreach ($relationships as $relationship) {
                if (! $relationship['external']) {
                    continue;
                }
                if ($source !== $sheetPath || $relationship['type'] !== self::OFFICE_REL.'/hyperlink'
                    || ! isset($used[$relationship['id']]) || ! $this->plainMailto($relationship['target'])) {
                    $this->reject('Unsafe or unsupported external workbook connection. Only automatic mailto hyperlinks on Email data cells may remain.');
                }
            }
        }
    }

    private function plainMailto(string $target): bool
    {
        if (! preg_match('/\Amailto:([^?\x00-\x20\x7F]+)\z/iD', $target, $match)) {
            return false;
        }
        $address = rawurldecode($match[1]);
        if (strpbrk($address, ':?&#') !== false || str_contains($address, '\\') || preg_match('/[\x00-\x20\x7F]/', $address)) {
            return false;
        }

        // Metadata is checked only for this narrow exception; it is never used as row data.
        return Validator::make(['email' => $address], ['email' => ['required', 'email', 'max:255']])->passes();
    }

    private function reject(string $message): never
    {
        throw ValidationException::withMessages(['file' => $message]);
    }
}
