<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class GenerateModel extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'db:generate-models
                            {--table= : Specify a single table to convert (defaults to all tables)}
                            {--schema=public : The database schema to inspect (PostgreSQL only, defaults to public)}
                            {--force : Overwrite models if they already exist}
                            {--no-relations : Skip generating belongsTo relationship methods from foreign keys}
                            {--no-casts : Skip generating the $casts property}';

    /**
     * The console command description.
     */
    protected $description = 'Inspect database tables and reverse-engineer them into clean Eloquent models';

    /**
     * Tables that are excluded from a "generate all" run.
     */
    protected array $excludedTables = ['migrations', 'failed_jobs', 'password_reset_tokens', 'personal_access_tokens'];

    /**
     * Columns that are never added to $fillable.
     */
    protected array $excludedColumns = ['id', 'uuid', 'created_at', 'updated_at', 'deleted_at'];

    /**
     * Maps Doctrine/Schema column types to Eloquent cast types.
     */
    protected array $castMap = [
        'boolean' => 'boolean',
        'integer' => 'integer',
        'bigint' => 'integer',
        'smallint' => 'integer',
        'tinyint' => 'integer',
        'float' => 'float',
        'double' => 'float',
        'decimal' => 'decimal:2',
        'date' => 'date',
        'datetime' => 'datetime',
        'datetimetz' => 'datetime',
        'timestamp' => 'datetime',
        'json' => 'array',
        'jsonb' => 'array',
    ];

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Connecting to database and scanning schema fields...');

        $tables = $this->getDatabaseTables();

        $specificTable = $this->option('table');
        if ($specificTable) {
            if (!in_array($specificTable, $tables)) {
                $this->error("Table '{$specificTable}' does not exist in the database schema.");

                return 1;
            }
            $tables = [$specificTable];
        }

        foreach ($tables as $table) {
            if (!$specificTable && in_array($table, $this->excludedTables)) {
                continue;
            }

            $this->generateModelFromTable($table);
        }

        $this->newLine();
        $this->components->info('Success! Database inspection and structural conversion completed.');

        return 0;
    }

    /**
     * Fetch all table names from the current database connection, filtered by schema.
     *
     * On PostgreSQL (including Supabase), Schema::getTables() returns tables from ALL schemas
     * (auth, storage, realtime, extensions, etc.). We query information_schema directly so we
     * only get tables belonging to the target schema (defaults to "public").
     *
     * On MySQL and SQLite there is only one schema, so we fall back to the standard approach.
     */
    protected function getDatabaseTables(): array
    {
        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            $schema = $this->option('schema') ?: 'public';

            return collect(
                DB::select(
                    "SELECT table_name FROM information_schema.tables
                     WHERE table_schema = ? AND table_type = 'BASE TABLE'
                     ORDER BY table_name",
                    [$schema]
                )
            )
                ->pluck('table_name')
                ->filter()
                ->values()
                ->toArray();
        }

        // MySQL / SQLite — single schema, no filtering needed
        return collect(Schema::getTables())
            ->pluck('name')
            ->filter()
            ->values()
            ->toArray();
    }

    /**
     * Inspect a specific table structure and build its model layout stream.
     */
    protected function generateModelFromTable(string $table)
    {
        $modelName = Str::studly(Str::singular($table));
        $targetFile = app_path("Models/{$modelName}.php");

        if (!$this->option('force') && File::exists($targetFile)) {
            $this->components->warn("Skipping table '{$table}': Model App\\Models\\{$modelName} already exists. (Use --force to overwrite)");

            return;
        }

        $columns = Schema::getColumnListing($table);

        $fillableColumns = array_filter($columns, function ($column) {
            return !in_array($column, $this->excludedColumns);
        });

        $fillableString = $this->buildArrayBlock($fillableColumns);

        $castsString = $this->option('no-casts')
            ? ''
            : $this->buildCastsBlock($table, $fillableColumns);

        // Check if the table uses a UUID primary key instead of an auto-incrementing id
        $primaryKeyOverride = '';
        if (in_array('uuid', $columns) && !in_array('id', $columns)) {
            $primaryKeyOverride = "\n    protected \$primaryKey = 'uuid';\n    public \$incrementing = false;\n    protected \$keyType = 'string';\n";
        }

        $relationsString = $this->option('no-relations')
            ? ''
            : $this->buildRelationsBlock($table);

        $template = "<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class {$modelName} extends Model
{
    /**
     * The table associated with the model data layout.
     */
    protected \$table = '{$table}';
{$primaryKeyOverride}
    /**
     * The attributes that are mass assignable from structural payloads.
     */
    protected \$fillable = [
{$fillableString}
    ];
{$castsString}{$relationsString}}
";

        File::ensureDirectoryExists(app_path('Models'));
        File::put($targetFile, $template);
        $this->components->info("Generated model: App\\Models\\{$modelName} ➜ (Table: '{$table}')");
    }

    /**
     * Render an indented PHP array body, one entry per line.
     */
    protected function buildArrayBlock(array $items, int $indent = 8): string
    {
        $pad = str_repeat(' ', $indent);
        $lines = array_map(fn($item) => "{$pad}'{$item}',", $items);

        return implode("\n", $lines);
    }

    /**
     * Build a $casts property block based on actual column types, when any are castable.
     */
    protected function buildCastsBlock(string $table, array $columns): string
    {
        $casts = [];

        foreach ($columns as $column) {
            try {
                $type = Schema::getColumnType($table, $column);
            } catch (\Throwable $e) {
                continue;
            }

            if (isset($this->castMap[$type])) {
                $casts[] = "        '{$column}' => '{$this->castMap[$type]}',";
            }
        }

        if (empty($casts)) {
            return '';
        }

        $castsLines = implode("\n", $casts);

        return "\n    /**\n     * The attributes that should be cast.\n     *\n     * @return array<string, string>\n     */\n    protected function casts(): array\n    {\n        return [\n{$castsLines}\n        ];\n    }\n";
    }

    /**
     * Build belongsTo() relationship methods from the table's foreign key constraints.
     */
    protected function buildRelationsBlock(string $table): string
    {
        try {
            $foreignKeys = Schema::getForeignKeys($table);
        } catch (\Throwable $e) {
            return '';
        }

        if (empty($foreignKeys)) {
            return '';
        }

        $methods = '';

        foreach ($foreignKeys as $fk) {
            $localColumn = $fk['columns'][0] ?? null;
            $foreignTable = $fk['foreign_table'] ?? null;

            if (!$localColumn || !$foreignTable) {
                continue;
            }

            $relatedModel = Str::studly(Str::singular($foreignTable));
            $methodName = Str::camel(Str::singular(Str::replaceLast('_id', '', $localColumn)));

            $methods .= "\n    /**\n     * Get the {$methodName} that owns this record.\n     */\n    public function {$methodName}()\n    {\n        return \$this->belongsTo(\\App\\Models\\{$relatedModel}::class, '{$localColumn}');\n    }\n";
        }

        return $methods;
    }
}