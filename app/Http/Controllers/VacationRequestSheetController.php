<?php

namespace App\Http\Controllers;

use App\Http\Resources\VacationPeriodResource;
use App\Http\Resources\VacationRequestResource;
use App\Models\VacationPeriod;
use App\Models\VacationRequest;
use App\Services\VacationSheetService;
use Barryvdh\Snappy\Facades\SnappyPdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/**
 * Ficha de vacaciones de una solicitud y concentrado anual del periodo, para
 * imprimirse y firmarse a mano. El PDF lo genera wkhtmltopdf a partir de las
 * plantillas de resources/views/pdf.
 */
class VacationRequestSheetController extends Controller
{
    /** Carta, el tamaño de la papelería de la empresa. */
    private const PAPER = 'letter';

    private const MARGINS = [
        'margin-top' => '16mm',
        'margin-right' => '16mm',
        'margin-bottom' => '16mm',
        'margin-left' => '16mm',
    ];

    public function __construct(private readonly VacationSheetService $sheets) {}

    public function show(VacationRequest $vacationRequest): JsonResponse
    {
        $data = $this->sheets->sheet($vacationRequest);

        return response()->json([
            'data' => [
                'request' => VacationRequestResource::make($data['request']),
                'employee' => $data['employee'],
                'period' => $data['period'] === null ? null : VacationPeriodResource::make($data['period']),
                'entitled_days' => $data['entitled_days'],
                'available_days' => $data['available_days'],
            ],
        ]);
    }

    public function pdf(VacationRequest $vacationRequest): Response
    {
        return $this->download(
            'pdf.vacation-sheet',
            $this->sheets->sheet($vacationRequest),
            "ficha-vacaciones-{$vacationRequest->id}.pdf",
        );
    }

    public function periodSummaryPdf(VacationPeriod $vacationPeriod): Response
    {
        $data = $this->sheets->periodSummary($vacationPeriod);
        $employee = Str::slug($data['employee']['full_name']);

        return $this->download(
            'pdf.vacation-period-summary',
            $data,
            "concentrado-vacaciones-{$employee}-ano-{$vacationPeriod->year_number}.pdf",
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function download(string $view, array $data, string $filename): Response
    {
        return SnappyPdf::loadView($view, $data)
            ->setPaper(self::PAPER)
            ->setOptions(self::MARGINS)
            ->download($filename);
    }
}
