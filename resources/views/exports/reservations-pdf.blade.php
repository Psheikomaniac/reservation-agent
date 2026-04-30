<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>Reservierungen — {{ $restaurant->name }}</title>
    <style>
        @page {
            margin: 24mm 16mm 18mm 16mm;
        }
        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 10pt;
            color: #1a1a1a;
            margin: 0;
        }
        h1 {
            font-size: 16pt;
            margin: 0 0 4px;
        }
        .meta {
            font-size: 9pt;
            color: #555;
            margin-bottom: 16px;
        }
        .meta strong { color: #1a1a1a; }
        .filters {
            margin-bottom: 12px;
            font-size: 9pt;
            color: #555;
        }
        .filters .label {
            font-weight: bold;
            color: #1a1a1a;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 9pt;
        }
        thead th {
            text-align: left;
            border-bottom: 1px solid #1a1a1a;
            padding: 6px 4px;
            background: #f4f4f4;
        }
        tbody td {
            padding: 5px 4px;
            border-bottom: 1px solid #e0e0e0;
            vertical-align: top;
        }
        tbody tr:nth-child(even) td {
            background: #fafafa;
        }
        .footer {
            position: fixed;
            bottom: -8mm;
            left: 0;
            right: 0;
            text-align: center;
            font-size: 8pt;
            color: #666;
        }
        .page-number:after {
            content: counter(page) ' / ' counter(pages);
        }
        .empty {
            font-style: italic;
            color: #777;
            text-align: center;
            padding: 24px 0;
        }
    </style>
</head>
<body>
    <h1>{{ $restaurant->name }}</h1>
    <div class="meta">
        <strong>Reservierungs-Export</strong> · erstellt am
        {{ $generatedAt->format('d.m.Y H:i') }} ({{ $restaurant->timezone }})
    </div>

    @if (count($filterSummary) > 0)
        <div class="filters">
            <span class="label">Filter:</span>
            @foreach ($filterSummary as $line)
                {{ $line }}@if (! $loop->last); @endif
            @endforeach
        </div>
    @endif

    @if (count($rows) === 0)
        <p class="empty">Keine Reservierungen für die gewählten Filter.</p>
    @else
        <table>
            <thead>
                <tr>
                    <th>Datum</th>
                    <th>Uhrzeit</th>
                    <th>Personen</th>
                    <th>Name</th>
                    <th>Status</th>
                    <th>Quelle</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $row)
                    <tr>
                        <td>{{ $row['date'] }}</td>
                        <td>{{ $row['time'] }}</td>
                        <td>{{ $row['party_size'] }}</td>
                        <td>{{ $row['name'] }}</td>
                        <td>{{ $row['status'] }}</td>
                        <td>{{ $row['source'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <div class="footer">
        <span class="page-number"></span>
    </div>
</body>
</html>
