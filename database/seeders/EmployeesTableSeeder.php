<?php

namespace Database\Seeders;

use App\Models\Employee;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Str;

/**
 * Genera 100 empleados de prueba con datos mexicanos coherentes.
 *
 * Solo corre en ambiente local: los datos son ficticios y no deben
 * llegar nunca a staging ni a producción.
 */
class EmployeesTableSeeder extends Seeder
{
    private const TOTAL = 100;

    /** Nombres por género para que RFC, CURP y nombre concuerden. */
    private array $nombres = [
        'masculino' => [
            'Juan', 'José', 'Miguel', 'Luis', 'Carlos', 'Jorge', 'Ricardo', 'Fernando',
            'Alejandro', 'Roberto', 'Eduardo', 'Javier', 'Sergio', 'Raúl', 'Arturo',
            'Héctor', 'Óscar', 'Pedro', 'Manuel', 'Rafael', 'Andrés', 'Emiliano',
            'Santiago', 'Diego', 'Gerardo',
        ],
        'femenino' => [
            'María', 'Guadalupe', 'Ana', 'Laura', 'Patricia', 'Claudia', 'Verónica',
            'Alejandra', 'Gabriela', 'Mónica', 'Leticia', 'Rosa', 'Adriana', 'Carmen',
            'Silvia', 'Elena', 'Fernanda', 'Daniela', 'Mariana', 'Paulina',
            'Regina', 'Ximena', 'Andrea', 'Lucía', 'Valeria',
        ],
        'no_binario' => [
            'Alex', 'Ariel', 'Guadalupe', 'Cruz', 'René', 'Noa', 'Andrea', 'Remi',
        ],
    ];

    private array $apellidos = [
        'Hernández', 'García', 'Martínez', 'López', 'González', 'Pérez', 'Rodríguez',
        'Sánchez', 'Ramírez', 'Flores', 'Gómez', 'Díaz', 'Cruz', 'Morales', 'Reyes',
        'Gutiérrez', 'Ortiz', 'Chávez', 'Ramos', 'Vázquez', 'Castillo', 'Jiménez',
        'Mendoza', 'Romero', 'Álvarez', 'Torres', 'Domínguez', 'Vargas', 'Aguilar',
        'Guerrero', 'Rojas', 'Medina', 'Herrera', 'Luna', 'Salazar',
    ];

    private array $estadosCiviles = ['Soltero(a)', 'Casado(a)', 'Divorciado(a)', 'Unión libre', 'Viudo(a)'];

    /** Municipio => [entidad CURP, códigos postales plausibles]. */
    private array $municipios = [
        'Guadalajara' => ['JC', ['44100', '44160', '44200', '44600']],
        'Zapopan' => ['JC', ['45010', '45050', '45130', '45200']],
        'Tlaquepaque' => ['JC', ['45500', '45560', '45590']],
        'Tonalá' => ['JC', ['45400', '45420']],
        'Tlajomulco de Zúñiga' => ['JC', ['45640', '45645']],
        'Monterrey' => ['NL', ['64000', '64060', '64720']],
        'Puebla' => ['PL', ['72000', '72160', '72410']],
        'León' => ['GT', ['37000', '37200', '37500']],
        'Querétaro' => ['QT', ['76000', '76090', '76160']],
        'Benito Juárez' => ['DF', ['03100', '03200', '03810']],
    ];

    private array $calles = [
        'Av. Vallarta', 'Av. Chapultepec', 'Calle Morelos', 'Av. Hidalgo', 'Calle Juárez',
        'Av. López Mateos', 'Calle Independencia', 'Av. Patria', 'Calle Reforma',
        'Av. Américas', 'Calle Zaragoza', 'Av. Revolución', 'Calle 5 de Mayo',
    ];

    private array $colonias = [
        'Centro', 'Americana', 'Providencia', 'Del Valle', 'Jardines del Bosque',
        'Moderna', 'Chapalita', 'Santa Teresita', 'Lomas del Country', 'Las Águilas',
    ];

