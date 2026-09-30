<?php

namespace App\Http\Controllers;

use App\Http\Requests\IndexUserRequest;
use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Http\Resources\EmployeeSummaryResource;
use App\Http\Resources\UserResource;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class UserController extends Controller
{
    private const RELATIONS = ['roles', 'employee.subDepartment.department'];

    /** Tope del buscador de trabajadores del formulario. */
    private const LINKABLE_LIMIT = 20;

    public function index(IndexUserRequest $request): AnonymousResourceCollection
    {
        $users = User::query()
            ->search($request->search())
            ->when($request->role(), fn (Builder $query, string $role) => $query->role($role))
            ->with(self::RELATIONS)
            ->orderBy($request->sortBy(), $request->sortDir())
            // Desempate estable entre páginas.
            ->orderBy('id')
            ->paginate($request->perPage())
            ->withQueryString();

        return UserResource::collection($users);
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        $user = DB::transaction(function () use ($request): User {
            $user = User::create($request->safe()->only(['name', 'email', 'password', 'employee_id']));
            $user->syncRoles([$request->validated('role')]);

            return $user;
        });

        return UserResource::make($user->load(self::RELATIONS))
            ->response()
            ->setStatusCode(201);
    }

    public function show(User $user): UserResource
    {
        return UserResource::make($user->load(self::RELATIONS));
    }

    public function update(UpdateUserRequest $request, User $user): UserResource
    {
        DB::transaction(function () use ($request, $user): void {
            $data = $request->safe()->only(['name', 'email', 'password', 'employee_id']);

            if (($data['password'] ?? null) === null) {
                unset($data['password']);
            }

            $user->update($data);

            if ($request->has('role')) {
                $user->syncRoles([$request->validated('role')]);
            }
        });

        return UserResource::make($user->refresh()->load(self::RELATIONS));
    }

    /**
     * Baja lógica. Se revocan sus tokens para cerrar al momento las sesiones
     * abiertas, no hasta que caduquen.
     */
    public function destroy(Request $request, User $user): JsonResponse
    {
        if ($user->is($request->user())) {
            return response()->json([
                'message' => 'No puedes darte de baja a ti mismo.',
            ], 422);
        }

        DB::transaction(function () use ($user): void {
            $user->tokens()->delete();
            $user->delete();
        });

        return response()->json(status: 204);
    }

    /**
     * Trabajadores a los que se puede dar acceso: con área y sin usuario
     * vigente. Alimenta el buscador del formulario.
     */
    public function linkableEmployees(Request $request): AnonymousResourceCollection
    {
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $employees = Employee::query()
            ->search(trim($data['search'] ?? ''))
            ->whereNotNull('sub_department_id')
            ->whereDoesntHave('user')
            ->with('subDepartment.department')
            ->orderBy('name')
            ->orderBy('last_name')
            ->limit(self::LINKABLE_LIMIT)
            ->get();

        return EmployeeSummaryResource::collection($employees);
    }
}
