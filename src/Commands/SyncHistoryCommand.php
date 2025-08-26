<?php

namespace LaraPack\ModelHistory\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SyncHistoryCommand extends Command
{
    protected $signature = 'lara-pack:sync-history {--model=}';

    protected $description = 'Sync all models with their history tables (create or alter if needed)';

    const HISTORY_COLUMNS = [
        "recorded_url" => "text('recorded_url')->nullable()",
        "recorded_at" => "timestamp('recorded_at')->nullable()",
        "recorded_action" => "string('recorded_action')->nullable()",
        "recorded_by" => "unsignedBigInteger('recorded_by')->nullable()",
    ];

    public function handle()
    {
        $optionModel = $this->option('model');

        $models = $this->getModels();
        $countModels = count($models);
        foreach ($models as $index => $modelClass) {
            $table = (new $modelClass)->getTable();
            $historyTable = '_history_' . $table;

            if ($optionModel && !Str::endsWith($modelClass, "\\$optionModel")) {
                continue;
            }

            if ($optionModel) {
                $this->info("🔍 Checking {$table}...");
            } else {
                $this->info("[" . ($index + 1) . "/{$countModels}] 🔍 Checking {$table}...");
            }

            if (!Schema::hasTable($historyTable)) {
                $this->generateCreateMigration($table, $historyTable);
            } else {
                $this->generateAlterMigration($table, $historyTable);
            }
        }

        return Command::SUCCESS;
    }

    private function generateCreateMigration($table, $historyTable)
    {
        $columns = DB::select("SHOW FULL COLUMNS FROM {$table}");
        $fields = [];
        foreach ($columns as $column) {
            // Skip jika terdapat pada history columns, karena akan ditambahkan msecara khusus
            if (array_key_exists($column->Field, self::HISTORY_COLUMNS)) {
                continue;
            }

            $definition = "\$table->{$this->mapTypeFromDB($column->Type,$column->Field)}->nullable()";
            if ($column->Key === "PRI") {
                $definition .= "->index()";
            }
            $fields[] = $definition . ";";
        }

        // tambahkan kolom khusus history
        foreach (self::HISTORY_COLUMNS as $def) {
            $fields[] = "\$table->{$def};";
        }

        $migration = $this->migrationStub('create', $historyTable, implode("\n                ", $fields));
        $this->saveMigration($migration, "create{$historyTable}_table");
        $this->info("✅ Created history table for {$table}");
    }

    private function generateAlterMigration($table, $historyTable)
    {
        $original = collect(DB::select("SHOW FULL COLUMNS FROM {$table}"))->pluck('Type', 'Field')->toArray();
        $history = collect(DB::select("SHOW FULL COLUMNS FROM {$historyTable}"))->pluck('Type', 'Field')->toArray();

        $additions = array_diff_key($original, $history);
        $removals = array_diff_key($history, $original);

        $changes = [];

        foreach ($additions as $col => $type) {
            $changes[] = "\$table->{$this->mapTypeFromDB($type,$col)}->nullable();";
        }
        foreach ($removals as $col => $type) {
            if (!array_key_exists($col, self::HISTORY_COLUMNS)) { // jangan hapus kolom khusus history
                $changes[] = "\$table->dropColumn('{$col}');";
            }
        }

        // Pastikan kolom history selalu ada
        foreach (self::HISTORY_COLUMNS as $col => $def) {
            if (!Schema::hasColumn($historyTable, $col)) {
                $changes[] = "\$table->{$def};";
            }
        }

        if (count($changes) > 0) {
            $migration = $this->migrationStub('table', $historyTable, implode("\n                ", $changes));
            $this->saveMigration($migration, "alter{$historyTable}_table");
            $this->info("⚡ Alter migration created for {$historyTable}");
        } else {
            $this->line("ℹ No changes for {$historyTable}");
        }
    }

    private function getModels()
    {
        $models = [];
        $files = File::allFiles(app_path('Models'));

        foreach ($files as $file) {
            $namespace = "App\\Models\\";
            $class = $namespace . str_replace(
                ['/', '.php'],
                ['\\', ''],
                $file->getRelativePathname()
            );

            if (class_exists($class) && is_subclass_of($class, \Illuminate\Database\Eloquent\Model::class)) {
                $models[] = $class;
            }
        }
        return $models;
    }

    private function mapTypeFromDB($type, $field)
    {
        if (Str::contains($type, 'tinyint')) {
            return "boolean('{$field}')";
        } elseif (Str::contains($type, 'bigint unsigned')) {
            return "unsignedBigInteger('{$field}')";
        } elseif (Str::contains($type, 'bigint')) {
            return "bigInteger('{$field}')";
        } elseif (Str::contains($type, 'double')) {
            return "double('{$field}')";
        } elseif (Str::contains($type, 'decimal')) {
            if (preg_match('/decimal\((\d+),(\d+)\)/', $type, $matches)) {
                $precision = $matches[1];
                $scale = $matches[2];
                return "decimal('{$field}', {$precision}, {$scale})";
            }
            return "decimal('{$field}')";
        } elseif (Str::contains($type, 'enum')) {
            if (preg_match('/enum\((.+?)\)/', $type, $matches)) {
                $values = $matches[1];
                return "enum('{$field}', [{$values}])";
            }
            return "string('{$field}')";
        } elseif (Str::contains($type, 'int')) {
            return "integer('{$field}')";
        } elseif (Str::contains($type, 'varchar')) {
            return "string('{$field}')";
        } elseif (Str::contains($type, 'text')) {
            return "text('{$field}')";
        } elseif (Str::contains($type, 'timestamp')) {
            return "timestamp('{$field}')";
        } elseif (Str::contains($type, 'datetime')) {
            return "dateTime('{$field}')";
        } elseif (Str::contains($type, 'date')) {
            return "date('{$field}')";
        } elseif (Str::contains($type, 'boolean')) {
            return "boolean('{$field}')";
        } else {
            return "string('{$field}')";
        }
    }

    private function migrationStub($action, $table, $fields)
    {
        return <<<PHP
            <?php

            use Illuminate\\Database\\Migrations\\Migration;
            use Illuminate\\Database\\Schema\\Blueprint;
            use Illuminate\\Support\\Facades\\Schema;

            return new class extends Migration {
                public function up(): void
                {
                    Schema::{$action}('{$table}', function (Blueprint \$table) {
                        {$fields}
                    });
                }

                public function down(): void
                {
                    Schema::dropIfExists('{$table}');
                }
            };
        PHP;
    }

    private function saveMigration($migration, $name)
    {
        $folder = database_path('migrations/histories/' . date('Y_m_d'));
        if (!File::isDirectory($folder)) {
            File::makeDirectory($folder, 0755, true);
        }

        $filename = $folder . '/' . date('Y_m_d_His') . "_{$name}.php";
        File::put($filename, $migration);
    }
}
