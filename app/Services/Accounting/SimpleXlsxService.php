<?php

namespace App\Services\Accounting;

use InvalidArgumentException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ZipArchive;

class SimpleXlsxService
{
    /**
     * Maximum allowed ZIP entries inside the XLSX package.
     */
    public const MAX_ZIP_ENTRIES = 100;

    /**
     * Maximum allowed uncompressed size across all ZIP entries (50 MB).
     */
    public const MAX_TOTAL_UNCOMPRESSED_BYTES = 50 * 1024 * 1024;

    /**
     * Maximum allowed uncompressed size for a single XML entry (25 MB).
     */
    public const MAX_ENTRY_UNCOMPRESSED_BYTES = 25 * 1024 * 1024;

    /**
     * Maximum allowed uncompressed size for sharedStrings.xml (15 MB).
     */
    public const MAX_SHARED_STRINGS_BYTES = 15 * 1024 * 1024;

    /**
     * Maximum allowed rows to parse per worksheet.
     */
    public const MAX_ROWS_PER_SHEET = 25000;

    /**
     * Maximum allowed cells per row.
     */
    public const MAX_CELLS_PER_ROW = 100;

    /**
     * Read rows from an XLSX file with strict security guards.
     *
     * @return array<int, array<int, string>>
     */
    public function readRows(string $filePath): array
    {
        if (! file_exists($filePath)) {
            throw new InvalidArgumentException("Berkas [{$filePath}] tidak ditemukan.");
        }

        $zip = new ZipArchive;
        $openResult = $zip->open($filePath);
        if ($openResult !== true) {
            throw new InvalidArgumentException('Struktur berkas .xlsx tidak valid atau rusak.');
        }

        try {
            // Security check: validate ZIP structure, entry count, and decompression size limit
            $this->validateZipSafety($zip);

            // 1. Shared Strings (if any)
            $sharedStrings = [];
            $sharedStringsXml = $this->safeGetXmlEntry($zip, 'xl/sharedStrings.xml', self::MAX_SHARED_STRINGS_BYTES);
            if ($sharedStringsXml !== false) {
                $sXml = $this->parseXmlSafely($sharedStringsXml);
                if ($sXml && isset($sXml->si)) {
                    foreach ($sXml->si as $si) {
                        if (isset($si->t)) {
                            $sharedStrings[] = (string) $si->t;
                        } elseif (isset($si->r)) {
                            $text = '';
                            foreach ($si->r as $r) {
                                $text .= (string) $r->t;
                            }
                            $sharedStrings[] = $text;
                        } else {
                            $sharedStrings[] = '';
                        }
                    }
                }
            }

            // 2. Sheet1 XML
            $sheetXmlContent = $this->safeGetXmlEntry($zip, 'xl/worksheets/sheet1.xml', self::MAX_ENTRY_UNCOMPRESSED_BYTES);
            if ($sheetXmlContent === false) {
                // Check other potential sheet names (sheet2 .. sheet5)
                for ($i = 1; $i <= 5; $i++) {
                    $sheetXmlContent = $this->safeGetXmlEntry($zip, "xl/worksheets/sheet{$i}.xml", self::MAX_ENTRY_UNCOMPRESSED_BYTES);
                    if ($sheetXmlContent !== false) {
                        break;
                    }
                }
            }

            if ($sheetXmlContent === false) {
                throw new InvalidArgumentException('Lembar kerja (worksheet) tidak ditemukan dalam berkas .xlsx.');
            }

            $sheetXml = $this->parseXmlSafely($sheetXmlContent);

            if (! $sheetXml || ! isset($sheetXml->sheetData)) {
                return [];
            }

            $rows = [];
            $rowCount = 0;

            foreach ($sheetXml->sheetData->row as $row) {
                $rowCount++;
                if ($rowCount > self::MAX_ROWS_PER_SHEET) {
                    throw new InvalidArgumentException(sprintf('Jumlah baris lembar kerja melebihi batas maksimal (%d baris).', self::MAX_ROWS_PER_SHEET));
                }

                $rowNum = (int) $row['r'];
                $rowData = [];
                $cellCount = 0;

                foreach ($row->c as $c) {
                    $cellCount++;
                    if ($cellCount > self::MAX_CELLS_PER_ROW) {
                        throw new InvalidArgumentException(sprintf('Jumlah kolom pada baris %d melebihi batas maksimal (%d kolom).', $rowNum, self::MAX_CELLS_PER_ROW));
                    }

                    $cellRef = (string) $c['r']; // e.g. "A1", "B1", "AA1"
                    $colIndex = $this->columnCoordinateToIndex($cellRef);
                    $type = (string) $c['t'];

                    $val = '';
                    if ($type === 's') { // shared string
                        $sIndex = (int) $c->v;
                        $val = $sharedStrings[$sIndex] ?? '';
                    } elseif ($type === 'inlineStr') {
                        $val = (string) ($c->is->t ?? '');
                    } else {
                        $val = (string) ($c->v ?? '');
                    }

                    $rowData[$colIndex] = trim($val);
                }

                if (! empty($rowData)) {
                    $maxCol = max(array_keys($rowData));
                    $normalizedRow = [];
                    for ($c = 0; $c <= $maxCol; $c++) {
                        $normalizedRow[$c] = $rowData[$c] ?? '';
                    }
                    $rows[$rowNum] = $normalizedRow;
                }
            }

            ksort($rows);

            return array_values($rows);
        } finally {
            $zip->close();
        }
    }

