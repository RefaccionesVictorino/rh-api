@use('App\Services\VacationSheetService', 'Format')

{{-- Concentrado anual: lo aprobado contra el periodo de un año de servicio. Es un reporte de consulta, sin firmas. --}}
@extends('pdf.layouts.vacation')

@section('title', 'Concentrado de vacaciones')

@section('folio')
  <strong>Año {{ $period->year_number }} de servicio</strong>
  {{ Format::describeRange($period->starts_on, $period->ends_on) }}
@endsection

@section('meta')
  Generado el {{ Format::shortDate(now()) }} · Prescribe el {{ Format::longDate($period->expires_on) }}
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
          <span class="label">Fecha de ingreso</span>
          @if ($employee['hire_date'])
            <span class="value">{{ Format::shortDate($employee['hire_date']) }}</span>
          @else
            <span class="blank"></span>
          @endif
        </td>
        <td>
          <span class="label">Área</span>
          @if ($employee['department'])
            <span class="value">{{ $employee['department'] }}@if ($employee['sub_department']) · {{ $employee['sub_department'] }}@endif</span>
          @else
            <span class="blank"></span>
          @endif
        </td>
      </tr>
    </table>
  </div>

  <div class="section">
    <h2 class="section-title">Saldo del periodo</h2>
    <table class="figures">
      <tr>
        <td>
          <span class="label">Corresponden</span>
          <span class="value">{{ Format::days($period->granted_days) }}</span>
          @if ($period->adjustment_days !== 0)
            <span class="label">{{ $period->entitled_days }} por ley {{ $period->adjustment_days > 0 ? '+' : '−' }} {{ abs($period->adjustment_days) }} de ajuste</span>
          @endif
        </td>
        <td>
          <span class="label">Aprobados</span>
          <span class="value">{{ Format::days($approved_days) }}</span>
        </td>
        <td>
          <span class="label">Restan</span>
          <span class="value">{{ Format::days($period->remaining_days) }}</span>
        </td>
      </tr>
    </table>

    @if ($pending_days > 0)
      <p class="note">{{ Format::days($pending_days) }} día(s) más están pendientes de autorizar y ya apartan saldo; no se listan abajo.</p>
    @endif
  </div>

  <div class="section">
    <h2 class="section-title">Prima vacacional</h2>
    @if ($period->is_premium_paid)
      <table class="figures">
        <tr>
          <td>
            <span class="label">Pagada el</span>
            <span class="value">{{ Format::shortDate($period->premium_paid_on) }}</span>
          </td>
          <td>
            <span class="label">Monto</span>
            <span class="value">{{ Format::money($period->premium_amount) }}</span>
          </td>
          <td>
            <span class="label">Porcentaje</span>
            <span class="value">{{ Format::percent($period->premium_percentage) }}</span>
          </td>
        </tr>
      </table>
    @else
      <p class="note">Sin pago registrado.</p>
    @endif
  </div>

  <div class="section">
    <h2 class="section-title">Vacaciones aprobadas</h2>
    <table class="list">
      <thead>
        <tr>
          <th>Periodo vacacional</th>
          <th class="num">Días</th>
          <th>Aprobada el</th>
          <th>Notas</th>
        </tr>
      </thead>
      <tbody>
        @forelse ($rows as $row)
          <tr>
            <td>{{ Format::describeRange($row['request']->starts_on, $row['request']->ends_on) }}</td>
            <td class="num">{{ Format::days($row['days']) }}</td>
            <td>{{ $row['request']->reviewed_at ? Format::shortDate($row['request']->reviewed_at) : '—' }}</td>
            <td>{{ $row['request']->reschedule_reason ?? $row['request']->comments ?? '' }}</td>
          </tr>
        @empty
          <tr>
            <td colspan="4" class="empty">No hay vacaciones aprobadas en este periodo.</td>
          </tr>
        @endforelse
      </tbody>
      @if ($rows->isNotEmpty())
        <tfoot>
          <tr>
            <td>Total aprobado</td>
            <td class="num">{{ Format::days($approved_days) }}</td>
            <td colspan="2"></td>
          </tr>
        </tfoot>
      @endif
    </table>
  </div>
@endsection
