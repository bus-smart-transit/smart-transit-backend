<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

class MakePattern extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'make:pattern 
                            {name : The base name of the files (e.g. Ticket, Route)} 
                            {--force : Overwrite existing layer files if they already exist}
                            {--only-repo : Only generate the clean data Repository layer}';

    /**
     * The console command description.
     */
    protected $description = 'Create a Service, Repository, Resource, and Controller stack with integrated UUID support';

    /**
     * The filesystem instance.
     */
    protected Filesystem $files;

    /**
     * Stub layout configuration for the Controller class.
     */
    protected const CONTROLLER_STUB = <<<'STUB'
<?php

namespace App\Http\Controllers;

use App\Services\DummyService;
use App\Http\Resources\DummyResource;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Exception;

class DummyController extends Controller
{
    private DummyService $dummyService;

    public function __construct(DummyService $dummyService)
    {
        $this->dummyService = $dummyService;
    }

    public function index(): JsonResponse
    {
        $records = $this->dummyService->getAll();
        return response()->json(DummyResource::collection(collect($records)));
    }

    public function show(string $uuid): JsonResponse
    {
        try {
            $record = $this->dummyService->getById($uuid);
            return response()->json(new DummyResource($record));
        } catch (Exception $e) {
            return response()->json(['error' => $e->getMessage()], 404);
        }
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            // Setup explicit manual layout validation properties here
        ]);

        $result = $this->dummyService->create($request->all());
        return response()->json(new DummyResource($result), 201);
    }

    public function update(Request $request, string $uuid): JsonResponse
    {
        $validated = $request->validate([
            // Setup explicit manual layout validation properties here
        ]);

        try {
            $result = $this->dummyService->update($uuid, $request->all());
            return response()->json(new DummyResource($result));
        } catch (Exception $e) {
            return response()->json(['error' => $e->getMessage()], 404);
        }
    }

    public function destroy(string $uuid): JsonResponse
    {
        try {
            $this->dummyService->delete($uuid);
            return response()->json(['message' => 'Record safely purged successfully']);
        } catch (Exception $e) {
            return response()->json(['error' => $e->getMessage()], 404);
        }
    }
}
STUB;

    /**
     * Stub layout configuration for the Service class.
     */
    protected const SERVICE_STUB = <<<'STUB'
<?php

namespace App\Services;

use App\Repositories\DummyRepository;
use Exception;

class DummyService
{
    private DummyRepository $dummyRepository;

    public function __construct(DummyRepository $dummyRepository) 
    {
        $this->dummyRepository = $dummyRepository;
    }

    public function getAll(): array
    {
        return $this->dummyRepository->all();
    }

    public function getById(string $uuid): array
    {
        $record = $this->dummyRepository->find($uuid);
        if (!$record) {
            throw new Exception("Record with identifier {$uuid} not found.");
        }
        return $record;
    }

    public function create(array $data): array
    {
        return $this->dummyRepository->create($data);
    }

    public function update(string $uuid, array $data): array
    {
        $this->getById($uuid); // Validate lifecycle integrity
        return $this->dummyRepository->update($uuid, $data);
    }

    public function delete(string $uuid): bool
    {
        $this->getById($uuid); // Validate lifecycle integrity
        return $this->dummyRepository->delete($uuid);
    }
}
STUB;

    /**
     * Stub layout configuration for the Repository class.
     */
    protected const REPOSITORY_STUB = <<<'STUB'
<?php

namespace App\Repositories;

use Illuminate\Support\Str;

class DummyRepository
{
    public function __construct()
    {
        // Setup your manual database connection context here
    }

    public function all(): array
    {
        // Manual Fetch Logic
        return [];
    }

    public function find(string $uuid): ?array
    {
        // Manual Single Fetch Logic matching UUID string
        return null;
    }

    public function create(array $data): array
    {
        // Automatically assign a secure UUIDv4 to the incoming dataset
        $data['id'] = (string) Str::uuid();
        
        // Manual creation/database persistence logic here
        return $data;
    }

    public function update(string $uuid, array $data): array
    {
        // Manual update logic matching UUID string
        return $data;
    }

    public function delete(string $uuid): bool
    {
        // Manual execution logic matching UUID string
        return true;
    }
}
STUB;

    /**
     * Stub layout configuration for the API JSON Resource.
     */
    protected const RESOURCE_STUB = <<<'STUB'
