<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

class MakePattern extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'make:pattern 
                            {name : The base name of the files (e.g. User, Taxi)} 
                            {--force : Overwrite existing files}
                            {--only-repo : Create only the Repository class}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create a Service, Repository, Resource, and Controller with UUID support';

    /**
     * The filesystem instance.
     */
    protected Filesystem $files;

    /**
     * Stub for the Controller class.
     */
    protected const CONTROLLER_STUB = <<<'STUB'
    <?php

    namespace App\Http\Controllers;

    use App\Services\DummyService;
    use Illuminate\Http\Request;

    class DummyController extends Controller
    {
        private DummyService $dummyService;

        public function __construct(DummyService $dummyService)
        {
            $this->dummyService = $dummyService;
        }

        public function index(Request $request)
        {
            return $this->dummyService->listDummy($request->input('per_page', 15));
        }

        public function store(Request $request)
        {
            return $this->dummyService->createDummy($request->all());
        }

        public function show(string $uuid)
        {
            return $this->dummyService->getDummy($uuid);
        }

        public function update(Request $request, string $uuid)
        {
            return $this->dummyService->updateDummy($uuid, $request->all());
        }

        public function destroy(string $uuid)
        {
            $this->dummyService->deleteDummy($uuid);
            return response()->json(['message' => 'Deleted successfully'], 200);
        }
        
        public function restore(string $uuid)
        {
            return $this->dummyService->restoreDummy($uuid);
        }
    }
    STUB;

    /**
     * Stub for the service class.
     */
    protected const SERVICE_STUB = <<<'STUB'
    <?php
    
    namespace App\Services;
    
    use App\Repositories\DummyRepository;
    use App\Http\Resources\DummyResource;
    
    class DummyService
    {
        private DummyRepository $dummyRepository;
    
        public function __construct(DummyRepository $dummyRepository) 
        {
            $this->dummyRepository = $dummyRepository;
        }
    
        public function listDummy(int $perPage = 15)
        {
            $collection = $this->dummyRepository->paginate($perPage);
            return DummyResource::collection($collection);
        }
    
        public function createDummy(array $payload)
        {
            $model = $this->dummyRepository->create($payload);
            
        }
    
        public function getDummy(string $uuid)
        {
            $model = $this->dummyRepository->findByUuid($uuid);
            
        }

        public function getDummyByField(string $field, $value)
        {
            $model = $this->dummyRepository->findByField($field, $value);
            
        }
    
        public function updateDummy(string $uuid, array $payload)
        {
            $model = $this->dummyRepository->update($uuid, $payload);
            
        }
    
        public function deleteDummy(string $uuid)
        {
            $this->dummyRepository->delete($uuid);
            return true;
        }

        public function restoreDummy(string $uuid)
        {
            $model = $this->dummyRepository->restore($uuid);
            
        }
    }
    STUB;

    /**
     * Stub for the repository class.
     */
    protected const REPOSITORY_STUB = <<<'STUB'
    <?php
    
    namespace App\Repositories;
    
    use App\Models\Dummy;
    use Illuminate\Database\Eloquent\ModelNotFoundException;
    
    class DummyRepository
    {
        public function paginate(int $perPage = 15)
        {
            return Dummy::latest()->paginate($perPage);
        }
    
        public function create(array $payload)
        {
            return Dummy::create($payload);
        }
    
        public function findByUuid(string $uuid)
        {
            return Dummy::where('uuid', $uuid)->first();
        }

        public function findByField(string $field, $value)
        {
            return Dummy::where($field, $value)->first();
        }
    
        public function update(string $uuid, array $payload)
        {
            $model = $this->findByUuid($uuid);
            $model->update($payload);
            return $model;
        }
    
        public function delete(string $uuid)
        {
            $model = $this->findByUuid($uuid);
            return $model->delete();
        }

        public function restore(string $uuid)
        {
            $model = Dummy::withTrashed()->where('uuid', $uuid)->first();
            $model->restore();
            return $model;
        }
    }
    STUB;

    /**
     * Stub for the resource class.
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
         *
         * @return array<string, mixed>
         */
        public function toArray(Request $request): array
        {
            return parent::toArray($request);
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

        // Logic Flag: Just Repository?
        if ($this->option('only-repo')) {
            $this->info("Creating ONLY Repository for {$baseName}...");
            $this->createRepository($baseName);
            $this->info('Repository created successfully.');

            return 0;
        }

        // Default: Create The Whole Stack
        $this->info("Creating API layer (Controller, Service, Repository, Resource) for {$baseName}...");

        $this->createRepository($baseName);
        $this->createResource($baseName);
        $this->createService($baseName);
        $this->createController($baseName);

        $this->info('API layer created successfully.');

        return 0;
    }

    protected function createController(string $baseName)
    {
        $className = "{$baseName}Controller";
        $serviceClass = "{$baseName}Service";
        $serviceVariable = lcfirst($serviceClass);

        $subPath = 'Http/Controllers';
        $targetDir = app_path($subPath);
        $targetFile = "{$targetDir}/{$className}.php";

        if (!$this->option('force') && $this->files->exists($targetFile)) {
            $this->error("File already exists: {$targetFile}");

            return;
        }

        $this->files->ensureDirectoryExists($targetDir);

        $stub = str_replace(
            ['DummyController', 'DummyService', 'dummyService', 'Dummy'],
            [$className, $serviceClass, $serviceVariable, $baseName],
            static::CONTROLLER_STUB
        );

        $this->files->put($targetFile, $stub);
        $this->info("Created controller: {$targetFile}");
    }

    protected function createService(string $baseName)
    {
        $className = "{$baseName}Service";
        $repoClass = "{$baseName}Repository";
        $resourceClass = "{$baseName}Resource";
        $repoVariable = lcfirst($repoClass);
        $subPath = 'Services';
        $targetDir = app_path($subPath);
        $targetFile = "{$targetDir}/{$className}.php";

        if (!$this->option('force') && $this->files->exists($targetFile)) {
            $this->error("File already exists: {$targetFile}");

            return;
        }

        $this->files->ensureDirectoryExists($targetDir);

        $stub = str_replace(
            ['DummyService', 'DummyRepository', 'DummyResource', 'dummyRepository', 'Dummy'],
            [$className, $repoClass, $resourceClass, $repoVariable, $baseName],
            static::SERVICE_STUB
        );

        $this->files->put($targetFile, $stub);
        $this->info("Created service: {$targetFile}");
    }

    protected function createRepository(string $baseName)
    {
        $className = "{$baseName}Repository";
        $subPath = 'Repositories';
        $targetDir = app_path($subPath);
        $targetFile = "{$targetDir}/{$className}.php";

        if (!$this->option('force') && $this->files->exists($targetFile)) {
            $this->error("File already exists: {$targetFile}");

            return;
        }

        $this->files->ensureDirectoryExists($targetDir);

        $stub = str_replace(
            ['DummyRepository', 'Dummy'],
            [$className, $baseName],
            static::REPOSITORY_STUB
        );

        $this->files->put($targetFile, $stub);
        $this->info("Created repository: {$targetFile}");
    }

    protected function createResource(string $baseName)
    {
        $className = "{$baseName}Resource";
        $subPath = 'Http/Resources';
        $targetDir = app_path($subPath);
        $targetFile = "{$targetDir}/{$className}.php";

        if (!$this->option('force') && $this->files->exists($targetFile)) {
            $this->error("File already exists: {$targetFile}");

            return;
        }

        $this->files->ensureDirectoryExists($targetDir);

        $stub = str_replace(
            ['DummyResource'],
            [$className],
            static::RESOURCE_STUB
        );

        $this->files->put($targetFile, $stub);
        $this->info("Created resource: {$targetFile}");
    }
}
