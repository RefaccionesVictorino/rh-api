<?php

namespace Tests\Feature;

use App\Models\AttendancePunch;
use App\Models\Employee;
use App\Models\TimeClockDevice;
use App\Models\TimeClockUser;
use Database\Seeders\EmployeesTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmployeesTableSeederTest extends TestCase
{
    use RefreshDatabase;

    /** @return list<array{pin: string, name: string}> */
    private function catalog(): array
    {
        return json_decode(file_get_contents(base_path('database/data/time_clock_users.json')), true);
    }

    public function test_seeds_one_employee_per_device_user_and_links_them(): void
    {
        $catalog = $this->catalog();

        $device = TimeClockDevice::create(['serial_number' => 'X']);
        AttendancePunch::create([
            'device_id' => $device->id, 'pin' => $catalog[0]['pin'],
            'punched_at' => '2026-09-14 08:00:00', 'punch_type' => AttendancePunch::TYPE_IN,
        ]);

        $this->seed(EmployeesTableSeeder::class);

        $this->assertSame(count($catalog), Employee::count());
        $this->assertSame(count($catalog), TimeClockUser::whereNotNull('employee_id')->count());

        // Cada usuario del checador apunta a un empleado distinto.
        $this->assertSame(count($catalog), TimeClockUser::distinct()->count('employee_id'));

        // La checada que llegó antes del empleado ahora tiene dueño.
        $punch = AttendancePunch::first();
        $this->assertNotNull($punch->employee_id);
        $this->assertSame($punch->employee_id, TimeClockUser::where('pin', $catalog[0]['pin'])->value('employee_id'));

        // Ningún empleado queda sin nombre y los RFC válidos se respetan.
        $this->assertSame(0, Employee::where('name', '')->count());
        foreach ($catalog as $worker) {
            if (preg_match('/^[A-ZÑ&]{4}\d{6}[A-Z0-9]{3}$/', $worker['pin'])) {
                $this->assertDatabaseHas('employees', ['rfc' => $worker['pin']]);
            }
        }
    }

    public function test_splits_device_names_using_the_rfc(): void
    {
        $this->seed(EmployeesTableSeeder::class);

        $andrea = Employee::where('rfc', 'GARA061007MD5')->first();
        $this->assertSame(['Andrea Lizzeth', 'Garvalena', null], [$andrea->name, $andrea->last_name, $andrea->second_last_name]);
        $this->assertSame('2006-10-07', $andrea->birthdate);

        $carmen = Employee::where('rfc', 'LOVC9005297X7')->first();
        $this->assertSame(['Maria del Carmen', 'Lopez'], [$carmen->name, $carmen->last_name]);

        $omar = Employee::where('rfc', 'OIOM980524EW8')->first();
        $this->assertSame(['Omar', 'Ortiz', 'Garcia'], [$omar->name, $omar->last_name, $omar->second_last_name]);

        // PIN que no es RFC: se genera uno y el nombre queda como viene.
        $sergio = TimeClockUser::where('pin', '6767')->first()->employee;
        $this->assertSame('Sergio', $sergio->name);
        $this->assertMatchesRegularExpression('/^[A-Z]{4}\d{6}[A-Z0-9]{3}$/', $sergio->rfc);
    }

    public function test_running_the_seeder_twice_does_not_duplicate_anyone(): void
    {
        $this->seed(EmployeesTableSeeder::class);
        $this->seed(EmployeesTableSeeder::class);

        $this->assertSame(count($this->catalog()), Employee::count());
        $this->assertSame(count($this->catalog()), TimeClockUser::count());
    }
}