<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DummyResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this['id'] ?? null,
            // Maps structural keys from manual array results into clean response blocks
            'created_at' => $this['created_at'] ?? now()->toIso8601String(),
        ];
    }
}
STUB;

    public function __construct()
    {
        parent::__construct();
        $this->files = new Filesystem;
    }

    public function handle()
    {
        $baseName = Str::studly($this->argument('name'));

        // Handle isolated repository execution
        if ($this->option('only-repo')) {
            $this->components->info("Creating ONLY Repository for {$baseName}...");
            $this->createRepository($baseName);
            return 0;
        }

        // Handle full structural orchestration stack
        $this->components->info("Creating End-to-End API Layer for {$baseName}...");

        $this->createRepository($baseName);
        $this->createResource($baseName);
        $this->createService($baseName);
        $this->createController($baseName);

        $this->newLine();
        $this->components->info("Success! Extended layers generated for {$baseName}.");
        $this->line("👉 Remember to append your resource route to routes/api.php:\n   Route::apiResource('" . Str::snake(Str::plural($baseName)) . "', \\App\\Http\\Controllers\\{$baseName}Controller::class);");

        return 0;
    }

    protected function createController(string $baseName)
    {
        $className = "{$baseName}Controller";
        $serviceClass = "{$baseName}Service";
        $resourceClass = "{$baseName}Resource";
        $serviceVariable = lcfirst($serviceClass);

        $targetFile = app_path("Http/Controllers/{$className}.php");

        if (!$this->option('force') && $this->files->exists($targetFile)) {
            $this->components->error("File already exists: {$className}.php (Pass --force to overwrite)");
            return;
        }

        $stub = str_replace(
            ['DummyController', 'DummyService', 'dummyService', 'DummyResource', 'Dummy'],
            [$className, $serviceClass, $serviceVariable, $resourceClass, $baseName],
            static::CONTROLLER_STUB
        );

        $this->files->put($targetFile, $stub);
        $this->components->info("Created controller: App\Http\Controllers\\{$className}");
    }

    protected function createService(string $baseName)
    {
        $className = "{$baseName}Service";
        $repoClass = "{$baseName}Repository";
        $repoVariable = lcfirst($repoClass);

        $targetFile = app_path("Services/{$className}.php");

        if (!$this->option('force') && $this->files->exists($targetFile)) {
            $this->components->error("File already exists: {$className}.php (Pass --force to overwrite)");
            return;
        }

        $this->files->ensureDirectoryExists(app_path('Services'));

        $stub = str_replace(
            ['DummyService', 'DummyRepository', 'dummyRepository', 'Dummy'],
            [$className, $repoClass, $repoVariable, $baseName],
            static::SERVICE_STUB
        );

        $this->files->put($targetFile, $stub);
        $this->components->info("Created service: App\Services\\{$className}");
    }

    protected function createRepository(string $baseName)
    {
        $className = "{$baseName}Repository";
        $targetFile = app_path("Repositories/{$className}.php");

        if (!$this->option('force') && $this->files->exists($targetFile)) {
            $this->components->error("File already exists: {$className}.php (Pass --force to overwrite)");
            return;
        }

        $this->files->ensureDirectoryExists(app_path('Repositories'));

        $stub = str_replace(
            ['DummyRepository', 'Dummy'],
            [$className, $baseName],
            static::REPOSITORY_STUB
        );

        $this->files->put($targetFile, $stub);
        $this->components->info("Created repository: App\Repositories\\{$className}");
    }

    protected function createResource(string $baseName)
    {
        $className = "{$baseName}Resource";
        $targetFile = app_path("Http/Resources/{$className}.php");

        if (!$this->option('force') && $this->files->exists($targetFile)) {
            $this->components->error("File already exists: {$className}.php (Pass --force to overwrite)");
            return;
        }

        $this->files->ensureDirectoryExists(app_path('Http/Resources'));

        $stub = str_replace(
            ['DummyResource', 'Dummy'],
            [$className, $baseName],
            static::RESOURCE_STUB
        );

        $this->files->put($targetFile, $stub);
        $this->components->info("Created resource: App\Http\Resources\\{$className}");
    }
}