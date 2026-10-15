<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Simulasi Pinjaman SHM</title>
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
            margin: 18px 0 6px 0;
            padding-bottom: 3px;
            border-bottom: 1px solid #cbd5e1;
            text-transform: uppercase;
        }

        .subtitle {
            font-size: 10px;
            color: #475569;
            margin: 0 0 14px 0;
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

        .schedule {
            margin-top: 4px;
        }

        .schedule th,
        .schedule td {
            border: 1px solid #cbd5e1;
            padding: 4px 6px;
            font-size: 10px;
        }

        .schedule th {
            background: #f1f5f9;
            font-weight: bold;
            text-align: center;
        }

        .schedule td.num {
            text-align: right;
            white-space: nowrap;
        }

        .schedule td.center {
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
<h1>Simulasi Pinjaman SHM</h1>
<p class="subtitle">
    KSP Bima Sakti Mandiri — Modul SHM/SHGB<br>
    Dibuat: {{ $generatedAt->format('d/m/Y H:i') }}
</p>

<h2>Identitas</h2>
<table class="info">
    <tr>
        <td class="label">Nama calon / nasabah</td>
        <td class="value">{{ ($payload['name'] ?? null) ?: '-' }}</td>
    </tr>
    <tr>
        <td class="label">Produk pinjaman</td>
        <td class="value">{{ $product ? $product->name.' ('.$product->code.')' : '-' }}</td>
    </tr>
    <tr>
        <td class="label">Lokasi pinjaman</td>
        <td class="value">{{ ($payload['location'] ?? null) ?: '-' }}</td>
    </tr>
    <tr>
        <td class="label">Tujuan pinjaman</td>
        <td class="value">{{ ($payload['purpose'] ?? null) ?: '-' }}</td>
    </tr>
</table>

<h2>Detail Pinjaman</h2>
<table class="info">
    <tr>
        <td class="label">Plafon pinjaman</td>
        <td class="value">Rp {{ number_format((float) $result['principal'], 0, ',', '.') }}</td>
    </tr>
    <tr>
        <td class="label">Tenor</td>
        <td class="value">{{ $result['tenor_months'] }} bulan</td>
    </tr>
    <tr>
        <td class="label">Bunga / jasa</td>
        <td class="value">
            {{ rtrim(rtrim(number_format((float) $result['rate_percent'], 4, ',', '.'), '0'), ',') }}%
            per {{ $result['rate_period'] === 'monthly' ? 'bulan' : 'tahun' }}
            <span class="badge">Rate resmi = matriks produk (T82)</span>
        </td>
    </tr>
    <tr>
        <td class="label">Metode angsuran</td>
        <td class="value">            {{ $methods[$result['method']] ?? $result['method'] }}</td>
    </tr>
    <tr>
        <td class="label">Angsuran periode pertama</td>
        <td class="value">Rp {{ number_format((float) $result['installment_per_period'], 0, ',', '.') }}</td>
    </tr>
    <tr>
        <td class="label">Total jasa</td>
        <td class="value">Rp {{ number_format((float) $result['total_interest'], 0, ',', '.') }}</td>
    </tr>
    <tr>
        <td class="label">Total kewajiban (pokok + jasa)</td>
        <td class="value">Rp {{ number_format((float) $result['total_obligation'], 0, ',', '.') }}</td>
    </tr>
    <tr>
        <td class="label">Denda</td>
        <td class="value">PENDING BUSINESS CONFIRMATION</td>
    </tr>
</table>

<h2>Rincian Biaya</h2>
<table class="info">
    <tr>
        <td class="label">Biaya administrasi</td>
        <td class="value">Rp {{ number_format((float) $result['fees']['administration'], 0, ',', '.') }}</td>
    </tr>
    <tr>
        <td class="label">Provisi</td>
        <td class="value">Rp {{ number_format((float) $result['fees']['provision'], 0, ',', '.') }}</td>
    </tr>
    <tr>
        <td class="label">Asuransi</td>
        <td class="value">Rp {{ number_format((float) $result['fees']['insurance'], 0, ',', '.') }}</td>
    </tr>
    <tr>
        <td class="label">Biaya lainnya</td>
        <td class="value">Rp {{ number_format((float) $result['fees']['other'], 0, ',', '.') }}</td>
    </tr>
    <tr>
        <td class="label">Total biaya</td>
        <td class="value">Rp {{ number_format((float) $result['total_fees'], 0, ',', '.') }}</td>
    </tr>
    <tr>
        <td class="label">Blokir dana</td>
        <td class="value">Rp {{ number_format((float) $result['block_funds'], 0, ',', '.') }}</td>
    </tr>
    <tr>
        <td class="label">Total bersih pencairan</td>
        <td class="value">Rp {{ number_format((float) $result['net_disbursement'], 0, ',', '.') }}</td>
    </tr>
</table>

<h2>Tabel Angsuran</h2>
<table class="schedule">
    <thead>
        <tr>
            <th style="width: 8%;">No</th>
            <th style="width: 23%;">Pokok</th>
            <th style="width: 23%;">Jasa / Bunga</th>
            <th style="width: 23%;">Total</th>
            <th style="width: 23%;">Saldo</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($result['rows'] as $row)
            <tr>
                <td class="center">{{ $row['period'] }}</td>
                <td class="num">Rp {{ number_format((float) $row['principal'], 0, ',', '.') }}</td>
                <td class="num">Rp {{ number_format((float) $row['interest'], 0, ',', '.') }}</td>
                <td class="num">Rp {{ number_format((float) $row['total'], 0, ',', '.') }}</td>
                <td class="num">Rp {{ number_format((float) $row['balance'], 0, ',', '.') }}</td>
            </tr>
        @endforeach
        <tr>
            <th class="center">Total</th>
            <th class="num">Rp {{ number_format((float) $result['principal'], 0, ',', '.') }}</th>
            <th class="num">Rp {{ number_format((float) $result['total_interest'], 0, ',', '.') }}</th>
            <th class="num">Rp {{ number_format((float) $result['total_obligation'], 0, ',', '.') }}</th>
            <th class="num">Rp 0</th>
        </tr>
    </tbody>
</table>

<div class="note">
    Dokumen ini adalah HASIL SIMULASI, bukan persetujuan pinjaman. Rate resmi
    SHM mengikuti matriks produk (flat, bulanan — kontrak T79/T82); nilai 2%
    hanyalah default awal form simulasi. Metode angsuran dan periode rate pada
    simulasi ini dipilih/diinput secara eksplisit — bukan keputusan bisnis
    sistem. Denda masih <strong>PENDING BUSINESS CONFIRMATION</strong> dan
    tidak dihitung. Nilai rincian biaya diinput manual, bukan hasil
    perhitungan; biaya pencairan resmi dihitung otomatis saat pencairan (T85).
</div>
</body>
</html>
