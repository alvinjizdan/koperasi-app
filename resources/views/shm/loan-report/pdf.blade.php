<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Laporan Pinjaman BSM Makro - {{ $loan->loan_number }}</title>
    <style>
        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 10px;
            color: #0f172a;
            margin: 0;
            padding: 10px;
        }

        h1 {
            font-size: 15px;
            margin: 0 0 2px 0;
            color: #0f172a;
        }

        h2 {
            font-size: 11px;
            margin: 14px 0 5px 0;
            padding-bottom: 3px;
            border-bottom: 1.5px solid #0284c7;
            text-transform: uppercase;
            color: #0369a1;
        }

        .subtitle {
            font-size: 9px;
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
            width: 38%;
            color: #475569;
        }

        .info td.value {
            font-weight: bold;
            color: #1e293b;
        }

        .list {
            margin-top: 4px;
            font-size: 9px;
        }

        .list th, .list td {
            border: 1px solid #cbd5e1;
            padding: 4px 5px;
            vertical-align: top;
        }

        .list th {
            background: #f1f5f9;
            text-align: left;
            font-weight: bold;
            color: #334155;
        }

        .list td.center {
            text-align: center;
        }

        .list td.right {
            text-align: right;
        }

        .summary-card {
            background: #f8fafc;
            border: 1px solid #cbd5e1;
            border-radius: 4px;
            padding: 8px;
            margin-top: 10px;
        }

        .badge {
            display: inline-block;
            padding: 2px 6px;
            border-radius: 3px;
            font-size: 9px;
            font-weight: bold;
        }

        .badge-active {
            background: #dcfce7;
            color: #15803d;
        }

        .badge-closed {
            background: #f1f5f9;
            color: #475569;
        }
    </style>
