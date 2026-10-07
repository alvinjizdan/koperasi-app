<?php

namespace App\Http\Controllers\Marketing;

use App\Http\Controllers\Controller;
use App\Http\Requests\Marketing\UpdateMemberLocationRequest;
use App\Models\Member;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;

/**
 * Titik lokasi anggota/nasabah.
 *
 * Lokasi adalah informasi terpisah dari alamat. Alamat yang sudah tersimpan
 * tidak diubah dan tidak diganti oleh koordinat.
 *
 * Koordinat hanya dapat diperbarui oleh pemegang
 * `marketing.member_location.update` (di antara role bawaan, hanya admin yang
 * memilikinya). Siapa yang sebaiknya boleh mengubah lokasi anggota masih
 * PENDING BUSINESS DECISION.
 */
class MemberLocationController extends Controller
{
    /**
     * Simpan atau kosongkan titik lokasi anggota.
     */
    public function update(UpdateMemberLocationRequest $request, Member $member): RedirectResponse
    {
        $validated = $request->validated();

        $before = [
            'latitude' => $member->latitude,
            'longitude' => $member->longitude,
        ];

        // Koordinat kosong berarti lokasi belum diketahui; sistem tidak pernah
        // membuat koordinat pengganti.
        $member->forceFill([
            'latitude' => $validated['latitude'] ?? null,
            'longitude' => $validated['longitude'] ?? null,
        ])->save();

        AuditLogger::log(
            'member.location_updated',
            Member::class,
            $member->id,
            $member->full_name,
            ($member->hasLocation() ? 'Titik lokasi anggota diperbarui.' : 'Titik lokasi anggota dikosongkan.'),
            [
                'member_number' => $member->member_number,
                'latitude' => ['old' => $before['latitude'], 'new' => $member->latitude],
                'longitude' => ['old' => $before['longitude'], 'new' => $member->longitude],
            ],
        );

        return redirect()
            ->route('anggota.show', $member->id)
            ->with(
                'success',
                $member->hasLocation()
                    ? 'Titik lokasi anggota berhasil disimpan.'
                    : 'Titik lokasi anggota berhasil dikosongkan.'
            );
    }
}
