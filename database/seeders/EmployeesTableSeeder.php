<?php

namespace Database\Seeders;

use App\Models\AttendancePunch;
use App\Models\Employee;
use App\Models\TimeClockUser;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Str;

/**
 * Siembra la plantilla a partir del catálogo real del checador
 * (database/data/time_clock_users.json): nombre y RFC son los reales; todo lo
 * que el checador no sabe (CURP, NSS, domicilio, teléfonos, fecha de ingreso,
 * foto) se genera con datos mexicanos coherentes.
 *
 * Además liga cada empleado con su usuario del checador y adopta las checadas
 * que hubieran llegado antes de existir el empleado. Es idempotente: quien ya
 * está ligado se omite, así que puede volver a correrse tras exportar de nuevo
 * el catálogo del equipo.
 *
 * Solo corre en ambiente local: los datos complementarios son ficticios y no
 * deben llegar a staging ni a producción.
 */
class EmployeesTableSeeder extends Seeder
{
    /** Catálogo exportado del checador: pin, name, card_number, privilege. */
    private const REAL_WORKERS_FILE = 'database/data/time_clock_users.json';

    /** Empleados ficticios adicionales a la plantilla real. */
    private const EXTRA_FAKE = 0;

    /** Retratos disponibles por carpeta en randomuser.me. */
    private const RETRATOS_POR_CARPETA = 100;

    /** Partículas que se pegan al apellido o nombre que les sigue. */
    private const PARTICULAS = ['DE', 'DEL', 'LA', 'LAS', 'LOS', 'Y'];

