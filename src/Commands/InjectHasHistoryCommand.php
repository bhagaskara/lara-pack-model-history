<?php

namespace LaraPack\ModelHistory\Commands;

use Illuminate\Console\Command;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class InjectHasHistoryCommand extends Command
{
    protected $signature = 'lara-pack:inject-has-history
        {--path=app/Models : Path folder model} 
        {--remove : Hapus HasHistory dari model}';

    protected $description = 'Inject atau hapus HasHistory trait ke seluruh model';

    const TRAIT_NAMESPACE = 'LaraPack\ModelHistory\Traits\HasHistory';
    const TRAIT_NAME = 'HasHistory';

    public function handle()
    {
        $path = base_path($this->option('path'));

        if (!is_dir($path)) {
            $this->error("Path {$path} tidak ditemukan.");
            return;
        }

        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path));
        $isRemove = $this->option('remove');

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $this->processFile($file->getPathname(), $isRemove);
            }
        }

        $this->info($isRemove
            ? 'Selesai menghapus ' . self::TRAIT_NAME . ' dari semua model.'
            : 'Selesai inject ' . self::TRAIT_NAME . ' ke semua model.');
    }

    protected function processFile($filePath, $isRemove = false)
    {
        $content = file_get_contents($filePath);

        // Hanya proses file yang extend Model
        if (!preg_match('/class\s+\w+\s+extends\s+Model\b/', $content)) {
            return;
        }

        $modified = false;

        if ($isRemove) {
            // Posisi awal body class pertama
            if (!preg_match('/class\s+\w+\s+extends\s+Model\b[^{]*\{/', $content, $m, PREG_OFFSET_CAPTURE)) {
                return;
            }
            $classBodyStart = $m[0][1] + strlen($m[0][0]);

            // 1) Hapus HasHistory dari "use ..." di dalam class (trait use)
            [$content, $changedClassUse] = $this->stripHasHistoryFromTraitUses($content, $classBodyStart);
            $modified = $modified || $changedClassUse;

            // 2) Hapus import di header (sebelum class)
            [$content, $changedImport] = $this->stripImportHasHistory($content, $classBodyStart);
            $modified = $modified || $changedImport;

            // Rapihkan extra blank lines (maks 2 newline berurutan)
            if ($modified) {
                $content = preg_replace("/(\r?\n){3,}/", "\n\n", $content);
            }
        } else {
            // ==== MODE INJECT ====

            // 1) Tambah import di header (tanpa double blank line):
            if (strpos($content, 'use ' . self::TRAIT_NAMESPACE . ';') === false) {
                if (preg_match_all('/^use\s+[^\n;]+;/m', $content, $mm, PREG_OFFSET_CAPTURE)) {
                    $lastUse = end($mm[0]);
                    $pos = $lastUse[1] + strlen($lastUse[0]);
                    $content = substr_replace($content, "\nuse " . self::TRAIT_NAMESPACE . ";", $pos, 0);
                } else {
                    // Letakkan tepat setelah namespace, dengan satu newline saja
                    $content = preg_replace(
                        '/^<\?php\s+namespace\s+[^;]+;/m',
                        "$0\nuse " . self::TRAIT_NAMESPACE . ";",
                        $content
                    );
                }
                $modified = true;
                $this->info("Menambahkan import " . self::TRAIT_NAME . " di $filePath");
            }

            // 2) Tambahkan HasHistory ke trait use di dalam class
            if (preg_match('/class\s+\w+\s+extends\s+Model\b[^{]*\{/', $content, $m, PREG_OFFSET_CAPTURE)) {
                $classBodyStart = $m[0][1] + strlen($m[0][0]);

                // Ada baris 'use ...;' di dalam class?
                if (preg_match('/\G|/A', '')) {
                } // noop; hanya supaya tidak error phpcs

                if (preg_match('/^\s*use\s+([^\;]+);/m', $content, $useMatches, PREG_OFFSET_CAPTURE, $classBodyStart)) {
                    $traitList = $useMatches[1][0];
                    if (stripos($traitList, self::TRAIT_NAME) === false) {
                        $newList = rtrim($traitList) . ', ' . self::TRAIT_NAME;
                        $content = substr_replace($content, $newList, $useMatches[1][1], strlen($traitList));
                        $modified = true;
                        $this->info("Menambahkan " . self::TRAIT_NAME . " ke daftar trait di $filePath");
                    }
                } else {
                    // Belum ada trait di class → buat baris baru
                    $content = substr_replace($content, "\n    use " . self::TRAIT_NAME . ";\n", $classBodyStart, 0);
                    $modified = true;
                    $this->info("Menambahkan use " . self::TRAIT_NAME . " di class $filePath");
                }
            }
        }

        if ($modified) {
            file_put_contents($filePath, $content);
        }
    }

    /**
     * Menghapus HasHistory dari semua "use ...;" di dalam body class.
     * Menangani:
     *  - use HasFactory, HasHistory;
     *  - use HasFactory,
     *        HasHistory,
     *        SoftDeletes;
     *  - use HasHistory;
     *  - use HasHistory { ... }   (di-remove seluruh blok jika HasHistory satu-satunya trait)
     */
    private function stripHasHistoryFromTraitUses(string $content, int $classBodyStart): array
    {
        $changed = false;

        // Cari SEMUA statement "use ...;" (trait use) di dalam body class
        if (preg_match_all('/^\s*use\b[\s\S]*?;/m', $content, $matches, PREG_OFFSET_CAPTURE, $classBodyStart)) {
            // Reverse iterate supaya offset tidak bergeser saat replace
            foreach (array_reverse($matches[0]) as $m) {
                [$useStmt, $start] = $m;

                // Skip kalau tidak menyebut HasHistory
                if (stripos($useStmt, self::TRAIT_NAME) === false) {
                    continue;
                }

                // Pisahkan list trait (sebelum { atau ; )
                $bracePos = strpos($useStmt, '{');
                $listPart = $bracePos !== false ? substr($useStmt, 0, $bracePos) : rtrim($useStmt, ";\r\n");

                // Ambil indentasi
                $indent = '';
                if (preg_match('/^(\s*)use\b/i', $useStmt, $im)) {
                    $indent = $im[1];
                }

                // Hilangkan kata 'use' lalu split berdasarkan koma (multi-line aman)
                $listPart = preg_replace('/^\s*use\b/i', '', $listPart);
                $traits = array_map(function ($t) {
                    // bersihkan backslash di awal & whitespace
                    return ltrim(trim($t), '\\');
                }, preg_split('/,/', $listPart));

                // Filter keluarkan HasHistory (baik pendek atau FQCN)
                $traits = array_values(array_filter($traits, function ($t) {
                    return !in_array($t, [self::TRAIT_NAME, self::TRAIT_NAMESPACE], true);
                }));

                if (empty($traits)) {
                    // Tidak ada trait tersisa → hapus seluruh statement (termasuk blok {..} kalau ada)
                    $replacement = '';
                } else {
                    // Tersisa trait lain → rebuild jadi satu baris rapi
                    $replacement = $indent . 'use ' . implode(', ', $traits) . ';';
                }

                $content = substr_replace($content, $replacement, $start, strlen($useStmt));
                $changed = true;
            }
        }

        return [$content, $changed];
    }

    /**
     * Hapus import header sebelum body class.
     * Tidak menyisakan double blank line.
     */
    private function stripImportHasHistory(string $content, int $classBodyStart): array
    {
        $changed = false;

        $header = substr($content, 0, $classBodyStart);
        if (strpos($header, 'use ' . self::TRAIT_NAMESPACE . ';') !== false) {
            $header = preg_replace('/\r?\n?use\s+App\\\\Traits\\\\HasHistory;/', '', $header, 1, $cnt);
            if ($cnt > 0) {
                $changed = true;
                // Rapikan kosong berlebih di header
                $header = preg_replace("/(\r?\n){3,}/", "\n\n", $header);
            }
            $content = $header . substr($content, $classBodyStart);
        }

        return [$content, $changed];
    }
}
