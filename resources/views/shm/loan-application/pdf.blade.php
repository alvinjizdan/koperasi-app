<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Pengajuan Pinjaman SHM</title>
    <style>
        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 11px;
            color: #0f172a;
            margin: 0;
        }

        h1 {
            font-size: 16px;
            margin: 0 0 2px 0;
        }

        h2 {
            font-size: 12px;
            margin: 16px 0 6px 0;
            padding-bottom: 3px;
            border-bottom: 1px solid #cbd5e1;
            text-transform: uppercase;
        }

        .subtitle {
            font-size: 10px;
            color: #475569;
            margin: 0 0 12px 0;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        .info td {
            padding: 3px 4px;
            vertical-align: top;
            border-bottom: 1px dotted #e2e8f0;
        }

        .info td.label {
            width: 42%;
            color: #475569;
        }

        .info td.value {
            font-weight: bold;
        }

        .list {
            margin-top: 4px;
        }

        .list th, .list td {
            border: 1px solid #cbd5e1;
            padding: 4px 6px;
            vertical-align: top;
        }

        .list th {
            background: #f1f5f9;
            text-align: left;
            font-size: 10px;
        }

        .list td.center {
            text-align: center;
        }

        .note {
            margin-top: 12px;
            padding: 6px 8px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            font-size: 9px;
            color: #475569;
        }

        .badge {
            display: inline-block;
            padding: 1px 5px;
            border: 1px solid #cbd5e1;
            border-radius: 3px;
            font-size: 9px;
            color: #334155;
        }
    </style>
</head>
<body>
<h1>PENGAJUAN PINJAMAN SHM</h1>
<p class="subtitle">
    KSP Bima Sakti Mandiri — Modul SHM/SHGB<br>
    Nomor pengajuan: <strong>{{ $application->application_number }}</strong><br>
    Tanggal pengajuan: {{ $application->application_date?->format('d/m/Y') }} &middot;
    Status:
    @if ($application->status === 'approved')
        DISETUJUI
    @elseif ($application->status === 'rejected')
        DITOLAK
    @else
        BARU (menunggu keputusan)
    @endif
    <br>
    Dibuat: {{ $generatedAt->format('d/m/Y H:i') }}
</p>

<h2>1. Data Pribadi</h2>
<table class="info">
    <tr>
        <td class="label">Nomor Nasabah (Nomor Anggota SHM)</td>
        <td class="value">{{ $application->customer?->customer_number ?? '-' }}</td>
    </tr>
    <tr>
        <td class="label">Nama Lengkap</td>
        <td class="value">{{ $application->customer?->name ?? '-' }}</td>
    </tr>
    <tr>
        <td class="label">No. Identitas (NIK)</td>
        <td class="value">{{ ($application->customer?->nik) ?: '-' }}</td>
    </tr>
    <tr>
        <td class="label">No. Ponsel</td>
        <td class="value">{{ ($application->customer?->phone) ?: '-' }}</td>
    </tr>
    <tr>
        <td class="label">Alamat</td>
        <td class="value">{{ ($application->customer?->address) ?: '-' }}</td>
    </tr>
    <tr>
        <td class="label">Status nasabah</td>
        <td class="value">{{ $application->customer?->is_active ? 'Aktif' : 'Nonaktif' }}</td>
    </tr>
    <tr>
        <td class="label">Tanggal menjadi nasabah SHM</td>
        <td class="value">{{ $application->customer?->created_at?->format('d/m/Y') ?? '-' }}</td>
    </tr>
    <tr>
        <td class="label">Unit Kerja / NIP / Tempat-Tanggal Lahir / Jumlah Tanggungan</td>
        <td class="value">Belum tersedia (PENDING BUSINESS CONFIRMATION)</td>
    </tr>
    <tr>
        <td class="label">Saldo Modal / Saldo Non Modal / Batas Hutang</td>
        <td class="value">Belum tersedia (PENDING BUSINESS CONFIRMATION)</td>
    </tr>
</table>

<h2>2. Pengajuan Pinjaman</h2>
<table class="info">
    <tr>
        <td class="label">Produk Pinjaman</td>
        <td class="value">
            {{ $application->product ? $application->product->name.' ('.$application->product->code.')' : '-' }}
        </td>
    </tr>
    <tr>
        <td class="label">Besar Pinjaman</td>
        <td class="value">Rp {{ number_format((float) $application->principal, 0, ',', '.') }}</td>
    </tr>
    <tr>
        <td class="label">Jangka Waktu</td>
        <td class="value">{{ $application->tenor_months }} kali angsuran</td>
    </tr>
    <tr>
        <td class="label">Tujuan Pinjaman</td>
        <td class="value">{{ ($application->purpose) ?: '-' }}</td>
    </tr>
    <tr>
        <td class="label">Pinjaman yang ke-</td>
        <td class="value">{{ $application->loan_sequence ?? '-' }}</td>
    </tr>
    <tr>
        <td class="label">Metode penyetoran/pembayaran angsuran</td>
        <td class="value">{{ ($application->disbursement_method) ?: '-' }}</td>
    </tr>
</table>

<h2>3. Data Penghasilan dan Pengeluaran Tiap Bulan</h2>
<table class="info">
    <tr><td class="label">Penghasilan Tetap</td><td class="value">Rp {{ number_format((float) $application->income_fixed, 0, ',', '.') }}</td></tr>
    <tr><td class="label">Penghasilan Istri/Suami</td><td class="value">Rp {{ number_format((float) $application->income_spouse, 0, ',', '.') }}</td></tr>
    <tr><td class="label">Penghasilan Usaha/Lain-lain</td><td class="value">Rp {{ number_format((float) $application->income_business, 0, ',', '.') }}</td></tr>
    <tr><td class="label">Total Penghasilan</td><td class="value">Rp {{ number_format($application->totalIncome(), 0, ',', '.') }}</td></tr>
    <tr><td class="label">Potongan Gaji/Angsuran Pinjaman</td><td class="value">Rp {{ number_format((float) $application->expense_deduction, 0, ',', '.') }}</td></tr>
    <tr><td class="label">Biaya Rumah Tangga</td><td class="value">Rp {{ number_format((float) $application->expense_household, 0, ',', '.') }}</td></tr>
    <tr><td class="label">Biaya Pendidikan</td><td class="value">Rp {{ number_format((float) $application->expense_education, 0, ',', '.') }}</td></tr>
    <tr><td class="label">Sewa/Kontrak Rumah</td><td class="value">Rp {{ number_format((float) $application->expense_rent, 0, ',', '.') }}</td></tr>
    <tr><td class="label">Biaya Listrik/Telepon/Air</td><td class="value">Rp {{ number_format((float) $application->expense_utilities, 0, ',', '.') }}</td></tr>
    <tr><td class="label">Transportasi</td><td class="value">Rp {{ number_format((float) $application->expense_transport, 0, ',', '.') }}</td></tr>
    <tr><td class="label">Pengeluaran Lain-lain</td><td class="value">Rp {{ number_format((float) $application->expense_other, 0, ',', '.') }}</td></tr>
    <tr><td class="label">Total Pengeluaran</td><td class="value">Rp {{ number_format($application->totalExpense(), 0, ',', '.') }}</td></tr>
    <tr><td class="label">Sisa Penghasilan</td><td class="value">Rp {{ number_format($application->remainingIncome(), 0, ',', '.') }}</td></tr>
</table>

<h2>4. Data Tambahan</h2>
<table class="info">
    <tr>
        <td class="label">Saldo pinjaman koperasi saat ini</td>
        <td class="value">{{ $application->koperasi_loan_balance !== null ? 'Rp '.number_format((float) $application->koperasi_loan_balance, 0, ',', '.') : '-' }}</td>
    </tr>
    <tr>
        <td class="label">Fasilitas pinjaman dari lembaga lain</td>
        <td class="value">{{ ($application->other_facility) ?: '-' }}</td>
    </tr>
    <tr>
        <td class="label">Nama lembaga</td>
        <td class="value">{{ ($application->institution_name) ?: '-' }}</td>
    </tr>
</table>
@if ($application->assets->isEmpty())
    <table class="info">
        <tr><td class="label">Barang agunan kredit</td><td class="value">-</td></tr>
    </table>
@else
    <table class="list">
        <thead>
            <tr>
                <th style="width: 6%;">No</th>
                <th style="width: 34%;">Jenis barang</th>
                <th style="width: 20%;">Nomor</th>
                <th style="width: 10%;">Tahun</th>
                <th style="width: 30%;">Nilai perkiraan</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($application->assets as $index => $asset)
                <tr>
                    <td class="center">{{ $index + 1 }}</td>
                    <td>{{ $asset->asset_type }}</td>
                    <td>{{ $asset->asset_number ?? '-' }}</td>
                    <td>{{ $asset->asset_year ?? '-' }}</td>
                    <td>{{ $asset->estimated_value !== null ? 'Rp '.number_format((float) $asset->estimated_value, 0, ',', '.') : '-' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif

<h2>5. Jaminan Pinjaman</h2>
@if ($application->collaterals->isEmpty())
    <table class="info">
        <tr><td class="label">Jaminan</td><td class="value">-</td></tr>
    </table>
@else
    <table class="list">
        <thead>
            <tr>
                <th style="width: 5%;">No</th>
                <th style="width: 10%;">Tanggal</th>
                <th style="width: 17%;">Jaminan</th>
                <th style="width: 18%;">Nama Jaminan</th>
                <th style="width: 14%;">Nilai Perkiraan</th>
                <th style="width: 12%;">Status Pengikatan</th>
                <th style="width: 10%;">Tgl Pengikatan</th>
                <th style="width: 14%;">Nilai Hak Tanggungan</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($application->collaterals as $index => $collateral)
                <tr>
                    <td class="center">{{ $index + 1 }}</td>
                    <td>{{ $collateral->collateral_date?->format('d/m/Y') ?? '-' }}</td>
                    <td>{{ $collateral->collateral_type }}</td>
                    <td>{{ $collateral->collateral_name }}</td>
                    <td>{{ $collateral->estimated_value !== null ? 'Rp '.number_format((float) $collateral->estimated_value, 0, ',', '.') : '-' }}</td>
                    <td>{{ ($collateral->binding_status) ?? '-' }}</td>
                    <td>{{ $collateral->binding_date?->format('d/m/Y') ?? '-' }}</td>
                    <td>{{ $collateral->rights_value !== null ? 'Rp '.number_format((float) $collateral->rights_value, 0, ',', '.') : '-' }}</td>
                </tr>
                <tr>
                    <td colspan="8"><strong>Keterangan:</strong> {{ ($collateral->notes) ?? '-' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif
<p class="note">
    Foto jaminan: PENDING INFRASTRUCTURE — penyimpanan berkas belum tersedia,
    sehingga foto tidak disertakan pada dokumen ini.
</p>

<h2>6. Keterangan / Catatan oleh Kasir / SC</h2>
<table class="info">
    <tr><td class="label">Tanggal</td><td class="value">{{ $application->kasir_note_date?->format('d/m/Y') ?? '-' }}</td></tr>
    <tr><td class="label">Nama</td><td class="value">{{ ($application->kasir_note_name) ?? '-' }}</td></tr>
    <tr>
        <td class="label">Catatan</td>
        <td class="value">{!! nl2br(e($application->kasir_note_text ?? '-')) !!}</td>
    </tr>
</table>

<h2>7. Analisa Pinjaman</h2>
<table class="info">
    <tr><td class="label">Kode Analis</td><td class="value">{{ ($application->analyst_code) ?? '-' }}</td></tr>
    <tr><td class="label">Kantor (Analis)</td><td class="value">{{ ($application->analyst_office) ?? '-' }}</td></tr>
    <tr><td class="label">Kode Account Officer</td><td class="value">{{ ($application->officer_code) ?? '-' }}</td></tr>
    <tr><td class="label">Kantor (Account Officer)</td><td class="value">{{ ($application->officer_office) ?? '-' }}</td></tr>
    <tr><td class="label">Tanggal</td><td class="value">{{ $application->analysis_date?->format('d/m/Y') ?? '-' }}</td></tr>
    <tr><td class="label">Nilai Pinjaman</td><td class="value">{{ $application->analysis_value !== null ? 'Rp '.number_format((float) $application->analysis_value, 0, ',', '.') : '-' }}</td></tr>
    <tr><td class="label">Keterangan / hasil analisa</td><td class="value">{!! nl2br(e($application->analysis_notes ?? '-')) !!}</td></tr>
</table>

<h2>8. Data Tambahan Analisa Pinjaman</h2>
<table class="info">
    <tr>
        <td class="label">Data tambahan</td>
        <td class="value">Belum tersedia (PENDING BUSINESS CONFIRMATION)</td>
    </tr>
</table>

<h2>9. Kolektor Pinjaman</h2>
<table class="info">
    <tr><td class="label">Nama Kolektor Pinjaman</td><td class="value">{{ ($application->collector_name) ?? '-' }}</td></tr>
</table>

<h2>10. Persetujuan Pinjaman</h2>
<table class="info">
    <tr>
        <td class="label">Produk Pinjaman</td>
        <td class="value">
            {{ $application->product ? $application->product->name.' ('.$application->product->code.')' : '-' }}
        </td>
    </tr>
    <tr><td class="label">Pengajuan Pinjaman Sebesar</td><td class="value">Rp {{ number_format((float) $application->principal, 0, ',', '.') }}</td></tr>
    <tr><td class="label">Analisa Pinjaman Sebesar</td><td class="value">{{ $application->analysis_value !== null ? 'Rp '.number_format((float) $application->analysis_value, 0, ',', '.') : '-' }}</td></tr>
    <tr><td class="label">Persetujuan Pinjaman Sebesar</td><td class="value">{{ $application->approved_amount !== null ? 'Rp '.number_format((float) $application->approved_amount, 0, ',', '.') : '-' }}</td></tr>
    <tr><td class="label">Biaya Provisi</td><td class="value">{{ $application->provision_fee !== null ? 'Rp '.number_format((float) $application->provision_fee, 0, ',', '.') : '-' }}</td></tr>
    <tr><td class="label">Biaya Administrasi</td><td class="value">{{ $application->administration_fee !== null ? 'Rp '.number_format((float) $application->administration_fee, 0, ',', '.') : '-' }}</td></tr>
    <tr><td class="label">Jangka waktu</td><td class="value">{{ $application->tenor_months }} kali angsuran</td></tr>
    <tr>
        <td class="label">Jasa / rate</td>
        <td class="value">
            {{ rtrim(rtrim(number_format($application->officialRatePercent(), 4, ',', '.'), '0'), ',') }}%
            <span class="badge">Rate resmi SHM: 2%</span>
        </td>
    </tr>
    <tr>
        <td class="label">Metode perhitungan</td>
        <td class="value">{{ $application->calculation_method !== null ? ($methods[$application->calculation_method] ?? $application->calculation_method) : 'PENDING — belum dipilih (tidak ditebak sistem)' }}</td>
    </tr>
    <tr>
        <td class="label">Status keputusan</td>
        <td class="value">
            @if ($application->status === 'approved')
                DISETUJUI
            @elseif ($application->status === 'rejected')
                DITOLAK
            @else
                BARU (menunggu keputusan)
            @endif
        </td>
    </tr>
    <tr><td class="label">Keterangan keputusan</td><td class="value">{!! nl2br(e($application->approval_notes ?? '-')) !!}</td></tr>
</table>
<p class="note">
    Keputusan approval diambil melalui workflow approval SHM existing —
    halaman ini hanya mencatat statusnya. Formula bunga, periodisasi, dan
    status "ditangguhkan" masih PENDING BUSINESS CONFIRMATION.
</p>

<h2>11. Persyaratan Tambahan Jika Pinjaman Disetujui</h2>
@if ($application->requirements->isEmpty())
    <table class="info">
        <tr><td class="label">Persyaratan tambahan</td><td class="value">-</td></tr>
    </table>
@else
    <table class="list">
        <thead>
            <tr>
                <th style="width: 6%;">No</th>
                <th style="width: 74%;">Persyaratan</th>
                <th style="width: 20%;">Status</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($application->requirements as $index => $requirement)
                <tr>
                    <td class="center">{{ $index + 1 }}</td>
                    <td>{{ $requirement->requirement }}</td>
                    <td class="center">{{ $requirement->is_done ? 'Terpenuhi' : 'Belum' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif

<p class="note">
    Dokumen dihasilkan otomatis pada {{ $generatedAt->format('d/m/Y H:i') }} &middot;
    Identifier pengajuan: {{ $application->application_number }} &middot;
    Modul SHM/SHGB KSP Bima Sakti Mandiri.
</p>
</body>
</html>
