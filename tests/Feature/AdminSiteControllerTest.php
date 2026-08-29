<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\Admin\AdminSiteController;
use App\Services\SiteLogoService;
use App\View\Composers\GlobalDataComposer;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use PDO;
use ReflectionClass;
use Tests\TestCase;

class AdminSiteControllerTest extends TestCase
{
    private string $databasePath;

    /**
     * @var array<string, string|false>
     */
    private array $originalEnvironment = [];

    public function createApplication()
    {
        $this->databasePath = sys_get_temp_dir().'/nntmux-admin-site-test.sqlite';

        $this->originalEnvironment = [
            'APP_ENV' => getenv('APP_ENV'),
            'DB_CONNECTION' => getenv('DB_CONNECTION'),
            'DB_DATABASE' => getenv('DB_DATABASE'),
        ];

        if (file_exists($this->databasePath)) {
            unlink($this->databasePath);
        }

        $pdo = new PDO('sqlite:'.$this->databasePath);
        $pdo->exec('CREATE TABLE settings (name VARCHAR PRIMARY KEY, value TEXT NULL)');
        $pdo->exec("INSERT INTO settings (name, value) VALUES
            ('categorizeforeign', '0'),
            ('catwebdl', '0'),
            ('title', 'NNTmux Test'),
            ('home_link', '/')");

        $this->setEnvironmentValue('APP_ENV', 'testing');
        $this->setEnvironmentValue('DB_CONNECTION', 'sqlite');
        $this->setEnvironmentValue('DB_DATABASE', $this->databasePath);

        $app = require __DIR__.'/../../bootstrap/app.php';

        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => $this->databasePath,
            'app.key' => 'base64:'.base64_encode(random_bytes(32)),
        ]);

        DB::purge();
        DB::reconnect();
        Cache::flush();

