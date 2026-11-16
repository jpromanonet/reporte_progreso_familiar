<?php

declare(strict_types=1);

date_default_timezone_set('America/Argentina/Buenos_Aires');

require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/ReportService.php';
require_once __DIR__ . '/lib/SimplePdf.php';

$cfg = app_config();
$year = (int) ($_GET['year'] ?? date('Y'));
$scope = (string) ($_GET['scope'] ?? 'year');
$month = (int) ($_GET['month'] ?? 0);
$names = month_names();
$colors = [
    'task' => [0.37, 0.57, 0.53],
    'repair' => [0.77, 0.52, 0.42],
    'medical' => [0.44, 0.56, 0.68],
    'spend' => [0.76, 0.48, 0.46],
    'habit' => [0.72, 0.58, 0.31],
    'note' => [0.54, 0.49, 0.44],
    'metrics' => [0.55, 0.42, 0.62], // solo el título "Métricas"
];

$appendInsights = static function (SimplePdf $pdf, array $insights, array $colors): void {
    if ($insights === []) {
        return;
    }
    $pdf->section('Métricas', $colors['metrics'], (string) count($insights));
    foreach ($insights as $item) {
        $pdf->item((string) $item['label'], (string) $item['value'], true);
    }
};

$monthMetrics = static function (array $t) use ($colors): array {
    return [
        ['label' => 'Tareas', 'value' => (string) (int) $t['tasks'], 'color' => $colors['task']],
        ['label' => 'Reparaciones', 'value' => (string) (int) $t['repairs'], 'color' => $colors['repair']],
        ['label' => 'Médico', 'value' => (string) (int) $t['medical'], 'color' => $colors['medical']],
        ['label' => 'Hábitos', 'value' => (string) (int) $t['habits'], 'color' => $colors['habit']],
        ['label' => 'Notas', 'value' => (string) (int) $t['notes'], 'color' => $colors['note']],
        ['label' => 'Gastos', 'value' => money_fmt((float) $t['spend']), 'color' => $colors['spend']],
    ];
};

try {
    $svc = new ReportService();
    $pdf = new SimplePdf();
    $data = $svc->yearSummary($year);

    if ($scope === 'month' && $month >= 1 && $month <= 12) {
        $t = $data['months'][$month] ?? [
            'tasks' => 0, 'repairs' => 0, 'medical' => 0, 'habits' => 0, 'notes' => 0, 'spend' => 0.0,
        ];
        $label = ($names[$month] ?? '') . ' ' . $year;
        $pdf->banner('Informe mensual familiar', $cfg['source'] . '  ·  ' . $label, $label);
        $pdf->metrics($monthMetrics($t));
        $appendInsights($pdf, $svc->monthInsights($t, $year, $month), $colors);
        $pdf->footer($cfg['app_name'] . ' · ' . $label);
        $filename = sprintf('reporte_%s_%d.pdf', month_slug($month), $year);
    } else {
        $t = $data['totals'];
        $pdf->banner('Informe anual familiar', $cfg['source'] . '  ·  Año ' . $year, (string) $year);
        $pdf->metrics($monthMetrics($t));
        $pdf->section('Mes a mes', $colors['task'], '12');
        foreach ($data['months'] as $m => $row) {
            $meta = sprintf(
                'Tareas %d · Reparaciones %d · Médico %d · Hábitos %d · Notas %d · Gastos %s',
                (int) $row['tasks'],
                (int) $row['repairs'],
                (int) $row['medical'],
                (int) $row['habits'],
                (int) $row['notes'],
                money_fmt((float) $row['spend'])
            );
            $pdf->item(($names[$m] ?? ('Mes ' . $m)), $meta, true);
        }
        $appendInsights($pdf, $data['insights'] ?? [], $colors);
        $charts = $data['charts'] ?? null;
        if (is_array($charts)) {
            $pdf->barChart('Hábitos por mes', $charts['labels'] ?? [], $charts['habits'] ?? [], $colors['habit']);
            $pdf->barChart('Tareas por mes', $charts['labels'] ?? [], $charts['tasks'] ?? [], $colors['task']);
            $pdf->barChart('Gastos por mes', $charts['labels'] ?? [], $charts['spend'] ?? [], $colors['spend']);
        }
        $pdf->footer($cfg['app_name'] . ' · Año ' . $year);
        $filename = sprintf('reporte_%d.pdf', $year);
    }

    $bytes = $pdf->render();
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($bytes));
    echo $bytes;
    exit;
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'No se pudo generar el PDF: ' . $e->getMessage();
    exit;
}
