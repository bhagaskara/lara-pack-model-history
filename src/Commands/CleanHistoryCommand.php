<?php

namespace LaraPack\ModelHistory\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CleanHistoryCommand extends Command
{
    protected $signature = 'lara-pack:clean-history 
                            {date : Batas tanggal (format Y-m-d), data <= tanggal ini akan dihapus} 
                            {--table= : Nama tabel original (opsional)} 
                            {--force : Lewatkan konfirmasi}';

    protected $description = 'Hapus isi tabel _history berdasarkan recorded_at (atau fallback created_at) dan opsional tabel tertentu';

    public function handle()
    {
        $date = $this->argument('date');
        $table = $this->option('table');
        $force = $this->option('force');

        // validasi tanggal (format Y-m-d)
        $d = \DateTime::createFromFormat('Y-m-d', $date);
        if (!($d && $d->format('Y-m-d') === $date)) {
            $this->error("Format tanggal harus Y-m-d, contoh: 2025-08-01");
            return self::FAILURE;
        }

        // konfirmasi jika tidak --force
        if (!$force) {
            $confirm = $this->confirm("Anda akan menghapus data history <= {$date}" . ($table ? " pada tabel _history_{$table}" : " pada seluruh tabel history") . ". Lanjutkan?");
            if (!$confirm) {
                $this->info('Dibatalkan oleh user.');
                return self::SUCCESS;
            }
        }

        $deletedTotal = 0;
        $driver = DB::getPdo()->getAttribute(\PDO::ATTR_DRIVER_NAME);

        // jika table spesifik diberikan
        if ($table) {
            $historyTable = '_history_' . $table;
            if (!Schema::hasTable($historyTable)) {
                $this->error("⚠ Tabel history '{$historyTable}' tidak ditemukan.");
                return self::FAILURE;
            }

            $col = $this->determineDateColumn($historyTable);
            if (!$col) {
                $this->warn("⚠ Lewati Tabel {$historyTable} tidak memiliki kolom recorded_at/created_at/updated_at.");
            } else {
                $deleted = $this->deleteByDate($historyTable, $col, $date);
                $this->info("Terhapus {$deleted} baris dari tabel {$historyTable} (kolom dipakai: {$col})");
                $deletedTotal += $deleted;
            }

            $this->info("Total terhapus: {$deletedTotal} baris.");
            return self::SUCCESS;
        }

        // tidak ada table spesifik -> scan semua tabel DB (MySQL, SQLite, PostgreSQL)
        $tables = [];

        if (in_array($driver, ['mysql', 'mysqli'])) {
            $rows = DB::select('SHOW TABLES');
            if (count($rows) === 0) {
                $this->warn('Tidak ada tabel ditemukan.');
            } else {
                // ambil nama kolom hasil SHOW TABLES (bisa "Tables_in_databasename")
                $firstRow = (array)$rows[0];
                $key = array_keys($firstRow)[0];
                foreach ($rows as $r) {
                    $arr = (array)$r;
                    $tables[] = $arr[$key];
                }
            }
        } elseif ($driver === 'sqlite') {
            $rows = DB::select("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'");
            foreach ($rows as $r) {
                $tables[] = $r->name;
            }
        } elseif ($driver === 'pgsql') {
            $rows = DB::select("SELECT tablename FROM pg_tables WHERE schemaname = 'public'");
            foreach ($rows as $r) {
                $tables[] = $r->tablename;
            }
        } else {
            $this->error("Driver database '{$driver}' tidak dikenali oleh command ini. Silakan sesuaikan query daftar tabel.");
            return self::FAILURE;
        }

        if (empty($tables)) {
            $this->info('Tidak ada tabel untuk diproses.');
            return self::SUCCESS;
        }

        foreach ($tables as $tbl) {
            if (str_starts_with($tbl, '_history_')) {
                // pastikan kolom date ada (recorded_at atau fallback)
                $col = $this->determineDateColumn($tbl);
                if (!$col) {
                    $this->line("⚠ Lewati {$tbl} — tidak ada kolom recorded_at/created_at/updated_at.");
                    continue;
                }

                $deleted = $this->deleteByDate($tbl, $col, $date);
                $this->line("Tabel {$tbl}: {$deleted} baris terhapus (kolom dipakai: {$col}).");
                $deletedTotal += $deleted;
            }
        }

        $this->info("Total terhapus: {$deletedTotal} baris.");
        return self::SUCCESS;
    }

    /**
     * Pilih kolom tanggal untuk filter: prefer recorded_at, lalu created_at, lalu updated_at.
     * Return column name string or null jika tidak ada.
     */
    protected function determineDateColumn(string $table): ?string
    {
        if (Schema::hasColumn($table, 'recorded_at')) {
            return 'recorded_at';
        }
        if (Schema::hasColumn($table, 'created_at')) {
            return 'created_at';
        }
        if (Schema::hasColumn($table, 'updated_at')) {
            return 'updated_at';
        }
        return null;
    }

    /**
     * Hapus baris di $table dengan $dateColumn <= $date (Y-m-d).
     * Menggunakan whereDate agar perbandingan oleh tanggal, bukan by datetime.
     */
    protected function deleteByDate(string $table, string $dateColumn, string $date): int
    {
        // Gunakan transaksi untuk safety (opsional)
        return DB::transaction(function () use ($table, $dateColumn, $date) {
            // Jika table sangat besar, delete langsung bisa berat — tetap lakukan delete sederhana.
            // Jika ingin batching, bisa diubah ke chunking.
            return DB::table($table)
                ->where($dateColumn, '<=', "$date 23:59:59")
                ->delete();
        });
    }
}
