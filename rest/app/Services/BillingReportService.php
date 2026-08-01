<?php

namespace App\Services;

class BillingReportService
{
    private BillingTenantDatabaseContextService $tenantDbContext;
    private BillingTenantSchemaService $schema;
    private BillingDocumentService $documents;

    public function __construct(
        ?BillingTenantDatabaseContextService $tenantDbContext = null,
        ?BillingTenantSchemaService $schema = null,
        ?BillingDocumentService $documents = null
    ) {
        $this->tenantDbContext = $tenantDbContext ?? new BillingTenantDatabaseContextService();
        $this->schema = $schema ?? new BillingTenantSchemaService();
        $this->documents = $documents ?? new BillingDocumentService();
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, string>
     */
    public function normalizeFilters(array $input): array
    {
        $period = strtolower(trim((string) ($input['period'] ?? 'all')));
        $allowedPeriods = ['current_month', 'previous_month', 'current_year', 'previous_year', 'custom', 'all'];
        if (!in_array($period, $allowedPeriods, true)) {
            $period = 'all';
        }

        [$dateFrom, $dateTo] = $this->resolvePeriodDates($period, $input);

        $documentType = strtolower(trim((string) ($input['document_type'] ?? '')));
        if (!array_key_exists($documentType, $this->documents->documentTypeLabels())) {
            $documentType = '';
        }

        $paymentMethod = strtolower(trim((string) ($input['payment_method'] ?? '')));
        if (!array_key_exists($paymentMethod, $this->documents->paymentMethodLabels())) {
            $paymentMethod = '';
        }

        return [
            'period' => $period,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'local_state' => 'issued',
            'document_type' => $documentType,
            'payment_method' => $paymentMethod,
            'ts_sync_state' => '',
            'patient' => mb_substr(trim((string) ($input['patient'] ?? '')), 0, 120),
        ];
    }

    /**
     * @param array<string, mixed> $filterInput
     * @return array<string, mixed>
     */
    public function buildReportForTenant(int $tenantId, array $filterInput = []): array
    {
        $filters = $this->normalizeFilters($filterInput);
        $schemaStatus = $this->schema->ensureTenantSchemaReady($tenantId);
        if (empty($schemaStatus['ready'])) {
            return $this->emptyReport($filters, trim((string) ($schemaStatus['message'] ?? '')));
        }

        $context = $this->tenantDbContext->resolveTenantContext($tenantId);
        $db = $context['db'];
        if (!$db->tableExists('billing_documents')) {
            return $this->emptyReport($filters, 'Archivio fatture non disponibile per questo spazio.');
        }

        $builder = $db->table('billing_documents')
            ->select('id_billing_document, document_number, document_type, issue_date, payment_date, patient_name, patient_tax_code, payment_method, line_items_json, subtotal_amount, stamp_duty_amount, vat_rate, vat_nature, amount_total, notes, ts_expense_type_code, ts_sync_state, local_state');

        $this->applyFilters($builder, $filters);
        $rows = $builder
            ->orderBy('issue_date', 'DESC')
            ->orderBy('id_billing_document', 'DESC')
            ->get()
            ->getResultArray();

        $documentTypeLabels = $this->documents->documentTypeLabels();
        $paymentMethodLabels = $this->documents->paymentMethodLabels();
        $localStateLabels = $this->documents->localStateLabels();
        $tsSyncLabels = $this->documents->tsSyncStateLabels();

        foreach ($rows as &$row) {
            $documentType = (string) ($row['document_type'] ?? '');
            $paymentMethod = (string) ($row['payment_method'] ?? '');
            $localState = (string) ($row['local_state'] ?? '');
            $tsSyncState = (string) ($row['ts_sync_state'] ?? '');
            $row['document_type_label'] = $documentTypeLabels[$documentType] ?? $documentType;
            $row['payment_method_label'] = $paymentMethodLabels[$paymentMethod] ?? $paymentMethod;
            $row['local_state_label'] = $localStateLabels[$localState] ?? $localState;
            $row['ts_sync_state_label'] = $tsSyncLabels[$tsSyncState] ?? $tsSyncState;
            $row['service_descriptions'] = $this->decodeServiceDescriptions((string) ($row['line_items_json'] ?? ''));
        }
        unset($row);

        return [
            'table_available' => true,
            'schema_message' => trim((string) ($schemaStatus['message'] ?? '')),
            'filters' => $filters,
            'filter_options' => $this->filterOptions(),
            'documents' => $rows,
            'summary' => $this->buildSummary($rows),
            'monthly_breakdown' => $this->buildMonthlyBreakdown($rows),
            'payment_breakdown' => $this->buildBreakdown($rows, 'payment_method', 'payment_method_label'),
            'document_type_breakdown' => $this->buildBreakdown($rows, 'document_type', 'document_type_label'),
        ];
    }

    /**
     * @param array<string, mixed> $report
     */
    public function buildAccountantCsv(array $report): string
    {
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            throw new \RuntimeException('Impossibile generare il file CSV.');
        }

        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, [
            'Numero documento',
            'Tipo documento',
            'Data emissione',
            'Data pagamento',
            'Cliente',
            'Codice fiscale',
            'Prestazioni',
            'Metodo pagamento',
            'Imponibile',
            'Aliquota IVA',
            'Natura IVA',
            'Bollo',
            'Totale documento',
            'Tipo spesa TS',
            'Note',
        ], ';');