    /**
     * Validate ZIP entry counts, uncompressed sizes, and prevent ZIP bomb attacks.
     */
    private function validateZipSafety(ZipArchive $zip): void
    {
        $numFiles = $zip->numFiles;

        if ($numFiles <= 0) {
            throw new InvalidArgumentException('Berkas .xlsx tidak berisi file yang valid.');
        }

        if ($numFiles > self::MAX_ZIP_ENTRIES) {
            throw new InvalidArgumentException(sprintf('Berkas .xlsx berisi %d entri (batas maksimal %d entri).', $numFiles, self::MAX_ZIP_ENTRIES));
        }

        $totalUncompressedBytes = 0;

        for ($i = 0; $i < $numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if (! is_array($stat)) {
                throw new InvalidArgumentException('Struktur metadata arsip ZIP rusak atau tidak terbaca.');
            }

            $entryName = $stat['name'] ?? '';
            $entrySize = (int) ($stat['size'] ?? 0);

            // Path traversal guard inside archive names
            if (str_contains($entryName, '..') || str_starts_with($entryName, '/') || str_starts_with($entryName, '\\')) {
                throw new InvalidArgumentException('Ditemukan path entry yang mencurigakan di dalam arsip ZIP.');
            }

            if ($entrySize > self::MAX_ENTRY_UNCOMPRESSED_BYTES) {
                throw new InvalidArgumentException(sprintf('Entri [%s] berukuran %s, melebihi batas aman per entri (%s).', $entryName, $this->formatBytes($entrySize), $this->formatBytes(self::MAX_ENTRY_UNCOMPRESSED_BYTES)));
            }

            $totalUncompressedBytes += $entrySize;

            if ($totalUncompressedBytes > self::MAX_TOTAL_UNCOMPRESSED_BYTES) {
                throw new InvalidArgumentException(sprintf('Total ukuran dekompresi berkas melebihi batas keamanan (%s).', $this->formatBytes(self::MAX_TOTAL_UNCOMPRESSED_BYTES)));
            }
        }
    }

    /**
     * Safely read an XML entry from ZIP with size enforcement.
     */
    private function safeGetXmlEntry(ZipArchive $zip, string $entryName, int $maxBytes): string|false
    {
        $stat = $zip->statName($entryName);
        if ($stat === false) {
            return false;
        }

        $size = (int) ($stat['size'] ?? 0);
        if ($size > $maxBytes) {
            throw new InvalidArgumentException(sprintf('Ukuran entri [%s] (%s) melampaui batas aman (%s).', $entryName, $this->formatBytes($size), $this->formatBytes($maxBytes)));
        }

        return $zip->getFromName($entryName);
    }

