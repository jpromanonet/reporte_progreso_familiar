<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';

final class ReportService
{
    /** @return list<int> */
    public function availableYears(): array
    {
        $pdo = db();
        $years = [];
        $queries = [
            "SELECT DISTINCT YEAR(completed_at) y FROM tasks WHERE completed_at IS NOT NULL AND archived_at IS NULL",
            "SELECT DISTINCT YEAR(closed_at) y FROM repair_records WHERE closed_at IS NOT NULL AND archived_at IS NULL",
            "SELECT DISTINCT YEAR(executed_at) y FROM routine_executions WHERE executed_at IS NOT NULL",
            "SELECT DISTINCT YEAR(completed_at) y FROM calendar_notes WHERE completed_at IS NOT NULL AND archived_at IS NULL",
            "SELECT DISTINCT YEAR(week_start) y FROM week_spend_items WHERE archived_at IS NULL",
        ];
        foreach ($queries as $sql) {
            try {
                foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN) as $y) {
                    $y = (int) $y;
                    if ($y >= 2000) {
                        $years[$y] = true;
                    }
                }
            } catch (Throwable) {
                // table may not exist yet
            }
        }
        $years[(int) date('Y')] = true;
        $list = array_keys($years);
        rsort($list);

        return $list;
    }

    /** @return array{year:int,totals:array<string,float|int>,months:array<int,array<string,float|int>>} */
    public function yearSummary(int $year): array
    {
        $months = [];
        for ($m = 1; $m <= 12; $m++) {
            $months[$m] = $this->emptyTotals();
        }
        $totals = $this->emptyTotals();

        foreach ($this->countByMonth(
            "SELECT MONTH(completed_at) m, COUNT(*) c FROM tasks
             WHERE archived_at IS NULL AND status = 'done' AND completed_at IS NOT NULL
               AND YEAR(completed_at) = :y
               AND (kind IS NULL OR kind NOT IN ('reparacion','medico'))
             GROUP BY MONTH(completed_at)",
            $year
        ) as $m => $c) {
            $months[$m]['tasks'] = $c;
        }

        foreach ($this->countByMonth(
            "SELECT MONTH(completed_at) m, COUNT(*) c FROM tasks
             WHERE archived_at IS NULL AND status = 'done' AND kind = 'reparacion'
               AND completed_at IS NOT NULL AND YEAR(completed_at) = :y
             GROUP BY MONTH(completed_at)",
            $year
        ) as $m => $c) {
            $months[$m]['repairs'] += $c;
        }
        foreach ($this->countByMonth(
            "SELECT MONTH(closed_at) m, COUNT(*) c FROM repair_records
             WHERE archived_at IS NULL AND status IN ('hecho','done')
               AND closed_at IS NOT NULL AND YEAR(closed_at) = :y
             GROUP BY MONTH(closed_at)",
            $year
        ) as $m => $c) {
            $months[$m]['repairs'] += $c;
        }

        foreach ($this->countByMonth(
            "SELECT MONTH(completed_at) m, COUNT(*) c FROM tasks
             WHERE archived_at IS NULL AND status = 'done' AND kind = 'medico'
               AND completed_at IS NOT NULL AND YEAR(completed_at) = :y
             GROUP BY MONTH(completed_at)",
            $year
        ) as $m => $c) {
            $months[$m]['medical'] = $c;
        }

        foreach ($this->countByMonth(
            "SELECT MONTH(executed_at) m, COUNT(*) c FROM routine_executions
             WHERE executed_at IS NOT NULL AND YEAR(executed_at) = :y
             GROUP BY MONTH(executed_at)",
            $year
        ) as $m => $c) {
            $months[$m]['habits'] = $c;
        }

        foreach ($this->countByMonth(
            "SELECT MONTH(completed_at) m, COUNT(*) c FROM calendar_notes
             WHERE archived_at IS NULL AND completed_at IS NOT NULL AND YEAR(completed_at) = :y
             GROUP BY MONTH(completed_at)",
            $year
        ) as $m => $c) {
            $months[$m]['notes'] = $c;
        }

        foreach ($this->sumByMonth(
            "SELECT MONTH(week_start) m, COALESCE(SUM(amount),0) c FROM week_spend_items
             WHERE archived_at IS NULL AND YEAR(week_start) = :y
             GROUP BY MONTH(week_start)",
            $year
        ) as $m => $c) {
            $months[$m]['spend'] = $c;
        }

        foreach ($months as $row) {
            foreach ($row as $k => $v) {
                $totals[$k] += $v;
            }
        }

        return [
            'year' => $year,
            'totals' => $totals,
            'months' => $months,
            'insights' => $this->yearInsights($totals, $months),
            'charts' => $this->chartSeries($months),
        ];
    }

    /**
     * @param array<string,float|int> $totals
     * @param array<int,array<string,float|int>> $months
     * @return list<array{label:string,value:string,tone:string}>
     */
    public function yearInsights(array $totals, array $months): array
    {
        $names = month_names();
        $active = 0;
        $bestHabits = -1;
        $bestHabitsMonth = 0;
        $bestTasks = -1;
        $bestTasksMonth = 0;
        $bestSpend = -1.0;
        $bestSpendMonth = 0;
        foreach ($months as $m => $row) {
            if (
                (int) $row['tasks'] > 0 || (int) $row['repairs'] > 0 || (int) $row['medical'] > 0
                || (int) $row['habits'] > 0 || (int) $row['notes'] > 0 || (float) $row['spend'] > 0
            ) {
                $active++;
            }
            if ((int) $row['habits'] > $bestHabits) {
                $bestHabits = (int) $row['habits'];
                $bestHabitsMonth = (int) $m;
            }
            if ((int) $row['tasks'] > $bestTasks) {
                $bestTasks = (int) $row['tasks'];
                $bestTasksMonth = (int) $m;
            }
            if ((float) $row['spend'] > $bestSpend) {
                $bestSpend = (float) $row['spend'];
                $bestSpendMonth = (int) $m;
            }
        }
        $div = max(1, $active);

        return [
            ['label' => 'Meses con datos', 'value' => (string) $active . ' / 12', 'tone' => 'task'],
            ['label' => 'Prom. tareas / mes', 'value' => (string) round((int) $totals['tasks'] / $div, 1), 'tone' => 'task'],
            ['label' => 'Prom. hábitos / mes', 'value' => (string) round((int) $totals['habits'] / $div, 1), 'tone' => 'habit'],
            ['label' => 'Prom. reparaciones / mes', 'value' => (string) round((int) $totals['repairs'] / $div, 1), 'tone' => 'repair'],
            ['label' => 'Mes con más hábitos', 'value' => $bestHabitsMonth > 0 ? (($names[$bestHabitsMonth] ?? '') . ' · ' . $bestHabits) : '—', 'tone' => 'habit'],
            ['label' => 'Mes con más tareas', 'value' => $bestTasksMonth > 0 ? (($names[$bestTasksMonth] ?? '') . ' · ' . $bestTasks) : '—', 'tone' => 'task'],
            ['label' => 'Mes con más gasto', 'value' => $bestSpendMonth > 0 ? (($names[$bestSpendMonth] ?? '') . ' · ' . money_fmt($bestSpend)) : '—', 'tone' => 'spend'],
            ['label' => 'Prom. gasto / mes', 'value' => money_fmt((float) $totals['spend'] / $div), 'tone' => 'spend'],
        ];
    }

    /**
     * @param array<string,float|int> $totals
     * @return list<array{label:string,value:string,tone:string}>
     */
    public function monthInsights(array $totals, int $year, int $month): array
    {
        $days = (int) (new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month)))
            ->modify('last day of this month')
            ->format('j');

        return [
            ['label' => 'Tareas / día', 'value' => (string) round((int) $totals['tasks'] / max(1, $days), 1), 'tone' => 'task'],
            ['label' => 'Hábitos / día', 'value' => (string) round((int) $totals['habits'] / max(1, $days), 1), 'tone' => 'habit'],
            ['label' => 'Reparaciones', 'value' => (string) (int) $totals['repairs'], 'tone' => 'repair'],
            ['label' => 'Médico', 'value' => (string) (int) $totals['medical'], 'tone' => 'medical'],
            ['label' => 'Notas leídas', 'value' => (string) (int) $totals['notes'], 'tone' => 'note'],
            ['label' => 'Gasto del mes', 'value' => money_fmt((float) $totals['spend']), 'tone' => 'spend'],
        ];
    }

    /**
     * @param array<int,array<string,float|int>> $months
     * @return array{labels:list<string>,tasks:list<int>,habits:list<int>,repairs:list<int>,spend:list<float>}
     */
    public function chartSeries(array $months): array
    {
        $short = [1 => 'Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun', 'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic'];
        $out = ['labels' => [], 'tasks' => [], 'habits' => [], 'repairs' => [], 'spend' => []];
        for ($m = 1; $m <= 12; $m++) {
            $row = $months[$m] ?? $this->emptyTotals();
            $out['labels'][] = $short[$m];
            $out['tasks'][] = (int) $row['tasks'];
            $out['habits'][] = (int) $row['habits'];
            $out['repairs'][] = (int) $row['repairs'];
            $out['spend'][] = round((float) $row['spend'], 2);
        }

        return $out;
    }

    /** @return array<string,mixed> */
    public function monthDetail(int $year, int $month): array
    {
        $summary = $this->yearSummary($year);
        $totals = $summary['months'][$month] ?? $this->emptyTotals();
        $start = sprintf('%04d-%02d-01 00:00:00', $year, $month);
        $end = (new DateTimeImmutable($start))->modify('first day of next month')->format('Y-m-d H:i:s');
        $startDay = substr($start, 0, 10);
        $endDay = (new DateTimeImmutable($start))->modify('last day of this month')->format('Y-m-d');

        $pdo = db();
        $tasks = $this->fetchAll(
            "SELECT title, completed_at FROM tasks
             WHERE archived_at IS NULL AND status = 'done' AND completed_at IS NOT NULL
               AND completed_at >= :a AND completed_at < :b
               AND (kind IS NULL OR kind NOT IN ('reparacion','medico'))
             ORDER BY completed_at, title",
            ['a' => $start, 'b' => $end]
        );

        $repairs = $this->fetchAll(
            "SELECT title, completed_at FROM tasks
             WHERE archived_at IS NULL AND status = 'done' AND kind = 'reparacion'
               AND completed_at IS NOT NULL AND completed_at >= :a AND completed_at < :b
             ORDER BY completed_at, title",
            ['a' => $start, 'b' => $end]
        );
        foreach ($this->fetchAll(
            "SELECT title, closed_at AS completed_at FROM repair_records
             WHERE archived_at IS NULL AND status IN ('hecho','done')
               AND closed_at IS NOT NULL AND closed_at >= :a AND closed_at <= :b
             ORDER BY closed_at, title",
            ['a' => $startDay, 'b' => $endDay]
        ) as $row) {
            $repairs[] = $row;
        }

        $medical = $this->fetchAll(
            "SELECT title, completed_at FROM tasks
             WHERE archived_at IS NULL AND status = 'done' AND kind = 'medico'
               AND completed_at IS NOT NULL AND completed_at >= :a AND completed_at < :b
             ORDER BY completed_at, title",
            ['a' => $start, 'b' => $end]
        );

        $habits = $this->fetchAll(
            "SELECT r.title, COUNT(e.id) AS times
             FROM routine_executions e
             INNER JOIN routines r ON r.id = e.routine_id
             WHERE r.archived_at IS NULL AND e.executed_at >= :a AND e.executed_at < :b
             GROUP BY r.id, r.title
             ORDER BY times DESC, r.title",
            ['a' => $start, 'b' => $end]
        );

        $notes = $this->fetchAll(
            "SELECT title, completed_at FROM calendar_notes
             WHERE archived_at IS NULL AND completed_at IS NOT NULL
               AND completed_at >= :a AND completed_at < :b
             ORDER BY completed_at, title",
            ['a' => $start, 'b' => $end]
        );

        $spend = $this->fetchAll(
            "SELECT title, amount, currency, week_start, completed_at
             FROM week_spend_items
             WHERE archived_at IS NULL AND week_start >= :a AND week_start <= :b
             ORDER BY week_start, id",
            ['a' => $startDay, 'b' => $endDay]
        );

        return [
            'year' => $year,
            'month' => $month,
            'totals' => $totals,
            'tasks' => $tasks,
            'repairs' => $repairs,
            'medical' => $medical,
            'habits' => $habits,
            'notes' => $notes,
            'spend' => $spend,
            'insights' => $this->monthInsights($totals, $year, $month),
        ];
    }

    /** @return array<string,float|int> */
    private function emptyTotals(): array
    {
        return [
            'tasks' => 0,
            'repairs' => 0,
            'medical' => 0,
            'habits' => 0,
            'notes' => 0,
            'spend' => 0.0,
        ];
    }

    /** @return array<int,int> */
    private function countByMonth(string $sql, int $year): array
    {
        try {
            $stmt = db()->prepare($sql);
            $stmt->execute(['y' => $year]);
            $out = [];
            foreach ($stmt->fetchAll() as $row) {
                $out[(int) $row['m']] = (int) $row['c'];
            }
            return $out;
        } catch (Throwable) {
            return [];
        }
    }

    /** @return array<int,float> */
    private function sumByMonth(string $sql, int $year): array
    {
        try {
            $stmt = db()->prepare($sql);
            $stmt->execute(['y' => $year]);
            $out = [];
            foreach ($stmt->fetchAll() as $row) {
                $out[(int) $row['m']] = (float) $row['c'];
            }
            return $out;
        } catch (Throwable) {
            return [];
        }
    }

    /** @return list<array<string,mixed>> */
    private function fetchAll(string $sql, array $params): array
    {
        try {
            $stmt = db()->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll() ?: [];
        } catch (Throwable) {
            return [];
        }
    }
}