        foreach ((array) ($report['documents'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }

            fputcsv($handle, [
                (string) ($row['document_number'] ?? ''),
                (string) ($row['document_type_label'] ?? ''),
                $this->formatDateForExport((string) ($row['issue_date'] ?? '')),
                $this->formatDateForExport((string) ($row['payment_date'] ?? '')),
                (string) ($row['patient_name'] ?? ''),
                (string) ($row['patient_tax_code'] ?? ''),
                implode(' | ', (array) ($row['service_descriptions'] ?? [])),
                (string) ($row['payment_method_label'] ?? ''),
                $this->formatMoneyForExport($row['subtotal_amount'] ?? 0),
                $this->formatMoneyForExport($row['vat_rate'] ?? 0),
                (string) ($row['vat_nature'] ?? ''),
                $this->formatMoneyForExport($row['stamp_duty_amount'] ?? 0),
                $this->formatMoneyForExport($row['amount_total'] ?? 0),
                (string) ($row['ts_expense_type_code'] ?? ''),
                preg_replace('/\s+/', ' ', trim((string) ($row['notes'] ?? ''))) ?? '',
            ], ';');
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv !== false ? $csv : '';
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function filterOptions(): array
    {
        return [
            'periods' => [
                'current_month' => 'Mese corrente',
                'previous_month' => 'Mese precedente',
                'current_year' => 'Anno corrente',
                'previous_year' => 'Anno precedente',
                'custom' => 'Periodo personalizzato',
                'all' => 'Tutto lo storico',
            ],
            'document_types' => ['' => 'Tutti i tipi'] + $this->documents->documentTypeLabels(),
            'payment_methods' => ['' => 'Tutti i pagamenti'] + $this->documents->paymentMethodLabels(),
        ];
    }

    /**
     * @param \CodeIgniter\Database\BaseBuilder $builder
     * @param array<string, string> $filters
     */
    private function applyFilters($builder, array $filters): void
    {
        if ($filters['date_from'] !== '') {
            $builder->where('issue_date >=', $filters['date_from']);
        }
        if ($filters['date_to'] !== '') {
            $builder->where('issue_date <=', $filters['date_to']);
        }
        if ($filters['local_state'] !== 'all') {
            $builder->where('local_state', $filters['local_state']);
        }
        if ($filters['document_type'] !== '') {
            $builder->where('document_type', $filters['document_type']);
        }
        if ($filters['payment_method'] !== '') {
            $builder->where('payment_method', $filters['payment_method']);
        }
        if ($filters['ts_sync_state'] !== '') {
            $builder->where('ts_sync_state', $filters['ts_sync_state']);
        }
        if ($filters['patient'] !== '') {
            $builder->groupStart()
                ->like('patient_name', $filters['patient'])
                ->orLike('patient_tax_code', $filters['patient'])
                ->orLike('document_number', $filters['patient'])
                ->groupEnd();
        }
    }

    /**
     * @param array<string, mixed> $input
     * @return array{0: string, 1: string}
     */
    private function resolvePeriodDates(string $period, array $input): array
    {
        if ($period === 'all') {
            return ['', ''];
        }

        if ($period === 'custom') {
            $from = $this->normalizeDate((string) ($input['date_from'] ?? ''));
            $to = $this->normalizeDate((string) ($input['date_to'] ?? ''));
            if ($from !== '' && $to !== '' && $from > $to) {
                [$from, $to] = [$to, $from];
            }

            return [$from, $to];
        }

        $now = new \DateTimeImmutable('today');
        if ($period === 'previous_month') {
            $start = $now->modify('first day of previous month');
            $end = $now->modify('last day of previous month');
        } elseif ($period === 'current_year') {
            $start = new \DateTimeImmutable($now->format('Y') . '-01-01');
            $end = new \DateTimeImmutable($now->format('Y') . '-12-31');
        } elseif ($period === 'previous_year') {
            $year = (int) $now->format('Y') - 1;
            $start = new \DateTimeImmutable($year . '-01-01');
            $end = new \DateTimeImmutable($year . '-12-31');
        } else {
            $start = $now->modify('first day of this month');
            $end = $now->modify('last day of this month');
        }

        return [$start->format('Y-m-d'), $end->format('Y-m-d')];
    }

    private function normalizeDate(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value ? $value : '';
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, int|float>
     */
    private function buildSummary(array $rows): array
    {
        $total = 0.0;
        $subtotal = 0.0;
        $stampDuty = 0.0;
        $paid = 0.0;
        $clients = [];

        foreach ($rows as $row) {
            $rowTotal = (float) ($row['amount_total'] ?? 0);
            $total += $rowTotal;
            $subtotal += (float) ($row['subtotal_amount'] ?? 0);
            $stampDuty += (float) ($row['stamp_duty_amount'] ?? 0);
            if (trim((string) ($row['payment_date'] ?? '')) !== '') {
                $paid += $rowTotal;
            }
            $clientKey = strtoupper(trim((string) ($row['patient_tax_code'] ?? '')));
            if ($clientKey === '') {
                $clientKey = mb_strtolower(trim((string) ($row['patient_name'] ?? '')));
            }
            if ($clientKey !== '') {
                $clients[$clientKey] = true;
            }
        }

        $count = count($rows);

        return [
            'document_count' => $count,
            'client_count' => count($clients),
            'total_amount' => round($total, 2),
            'subtotal_amount' => round($subtotal, 2),
            'stamp_duty_amount' => round($stampDuty, 2),
            'paid_amount' => round($paid, 2),
            'average_amount' => $count > 0 ? round($total / $count, 2) : 0.0,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function buildMonthlyBreakdown(array $rows): array
    {
        $months = [];
        foreach ($rows as $row) {
            $issueDate = trim((string) ($row['issue_date'] ?? ''));
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $issueDate)) {
                continue;
            }
            $key = substr($issueDate, 0, 7);
            if (!isset($months[$key])) {
                $months[$key] = ['month' => $key, 'label' => $this->monthLabel($key), 'count' => 0, 'amount' => 0.0];
            }
            $months[$key]['count']++;
            $months[$key]['amount'] += (float) ($row['amount_total'] ?? 0);
        }

        ksort($months);
        foreach ($months as &$month) {
            $month['amount'] = round((float) $month['amount'], 2);
        }
        unset($month);

        return array_values($months);
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function buildBreakdown(array $rows, string $keyField, string $labelField): array
    {
        $breakdown = [];
        foreach ($rows as $row) {
            $key = trim((string) ($row[$keyField] ?? ''));
            if ($key === '') {
                $key = 'not_set';
            }
            if (!isset($breakdown[$key])) {
                $breakdown[$key] = [
                    'key' => $key,
                    'label' => trim((string) ($row[$labelField] ?? '')) ?: 'Non specificato',
                    'count' => 0,
                    'amount' => 0.0,
                ];
            }
            $breakdown[$key]['count']++;
            $breakdown[$key]['amount'] += (float) ($row['amount_total'] ?? 0);
        }

        usort($breakdown, static fn(array $left, array $right): int => (float) $right['amount'] <=> (float) $left['amount']);
        foreach ($breakdown as &$item) {
            $item['amount'] = round((float) $item['amount'], 2);
        }
        unset($item);

        return array_values($breakdown);
    }

    /**
     * @return array<int, string>
     */
    private function decodeServiceDescriptions(string $json): array
    {
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return [];
        }

        $descriptions = [];
        foreach ($decoded as $item) {
            if (!is_array($item)) {
                continue;
            }
            $description = trim((string) ($item['description'] ?? ''));
            if ($description !== '') {
                $descriptions[$description] = $description;
            }
        }

        return array_values($descriptions);
    }

    private function monthLabel(string $month): string
    {
        $monthNames = [
            '01' => 'Gen', '02' => 'Feb', '03' => 'Mar', '04' => 'Apr',
            '05' => 'Mag', '06' => 'Giu', '07' => 'Lug', '08' => 'Ago',
            '09' => 'Set', '10' => 'Ott', '11' => 'Nov', '12' => 'Dic',
        ];
        [$year, $number] = array_pad(explode('-', $month, 2), 2, '');

        return ($monthNames[$number] ?? $number) . ' ' . $year;
    }

    private function formatDateForExport(string $value): string
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', trim($value));

        return $date !== false ? $date->format('d/m/Y') : '';
    }

    /**
     * @param mixed $value
     */
    private function formatMoneyForExport($value): string
    {
        return number_format((float) $value, 2, ',', '');
    }

    /**
     * @param array<string, string> $filters
     * @return array<string, mixed>
     */
    private function emptyReport(array $filters, string $message): array
    {
        return [
            'table_available' => false,
            'schema_message' => $message,
            'filters' => $filters,
            'filter_options' => $this->filterOptions(),
            'documents' => [],
            'summary' => [
                'document_count' => 0,
                'client_count' => 0,
                'total_amount' => 0.0,
                'subtotal_amount' => 0.0,
                'stamp_duty_amount' => 0.0,
                'paid_amount' => 0.0,
                'average_amount' => 0.0,
            ],
            'monthly_breakdown' => [],
            'payment_breakdown' => [],
            'document_type_breakdown' => [],
        ];
    }
}
