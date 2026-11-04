@use('App\Services\VacationSheetService', 'Format')

{{-- Ficha de una solicitud. Sucursal, modalidad y prima pagada no existen en el expediente: van en blanco para llenarse a mano. --}}
@extends('pdf.layouts.vacation')

@section('title', 'Solicitud de vacaciones')

@section('folio')
  <strong>Folio {{ str_pad((string) $request->id, 5, '0', STR_PAD_LEFT) }}</strong>
  <span class="status">{{ $request->status_label }}</span>
@endsection

@section('meta')
  Solicitada el {{ Format::shortDate($request->created_at) }}@if ($request->requestedBy) por {{ $request->requestedBy->name }}@endif
@endsection

@section('content')
  <div class="section">
    <h2 class="section-title">Datos del empleado</h2>
    <table class="fields">
      <tr>
        <td colspan="2">
          <span class="label">Nombre</span>
          <span class="value">{{ $employee['full_name'] }}</span>
        </td>
      </tr>
      <tr>
        <td>
          <span class="label">Sucursal</span>
          <span class="blank"></span>
        </td>
        <td>
          <span class="label">Fecha de ingreso</span>
          @if ($employee['hire_date'])
            <span class="value">{{ Format::shortDate($employee['hire_date']) }}</span>
          @else
            <span class="blank"></span>
          @endif
        </td>
      </tr>
      <tr>
        <td>
          <span class="label">Área</span>
          @if ($employee['department'])
            <span class="value">{{ $employee['department'] }}@if ($employee['sub_department']) · {{ $employee['sub_department'] }}@endif</span>
          @else
            <span class="blank"></span>
          @endif
        </td>
        <td>
          <span class="label">Días por Ley Federal del Trabajo</span>
          <span class="value">{{ $entitled_days }} días</span>
        </td>
      </tr>
      <tr>
        <td>
          <span class="label">Modalidad</span>
          <span class="blank"></span>
        </td>
        <td>
          <span class="label">Prima pagada</span>
          <span class="blank"></span>
        </td>
      </tr>
    </table>
  </div>

  <div class="section">
    <h2 class="section-title">Periodo solicitado</h2>
    <table class="figures">
      <tr>
        <td>
          <span class="label">Días a tomar</span>
          <span class="value">{{ Format::days($request->requested_days) }}</span>
        </td>
        <td>
          <span class="label">Periodo vacacional</span>
          <span class="value">{{ Format::describeRange($request->starts_on, $request->ends_on) }}</span>
        </td>
        <td>
          <span class="label">Días pendientes</span>
          <span class="value">{{ Format::days($available_days) }}</span>
        </td>
      </tr>
    </table>

    @if ($request->comments)
      <p class="comments"><strong>Comentarios:</strong> {{ $request->comments }}</p>
    @endif
  </div>

  <div class="section">
    <h2 class="section-title">Firmas</h2>
    <table class="signatures">
      <tr>
        <td></td>
        <td></td>
        <td></td>
      </tr>
      <tr class="caption">
        <td>Firma del empleado</td>
        <td>Firma del jefe inmediato</td>
        <td>Firma de recursos humanos</td>
      </tr>
    </table>
  </div>
@endsection
