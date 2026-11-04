<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateEmployeePhotoRequest;
use App\Http\Resources\EmployeeResource;
use App\Models\Employee;
use App\Services\FileUploader;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Fotografía del expediente.
 *
 * El archivo nunca llega al disco del API: se reenvía al microservicio de carga
 * y en la columna queda la URL que devuelve. Al borrar solo se limpia la
 * columna; el objeto permanece en el bucket, igual que en el resto del sistema,
 * porque el microservicio no expone borrado.
 */
class EmployeePhotoController extends Controller
{
    /** Relaciones que lleva el expediente completo, como en EmployeeController. */
    private const DETAIL_RELATIONS = [
        'subDepartment.department',
        'currentShiftAssignment.shift.days',
        'timeClockUser',
    ];

    public function update(
        UpdateEmployeePhotoRequest $request,
        Employee $employee,
        FileUploader $uploader,
    ): EmployeeResource|JsonResponse {
        try {
            $location = $uploader->upload(
                $request->file('photo'),
                'empleados/'.$employee->id.'/',
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }

        $employee->update(['photo_url' => $location]);

        return EmployeeResource::make($employee->refresh()->load(self::DETAIL_RELATIONS));
    }

    public function destroy(Employee $employee): EmployeeResource
    {
        $employee->update(['photo_url' => null]);

        return EmployeeResource::make($employee->refresh()->load(self::DETAIL_RELATIONS));
    }
}