</head>
<body>
    <h1>LAPORAN PINJAMAN BSM MAKRO</h1>
    <p class="subtitle">
        KSP Bima Sakti Mandiri &middot; Modul Pinjaman BSM Makro<br>
        Nomor Pinjaman: <strong>{{ $loan->loan_number }}</strong> &middot;
        Dicetak pada: {{ $generatedAt->format('d/m/Y H:i') }} &middot;
        Status: 
        <span class="badge {{ $loan->status === 'active' ? 'badge-active' : 'badge-closed' }}">
            {{ $loan->status === 'active' ? 'BERJALAN (ACTIVE)' : 'LUNAS / DITUTUP (CLOSED)' }}
        </span>
    </p>

    <!-- 1. DATA NASABAH -->
    <h2>1. Data Nasabah</h2>
    <table class="info">
        <tr>
            <td class="label">Nomor Nasabah (No. Anggota SHM)</td>
            <td class="value">{{ $report['nasabah']['nomor_nasabah'] ?? '-' }}</td>
        </tr>
        <tr>
            <td class="label">Nama Lengkap</td>
            <td class="value">{{ $report['nasabah']['nama'] ?? '-' }}</td>
        </tr>
        <tr>
            <td class="label">No. Identitas (NIK)</td>
            <td class="value">{{ $report['nasabah']['nik'] ?? '-' }}</td>
        </tr>
        <tr>
            <td class="label">No. Ponsel</td>
            <td class="value">{{ $report['nasabah']['telepon'] ?? '-' }}</td>
        </tr>
        <tr>
            <td class="label">Alamat</td>
            <td class="value">{{ $report['nasabah']['alamat'] ?? '-' }}</td>
        </tr>
        <tr>
            <td class="label">Status Nasabah</td>
            <td class="value">{{ ($report['nasabah']['status_aktif'] ?? false) ? 'Aktif' : 'Nonaktif' }}</td>
        </tr>
        <tr>
            <td class="label">Tanggal Menjadi Nasabah</td>
            <td class="value">{{ $report['nasabah']['tanggal_gabung'] ?? '-' }}</td>
        </tr>
        @if (!empty($report['nasabah']['unit_kerja']))
        <tr>
            <td class="label">Unit Kerja</td>
            <td class="value">{{ $report['nasabah']['unit_kerja'] }}</td>
        </tr>
        @endif
        @if (!empty($report['nasabah']['nip']))
        <tr>
            <td class="label">NIP</td>
            <td class="value">{{ $report['nasabah']['nip'] }}</td>
        </tr>
        @endif
        @if (!empty($report['nasabah']['telepon_rumah']))
        <tr>
            <td class="label">Telepon Rumah</td>
            <td class="value">{{ $report['nasabah']['telepon_rumah'] }}</td>
        </tr>
        @endif
        @if (!empty($report['nasabah']['ttl']))
        <tr>
            <td class="label">Tempat / Tanggal Lahir</td>
            <td class="value">{{ $report['nasabah']['ttl'] }}</td>
        </tr>
        @endif
        @if (isset($report['nasabah']['jumlah_tanggungan']) && $report['nasabah']['jumlah_tanggungan'] !== null)
        <tr>
            <td class="label">Jumlah Tanggungan</td>
            <td class="value">{{ $report['nasabah']['jumlah_tanggungan'] }} orang</td>
        </tr>
        @endif
    </table>

    <!-- 2. DATA PINJAMAN -->
    <h2>2. Data Pinjaman</h2>
    <table class="info">
        <tr>
            <td class="label">Nomor Pinjaman</td>
            <td class="value">{{ $loan->loan_number }}</td>
        </tr>
        <tr>
            <td class="label">Produk Pinjaman</td>
            <td class="value">{{ $report['pinjaman']['produk'] }}</td>
        </tr>
        <tr>
            <td class="label">Besar Pinjaman (Plafon)</td>
            <td class="value">Rp {{ number_format((float) $loan->principal, 0, ',', '.') }}</td>
        </tr>
        <tr>
            <td class="label">Jangka Waktu (Tenor)</td>
            <td class="value">{{ $loan->tenor_months }} Bulan</td>
        </tr>
        <tr>
            <td class="label">Suku Bunga / Jasa</td>
            <td class="value">{{ Number::format((float) $loan->rate_percent, 2) }}% ({{ ucfirst($loan->rate_period ?? 'bulan') }}) &middot; {{ ucfirst($loan->calculation_method ?? 'flat') }}</td>
        </tr>
        <tr>
            <td class="label">Tujuan Pinjaman</td>
            <td class="value">{{ $loan->purpose ?: '-' }}</td>
        </tr>
        <tr>
            <td class="label">Tanggal Pencairan (Disbursed)</td>
            <td class="value">{{ $loan->disbursed_at?->format('d/m/Y') ?? '-' }}</td>
        </tr>
        <tr>
            <td class="label">Jatuh Tempo Pertama</td>
            <td class="value">{{ $loan->first_due_date?->format('d/m/Y') ?? '-' }}</td>
        </tr>
        @if ($loan->application)
        <tr>
            <td class="label">Nomor Pengajuan Asal</td>
            <td class="value">{{ $loan->application->application_number }} (Tgl: {{ $loan->application->application_date?->format('d/m/Y') }})</td>
        </tr>
        @endif
    </table>

    <!-- 3. DATA JAMINAN -->
    <h2>3. Jaminan Pinjaman</h2>
    @if (empty($report['jaminan']))
        <table class="info">
            <tr><td class="label">Jaminan</td><td class="value">Tidak ada data jaminan yang terdaftar untuk pinjaman ini.</td></tr>
        </table>
    @else
        <table class="list">
            <thead>
                <tr>
                    <th style="width: 5%;">No</th>
                    <th style="width: 15%;">Jenis</th>
                    <th style="width: 25%;">Nama / Objek Jaminan</th>
                    <th style="width: 25%;">Keterangan</th>
                    <th style="width: 15%;" class="right">Nilai Perkiraan</th>
                    <th style="width: 15%;">Status Pengikatan</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($report['jaminan'] as $index => $item)
                    <tr>
                        <td class="center">{{ $index + 1 }}</td>
                        <td>{{ $item['jenis'] }}</td>
                        <td>{{ $item['nama'] }}</td>
                        <td>{{ $item['keterangan'] ?: '-' }}</td>
                        <td class="right">Rp {{ number_format((float) $item['nilai_perkiraan'], 0, ',', '.') }}</td>
                        <td>{{ $item['status_pengikatan'] ?: '-' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <!-- 4. RINGKASAN KEWAJIBAN & ANGSURAN -->
    <h2>4. Ringkasan Kewajiban & Pembayaran</h2>
    <div class="summary-card">
        <table class="info">
            <tr>
                <td class="label">Total Plafon Pinjaman</td>
                <td class="value">Rp {{ number_format((float) $report['ringkasan']['total_pinjaman'], 0, ',', '.') }}</td>
            </tr>
            <tr>
                <td class="label">Pokok Sudah Dibayar</td>
                <td class="value" style="color: #16a34a;">Rp {{ number_format((float) $report['ringkasan']['pokok_dibayar'], 0, ',', '.') }}</td>
            </tr>
            <tr>
                <td class="label">Bunga / Jasa Sudah Dibayar</td>
                <td class="value" style="color: #16a34a;">Rp {{ number_format((float) $report['ringkasan']['bunga_dibayar'], 0, ',', '.') }}</td>
            </tr>
            <tr>
                <td class="label">Sisa Pokok (Outstanding Principal)</td>
                <td class="value" style="color: #dc2626;">Rp {{ number_format((float) $report['ringkasan']['sisa_pokok'], 0, ',', '.') }}</td>
            </tr>
            <tr>
                <td class="label">Sisa Kewajiban Bunga</td>
                <td class="value" style="color: #dc2626;">Rp {{ number_format((float) $report['ringkasan']['sisa_bunga'], 0, ',', '.') }}</td>
            </tr>
            <tr>
                <td class="label">Total Sisa Kewajiban</td>
                <td class="value" style="color: #b91c1c; font-size: 11px;">Rp {{ number_format((float) $report['ringkasan']['total_sisa_kewajiban'], 0, ',', '.') }}</td>
            </tr>
            <tr>
                <td class="label">Progres Angsuran</td>
                <td class="value">{{ $report['ringkasan']['angsuran_terbayar'] }} dari {{ $report['ringkasan']['total_angsuran'] }} angsuran terbayar (Sisa: {{ $report['ringkasan']['angsuran_tersisa'] }})</td>
            </tr>
        </table>
    </div>

    <!-- 5. RIWAYAT JADWAL & ANGSURAN -->
    @if (!empty($report['angsuran']))
        <h2>5. Riwayat Jadwal Angsuran</h2>
        <table class="list">
            <thead>
                <tr>
                    <th style="width: 6%;" class="center">Ke</th>
                    <th style="width: 14%;">Jatuh Tempo</th>
                    <th style="width: 18%;" class="right">Pokok (Rp)</th>
                    <th style="width: 18%;" class="right">Bunga (Rp)</th>
                    <th style="width: 18%;" class="right">Total (Rp)</th>
                    <th style="width: 16%;" class="right">Terbayar (Rp)</th>
                    <th style="width: 10%;" class="center">Status</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($report['angsuran'] as $inst)
                    <tr>
                        <td class="center">{{ $inst['nomor'] }}</td>
                        <td>{{ $inst['jatuh_tempo'] }}</td>
                        <td class="right">{{ number_format((float) $inst['pokok'], 0, ',', '.') }}</td>
                        <td class="right">{{ number_format((float) $inst['bunga'], 0, ',', '.') }}</td>
                        <td class="right">{{ number_format((float) $inst['total'], 0, ',', '.') }}</td>
                        <td class="right">{{ number_format((float) $inst['terbayar'], 0, ',', '.') }}</td>
                        <td class="center">{{ $inst['status'] === 'paid' ? 'LUNAS' : 'BELUM' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</body>
</html>