    /**
     * Safely parse XML string with entity loading disabled and errors silenced.
     */
    private function parseXmlSafely(string $xmlContent): \SimpleXMLElement
    {
        $previousEntityLoader = false;
        if (\PHP_VERSION_ID < 80000 && function_exists('libxml_disable_entity_loader')) {
            /** @noinspection PhpDeprecationInspection */
            $previousEntityLoader = libxml_disable_entity_loader(true);
        }

        $previousInternalErrors = libxml_use_internal_errors(true);
        libxml_clear_errors();

        try {
            $xml = simplexml_load_string($xmlContent, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
            if ($xml === false) {
                throw new InvalidArgumentException('Gagal memproses struktur XML pada berkas .xlsx.');
            }

            return $xml;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousInternalErrors);

            if (\PHP_VERSION_ID < 80000 && function_exists('libxml_disable_entity_loader')) {
                /** @noinspection PhpDeprecationInspection */
                libxml_disable_entity_loader($previousEntityLoader);
            }
        }
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 1).' MB';
        }
        if ($bytes >= 1024) {
            return round($bytes / 1024, 1).' KB';
        }

        return $bytes.' B';
    }

    /**
     * Write rows to an XLSX file.
     *
     * @param  array<int, array<int, mixed>>  $rows
     */
    public function writeRows(string $outputPath, array $rows, string $sheetName = 'Sheet1'): void
    {
        $tempDir = sys_get_temp_dir().'/xlsx_build_'.uniqid('', true);
        mkdir($tempDir, 0755, true);
        mkdir($tempDir.'/_rels', 0755, true);
        mkdir($tempDir.'/xl', 0755, true);
        mkdir($tempDir.'/xl/_rels', 0755, true);
        mkdir($tempDir.'/xl/worksheets', 0755, true);

        file_put_contents($tempDir.'/[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
    <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
    <Default Extension="xml" ContentType="application/xml"/>
    <Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
    <Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
</Types>');

        file_put_contents($tempDir.'/_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
    <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>
</Relationships>');

        file_put_contents($tempDir.'/xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
    <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>
</Relationships>');

        $safeSheetName = htmlspecialchars($sheetName, ENT_XML1, 'UTF-8');
        file_put_contents($tempDir.'/xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
    <sheets>
        <sheet name="'.$safeSheetName.'" sheetId="1" r:id="rId1"/>
    </sheets>
</workbook>');

        // Generate sheet1.xml
        $sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'."\n";
        $sheetXml .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'."\n";
        $sheetXml .= '    <sheetData>'."\n";

        foreach ($rows as $rowIndex => $columns) {
            $rNum = $rowIndex + 1;
            $sheetXml .= "        <row r=\"{$rNum}\">\n";

            foreach ($columns as $colIndex => $val) {
                $colLetter = $this->indexToColumnLetter($colIndex);
                $cellRef = "{$colLetter}{$rNum}";
                $safeVal = htmlspecialchars((string) $val, ENT_XML1, 'UTF-8');

                $sheetXml .= "            <c r=\"{$cellRef}\" t=\"inlineStr\"><is><t>{$safeVal}</t></is></c>\n";
            }

            $sheetXml .= "        </row>\n";
        }

        $sheetXml .= "    </sheetData>\n";
        $sheetXml .= '</worksheet>';

        file_put_contents($tempDir.'/xl/worksheets/sheet1.xml', $sheetXml);

        $zip = new ZipArchive;
        if ($zip->open($outputPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            $this->removeDirectory($tempDir);
            throw new \RuntimeException("Gagal membuat berkas XLSX pada path [{$outputPath}].");
        }

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($tempDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($files as $file) {
            $local = substr($file->getPathname(), strlen($tempDir) + 1);
            $local = str_replace('\\', '/', $local);
            $zip->addFile($file->getPathname(), $local);
        }

        $zip->close();
        $this->removeDirectory($tempDir);
    }

    private function columnCoordinateToIndex(string $coord): int
    {
        preg_match('/^([A-Z]+)/i', $coord, $matches);
        if (! isset($matches[1])) {
            return 0;
        }

        $letters = strtoupper($matches[1]);
        $length = strlen($letters);
        $index = 0;

        for ($i = 0; $i < $length; $i++) {
            $index = $index * 26 + (ord($letters[$i]) - ord('A') + 1);
        }

        return $index - 1; // 0-based
    }

    private function indexToColumnLetter(int $index): string
    {
        $letter = '';
        $index = $index + 1; // 1-based

        while ($index > 0) {
            $modulo = ($index - 1) % 26;
            $letter = chr(65 + $modulo).$letter;
            $index = (int) (($index - $modulo) / 26);
        }

        return $letter;
    }

    private function removeDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $files = array_diff(scandir($path), ['.', '..']);
        foreach ($files as $file) {
            $full = "{$path}/{$file}";
            is_dir($full) ? $this->removeDirectory($full) : unlink($full);
        }
        rmdir($path);
    }
}
