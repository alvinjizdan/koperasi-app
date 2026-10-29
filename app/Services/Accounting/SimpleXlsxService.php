<?php

namespace App\Services\Accounting;

use InvalidArgumentException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ZipArchive;

class SimpleXlsxService
{
    /**
     * Read rows from an XLSX file.
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

        // 1. Shared Strings (if any)
        $sharedStrings = [];
        $sharedStringsXml = $zip->getFromName('xl/sharedStrings.xml');
        if ($sharedStringsXml !== false) {
            $sXml = simplexml_load_string($sharedStringsXml);
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
        $sheetXmlContent = $zip->getFromName('xl/worksheets/sheet1.xml');
        if ($sheetXmlContent === false) {
            // Check other potential sheet names
            for ($i = 1; $i <= 5; $i++) {
                $sheetXmlContent = $zip->getFromName("xl/worksheets/sheet{$i}.xml");
                if ($sheetXmlContent !== false) {
                    break;
                }
            }
        }

        if ($sheetXmlContent === false) {
            $zip->close();
            throw new InvalidArgumentException('Lembar kerja (worksheet) tidak ditemukan dalam berkas .xlsx.');
        }

        $sheetXml = simplexml_load_string($sheetXmlContent);
        $zip->close();

        if (! $sheetXml || ! isset($sheetXml->sheetData)) {
            return [];
        }

        $rows = [];
        foreach ($sheetXml->sheetData->row as $row) {
            $rowNum = (int) $row['r'];
            $rowData = [];

            foreach ($row->c as $c) {
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