    /** Nombres por género para que RFC, CURP y nombre concuerden. */
    private array $nombres = [
        'masculino' => [
            'Juan', 'José', 'Miguel', 'Luis', 'Carlos', 'Jorge', 'Ricardo', 'Fernando',
            'Alejandro', 'Roberto', 'Eduardo', 'Javier', 'Sergio', 'Raúl', 'Arturo',
            'Héctor', 'Óscar', 'Pedro', 'Manuel', 'Rafael', 'Andrés', 'Emiliano', 'Lamine',
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

    /** Valores ya ocupados en columnas que en la vida real son irrepetibles. */
    private array $usados = ['rfc' => [], 'curp' => [], 'nss' => [], 'email' => []];

    /** Retratos ya asignados, por carpeta. */
    private array $retratosUsados = ['men' => [], 'women' => []];

    public function run(): void
    {
        if (! App::environment(['local', 'testing'])) {
            $this->command->warn('EmployeesTableSeeder solo corre en ambiente local. Omitido.');

            return;
        }

        // Lo que ya existe en la base cuenta como ocupado, para no chocar con
        // registros previos al volver a correr el seeder.
        foreach (['rfc', 'curp', 'nss'] as $columna) {
            $this->usados[$columna] = array_fill_keys(Employee::pluck($columna)->all(), true);
        }
        $this->usados['email'] = array_fill_keys(Employee::pluck('personal_email')->all(), true);

        $creados = 0;
        $ligados = 0;
        $adoptadas = 0;

        foreach ($this->plantillaReal() as $trabajador) {
            $yaLigado = TimeClockUser::where('pin', $trabajador['pin'])
                ->whereNotNull('employee_id')
                ->exists();

            if ($yaLigado) {
                continue;
            }

            // Si el RFC ya está en la base (capturado por RH), se liga a ese
            // registro en vez de duplicarlo.
            $empleado = $this->esRfc($trabajador['pin'])
                ? Employee::where('rfc', $trabajador['pin'])->first()
                : null;

            if ($empleado === null) {
                $empleado = Employee::create($this->empleado($trabajador));
                $creados++;
            }

            TimeClockUser::updateOrCreate(
                ['pin' => $trabajador['pin']],
                [
                    'name' => $trabajador['name'],
                    'card_number' => $trabajador['card_number'],
                    'privilege' => $trabajador['privilege'],
                    'employee_id' => $empleado->id,
                ]
            );
            $ligados++;

            // Checadas que llegaron antes de que existiera el empleado.
            $adoptadas += AttendancePunch::whereNull('employee_id')
                ->where('pin', $trabajador['pin'])
                ->update(['employee_id' => $empleado->id]);
        }

        for ($i = 0; $i < self::EXTRA_FAKE; $i++) {
            Employee::create($this->empleado(null));
            $creados++;
        }

        $this->command->info(sprintf(
            '%d empleados creados, %d usuarios de checador ligados, %d checadas adoptadas.',
            $creados,
            $ligados,
            $adoptadas,
        ));
    }

    /**
     * Catálogo real del checador, tal como lo exportó el equipo.
     *
     * @return list<array{pin: string, name: string, card_number: ?string, privilege: int}>
     */
    private function plantillaReal(): array
    {
        $ruta = base_path(self::REAL_WORKERS_FILE);

        if (! is_file($ruta)) {
            $this->command->warn('No existe '.self::REAL_WORKERS_FILE.'; no se sembró plantilla real.');

            return [];
        }

        return json_decode(file_get_contents($ruta), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * Arma un empleado completo. Con $real se respetan nombre, RFC y fecha de
     * nacimiento del checador; sin él, todo es ficticio.
     *
     * @param  array{pin: string, name: string}|null  $real
     * @return array<string, mixed>
     */
    private function empleado(?array $real): array
    {
        $genero = $this->generoPonderado();

        if ($real !== null) {
            [$nombre, $apellido, $segundoApellido] = $this->separarNombre($real['name'], $real['pin']);
            $nacimiento = $this->nacimientoDesdeRfc($real['pin']) ?? $this->fechaNacimiento();
        } else {
            $nombre = $this->nombres[$genero][array_rand($this->nombres[$genero])];
            $apellido = $this->apellidos[array_rand($this->apellidos)];
            $segundoApellido = random_int(1, 100) <= 90
                ? $this->apellidos[array_rand($this->apellidos)]
                : null;
            $nacimiento = $this->fechaNacimiento();
        }

        $municipio = array_rand($this->municipios);
        [$entidad, $codigosPostales] = $this->municipios[$municipio];

        if ($real !== null && $this->esRfc($real['pin'])) {
            $rfc = $real['pin'];
            $this->usados['rfc'][$rfc] = true;
        } else {
            $rfc = $this->unico($this->usados['rfc'], fn () => $this->rfc($nombre, $apellido, $segundoApellido, $nacimiento));
        }

        return [
            'name' => $nombre,
            'last_name' => $apellido,
            'second_last_name' => $segundoApellido,
            'gender' => $genero,
            'rfc' => $rfc,
            'curp' => $this->unico($this->usados['curp'], fn () => $this->curp($nombre, $apellido, $segundoApellido, $nacimiento, $genero, $entidad)),
            'nss' => $this->unico($this->usados['nss'], fn () => $this->nss()),
            'birth_country' => 'México',
            'marital_status' => $this->estadosCiviles[array_rand($this->estadosCiviles)],
            'birthdate' => $nacimiento->format('Y-m-d'),
            'work_phone' => $this->telefono(),
            'personal_phone' => $this->telefono(),
            'personal_email' => $this->unico($this->usados['email'], fn () => $this->email($nombre, $apellido)),
            'address' => sprintf(
                '%s #%d, Col. %s',
                $this->calles[array_rand($this->calles)],
                random_int(100, 4999),
                $this->colonias[array_rand($this->colonias)]
            ),
            'municipality' => $municipio,
            'postal_code' => $codigosPostales[array_rand($codigosPostales)],
            'photo_url' => $this->foto($genero),
            'hire_date' => $this->fechaIngreso($nacimiento)->format('Y-m-d'),
        ];
    }

    /**
     * Separa "NOMBRE(S) APELLIDO PATERNO APELLIDO MATERNO" en sus tres partes.
     *
     * El checador guarda el nombre en una sola cadena, en mayúsculas y recortada
     * a 24 caracteres. Para ubicar el apellido paterno se usa la primera letra
     * del RFC, que por regla es la inicial del paterno; si no se encuentra
     * (nombre truncado, RFC malformado), se asume que los dos últimos tokens
     * son los apellidos.
     *
     * @return array{0: string, 1: string, 2: ?string}
     */
    private function separarNombre(string $completo, string $pin): array
    {
        $tokens = $this->tokensDeNombre($completo);
        $total = count($tokens);

        if ($total === 0) {
            return ['Sin nombre', '', null];
        }

        $paterno = null;

        if ($this->esRfc($pin) && $total >= 2) {
            for ($i = 1; $i < $total; $i++) {
                if (Str::upper(Str::ascii($tokens[$i]))[0] === $pin[0]) {
                    $paterno = $i;
                    break;
                }
            }
        }

        $paterno ??= match (true) {
            $total >= 3 => $total - 2,
            $total === 2 => 1,
            default => null,
        };

        if ($paterno === null) {
            return [$this->titulo($tokens[0]), '', null];
        }

        $materno = implode(' ', array_slice($tokens, $paterno + 1));

        return [
            $this->titulo(implode(' ', array_slice($tokens, 0, $paterno))),
            $this->titulo($tokens[$paterno]),
            $materno === '' ? null : $this->titulo($materno),
        ];
    }

    /**
     * Tokens del nombre con las partículas pegadas a la palabra que les sigue
     * ("DE LA CRUZ" es un solo apellido) y sin residuos de recorte ("...", "-").
     *
     * @return list<string>
     */
    private function tokensDeNombre(string $completo): array
    {
        $crudos = array_values(array_filter(
            array_map(fn (string $t) => trim($t, '.-'), preg_split('/\s+/', trim($completo))),
            fn (string $t) => $t !== ''
        ));

        $tokens = [];
        $total = count($crudos);

        for ($i = 0; $i < $total; $i++) {
            if (! in_array(Str::upper($crudos[$i]), self::PARTICULAS, true)) {
                $tokens[] = $crudos[$i];

                continue;
            }

            $grupo = $crudos[$i];
            while (isset($crudos[$i + 1]) && in_array(Str::upper($crudos[$i + 1]), self::PARTICULAS, true)) {
                $grupo .= ' '.$crudos[++$i];
            }

            // Partícula suelta al final (nombre recortado): se descarta.
            if (isset($crudos[$i + 1])) {
                $tokens[] = $grupo.' '.$crudos[++$i];
            }
        }

        return $tokens;
    }

    /** "MARIA DEL CARMEN" → "María Del Carmen" → "María del Carmen". */
    private function titulo(string $texto): string
    {
        $titulo = mb_convert_case(mb_strtolower($texto, 'UTF-8'), MB_CASE_TITLE, 'UTF-8');

        return preg_replace_callback(
            '/(?<=\s)(De|Del|La|Las|Los|Y)(?=\s)/u',
            fn (array $m) => mb_strtolower($m[1], 'UTF-8'),
            $titulo
        );
    }

    /** RFC de persona física: 4 letras, 6 dígitos de fecha y homoclave de 3. */
    private function esRfc(string $valor): bool
    {
        return (bool) preg_match('/^[A-ZÑ&]{4}\d{6}[A-Z0-9]{3}$/', $valor);
    }

    /**
     * Fecha de nacimiento a partir de los 6 dígitos del RFC (AAMMDD).
     * Nulo si el RFC no es válido o la fecha no existe.
     */
    private function nacimientoDesdeRfc(string $rfc): ?\DateTimeImmutable
    {
        if (! $this->esRfc($rfc)) {
            return null;
        }

        $aa = (int) substr($rfc, 4, 2);
        $mes = (int) substr($rfc, 6, 2);
        $dia = (int) substr($rfc, 8, 2);

        // Dos dígitos de año: los mayores al año actual son del siglo pasado.
        $anio = $aa > (int) date('y') ? 1900 + $aa : 2000 + $aa;

        if (! checkdate($mes, $dia, $anio)) {
            return null;
        }

        return new \DateTimeImmutable(sprintf('%04d-%02d-%02d', $anio, $mes, $dia));
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

        // Menores de 18 en la plantilla real: se toma la fecha más temprana posible.
        return new \DateTimeImmutable('@'.random_int(min($minimo, time()), time()));
    }

    /**
     * RFC de persona física: 4 letras + AAMMDD + homoclave de 3.
     */
    private function rfc(string $nombre, string $apellido, ?string $segundo, \DateTimeImmutable $nacimiento): string
    {
        $a = $this->normalizar($apellido ?: 'X');
        $b = $this->normalizar($segundo ?: 'X');
        $n = $this->normalizar($nombre ?: 'X');

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
        $a = $this->normalizar($apellido ?: 'X');
        $b = $this->normalizar($segundo ?: 'X');
        $n = $this->normalizar($nombre ?: 'X');

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

    /**
     * Retrato de randomuser.me, acorde al género del registro.
     * Cada carpeta tiene 100 imágenes (0-99); se evitan repeticiones
     * mientras el inventario alcance.
     */
    private function foto(string $genero): string
    {
        // randomuser.me solo publica men/women; para no binario se alterna.
        $carpeta = match ($genero) {
            'masculino' => 'men',
            'femenino' => 'women',
            default => random_int(0, 1) ? 'men' : 'women',
        };

        if (count($this->retratosUsados[$carpeta]) >= self::RETRATOS_POR_CARPETA) {
            $this->retratosUsados[$carpeta] = [];
        }

        do {
            $indice = random_int(0, self::RETRATOS_POR_CARPETA - 1);
        } while (isset($this->retratosUsados[$carpeta][$indice]));

        $this->retratosUsados[$carpeta][$indice] = true;

        return sprintf('https://randomuser.me/api/portraits/%s/%d.jpg', $carpeta, $indice);
    }

    private function telefono(): string
    {
        return sprintf('33%08d', random_int(0, 99999999));
    }

    private function email(string $nombre, string $apellido): string
    {
        $dominios = ['example.com', 'example.net', 'correo.example', 'mail.example'];

        $usuario = Str::slug(Str::ascii($nombre), '.');
        $usuario .= $apellido !== '' ? '.'.Str::slug(Str::ascii($apellido), '.') : '';

        return sprintf('%s%d@%s', $usuario, random_int(1, 9999), $dominios[array_rand($dominios)]);
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