    public function run(): void
    {
        if (! App::environment('local')) {
            $this->command->warn('EmployeesTableSeeder solo corre en ambiente local. Omitido.');

            return;
        }

        $usados = ['rfc' => [], 'curp' => [], 'nss' => [], 'email' => []];
        $empleados = [];

        for ($i = 0; $i < self::TOTAL; $i++) {
            $genero = $this->generoPonderado();
            $nombre = $this->nombres[$genero][array_rand($this->nombres[$genero])];
            $apellido = $this->apellidos[array_rand($this->apellidos)];
            $segundoApellido = random_int(1, 100) <= 90
                ? $this->apellidos[array_rand($this->apellidos)]
                : null;

            $nacimiento = $this->fechaNacimiento();
            $municipio = array_rand($this->municipios);
            [$entidad, $codigosPostales] = $this->municipios[$municipio];

            $empleados[] = [
                'name' => $nombre,
                'last_name' => $apellido,
                'second_last_name' => $segundoApellido,
                'gender' => $genero,
                'rfc' => $this->unico($usados['rfc'], fn () => $this->rfc($nombre, $apellido, $segundoApellido, $nacimiento)),
                'curp' => $this->unico($usados['curp'], fn () => $this->curp($nombre, $apellido, $segundoApellido, $nacimiento, $genero, $entidad)),
                'nss' => $this->unico($usados['nss'], fn () => $this->nss()),
                'birth_country' => 'México',
                'marital_status' => $this->estadosCiviles[array_rand($this->estadosCiviles)],
                'birthdate' => $nacimiento->format('Y-m-d'),
                'work_phone' => $this->telefono(),
                'personal_phone' => $this->telefono(),
                'personal_email' => $this->unico($usados['email'], fn () => $this->email($nombre, $apellido)),
                'address' => sprintf(
                    '%s #%d, Col. %s',
                    $this->calles[array_rand($this->calles)],
                    random_int(100, 4999),
                    $this->colonias[array_rand($this->colonias)]
                ),
                'municipality' => $municipio,
                'postal_code' => $codigosPostales[array_rand($codigosPostales)],
                'hire_date' => $this->fechaIngreso($nacimiento)->format('Y-m-d'),
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        foreach (array_chunk($empleados, 50) as $lote) {
            Employee::insert($lote);
        }

        $this->command->info(self::TOTAL.' empleados de prueba generados.');
    }

    /** Género variado, con una minoría no binaria realista. */
    private function generoPonderado(): string
    {
        $tirada = random_int(1, 100);

        return match (true) {
            $tirada <= 48 => 'masculino',
            $tirada <= 96 => 'femenino',
            default => 'no_binario',
        };
    }

    /** Entre 18 y 64 años cumplidos. */
    private function fechaNacimiento(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('@'.random_int(
            strtotime('-64 years'),
            strtotime('-18 years')
        ));
    }

    /** Contratación posterior a los 18 años y dentro de los últimos 15. */
    private function fechaIngreso(\DateTimeImmutable $nacimiento): \DateTimeImmutable
    {
        $minimo = max(
            $nacimiento->modify('+18 years')->getTimestamp(),
            strtotime('-15 years')
        );

        return new \DateTimeImmutable('@'.random_int($minimo, time()));
    }

    /**
     * RFC de persona física: 4 letras + AAMMDD + homoclave de 3.
     */
    private function rfc(string $nombre, string $apellido, ?string $segundo, \DateTimeImmutable $nacimiento): string
    {
        $a = $this->normalizar($apellido);
        $b = $this->normalizar($segundo ?? 'X');
        $n = $this->normalizar($nombre);

        $iniciales = substr($a, 0, 1)
            .($this->primeraVocalInterna($a) ?: 'X')
            .substr($b, 0, 1)
            .substr($n, 0, 1);

        $homoclave = $this->cadenaAleatoria('ABCDEFGHIJKLMNPQRSTUVWXYZ0123456789', 2)
            .$this->cadenaAleatoria('0123456789ABCDEFGHIJKLMNPQRSTUVWXYZ', 1);

        return $iniciales.$nacimiento->format('ymd').$homoclave;
    }

    /**
     * CURP de 18 posiciones: 4 letras + AAMMDD + sexo + entidad
     * + 3 consonantes internas + diferenciador + dígito.
     */
    private function curp(
        string $nombre,
        string $apellido,
        ?string $segundo,
        \DateTimeImmutable $nacimiento,
        string $genero,
        string $entidad
    ): string {
        $a = $this->normalizar($apellido);
        $b = $this->normalizar($segundo ?? 'X');
        $n = $this->normalizar($nombre);

        // El acta de nacimiento solo admite H o M; para no binario se alterna.
        $sexo = match ($genero) {
            'masculino' => 'H',
            'femenino' => 'M',
            default => random_int(0, 1) ? 'H' : 'M',
        };

        $curp = substr($a, 0, 1)
            .($this->primeraVocalInterna($a) ?: 'X')
            .substr($b, 0, 1)
            .substr($n, 0, 1)
            .$nacimiento->format('ymd')
            .$sexo
            .$entidad
            .($this->primeraConsonanteInterna($a) ?: 'X')
            .($this->primeraConsonanteInterna($b) ?: 'X')
            .($this->primeraConsonanteInterna($n) ?: 'X');

        // Homoclave: dígito para nacidos antes de 2000, letra a partir de 2000.
        $curp .= (int) $nacimiento->format('Y') < 2000
            ? (string) random_int(0, 9)
            : chr(random_int(65, 90));

        return $curp.random_int(0, 9);
    }

    /**
     * NSS del IMSS: 11 dígitos.
     * 2 subdelegación + 2 año de alta + 2 año de nacimiento + 5 folio.
     */
    private function nss(): string
    {
        return sprintf(
            '%02d%02d%02d%05d',
            random_int(1, 99),
            random_int(0, 99),
            random_int(0, 99),
            random_int(0, 99999)
        );
    }

    private function telefono(): string
    {
        return sprintf('33%08d', random_int(0, 99999999));
    }

    private function email(string $nombre, string $apellido): string
    {
        $dominios = ['example.com', 'example.net', 'correo.example', 'mail.example'];

        return sprintf(
            '%s.%s%d@%s',
            Str::lower(Str::ascii($nombre)),
            Str::lower(Str::ascii($apellido)),
            random_int(1, 9999),
            $dominios[array_rand($dominios)]
        );
    }

    /**
     * Reintenta hasta obtener un valor no usado antes; evita colisiones
     * en las columnas que en la vida real son irrepetibles.
     */
    private function unico(array &$usados, callable $generador): string
    {
        do {
            $valor = $generador();
        } while (isset($usados[$valor]));

        $usados[$valor] = true;

        return $valor;
    }

    private function normalizar(string $texto): string
    {
        return Str::upper(Str::ascii($texto));
    }

    private function primeraVocalInterna(string $texto): string
    {
        preg_match('/(?<=.)[AEIOU]/', $texto, $coincidencia);

        return $coincidencia[0] ?? '';
    }

    private function primeraConsonanteInterna(string $texto): string
    {
        preg_match('/(?<=.)[BCDFGHJKLMNPQRSTVWXYZ]/', $texto, $coincidencia);

        return $coincidencia[0] ?? '';
    }

    private function cadenaAleatoria(string $alfabeto, int $longitud): string
    {
        $salida = '';
        for ($i = 0; $i < $longitud; $i++) {
            $salida .= $alfabeto[random_int(0, strlen($alfabeto) - 1)];
        }

        return $salida;
    }
}