        $this->createSchema();
        $this->seedSettings();
        $this->resetGlobalComposerState();
    }

    protected function tearDown(): void
    {
        if ($this->databasePath !== '' && file_exists($this->databasePath)) {
            unlink($this->databasePath);
        }

        parent::tearDown();

        foreach ($this->originalEnvironment as $key => $value) {
            $this->setEnvironmentValue($key, $value === false ? null : $value);
        }
    }

    public function test_submit_converts_size_units_to_bytes(): void
    {
        $request = Request::create('/admin/site-edit', 'POST', [
            'action' => 'submit',
            'minsizetoformrelease' => '500',
            'minsizetoformrelease_unit' => 'MB',
            'maxsizetoformrelease' => '1.5',
            'maxsizetoformrelease_unit' => 'GB',
            'minsizetopostprocess' => '2',
            'minsizetopostprocess_unit' => 'MB',
            'maxsizetopostprocess' => '50',
            'maxsizetopostprocess_unit' => 'GB',
            'minsizetoprocessnfo' => '0',
            'minsizetoprocessnfo_unit' => 'MB',
            'maxsizetoprocessnfo' => '10',
            'maxsizetoprocessnfo_unit' => 'GB',
        ]);

        $response = app(AdminSiteController::class)->edit($request);

        $this->assertSame('524288000', $this->settingValue('minsizetoformrelease'));
        $this->assertSame('1610612736', $this->settingValue('maxsizetoformrelease'));
        $this->assertSame('2097152', $this->settingValue('minsizetopostprocess'));
        $this->assertSame('53687091200', $this->settingValue('maxsizetopostprocess'));
        $this->assertSame('0', $this->settingValue('minsizetoprocessnfo'));
        $this->assertSame('10737418240', $this->settingValue('maxsizetoprocessnfo'));
        $this->assertNull(DB::table('settings')->where('name', 'minsizetopostprocess_unit')->value('value'));
        $this->assertTrue($response->isRedirect());
    }

    public function test_submit_defaults_to_mb_when_unit_is_missing(): void
    {
        $request = Request::create('/admin/site-edit', 'POST', [
            'action' => 'submit',
            'minsizetopostprocess' => '3',
        ]);

        app(AdminSiteController::class)->edit($request);

        $this->assertSame('3145728', $this->settingValue('minsizetopostprocess'));
    }

    public function test_view_exposes_size_fields_split_into_value_and_unit(): void
    {
        DB::table('settings')->where('name', 'maxsizetopostprocess')->update(['value' => '53687091200']);
        DB::table('settings')->where('name', 'minsizetopostprocess')->update(['value' => '524288000']);
        DB::table('settings')->where('name', 'minsizetoformrelease')->update(['value' => '1572864']);
        Cache::flush();

        $request = Request::create('/admin/site-edit', 'GET');

        $response = app(AdminSiteController::class)->edit($request);

        $this->assertInstanceOf(View::class, $response);
        $sizeFields = $response->getData()['sizeFields'];

        $this->assertSame(['value' => 50, 'unit' => 'GB'], $sizeFields['maxsizetopostprocess']);
        $this->assertSame(['value' => 500, 'unit' => 'MB'], $sizeFields['minsizetopostprocess']);
        $this->assertSame(['value' => 1.5, 'unit' => 'MB'], $sizeFields['minsizetoformrelease']);
        $this->assertSame(['value' => 0, 'unit' => 'MB'], $sizeFields['maxsizetoformrelease']);
        $this->assertSame(['MB', 'GB'], $response->getData()['sizeUnits']);
    }

    public function test_submit_stores_uploaded_site_logo_and_updates_setting(): void
    {
        Storage::fake('public');
        DB::table('settings')->where('name', 'site_logo')->delete();
        $logo = UploadedFile::fake()->image('logo.png', 256, 256);
        $request = Request::create('/admin/site-edit', 'POST', ['action' => 'submit'], [], ['site_logo' => $logo]);

        app(AdminSiteController::class)->edit($request);

        $logoPath = $this->settingValue('site_logo');

        $this->assertNotNull($logoPath);
        $this->assertStringStartsWith('site-logos/', $logoPath);
        Storage::disk('public')->assertExists($logoPath);
    }

    public function test_site_logo_url_is_root_relative(): void
    {
        $this->assertSame(
            '/storage/site-logos/hanzo.png',
            app(SiteLogoService::class)->url('site-logos/hanzo.png'),
        );
    }

    public function test_submit_replaces_and_removes_managed_site_logos(): void
    {
        Storage::fake('public');
        $oldLogoPath = 'site-logos/old-logo.png';
        Storage::disk('public')->put($oldLogoPath, 'old-logo');
        DB::table('settings')->where('name', 'site_logo')->update(['value' => $oldLogoPath]);
        $replacement = UploadedFile::fake()->image('replacement.png', 256, 256);
        $replaceRequest = Request::create('/admin/site-edit', 'POST', ['action' => 'submit'], [], ['site_logo' => $replacement]);

        app(AdminSiteController::class)->edit($replaceRequest);

        $replacementPath = $this->settingValue('site_logo');
        $this->assertNotNull($replacementPath);
        $this->assertNotSame($oldLogoPath, $replacementPath);
        Storage::disk('public')->assertMissing($oldLogoPath);
        Storage::disk('public')->assertExists($replacementPath);

        $removeRequest = Request::create('/admin/site-edit', 'POST', [
            'action' => 'submit',
            'remove_site_logo' => '1',
        ]);

        app(AdminSiteController::class)->edit($removeRequest);

        $this->assertSame('', $this->settingValue('site_logo'));
        Storage::disk('public')->assertMissing($replacementPath);
    }

    public function test_submit_rejects_unsupported_site_logo_files(): void
    {
        Storage::fake('public');
        $logo = UploadedFile::fake()->create('logo.svg', 10, 'image/svg+xml');
        $request = Request::create('/admin/site-edit', 'POST', ['action' => 'submit'], [], ['site_logo' => $logo]);

        $this->expectException(ValidationException::class);

        app(AdminSiteController::class)->edit($request);
    }

    public function test_submit_rejects_oversized_site_logo_files(): void
    {
        Storage::fake('public');
        $logo = UploadedFile::fake()->image('large-logo.png')->size(2049);
        $request = Request::create('/admin/site-edit', 'POST', ['action' => 'submit'], [], ['site_logo' => $logo]);

        $this->expectException(ValidationException::class);

        app(AdminSiteController::class)->edit($request);
    }

    public function test_submit_does_not_delete_files_outside_the_managed_logo_directory(): void
    {
        Storage::fake('public');
        $unmanagedPath = 'covers/keep.png';
        Storage::disk('public')->put($unmanagedPath, 'keep');
        DB::table('settings')->where('name', 'site_logo')->update(['value' => $unmanagedPath]);
        $replacement = UploadedFile::fake()->image('replacement.png');
        $request = Request::create('/admin/site-edit', 'POST', ['action' => 'submit'], [], ['site_logo' => $replacement]);

        app(AdminSiteController::class)->edit($request);

        Storage::disk('public')->assertExists($unmanagedPath);
    }

    private function settingValue(string $name): ?string
    {
        $value = DB::table('settings')->where('name', $name)->value('value');

        return $value === null ? null : (string) $value;
    }

    private function setEnvironmentValue(string $key, ?string $value): void
    {
        if ($value === null) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);

            return;
        }

        putenv($key.'='.$value);
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }

    private function createSchema(): void
    {
        if (! Schema::hasTable('settings')) {
            Schema::create('settings', function (Blueprint $table): void {
                $table->string('name')->primary();
                $table->text('value')->nullable();
            });
        }
    }

    private function seedSettings(): void
    {
        DB::table('settings')->upsert([
            ['name' => 'title', 'value' => 'NNTmux Test'],
            ['name' => 'home_link', 'value' => '/'],
            ['name' => 'site_logo', 'value' => ''],
            ['name' => 'categorizeforeign', 'value' => '0'],
            ['name' => 'catwebdl', 'value' => '0'],
            ['name' => 'minsizetoformrelease', 'value' => '0'],
            ['name' => 'maxsizetoformrelease', 'value' => '0'],
            ['name' => 'minsizetopostprocess', 'value' => '1048576'],
            ['name' => 'maxsizetopostprocess', 'value' => '107374182400'],
            ['name' => 'minsizetoprocessnfo', 'value' => '1048576'],
            ['name' => 'maxsizetoprocessnfo', 'value' => '107374182400'],
        ], ['name'], ['value']);
    }

    private function resetGlobalComposerState(): void
    {
        $reflection = new ReflectionClass(GlobalDataComposer::class);
        $property = $reflection->getProperty('resolvedData');
        $property->setValue(null, null);
    }
}
