<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class EmployeePhotoTest extends TestCase
{
    use RefreshDatabase;

    private const LOCATION = 'https://cdn.example.net/empleados/1/abc-foto.jpg';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.file_upload', [
            'url' => 'https://upload.example.net/uploadObject',
            'bucket' => 'bucket-de-prueba',
            'project_key' => 'proyecto-de-prueba',
        ]);
    }

    private function actingAsUserWith(string ...$permissions): User
    {
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $user = User::factory()->create();
        $user->givePermissionTo($permissions);

        Sanctum::actingAs($user);

        return $user;
    }

    public function test_uploads_the_photo_and_stores_the_returned_location(): void
    {
        $this->actingAsUserWith('empleados.editar', 'empleados.ver');

        Http::fake([
            'upload.example.net/*' => Http::response(['location' => self::LOCATION]),
        ]);

        $employee = Employee::factory()->create(['photo_url' => null]);

        $this->postJson("/api/employees/{$employee->id}/photo", [
            'photo' => UploadedFile::fake()->image('retrato.jpg'),
        ])->assertOk();

        // En la columna queda la URL cruda: la firma se calcula al leer.
        $this->assertSame(self::LOCATION, $employee->refresh()->photo_url);
    }

    public function test_the_photo_is_served_signed(): void
    {
        $this->actingAsUserWith('empleados.ver');

        config()->set('services.cloudfront.key_pair_id', 'KEYPAIRDEPRUEBA');
        config()->set('services.cloudfront.private_key_path', $this->fakeKey());

        $employee = Employee::factory()->create(['photo_url' => self::LOCATION]);

        $photo = $this->getJson("/api/employees/{$employee->id}")
            ->assertOk()
            ->json('data.photo_url');

        $this->assertStringStartsWith(self::LOCATION.'?', $photo);
        $this->assertStringContainsString('Key-Pair-Id=KEYPAIRDEPRUEBA', $photo);
        $this->assertStringContainsString('Signature=', $photo);
    }

    public function test_rejects_a_file_that_is_not_an_image(): void
    {
        $this->actingAsUserWith('empleados.editar');

        $employee = Employee::factory()->create();

        $this->postJson("/api/employees/{$employee->id}/photo", [
            'photo' => UploadedFile::fake()->create('contrato.pdf', 100, 'application/pdf'),
        ])->assertStatus(422)->assertJsonValidationErrors('photo');
    }

    public function test_keeps_the_previous_photo_when_the_upload_fails(): void
    {
        $this->actingAsUserWith('empleados.editar');

        Http::fake([
            'upload.example.net/*' => Http::response('', 500),
        ]);

        $employee = Employee::factory()->create(['photo_url' => self::LOCATION]);

        $this->postJson("/api/employees/{$employee->id}/photo", [
            'photo' => UploadedFile::fake()->image('retrato.jpg'),
        ])->assertStatus(502);

        $this->assertSame(self::LOCATION, $employee->refresh()->photo_url);
    }

    public function test_removes_the_photo(): void
    {
        $this->actingAsUserWith('empleados.editar', 'empleados.ver');

        $employee = Employee::factory()->create(['photo_url' => self::LOCATION]);

        $this->deleteJson("/api/employees/{$employee->id}/photo")->assertOk();

        $this->assertNull($employee->refresh()->photo_url);
    }

    public function test_denies_access_without_the_permission(): void
    {
        $this->actingAsUserWith('empleados.ver');

        $employee = Employee::factory()->create();

        $this->postJson("/api/employees/{$employee->id}/photo", [
            'photo' => UploadedFile::fake()->image('retrato.jpg'),
        ])->assertForbidden();
    }

    public function test_the_employee_update_rejects_a_photo_url(): void
    {
        $this->actingAsUserWith('empleados.editar');

        $employee = Employee::factory()->create();

        $this->putJson("/api/employees/{$employee->id}", [
            'photo_url' => 'https://ejemplo.net/otra.jpg',
        ])->assertStatus(422)->assertJsonValidationErrors('photo_url');
    }

    /** Llave RSA de usar y tirar, para no depender del certificado real. */
    private function fakeKey(): string
    {
        $key = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        openssl_pkey_export($key, $pem);

        $path = tempnam(sys_get_temp_dir(), 'pem');
        file_put_contents($path, $pem);

        return $path;
    }
}
