{{--
  Base de los PDF de vacaciones. wkhtmltopdf usa un WebKit viejo: el acomodo
  va con tablas, no con flex ni grid, y los colores son fijos (no hay tema).
--}}
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>@yield('title')</title>
  <style>
    * { box-sizing: border-box; }
    body {
      margin: 0;
      font-family: "Segoe UI", Helvetica, Arial, sans-serif;
      font-size: 12px;
      line-height: 1.45;
      color: #111111;
    }
    table { border-collapse: collapse; }

    .header { width: 100%; border-bottom: 1px solid #111111; padding-bottom: 12px; margin-bottom: 24px; }
    .header td { vertical-align: top; padding: 0; }
    .brand { font-size: 9px; font-weight: 700; letter-spacing: 2px; text-transform: uppercase; color: #555555; }
    h1 { margin: 2px 0 0; font-size: 22px; font-weight: 700; letter-spacing: -0.3px; }
    .folio { text-align: right; font-size: 12px; white-space: nowrap; }
    .folio strong { display: block; font-size: 13px; }
    .status { display: inline-block; margin-top: 4px; padding: 1px 8px; border: 1px solid #111111; border-radius: 10px; font-size: 10px; }
    .meta { margin: 8px 0 0; font-size: 10px; color: #555555; }

    .section { margin-bottom: 26px; page-break-inside: avoid; }
    .section-title { margin: 0 0 10px; font-size: 9px; font-weight: 700; letter-spacing: 1.5px; text-transform: uppercase; color: #555555; }

    .fields { width: 100%; }
    .fields td { width: 50%; padding: 0 24px 12px 0; vertical-align: bottom; }
    .label { display: block; margin-bottom: 2px; font-size: 10px; color: #555555; }
    .value { font-size: 13px; font-weight: 600; }
    .blank { display: block; height: 16px; border-bottom: 1px dotted #777777; }

    .figures { width: 100%; border: 1px solid #111111; }
    .figures td { width: 33.33%; padding: 10px 14px; border-left: 1px solid #111111; vertical-align: top; }
    .figures td:first-child { border-left: 0; }
    .figures .value { font-size: 15px; }

    .list { width: 100%; }
    .list th { padding: 6px 8px; border-bottom: 1px solid #111111; text-align: left; font-size: 10px; font-weight: 700; letter-spacing: 1px; text-transform: uppercase; color: #555555; }
    .list td { padding: 7px 8px; border-bottom: 1px solid #dddddd; vertical-align: top; }
    .list .num { text-align: right; white-space: nowrap; }
    .list tfoot td { border-top: 1px solid #111111; border-bottom: 0; font-weight: 700; }
    .list .empty { padding: 18px 8px; text-align: center; color: #555555; }

    .signatures { width: 100%; margin-top: 44px; border-collapse: separate; border-spacing: 20px 0; page-break-inside: avoid; }
    .signatures td { width: 33.33%; padding-top: 72px; border-bottom: 1px solid #111111; }
    .signatures .caption td { padding-top: 6px; border-bottom: 0; text-align: center; font-size: 11px; font-weight: 600; }

    .note { margin: 10px 0 0; font-size: 10px; color: #555555; }
    .comments { margin: 12px 0 0; font-size: 12px; }
  </style>
</head>
<body>
  <table class="header">
    <tr>
      <td>
        <div class="brand">Refacciones Victorino</div>
        <h1>@yield('title')</h1>
      </td>
      <td class="folio">@yield('folio')</td>
    </tr>
    @hasSection('meta')
      <tr>
        <td colspan="2"><p class="meta">@yield('meta')</p></td>
      </tr>
    @endif
  </table>

  @yield('content')
</body>
</html>
