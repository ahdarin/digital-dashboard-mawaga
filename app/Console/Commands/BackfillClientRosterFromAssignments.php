<?php

namespace App\Console\Commands;

use App\Models\ContentItemAssignment;
use App\Models\UserClientAssignment;
use Illuminate\Console\Command;

/**
 * Sekali jalan: isi roster client (user_client_assignments) berdasarkan
 * penugasan item yang sudah ada (content_item_assignments) dari SEBELUM
 * roster jadi wajib diisi manual - buat migrasi data lama satu kali saja,
 * bukan proses otomatis yang jalan terus. Roster (siapa PIC klien mana)
 * sekarang murni diatur lewat "Assign Klien" di Kelola Pengguna - PIC
 * hanya bisa dipilih untuk content item kalau sudah terdaftar di roster
 * client-nya, jadi jangan andalkan command ini buat alur normal.
 */
class BackfillClientRosterFromAssignments extends Command
{
    protected $signature = 'roster:backfill-from-assignments';
    protected $description = 'Isi user_client_assignments dari content_item_assignments yang sudah ada';

    public function handle(): void
    {
        $pairs = ContentItemAssignment::with('contentItem')
            ->get()
            ->map(fn ($a) => [
                'user_id' => $a->user_id,
                'client_id' => $a->contentItem?->client_id,
            ])
            ->filter(fn ($p) => $p['client_id'] !== null)
            ->unique(fn ($p) => $p['user_id'].'-'.$p['client_id']);

        $created = 0;

        foreach ($pairs as $pair) {
            $assignment = UserClientAssignment::firstOrCreate($pair);
            if ($assignment->wasRecentlyCreated) {
                $created++;
            }
        }

        $this->info("Roster diperbarui: {$created} pasangan user-client baru ditambahkan dari {$pairs->count()} pasangan unik yang ditemukan.");
    }
}
