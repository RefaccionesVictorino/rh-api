<?php

namespace Database\Seeders;

use App\Models\Location;
use Illuminate\Database\Seeder;

class LocationSeeder extends Seeder
{
    /**
     * @var array<string, string>
     */
    private array $sucursales = [
        'Matriz' => 'MAT',
        'Boulevard Durango' => 'BLV',
        'Factor' => 'FAC',
    ];

    public function run(): void
    {
        foreach ($this->sucursales as $nombre => $clave) {
            Location::firstOrCreate(
                ['name' => $nombre],
                ['code' => $clave, 'is_active' => true],
            );
        }

        $this->command->info(sprintf('%d sucursales disponibles.', Location::count()));
    }
}
