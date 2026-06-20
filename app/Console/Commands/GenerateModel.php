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
                            {--force : Overwrite models if they already exist}';

    /**
     * The console command description.
     */
    protected $description = 'Inspect database tables and reverse-engineer them into clean Eloquent models';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Connecting to database and scanning schema fields...');

        // 1. Get the list of tables based on your database driver
        $driver = DB::getDriverName();
        $tables = $this->getDatabaseTables($driver);

        $specificTable = $this->option('table');
        if ($specificTable) {
            if (!in_array($specificTable, $tables)) {
                $this->error("Table '{$specificTable}' does not exist in the database schema.");

                return 1;
            }
            $tables = [$specificTable];
        }

        // Exclude default internal system migrations/tokens tables unless requested
        $excludedTables = ['migrations', 'failed_jobs', 'password_reset_tokens', 'personal_access_tokens'];

        foreach ($tables as $table) {
            if (!$specificTable && in_array($table, $excludedTables)) {
                continue;
            }

            $this->generateModelFromTable($table);
        }

        $this->newLine();
        $this->components->info('Success! Database inspection and structural conversion completed.');

        return 0;
    }

    /**
     * Fetch all raw table names from the current database connection instance.
     */
    protected function getDatabaseTables(string $driver): array
    {
        if ($driver === 'pgsql') {
            return collect(DB::select("SELECT table_name FROM information_schema.tables WHERE table_schema = 'public'"))
                ->pluck('table_name')
                ->toArray();
        }

        // Fallback standard default mapping for MySQL / SQLite
        return Schema::getTables() ?? [];
    }

    /**
     * Inspect a specific table structure and build its model layout stream.
     */
    protected function generateModelFromTable(string $table)
    {
        $modelName = Str::studly(Str::singular($table));
        $targetFile = app_path("Models/{$modelName}.php");

        if (!$this->option('force') && File::exists($targetFile)) {
            $this->components->warn("Skipping table '{$table}': Model App\Models\\{$modelName} already exists. (Use --force to overwrite)");

            return;
        }

        // 2. Fetch the columns of the table to populate the fillable property array
        $columns = Schema::getColumnListing($table);

        // Exclude auto-managed primary keys or standard tracking stamps from mass assignment templates
        $excludedColumns = ['id', 'uuid', 'created_at', 'updated_at', 'deleted_at'];
        $fillableColumns = array_filter($columns, function ($column) use ($excludedColumns) {
            return !in_array($column, $excludedColumns);
        });

        // 3. Construct the clean fillable array blueprint text layout
        $fillableString = '';
        foreach ($fillableColumns as $col) {
            $fillableString .= "        '{$col}',\n";
        }
        $fillableString = rtrim($fillableString, "\n");

        // 4. Check if the table has standard auto-incrementing id keys or UUID setups
        $primaryKeyOverride = '';
        if (in_array('uuid', $columns) && !in_array('id', $columns)) {
            $primaryKeyOverride = "\n    protected \$primaryKey = 'uuid';\n    public \$incrementing = false;\n    protected \$keyType = 'string';\n";
        }

        // 5. Generate template string
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
}
";

        File::ensureDirectoryExists(app_path('Models'));
        File::put($targetFile, $template);
        $this->components->info("Generated model: App\Models\\{$modelName} ➜ (Table: '{$table}')");
    }
}
