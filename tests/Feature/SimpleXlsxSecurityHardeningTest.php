<?php

namespace Tests\Feature;

use App\Services\Accounting\SimpleXlsxService;
use InvalidArgumentException;
use Tests\TestCase;
use ZipArchive;

class SimpleXlsxSecurityHardeningTest extends TestCase
{
    private SimpleXlsxService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new SimpleXlsxService;
    }

    /** 1. Normal XLSX file parses successfully */
    public function test_normal_xlsx_parses_successfully(): void
    {
        $rows = [
            ['Header 1', 'Header 2', 'Header 3'],
            ['Val 1', 'Val 2', 'Val 3'],
            ['Val 4', 'Val 5', 'Val 6'],
        ];

        $filePath = sys_get_temp_dir().'/test_normal_'.uniqid().'.xlsx';
        $this->service->writeRows($filePath, $rows, 'Data');

        $result = $this->service->readRows($filePath);

        $this->assertCount(3, $result);
        $this->assertEquals(['Header 1', 'Header 2', 'Header 3'], $result[0]);
        $this->assertEquals(['Val 1', 'Val 2', 'Val 3'], $result[1]);
        $this->assertEquals(['Val 4', 'Val 5', 'Val 6'], $result[2]);

        @unlink($filePath);
    }

    /** 2. Corrupt or invalid file rejected */
    public function test_corrupt_file_is_rejected(): void
    {
        $filePath = sys_get_temp_dir().'/corrupt_'.uniqid().'.xlsx';
        file_put_contents($filePath, 'NOT A ZIP FILE HEADER 123456');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Struktur berkas .xlsx tidak valid atau rusak.');

        try {
            $this->service->readRows($filePath);
        } finally {
            @unlink($filePath);
        }
    }

    /** 3. Rejection when total uncompressed size exceeds limit */
    public function test_total_uncompressed_size_exceeded_is_rejected(): void
    {
        $filePath = sys_get_temp_dir().'/big_uncompressed_'.uniqid().'.xlsx';
        $zip = new ZipArchive;
        $zip->open($filePath, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        // Add an entry with fake or real large content
        // We write 52 MB of compressible data
        $chunk = str_repeat('A', 1024 * 1024); // 1 MB
        $largeString = str_repeat($chunk, 52); // 52 MB uncompressed
        $zip->addFromString('xl/worksheets/sheet1.xml', $largeString);
        $zip->close();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('melebihi batas aman');

        try {
            $this->service->readRows($filePath);
        } finally {
            @unlink($filePath);
        }
    }

    /** 4. Rejection when number of ZIP entries exceeds limit */
    public function test_excessive_zip_entries_rejected(): void
    {
        $filePath = sys_get_temp_dir().'/many_entries_'.uniqid().'.xlsx';
        $zip = new ZipArchive;
        $zip->open($filePath, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        // Add 105 entries (limit is 100)
        for ($i = 0; $i < 105; $i++) {
            $zip->addFromString("entry_{$i}.txt", 'test');
        }
        $zip->close();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('entri (batas maksimal 100 entri)');

        try {
            $this->service->readRows($filePath);
        } finally {
            @unlink($filePath);
        }
    }

    /** 5. Rejection of suspicious path traversal inside ZIP entry name */
    public function test_suspicious_entry_path_rejected(): void
    {
        $filePath = sys_get_temp_dir().'/path_traversal_'.uniqid().'.xlsx';
        $zip = new ZipArchive;
        $zip->open($filePath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('../../../etc/passwd', 'root:x:0:0');
        $zip->close();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Ditemukan path entry yang mencurigakan');

        try {
            $this->service->readRows($filePath);
        } finally {
            @unlink($filePath);
        }
    }

    /** 6. Missing worksheet rejected cleanly */
    public function test_missing_worksheet_rejected(): void
    {
        $filePath = sys_get_temp_dir().'/no_sheet_'.uniqid().'.xlsx';
        $zip = new ZipArchive;
        $zip->open($filePath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('xl/workbook.xml', '<workbook/>');
        $zip->close();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Lembar kerja (worksheet) tidak ditemukan');

        try {
            $this->service->readRows($filePath);
        } finally {
            @unlink($filePath);
        }
    }

    /** 7. Excessive rows rejected */
    public function test_excessive_rows_rejected(): void
    {
        $filePath = sys_get_temp_dir().'/huge_rows_'.uniqid().'.xlsx';

        // Construct sheet XML with 25001 rows
        $xml = '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
        for ($i = 1; $i <= 25005; $i++) {
            $xml .= "<row r=\"{$i}\"><c r=\"A{$i}\" t=\"inlineStr\"><is><t>Row {$i}</t></is></c></row>";
        }
        $xml .= '</sheetData></worksheet>';

        $zip = new ZipArchive;
        $zip->open($filePath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('xl/worksheets/sheet1.xml', $xml);
        $zip->close();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Jumlah baris lembar kerja melebihi batas maksimal (25000 baris)');

        try {
            $this->service->readRows($filePath);
        } finally {
            @unlink($filePath);
        }
    }

    /** 8. Temporary files are cleaned up on exception in writeRows */
    public function test_write_rows_cleans_up_on_failure(): void
    {
        // Try writing to an impossible/invalid path (e.g. non-existent drive or read-only directory)
        $invalidPath = '/non_existent_folder_xyz/file.xlsx';

        try {
            $this->service->writeRows($invalidPath, [['A', 'B']]);
            $this->fail('Expected exception was not thrown.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Gagal membuat berkas XLSX', $e->getMessage());
        }
    }
}
