<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <?php $agendaTitle = (string)($pageTitle ?? 'Agenda'); ?>
    <?php
        helper(['portal', 'session_auth', 'tenant_feature']);
        $agendaCompressedLayoutEnabled = tenant_feature_enabled('agenda_compressed_layout', false);
        $sharedMemoManagementEnabled = !empty($sharedMemoManagementEnabled);
        $agendaConsoleUrl = null;
        $assetVersion = static function (string $relativePath): string {
            $normalizedPath = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, ltrim($relativePath, '/\\'));
            $absolutePath = rtrim(FCPATH, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $normalizedPath;

            if (!is_file($absolutePath)) {
                return '';
            }

            $mtime = @filemtime($absolutePath);
            if (!$mtime) {
                return '';
            }

            return '?v=' . rawurlencode((string) $mtime);
        };
        if (session_has_operational_profile_access()) {
            $agendaConsoleUrl = portal_operational_home_url();
        }
        $memoDoctorOptions = is_array($memoDoctorOptions ?? null) ? $memoDoctorOptions : [];
        $agendaAppointmentBlockLayoutSettings = is_array($agendaAppointmentBlockLayoutSettings ?? null)
            ? $agendaAppointmentBlockLayoutSettings
            : [];
        $agendaHomeBlockOrderSettings = is_array($agendaHomeBlockOrderSettings ?? null) ? $agendaHomeBlockOrderSettings : [];
        $agendaSidebarBlockOrderSettings = is_array($agendaSidebarBlockOrderSettings ?? null) ? $agendaSidebarBlockOrderSettings : [];
        $appointmentDocumentActions = is_array($appointmentDocumentActions ?? null) ? $appointmentDocumentActions : [];
        $appointmentBillingWorkflowEnabled = !empty($appointmentDocumentActions['billing_enabled']);
        $appointmentTsWorkflowEnabled = !empty($appointmentDocumentActions['ts_enabled']);
        $appointmentDocumentWorkflowVisible = $appointmentBillingWorkflowEnabled || $appointmentTsWorkflowEnabled;
        $personalCommitmentsFeatureEnabled = !empty($personalCommitmentsFeatureEnabled);
        $customAppointmentTimeFeatureEnabled = !empty($customAppointmentTimeFeatureEnabled);
        $teamDaySingleSlotHeightFeatureEnabled = !empty($teamDaySingleSlotHeightFeatureEnabled);
        $teamDayCompactSlotDetailsFeatureEnabled = !empty($teamDayCompactSlotDetailsFeatureEnabled);
        $appointmentModalFallbackLayoutItems = [
            ['key' => 'time'],
        ];
        if (!empty($visitTypesFeatureEnabled)) {
            $appointmentModalFallbackLayoutItems[] = ['key' => 'visit_type'];
        }
        $appointmentModalFallbackLayoutItems[] = ['key' => 'patient_entry'];
        if ($appointmentDocumentWorkflowVisible) {
            $appointmentModalFallbackLayoutItems[] = ['key' => 'document_workflow'];
        }
        $appointmentModalEffectiveLayoutItems = [];
        foreach ((array) ($agendaAppointmentBlockLayoutSettings['effective_render_items'] ?? []) as $layoutItem) {
            if (!is_array($layoutItem)) {
                continue;
            }

            $blockKey = trim((string) ($layoutItem['key'] ?? ''));
            if ($blockKey === '') {
                continue;
            }

            $appointmentModalEffectiveLayoutItems[] = [
                'key' => $blockKey,
            ];
        }
        if ($appointmentModalEffectiveLayoutItems === []) {
            $appointmentModalEffectiveLayoutItems = $appointmentModalFallbackLayoutItems;
        }
        $memoDoctorSelectOptions = [];
        $agendaHomeFallbackLayoutItems = [
            ['key' => 'doctor_selector', 'column' => 'main'],
            ['key' => 'patient_search', 'column' => 'main'],
            ['key' => 'day_note', 'column' => 'main'],
            ['key' => 'calendar', 'column' => 'main'],
            ['key' => 'memo', 'column' => 'main'],
        ];
        $agendaHomeEffectiveLayoutItems = [];
        $agendaHomeEffectivePlacementMap = [];
        foreach ((array) ($agendaHomeBlockOrderSettings['effective_layout_items'] ?? []) as $layoutItem) {
            if (!is_array($layoutItem)) {
                continue;
            }

            $blockKey = trim((string) ($layoutItem['key'] ?? ''));
            if ($blockKey === '') {
                continue;
            }

            $columnKey = strtolower(trim((string) ($layoutItem['column'] ?? 'main')));
            if (!in_array($columnKey, ['main', 'left', 'hidden'], true)) {
                $columnKey = 'main';
            }

            $agendaHomeEffectiveLayoutItems[] = [
                'key' => $blockKey,
                'column' => $columnKey,
            ];
            $agendaHomeEffectivePlacementMap[$blockKey] = $columnKey;
        }

        if ($agendaHomeEffectiveLayoutItems === []) {
            $agendaHomeEffectiveLayoutItems = $agendaHomeFallbackLayoutItems;
            foreach ($agendaHomeFallbackLayoutItems as $layoutItem) {
                $agendaHomeEffectivePlacementMap[(string) ($layoutItem['key'] ?? '')] = (string) ($layoutItem['column'] ?? 'main');
            }
        }

        $agendaHomeLayoutMetaJson = json_encode($agendaHomeEffectiveLayoutItems, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($agendaHomeLayoutMetaJson) || $agendaHomeLayoutMetaJson === '') {
            $agendaHomeLayoutMetaJson = '[]';
        }

        $agendaSidebarFallbackOrderKeys = [
            'sidebar_menu',
            'sidebar_calendar',
            'doctor_selector',
            'patient_search',
            'day_note',
            'calendar',
            'memo',
        ];
        $agendaSidebarEffectiveOrderKeys = array_values(array_filter(array_map(
            static fn($value): string => trim((string) $value),
            (array) ($agendaSidebarBlockOrderSettings['effective_order_keys'] ?? [])
        ), static fn(string $value): bool => $value !== ''));
        if ($agendaSidebarEffectiveOrderKeys === []) {
            $agendaSidebarEffectiveOrderKeys = $agendaSidebarFallbackOrderKeys;
        }
        $agendaSidebarOrderMetaJson = json_encode($agendaSidebarEffectiveOrderKeys, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($agendaSidebarOrderMetaJson) || $agendaSidebarOrderMetaJson === '') {
            $agendaSidebarOrderMetaJson = '[]';
        }

        $agendaHomeLeftColumnHasBlocks = false;
        $agendaHomeMainColumnHasBlocks = false;
        foreach ($agendaHomeEffectiveLayoutItems as $layoutItem) {
            $layoutColumn = (string) ($layoutItem['column'] ?? 'main');
            if ($layoutColumn === 'hidden') {
                continue;
            }

            if ($layoutColumn === 'left') {
                $agendaHomeLeftColumnHasBlocks = true;
                continue;
            }

            $agendaHomeMainColumnHasBlocks = true;
        }
        $agendaHomeHasVisibleBlocks = $agendaHomeLeftColumnHasBlocks || $agendaHomeMainColumnHasBlocks;

        $agendaLayoutClasses = ['row', 'agenda-layout'];
        if ($agendaCompressedLayoutEnabled) {
            $agendaLayoutClasses[] = 'is-sidebar-collapsed';
        }
        if ($agendaHomeLeftColumnHasBlocks) {
            $agendaLayoutClasses[] = 'has-sidebar-custom-blocks';
        }

        $agendaHomeBlockPlacement = static function (string $blockKey) use ($agendaHomeEffectivePlacementMap): string {
            $placement = strtolower(trim((string) ($agendaHomeEffectivePlacementMap[$blockKey] ?? 'main')));
            return in_array($placement, ['main', 'left', 'hidden'], true) ? $placement : 'main';
        };
        $agendaHomeBlockHiddenStyle = static function (string $blockKey) use ($agendaHomeBlockPlacement): string {
            return $agendaHomeBlockPlacement($blockKey) === 'hidden' ? 'display:none;' : '';
        };

        foreach ($memoDoctorOptions as $doctorOption) {
            $doctorRow = is_object($doctorOption) ? get_object_vars($doctorOption) : (array) $doctorOption;
            $doctorId = (int) ($doctorRow['id_dot'] ?? 0);
            if ($doctorId <= 0) {
                continue;
            }

            $doctorLabel = trim((string) ($doctorRow['label'] ?? ''));
            if ($doctorLabel === '') {
                $doctorLabel = trim((string) ($doctorRow['cognome'] ?? '') . ' ' . (string) ($doctorRow['nome'] ?? ''));
            }

            $memoDoctorSelectOptions[] = [
                'id_dot' => $doctorId,
                'label' => $doctorLabel !== '' ? $doctorLabel : ('Dottore #' . $doctorId),
            ];
        }
        $agendaTextThemeCssVars = is_array($agendaTextThemeCssVars ?? null) ? $agendaTextThemeCssVars : [];
        $agendaTextThemeInlineStyle = '';
        foreach ($agendaTextThemeCssVars as $cssVarName => $cssVarValue) {
            $cssVarName = trim((string) $cssVarName);
            $cssVarValue = trim((string) $cssVarValue);
            if ($cssVarName === '' || $cssVarValue === '') {
                continue;
            }

            $agendaTextThemeInlineStyle .= $cssVarName . ':' . $cssVarValue . ';';
        }
    ?>
    <title><?= esc($agendaTitle . (' | AmbulatorioFacile')) ?></title>
    <meta content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no" name="viewport">
<link href="<?= base_url('public/css/agenda-menu.css') . $assetVersion('public/css/agenda-menu.css') ?>" rel="stylesheet" type="text/css" />
    <link href="<?= base_url('public/bootstrap/css/bootstrap.min.css') . $assetVersion('public/bootstrap/css/bootstrap.min.css') ?>" rel="stylesheet" type="text/css" />
    <link href="<?= base_url('public/assets/fontawesome/css/all.min.css') . $assetVersion('public/assets/fontawesome/css/all.min.css') ?>" rel="stylesheet" type="text/css" />
    <link href="<?= base_url('public/assets/fontawesome/css/v4-shims.min.css') . $assetVersion('public/assets/fontawesome/css/v4-shims.min.css') ?>" rel="stylesheet" type="text/css" />
    <link href="<?= base_url('public/assets/fontawesome/css/v4-font-face.min.css') . $assetVersion('public/assets/fontawesome/css/v4-font-face.min.css') ?>" rel="stylesheet" type="text/css" />
    <link href="<?= base_url('public/assets/css/ionicons.min.css') . $assetVersion('public/assets/css/ionicons.min.css') ?>" rel="stylesheet" type="text/css" />

    <link href="<?= base_url('public/plugins/fullcalendar/fullcalendar.min.css') . $assetVersion('public/plugins/fullcalendar/fullcalendar.min.css') ?>" rel="stylesheet" type="text/css" />
    <link href="<?= base_url('public/plugins/fullcalendar/fullcalendar.print.css') . $assetVersion('public/plugins/fullcalendar/fullcalendar.print.css') ?>" rel="stylesheet" type="text/css" media="print" />

    <link href="<?= base_url('public/dist/css/AdminLTE.css') . $assetVersion('public/dist/css/AdminLTE.css') ?>" rel="stylesheet" type="text/css" />
    <link href="<?= base_url('public/dist/css/skins/_all-skins.min.css') . $assetVersion('public/dist/css/skins/_all-skins.min.css') ?>" rel="stylesheet" type="text/css" />
    <link href="<?= base_url('public/plugins/iCheck/flat/blue.css') . $assetVersion('public/plugins/iCheck/flat/blue.css') ?>" rel="stylesheet" type="text/css" />

    <link href="<?= base_url('public/css/agenda.css') . $assetVersion('public/css/agenda.css') ?>" rel="stylesheet" type="text/css" />

    <style>
   
        #nota_giorno_text {
    resize: vertical;
    min-height: 85px;
    font-size: 18px;
    line-height: 1.5;
}

#nota_giorno_text[disabled] {
    background: #f5f5f5;
    cursor: not-allowed;
}

#nota_giorno_status {
    display: inline-block;
    min-height: 16px;
}
        .appointment-document-workflow-panel {
            display: block;
            padding: 14px 16px;
            border: 1px solid #d8e5ec;
            border-radius: 18px;
            background: linear-gradient(135deg, #f8fbfc 0%, #eef5f8 100%);
            margin-top: 4px;
        }

        .appointment-document-workflow-copy {
            margin-bottom: 14px;
        }

        .appointment-document-workflow-title {
            font-size: 14px;
            font-weight: 700;
            color: #234357;
            margin-bottom: 4px;
        }

        .appointment-document-workflow-note {
            color: #647987;
            font-size: 12px;
            line-height: 1.5;
        }

        .appointment-document-workflow-buttons {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px;
        }

        .appointment-document-workflow-action {
            display: flex !important;
            align-items: flex-start;
            gap: 12px;
            width: 100%;
            min-height: 92px;
            padding: 16px 18px !important;
            border-radius: 18px !important;
            border: 1px solid transparent !important;
            text-align: left;
            box-shadow: 0 10px 24px rgba(35, 67, 87, 0.08);
            transition: transform 0.15s ease, box-shadow 0.15s ease, opacity 0.15s ease;
        }

        .appointment-document-workflow-action:hover,
        .appointment-document-workflow-action:focus {
            transform: translateY(-1px);
            box-shadow: 0 14px 28px rgba(35, 67, 87, 0.14);
        }

        .appointment-document-workflow-action:disabled {
            opacity: 0.7;
            box-shadow: none;
            transform: none;
        }

        .appointment-document-workflow-action-icon {
            width: 42px;
            height: 42px;
            border-radius: 14px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex: 0 0 42px;
            background: rgba(255, 255, 255, 0.22);
            font-size: 18px;
        }

        .appointment-document-workflow-action-copy {
            display: block;
            min-width: 0;
            white-space: normal;
        }

        .appointment-document-workflow-action-title {
            display: block;
            font-size: 16px;
            font-weight: 700;
            line-height: 1.25;
        }

        .appointment-document-workflow-action-text {
            display: block;
            margin-top: 4px;
            font-size: 12px;
            line-height: 1.5;
            opacity: 0.92;
        }

        .appointment-document-workflow-action-billing {
            background: linear-gradient(135deg, #14a85a 0%, #0f8a4a 100%);
            border-color: #10884a !important;
            color: #fff !important;
        }

        .appointment-document-workflow-action-ts {
            background: linear-gradient(135deg, #f6ad1a 0%, #ea8f04 100%);
            border-color: #da8504 !important;
            color: #fff !important;
        }

        .appointment-modal-layout {
            display: flex;
            flex-direction: column;
            gap: 18px;
        }

        .appointment-modal-block {
            position: relative;
        }

        .appointment-modal-block + .appointment-modal-block {
            padding-top: 18px;
            border-top: 1px solid #edf3f7;
        }

        .appointment-mode-switch .btn {
            min-width: 170px;
            border-radius: 12px !important;
        }

        .appointment-mode-switch .btn.is-active[data-appointment-mode="standard"],
        .appointment-mode-switch .btn.active[data-appointment-mode="standard"] {
            background: #3c8dbc;
            border-color: #2f74a0;
            color: #fff;
        }

        .appointment-mode-switch .btn.is-active[data-appointment-mode="personal_commitment"],
        .appointment-mode-switch .btn.active[data-appointment-mode="personal_commitment"] {
            background: #c9302c;
            border-color: #b52b27;
            color: #fff;
        }

        .appointment-personal-commitment-note {
            padding: 12px 14px;
            border: 1px solid #f2c7c5;
            border-radius: 14px;
            background: linear-gradient(135deg, #fff6f5 0%, #fdeceb 100%);
            color: #8a302d;
            line-height: 1.5;
            margin: 0 0 14px;
        }

        .appointment-personal-commitment-note strong {
            display: block;
            margin-bottom: 4px;
            color: #7f2220;
        }

        .appointment-custom-time-panel {
            margin-top: 2px;
            padding: 14px;
            border: 1px solid #d8e7f0;
            border-radius: 14px;
            background: linear-gradient(135deg, #f7fcff 0%, #edf7fc 100%);
        }

        .appointment-custom-time-toggle {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 16px;
        }

        .appointment-custom-time-toggle .checkbox {
            margin: 0;
        }

        .appointment-custom-time-toggle .help-block {
            margin: 4px 0 0;
        }

        .appointment-custom-time-fields {
            margin-top: 14px;
            padding-top: 14px;
            border-top: 1px solid #d8e7f0;
        }

        .appointment-custom-time-summary {
            margin-top: 2px;
            padding: 10px 12px;
            border-radius: 10px;
            background: #fff;
        }

        .appointment-modal-footer {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
            flex-wrap: wrap;
        }

        .appointment-modal-footer-left,
        .appointment-modal-footer-right {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }

        .appointment-modal-footer-right {
            margin-left: auto;
        }

        .appointment-modal-footer .btn {
            border-radius: 14px;
            font-weight: 700;
            padding: 10px 18px;
        }

        .appointment-modal-footer .btn .fa {
            margin-right: 6px;
        }

        #btnDeleteAppointment,
        #btnDeleteExtraSlot {
            min-width: 200px;
        }

        #btnAppointmentBillingWorkflow,
        #btnAppointmentTsWorkflow {
            min-width: 0;
        }

        #btnCancelAppointmentModal {
            min-width: 120px;
            background: #f8f8f8;
            border-color: #d6dbe1;
            color: #435466;
        }

        #btnSaveAppointment {
            min-width: 170px;
        }

        #appointmentDocumentWorkflowHint {
            max-width: 520px;
            margin-top: 8px;
            color: #667683;
            font-size: 12px;
            line-height: 1.5;
        }

        @media (max-width: 991px) {
            .appointment-modal-footer {
                align-items: stretch;
            }

            .appointment-document-workflow-buttons,
            .appointment-modal-footer-left,
            .appointment-modal-footer-right {
                width: 100%;
                margin-left: 0;
            }

            .appointment-document-workflow-buttons {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 767px) {
            .appointment-custom-time-toggle {
                display: block;
            }

            .appointment-custom-time-fields .form-group {
                margin-bottom: 12px;
            }

            .appointment-modal-footer-left .btn,
            .appointment-modal-footer-right .btn {
                width: 100%;
            }

            .appointment-document-workflow-action {
                min-height: 0;
            }
        }
        .agenda-calendar-shell {
            position: relative;
            min-height: 620px;
        }

        .agenda-calendar-shell.agenda-no-slots-shell {
            min-height: 0;
        }

        .agenda-calendar-shell.agenda-no-slots-shell #calendar {
            min-height: 0;
        }

        .agenda-calendar-shell.is-loading #calendar {
            opacity: 0.35;
            transition: opacity .16s ease;
            pointer-events: none;
        }

        .agenda-calendar-loading {
            position: absolute;
            top: 0;
            right: 0;
            bottom: 0;
            left: 0;
            z-index: 30;
            display: none;
            align-items: center;
            justify-content: center;
            background: rgba(255, 255, 255, 0.9);
            padding: 24px;
        }

        .agenda-calendar-shell.is-loading .agenda-calendar-loading {
            display: flex;
        }

        .agenda-calendar-loading-box {
            min-width: 250px;
            max-width: 360px;
            padding: 18px 20px;
            border: 1px solid #d9edf7;
            border-radius: 10px;
            background: #fff;
            box-shadow: 0 12px 28px rgba(60, 141, 188, 0.16);
            text-align: center;
        }

        .agenda-calendar-loading-box .fa {
            margin-bottom: 10px;
            color: #3c8dbc;
            font-size: 24px;
        }

        .agenda-calendar-loading-title {
            color: #1f2d3d;
            font-size: 16px;
            font-weight: 700;
        }

        .agenda-calendar-loading-note {
            margin-top: 6px;
            color: #5f6b77;
            font-size: 13px;
            line-height: 1.4;
        }
        .agenda-day-locked {
            position: relative;
        }

        .agenda-day-locked .fc-event,
        .agenda-day-locked .fc-time-grid-event,
        .agenda-day-locked .fc-v-event {
            background: #d9534f !important;
            border-color: #d9534f !important;
            color: #fff !important;
            cursor: not-allowed !important;
        }

        .agenda-day-locked .fc-slats,
        .agenda-day-locked .fc-content-skeleton,
        .agenda-day-locked .fc-time-grid,
        .agenda-day-locked .fc-day-grid {
            pointer-events: none;
            opacity: 0.95;
        }

        .agenda-day-locked::after {
            content: 'GIORNATA BLOCCATA';
            position: absolute;
            top: 15px;
            right: 15px;
            z-index: 50;
            background: #d9534f;
            color: #fff;
            padding: 8px 14px;
            font-weight: bold;
            border-radius: 4px;
        }

        .agenda-toolbar {
            padding: 10px;
        }

        .agenda-toolbar .form-group {
            margin-bottom: 10px;
        }

        .agenda-toolbar label {
            font-size: 12px;
        }

        .agenda-toolbar .form-control {
            font-size: 12px;
            padding: 6px 8px;
        }

        .agenda-toolbar .btn-block {
            white-space: normal;
        }

        .agenda-mini-calendar {
            margin-bottom: 14px;
            border: 1px solid #d2d6de;
            border-radius: 8px;
            background: #fff;
            overflow: hidden;
            box-shadow: 0 1px 2px rgba(0, 0, 0, 0.04);
        }

        .agenda-mini-calendar-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 10px 10px 8px;
            background: #f8fafc;
            border-bottom: 1px solid #eef2f6;
        }

        .agenda-mini-calendar-title {
            font-size: 13px;
            font-weight: 700;
            color: #2c3e50;
            text-transform: capitalize;
        }

        .agenda-mini-calendar-nav {
            width: 28px;
            height: 28px;
            padding: 0;
            border: 0;
            border-radius: 50%;
            background: #eaf1f7;
            color: #3c8dbc;
            line-height: 28px;
            text-align: center;
        }

        .agenda-mini-calendar-nav:hover,
        .agenda-mini-calendar-nav:focus {
            background: #dbe9f4;
            color: #2f79a8;
            outline: none;
        }

        .agenda-mini-calendar-weekdays,
        .agenda-mini-calendar-grid {
            display: grid;
            grid-template-columns: repeat(7, minmax(0, 1fr));
        }

        .agenda-mini-calendar-weekdays {
            padding: 8px 8px 0;
            gap: 4px;
        }

        .agenda-mini-calendar-weekday {
            text-align: center;
            font-size: 10px;
            font-weight: 700;
            color: #7b8a97;
            text-transform: uppercase;
        }

        .agenda-mini-calendar-grid {
            padding: 6px 8px 8px;
            gap: 4px;
        }

        .agenda-mini-calendar-day {
            min-height: 42px;
            padding: 6px 4px 4px;
            border: 1px solid transparent;
            border-radius: 8px;
            background: transparent;
            color: #334155;
            text-align: center;
            transition: background-color .15s ease, border-color .15s ease, color .15s ease, transform .15s ease;
        }

        .agenda-mini-calendar-day:hover,
        .agenda-mini-calendar-day:focus {
            background: #f1f7fb;
            border-color: #c8dceb;
            color: #1f2d3d;
            outline: none;
            transform: translateY(-1px);
        }

        .agenda-mini-calendar-day.is-selected {
            background: #3c8dbc;
            border-color: #2f79a8;
            color: #fff;
            box-shadow: 0 6px 14px rgba(60, 141, 188, 0.18);
        }

        .agenda-mini-calendar-day.is-today:not(.is-selected) {
            border-color: #9fc4dd;
            background: #f7fbfe;
        }

        .agenda-mini-calendar-day.is-outside {
            color: #a8b3bd;
        }

        .agenda-mini-calendar-day-number {
            display: block;
            font-size: 13px;
            font-weight: 600;
            line-height: 1.1;
        }

        .agenda-mini-calendar-day-dot-wrap {
            display: block;
            min-height: 10px;
            margin-top: 5px;
            line-height: 1;
        }

        .agenda-mini-calendar-day-dot {
            display: inline-block;
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: #31b057;
            box-shadow: 0 0 0 2px rgba(49, 176, 87, 0.15);
        }

        .agenda-mini-calendar-day.is-selected .agenda-mini-calendar-day-dot {
            background: #ffffff;
            box-shadow: 0 0 0 2px rgba(255, 255, 255, 0.28);
        }

        .agenda-mini-calendar-legend {
            padding: 0 10px 10px;
            font-size: 11px;
            color: #6b7785;
        }

        .agenda-mini-calendar-legend .agenda-mini-calendar-day-dot {
            margin-right: 6px;
            vertical-align: middle;
        }

        .agenda-mini-calendar-status {
            padding: 0 10px 10px;
            font-size: 11px;
            color: #7b8a97;
        }

        .agenda-autocomplete,
        .agenda-autocomplete-vd {
            position: absolute;
            left: 15px;
            right: 15px;
            top: calc(100% - 2px);
            z-index: 2000;
            background: #fff;
            border: 1px solid #d2d6de;
            border-radius: 4px;
            max-height: 240px;
            overflow-y: auto;
            box-shadow: 0 6px 16px rgba(0,0,0,.12);
        }

        .agenda-autocomplete-item,
        .agenda-autocomplete-vd-item,
        .agenda-patient-search-item {
            padding: 10px 12px;
            cursor: pointer;
            border-bottom: 1px solid #f4f4f4;
        }

        .agenda-autocomplete-item:hover,
        .agenda-autocomplete-vd-item:hover,
        .agenda-patient-search-item:hover {
            background: #f9fafc;
        }

        .agenda-autocomplete-item.is-special,
        .agenda-autocomplete-vd-item.is-special,
        .agenda-patient-search-item.is-special {
            background: #eef9f1;
            border-left: 4px solid #2e8b57;
        }

        .agenda-autocomplete-item.is-special:hover,
        .agenda-autocomplete-vd-item.is-special:hover,
        .agenda-patient-search-item.is-special:hover {
            background: #e3f4e8;
        }

        .agenda-autocomplete-item.is-special strong,
        .agenda-autocomplete-vd-item.is-special strong,
        .agenda-patient-search-item.is-special strong {
            color: #247149;
        }

        .agenda-patient-search-wrap {
            position: relative;
        }

        .agenda-patient-search-wrap .agenda-autocomplete {
            left: 0;
            right: 0;
            top: calc(100% - 1px);
        }

        .agenda-patient-lookup-box {
            margin-bottom: 15px;
        }

        .agenda-patient-selected-summary {
            margin-top: 8px;
            padding: 10px 12px;
            border-radius: 6px;
            background: #f4f8fb;
            color: #2c3e50;
            font-size: 13px;
            line-height: 1.45;
        }

        .agenda-patient-history-panel {
            min-height: 188px;
            padding: 12px;
            border: 1px solid #dbe6ef;
            border-radius: 10px;
            background: linear-gradient(180deg, #fbfdff 0%, #f5f9fc 100%);
        }

        .agenda-visit-types-box .help-block {
            margin-bottom: 12px;
        }

        .agenda-visit-type-row {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 10px;
            padding: 10px 0;
            border-bottom: 1px solid #eef3f7;
        }

        .agenda-visit-type-row:last-child {
            border-bottom: 0;
        }

        .agenda-visit-type-title {
            font-weight: 700;
            color: #1f2d3d;
        }

        .agenda-visit-type-title-row {
            display: flex;
            align-items: center;
            gap: 8px;
            min-width: 0;
        }

        .agenda-visit-type-color {
            width: 14px;
            height: 14px;
            flex: 0 0 14px;
            border-radius: 999px;
            border: 1px solid rgba(31, 45, 61, 0.14);
            box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.28);
        }

        .agenda-visit-type-meta {
            margin-top: 4px;
            font-size: 12px;
            color: #6b7785;
        }

        .agenda-visit-type-actions .btn {
            margin-left: 4px;
        }

        .agenda-visit-type-empty {
            padding: 10px 0;
            color: #7b8a97;
            font-size: 13px;
        }

        .agenda-visit-type-color-picker {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .agenda-visit-type-color-palette {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(34px, 1fr));
            gap: 8px;
        }

        .agenda-visit-type-color-swatch {
            height: 34px;
            border: 1px solid #d7e4ea;
            border-radius: 10px;
            background: var(--visit-type-color, #3C8DBC);
            box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.24);
            cursor: pointer;
            transition: transform .14s ease, box-shadow .14s ease, border-color .14s ease;
        }

        .agenda-visit-type-color-swatch:hover,
        .agenda-visit-type-color-swatch:focus {
            transform: translateY(-1px);
            border-color: #9fb8c6;
            box-shadow: 0 8px 18px rgba(31, 45, 61, 0.12);
            outline: none;
        }

        .agenda-visit-type-color-swatch.is-selected {
            border-color: #1f2d3d;
            box-shadow: 0 0 0 3px rgba(31, 45, 61, 0.16), 0 10px 22px rgba(31, 45, 61, 0.12);
        }

        .agenda-visit-type-color-footer {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            flex-wrap: wrap;
        }

        .agenda-visit-type-color-current {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            font-size: 12px;
            font-weight: 600;
            color: #455b63;
        }

        .agenda-visit-type-color-current-sample {
            width: 18px;
            height: 18px;
            border-radius: 999px;
            border: 1px solid rgba(31, 45, 61, 0.14);
            box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.32);
        }

        .agenda-visit-type-color-custom {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-left: auto;
        }

        .agenda-visit-type-color-custom-label {
            color: #6a7c82;
            font-size: 12px;
            font-weight: 600;
        }

        .agenda-visit-type-color-native {
            width: 42px;
            height: 30px;
            padding: 0;
            border: 1px solid #d7e4ea;
            border-radius: 9px;
            background: #fff;
            cursor: pointer;
        }

        .agenda-appointment-coverage {
            min-height: 18px;
            font-size: 12px;
            color: #5f6b77;
        }

        .agenda-appointment-coverage.is-error {
            color: #c0392b;
            font-weight: 600;
        }

        .agenda-appointment-coverage.is-ok {
            color: #247149;
        }

        .agenda-visit-type-select-preview {
            display: flex;
            align-items: center;
            gap: 10px;
            min-height: 42px;
            margin-top: 8px;
            padding: 8px 10px;
            border: 1px solid #dbe7ef;
            border-radius: 10px;
            background: linear-gradient(180deg, #fbfdff 0%, #f5f9fc 100%);
            color: #405463;
        }

        .agenda-visit-type-select-preview.is-empty {
            color: #7b8a97;
        }

        .agenda-visit-type-select-sample {
            width: 16px;
            height: 16px;
            flex: 0 0 16px;
            border-radius: 999px;
            border: 1px solid rgba(31, 45, 61, 0.14);
            box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.32);
            background: #dbe7ef;
        }

        .agenda-visit-type-select-copy {
            min-width: 0;
        }

        .agenda-visit-type-select-label {
            display: block;
            font-size: 13px;
            font-weight: 700;
            color: inherit;
        }

        .agenda-visit-type-select-meta {
            display: block;
            margin-top: 2px;
            font-size: 12px;
            color: inherit;
            opacity: 0.92;
        }

        .agenda-patient-history-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            margin-bottom: 10px;
            color: #1f2d3d;
        }

        .agenda-patient-history-list {
            max-height: 280px;
            overflow-y: auto;
            padding-right: 4px;
        }

        .agenda-patient-history-empty {
            padding: 14px 16px;
            border: 1px dashed #cbd9e5;
            border-radius: 8px;
            background: #fff;
            color: #6b7b88;
            line-height: 1.5;
        }

        .agenda-patient-history-group {
            margin-bottom: 14px;
        }

        .agenda-patient-history-group:last-child {
            margin-bottom: 0;
        }

        .agenda-patient-history-group-title {
            margin: 0 0 8px;
            color: #4f6474;
            font-size: 12px;
            font-weight: 700;
            letter-spacing: .04em;
            text-transform: uppercase;
        }

        .agenda-patient-history-item {
            display: block;
            width: 100%;
            margin-bottom: 10px;
            padding: 11px 12px;
            border: 1px solid #d7e4ef;
            border-left: 4px solid #3c8dbc;
            border-radius: 8px;
            background: #fff;
            color: #1f2d3d;
            text-align: left;
            transition: box-shadow .14s ease, transform .14s ease, border-color .14s ease;
        }

        .agenda-patient-history-item:last-child {
            margin-bottom: 0;
        }

        .agenda-patient-history-item:hover,
        .agenda-patient-history-item:focus {
            border-color: #9fc4df;
            box-shadow: 0 10px 22px rgba(60, 141, 188, 0.12);
            transform: translateY(-1px);
            outline: none;
        }

        .agenda-patient-history-item.is-future {
            border-left-color: #00a65a;
        }

        .agenda-patient-history-item.is-past {
            border-left-color: #7f8c8d;
        }

        .agenda-patient-history-item.is-today {
            border-left-color: #3c8dbc;
        }

        .agenda-patient-history-item.is-selected {
            box-shadow: 0 0 0 2px rgba(60, 141, 188, 0.18);
        }

        .agenda-patient-history-topline {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 4px;
        }

        .agenda-patient-history-date {
            font-weight: 700;
            color: #243746;
        }

        .agenda-patient-history-badge {
            display: inline-block;
            min-width: 68px;
            padding: 2px 8px;
            border-radius: 999px;
            background: #d9edf7;
            color: #25608b;
            font-size: 11px;
            font-weight: 700;
            text-align: center;
            text-transform: uppercase;
        }

        .agenda-patient-history-item.is-future .agenda-patient-history-badge {
            background: #dff0d8;
            color: #2b7d3f;
        }

        .agenda-patient-history-item.is-past .agenda-patient-history-badge {
            background: #eceff1;
            color: #56636d;
        }

        .agenda-patient-history-text {
            color: #4d6272;
            line-height: 1.45;
            white-space: normal;
        }

        .agenda-note-text {
            white-space: normal;
        }

        .agenda-domiciliari-table td,
        .agenda-note-table td {
            vertical-align: middle !important;
        }

        .agenda-domiciliari-table td {
            white-space: normal;
        }

        .agenda-domiciliari-day-locked {
            border-top-color: #d9534f !important;
            box-shadow: 0 0 0 1px rgba(217, 83, 79, 0.14), 0 8px 22px rgba(217, 83, 79, 0.10);
        }

        .agenda-domiciliari-day-locked > .box-header {
            background: linear-gradient(135deg, #fbe4e4 0%, #f7d2d2 100%);
            color: #a12622;
        }

        .agenda-domiciliari-day-locked > .box-header .box-title,
        .agenda-domiciliari-day-locked > .box-header .btn-box-tool {
            color: #a12622;
        }

        .agenda-domiciliari-day-locked > .box-body,
        .agenda-domiciliari-day-locked > .box-footer {
            background: #fff7f7;
        }

        .agenda-domiciliari-day-locked .agenda-domiciliari-table thead {
            background: #fff0f0;
        }

        .agenda-domiciliari-lock-notice {
            display: none;
            margin-bottom: 8px;
            padding: 10px 12px;
            border: 1px solid #f0b4b2;
            border-radius: 6px;
            background: #fdeeee;
            color: #a12622;
            font-size: 12px;
            font-weight: 600;
            text-align: left;
        }

        .fc-time-grid .fc-slats td,
        .fc-time-grid .fc-slats .fc-minor td,
        .fc-agenda-slots td,
        .fc-time-grid .fc-slats td > div {
            height: 45px !important;
        }

        .fc-time-grid-event {
            font-size: 13px;
            padding: 4px;
            border-radius: 4px;
            white-space: normal !important;
        }

        .fc-time-grid-event.evento-extra-libero {
            border: 1px dashed #1e7e34;
        }

        .fc-time-grid-event .fc-title {
            white-space: normal !important;
            line-height: 1.25;
        }

        /* Native FullCalendar event boxes are replaced by our custom slot layer. */
        #calendar .fc-time-grid .fc-event-container,
        #calendar .fc-time-grid .fc-helper-container,
        #calendar .fc-time-grid .fc-time-grid-event,
        #calendar .fc-time-grid .fc-v-event {
            display: none !important;
        }

        .fc-time-grid .fc-slats tr:hover td {
            background: inherit;
            cursor: default;
        }

        #calendar .fc-time-grid .fc-slats .fc-axis,
        #calendar .fc-time-grid .fc-content-skeleton .fc-axis,
        #calendar .fc-time-grid .fc-helper-skeleton .fc-axis {
            width: 84px !important;
            min-width: 84px !important;
            max-width: 84px !important;
        }

        #calendar .fc-time-grid col.fc-axis,
        #calendar .fc-time-grid .fc-slats col:first-child,
        #calendar .fc-time-grid .fc-content-skeleton col:first-child,
        #calendar .fc-time-grid .fc-helper-skeleton col:first-child {
            width: 84px !important;
        }

        #calendar .fc-agendaWeek-view .fc-head-container th.fc-axis.fc-widget-header,
        #calendar .fc-agendaWeek-view .fc-head-container col.fc-axis {
            width: 84px !important;
            min-width: 84px !important;
            max-width: 84px !important;
        }

        #calendar .fc-time-grid .fc-slats .fc-axis {
            position: relative;
            overflow: visible;
            color: transparent !important;
            font-size: 0 !important;
        }

        #calendar .fc-time-grid .fc-slats .fc-axis span {
            display: none !important;
        }

        #calendar .fc-time-grid {
            position: relative;
            overflow: visible;
        }

        #calendar .fc-time-grid-container,
        #calendar .fc-scroller {
            overflow-x: hidden !important;
        }

        .agenda-slot-layer {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            z-index: 2;
            pointer-events: auto;
            background: #fff;
        }

        .agenda-slot-layer.is-locked {
            pointer-events: auto;
        }

        .agenda-slot-layer .fc-bg,
        .agenda-slot-layer .fc-slats,
        .agenda-slot-layer .fc-content-skeleton,
        .agenda-slot-layer .fc-helper-skeleton {
            display: none;
        }

        .agenda-grid-hour-line {
            position: absolute;
            left: 0;
            right: 0;
            border-top: 1px solid #e6edf3;
        }

        .agenda-axis-overlay {
            position: absolute;
            top: 0;
            left: 0;
            bottom: 0;
            box-sizing: border-box;
            width: 84px;
            background: #fff;
            border-right: 1px solid #e6edf3;
            z-index: 4;
            pointer-events: none;
        }

        .agenda-grid-axis-label {
            position: absolute;
            right: 6px;
            width: 70px;
            color: #536574;
            font-size: 14px;
            font-weight: 700;
            line-height: 22px;
            text-align: right;
            background: transparent;
            z-index: 5;
        }

        .agenda-grid-axis-label.is-collapsed-gap {
            font-size: 12px;
            letter-spacing: -0.2px;
        }

        .agenda-grid-day-column {
            position: absolute;
            top: 0;
            bottom: 0;
            box-sizing: border-box;
            border-left: 1px solid #e6edf3;
        }

        .agenda-grid-day-column:last-child {
            border-right: 1px solid #e6edf3;
        }

        .agenda-slot-hitbox {
            position: absolute;
            display: block;
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            border: 0;
            border-radius: 0;
            background: transparent;
            cursor: pointer;
            outline: none;
            z-index: 2;
            transition: background .12s ease, box-shadow .12s ease;
        }

        .agenda-slot-hitbox:hover,
        .agenda-slot-hitbox:focus {
            background: rgba(60, 141, 188, 0.08);
            box-shadow: inset 0 0 0 1px rgba(60, 141, 188, 0.18);
        }

        .agenda-slot-hitbox.is-extra:hover,
        .agenda-slot-hitbox.is-extra:focus {
            background: rgba(30, 126, 52, 0.08);
            box-shadow: inset 0 0 0 1px rgba(30, 126, 52, 0.22);
        }

        .agenda-custom-slot {
            position: absolute;
            display: flex;
            box-sizing: border-box;
            flex-direction: column;
            align-items: flex-start;
            justify-content: flex-start;
            padding: 2px 4px;
            overflow: hidden;
            border: 1px solid #d7e1ea;
            border-radius: 4px;
            background: #fefefe;
            box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.24);
            color: #1f2d3d;
            cursor: pointer;
            outline: none;
            z-index: 3;
            transition: background .12s ease, border-color .12s ease, box-shadow .12s ease, transform .12s ease;
        }

        .agenda-custom-slot.has-print-action {
            padding-right: 44px;
        }

        .agenda-custom-slot:hover,
        .agenda-custom-slot:focus {
            background: rgba(60, 141, 188, 0.08);
            border-color: rgba(60, 141, 188, 0.45);
            box-shadow: inset 0 0 0 1px rgba(60, 141, 188, 0.18);
            transform: translateY(-1px);
        }

        .agenda-custom-slot.is-free {
            background: linear-gradient(180deg, #ffffff 0%, #f7fbff 100%);
            border-style: solid;
            color: var(--agenda-free-slot-text, inherit);
        }

        .agenda-custom-slot.is-extra {
            border-style: dashed;
            border-color: rgba(30, 126, 52, 0.42);
        }

        .agenda-custom-slot.is-booked {
            background: #3c8dbc;
            border-color: #2f74a0;
            color: var(--agenda-appointment-text, #fff);
        }

        .agenda-custom-slot.is-booked-spec {
            background: #2e8b57;
            border-color: #247149;
            color: var(--agenda-appointment-text, #fff);
        }

        .agenda-custom-slot.is-personal-commitment {
            background: #c9302c;
            border-color: #a82622;
            color: var(--agenda-appointment-text, #fff);
        }

        .agenda-custom-slot.is-blocked {
            background: #f39c12;
            border-color: #d58512;
            color: var(--agenda-appointment-text, #fff);
        }

        .agenda-custom-slot.is-day-blocked {
            background: #d9534f;
            border-color: #c9302c;
            color: var(--agenda-appointment-text, #fff);
        }

        .agenda-custom-slot.has-visit-type-color {
            background: var(--agenda-slot-bg, #3c8dbc);
            border-color: var(--agenda-slot-border, #2f74a0);
            color: var(--agenda-appointment-text, var(--agenda-slot-text, #fff));
            box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.18);
        }

        .agenda-custom-slot.has-visit-type-color:hover,
        .agenda-custom-slot.has-visit-type-color:focus {
            background: var(--agenda-slot-hover-bg, var(--agenda-slot-bg, #3c8dbc));
            border-color: var(--agenda-slot-hover-border, var(--agenda-slot-border, #2f74a0));
            box-shadow: var(--agenda-slot-hover-shadow, 0 10px 20px rgba(31, 45, 61, 0.16));
            color: var(--agenda-appointment-text, var(--agenda-slot-text, #fff));
        }

        .agenda-custom-slot.is-search-focus {
            border-color: #1f6fa7 !important;
            box-shadow: 0 0 0 3px rgba(60, 141, 188, 0.26), 0 14px 24px rgba(31, 111, 167, 0.18);
            animation: agendaSearchSlotPulse 1.35s ease-in-out 2;
        }

        .agenda-slot-location {
            position: absolute;
            display: flex;
            box-sizing: border-box;
            flex-direction: column;
            align-items: flex-start;
            justify-content: center;
            gap: 2px;
            padding: 4px 6px;
            overflow: hidden;
            border: 1px solid #d7e1ea;
            border-radius: 4px;
            background: linear-gradient(180deg, #ffffff 0%, #f7fbff 100%);
            color: var(--agenda-location-text, #405463);
            box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.22);
            z-index: 4;
            pointer-events: none;
        }

        .agenda-slot-location.is-booked {
            background: #eef6fb;
            border-color: #b8d3e4;
            color: var(--agenda-location-text, #24506c);
        }

        .agenda-slot-location.is-booked-spec {
            background: #edf8f1;
            border-color: #b8dec5;
            color: var(--agenda-location-text, #226745);
        }

        .agenda-slot-location.is-personal-commitment {
            background: #fbecec;
            border-color: #e3b4b2;
            color: var(--agenda-warning-text, #962721);
        }

        .agenda-slot-location.is-day-blocked {
            background: #fdeeee;
            border-color: #e5b6b4;
            color: var(--agenda-warning-text, #9e302a);
        }

        .agenda-slot-location.has-visit-type-color {
            background: var(--agenda-slot-soft-bg, #eef6fb);
            border-color: var(--agenda-slot-soft-border, #b8d3e4);
            color: var(--agenda-location-text, var(--agenda-slot-soft-text, #24506c));
            box-shadow: 0 10px 20px rgba(31, 45, 61, 0.08), inset 0 0 0 1px rgba(255, 255, 255, 0.22);
        }

        .agenda-slot-location.is-empty {
            color: var(--agenda-location-empty-text, #8a99a6);
        }

        .agenda-slot-location-amb,
        .agenda-slot-location-room {
            display: block;
            width: 100%;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            line-height: 1.15;
        }

        .agenda-slot-location-amb {
            font-size: 10px;
            font-weight: 700;
            letter-spacing: 0.01em;
            text-transform: uppercase;
        }

        .agenda-slot-location-room {
            font-size: 10px;
            font-weight: 600;
        }

        @keyframes agendaSearchSlotPulse {
            0% {
                box-shadow: 0 0 0 0 rgba(60, 141, 188, 0.35), 0 0 0 rgba(31, 111, 167, 0);
            }
            50% {
                box-shadow: 0 0 0 5px rgba(60, 141, 188, 0.18), 0 12px 22px rgba(31, 111, 167, 0.16);
            }
            100% {
                box-shadow: 0 0 0 3px rgba(60, 141, 188, 0.26), 0 14px 24px rgba(31, 111, 167, 0.18);
            }
        }

        .agenda-custom-slot-time {
            font-size: 11px;
            font-weight: 700;
            line-height: 1.1;
            margin-bottom: 1px;
            opacity: 0.95;
        }

        .agenda-custom-slot-title {
            font-size: 12px;
            font-weight: 600;
            line-height: 1.1;
            white-space: pre-line;
        }

        .agenda-slot-print-btn {
            position: absolute;
            top: 3px;
            right: 16px;
            width: 20px;
            height: 20px;
            border: 0;
            border-radius: 3px;
            background: rgba(255, 255, 255, 0.18);
            color: inherit;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: background .12s ease, transform .12s ease;
        }

        .agenda-slot-print-btn:hover,
        .agenda-slot-print-btn:focus {
            background: rgba(255, 255, 255, 0.28);
            transform: scale(1.05);
            outline: none;
        }

        .agenda-slot-print-btn img {
            width: 16px;
            height: auto;
            display: block;
        }

        .agenda-custom-slot.is-free .agenda-slot-print-btn,
        .agenda-custom-slot.is-blocked .agenda-slot-print-btn {
            background: rgba(31, 45, 61, 0.08);
        }

        .agenda-custom-slot.is-free .agenda-slot-print-btn:hover,
        .agenda-custom-slot.is-blocked .agenda-slot-print-btn:hover,
        .agenda-custom-slot.is-free .agenda-slot-print-btn:focus,
        .agenda-custom-slot.is-blocked .agenda-slot-print-btn:focus {
            background: rgba(31, 45, 61, 0.14);
        }

        .agenda-day-locked .agenda-custom-slot {
            pointer-events: none;
            cursor: not-allowed;
            background: #d9534f !important;
            border-color: #c9302c !important;
            color: var(--agenda-appointment-text, #fff) !important;
        }

        #calendar.agenda-no-slots .fc-view-container {
            display: none !important;
        }

        .vd-actions .btn {
            margin-bottom: 4px;
        }

        .vd-note-preview {
            display: block;
            margin-top: 4px;
            color: #666;
            font-size: 12px;
            line-height: 1.35;
        }

        .agenda-note-card {
            border: 1px solid #d2d6de;
            border-radius: 6px;
            padding: 12px;
            margin: 10px;
        }

        .agenda-note-card.note-oggi {
            background: #ffffff;
        }

        .agenda-note-card.note-scaduta {
            background: #f8d7da;
            border-color: #e0aeb5;
        }

        .agenda-note-card.note-futura {
            background: #d9ecff;
            border-color: #9fc7ef;
        }

        .agenda-note-card.note-fatta {
            opacity: 0.75;
        }

        .agenda-note-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            margin-bottom: 8px;
            flex-wrap: wrap;
        }

        .agenda-note-title {
            font-size: 15px;
            font-weight: 600;
            margin: 0;
        }

        .agenda-note-meta {
            font-size: 12px;
            color: #555;
        }

        .agenda-note-grid {
            margin-top: 8px;
        }

        .agenda-note-grid .row {
            margin-bottom: 6px;
        }

        .agenda-note-label {
            font-weight: 600;
        }

        .agenda-note-actions .btn {
            margin-left: 4px;
        }

        .agenda-note-done-label {
            display: inline-block;
            margin-left: 8px;
            font-size: 12px;
            color: #333;
        }

        .note-autocomplete-item-empty {
            padding: 10px 12px;
            color: #777;
        }

        .agenda-doctor-hero {
            margin-bottom: 18px;
            padding: 20px 24px;
            border: 1px solid #d9edf7;
            border-radius: 12px;
            background: linear-gradient(135deg, #f8fcff 0%, #eaf5ff 100%);
            box-shadow: 0 10px 24px rgba(60, 141, 188, 0.12);
            text-align: center;
        }

        .agenda-doctor-kicker {
            display: inline-block;
            margin-bottom: 8px;
            padding: 6px 12px;
            border-radius: 999px;
            background: #d9edf7;
            color: #2f6f91;
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 0.04em;
            text-transform: uppercase;
        }

        .agenda-doctor-label {
            display: block;
            margin-bottom: 6px;
            color: #1f2d3d;
            font-size: 26px;
            font-weight: 700;
            line-height: 1.25;
        }

        .agenda-doctor-help {
            max-width: 700px;
            margin: 0 auto 16px;
            color: #5f6b77;
            font-size: 14px;
        }

        .agenda-doctor-select {
            max-width: 560px;
            height: 48px;
            margin: 0 auto;
            border: 2px solid #3c8dbc;
            border-radius: 8px;
            font-size: 18px;
            font-weight: 600;
            box-shadow: 0 0 0 3px rgba(60, 141, 188, 0.08);
        }

        .agenda-doctor-current {
            margin-top: 12px;
            color: #35566b;
            font-size: 14px;
        }

        .agenda-home-block + .agenda-home-block {
            margin-top: 18px;
        }

        .agenda-day-note-box .box-title {
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .agenda-sidebar-stack-item > .box {
            margin-bottom: 0 !important;
        }

        .agenda-sidebar-panels > .agenda-sidebar-stack-item + .agenda-sidebar-stack-item {
            margin-top: 15px;
        }

        .agenda-sidebar-custom-blocks {
            margin-top: 15px;
        }

        .agenda-sidebar-custom-blocks:empty {
            display: none;
        }

        .agenda-sidebar-panels .agenda-home-block .agenda-doctor-hero {
            margin-bottom: 0;
            padding: 16px 18px;
            text-align: left;
        }

        .agenda-sidebar-panels .agenda-home-block .agenda-doctor-label {
            font-size: 20px;
            margin-bottom: 8px;
        }

        .agenda-sidebar-panels .agenda-home-block .agenda-doctor-help {
            max-width: none;
            margin: 0 0 12px 0;
        }

        .agenda-sidebar-panels .agenda-home-block .agenda-doctor-select {
            max-width: none;
            margin: 0;
            font-size: 16px;
            height: 44px;
        }

        .agenda-home-empty-state {
            margin-top: 10px;
        }

        .agenda-box-subtitle {
            display: inline-block;
            margin-left: 8px;
            color: #6b7886;
            font-size: 13px;
            font-weight: 500;
            vertical-align: middle;
        }

        .agenda-layout {
            display: flex;
            flex-wrap: wrap;
            align-items: flex-start;
        }

        .agenda-layout > .agenda-sidebar-col,
        .agenda-layout > .agenda-main-col {
            flex: 0 0 100%;
            width: 100%;
            max-width: 100%;
            transition: width .22s ease, max-width .22s ease, flex-basis .22s ease;
        }

        .agenda-sidebar-shell {
            position: relative;
        }

        .agenda-sidebar-toggle-wrap {
            margin-bottom: 14px;
        }

        .agenda-sidebar-toggle {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            width: 100%;
            padding: 10px 14px;
            border: 1px solid #d2e3ee;
            border-radius: 12px !important;
            background: linear-gradient(135deg, #fdfefe 0%, #f4f9fc 55%, #ebf5fb 100%);
            color: #23485c;
            font-size: 12px;
            font-weight: 700;
            box-shadow: 0 10px 22px rgba(31, 45, 61, 0.08);
            transition: all .18s ease;
        }

        .agenda-sidebar-toggle:hover,
        .agenda-sidebar-toggle:focus {
            border-color: #b7d1e2;
            background: linear-gradient(135deg, #ffffff 0%, #eef6fb 100%);
            color: #16394c;
            outline: none;
        }

        .agenda-sidebar-toggle-icon {
            font-size: 15px;
        }

        .agenda-sidebar-panels {
            transition: opacity .18s ease, visibility .18s ease, max-height .22s ease;
        }

        .agenda-view-switch {
            display: grid;
            gap: 6px;
        }

        .agenda-view-switch--sidebar {
            grid-template-columns: repeat(auto-fit, minmax(0, 1fr));
        }

        .agenda-view-switch .btn {
            padding: 9px 10px;
            font-size: 12px;
            font-weight: 700;
            white-space: normal;
            border-radius: 10px !important;
            transition: all .16s ease;
        }

        .agenda-view-switch .btn.btn-default {
            border-color: #d6e3ec;
            background: #fff;
            color: #365465;
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.72);
        }

        .agenda-view-switch .btn.btn-default:hover,
        .agenda-view-switch .btn.btn-default:focus {
            border-color: #bdd2e0;
            background: #f5f9fc;
            color: #24495d;
        }

        .agenda-view-switch .btn.btn-primary {
            box-shadow: 0 10px 18px rgba(60, 141, 188, 0.18);
        }

        .agenda-calendar-viewbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 18px;
            margin-bottom: 16px;
            padding: 16px 18px;
            border: 1px solid #dce8f0;
            border-radius: 12px;
            background: linear-gradient(135deg, #fcfdff 0%, #f5f9fd 52%, #edf6ff 100%);
            box-shadow: 0 10px 20px rgba(31, 45, 61, 0.06);
        }

        .agenda-calendar-viewbar-copy {
            min-width: 0;
        }

        .agenda-calendar-viewbar-kicker {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 10px;
            border-radius: 999px;
            background: #e8f3fb;
            color: #2f6f91;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.05em;
            text-transform: uppercase;
        }

        .agenda-view-switch--calendar {
            display: flex;
            flex-wrap: wrap;
            justify-content: flex-end;
            gap: 8px;
            flex: 0 0 auto;
        }

        .agenda-view-switch--calendar .btn {
            min-width: 132px;
            padding: 10px 14px;
            font-size: 13px;
        }

        .agenda-calendar-control-stack {
            position: relative;
        }

        .agenda-calendar-actions {
            margin-bottom: 15px;
        }

        .agenda-calendar-header-compact {
            padding-top: 6px;
            padding-bottom: 6px;
            min-height: 0;
        }

        .agenda-calendar-actions-buttons {
            display: flex;
            justify-content: flex-end;
            flex-wrap: wrap;
            gap: 8px;
        }

        .agenda-calendar-shell-main {
            min-width: 0;
        }

        .agenda-compressed-daybar {
            display: none;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 12px 14px;
            border: 1px solid #d8e5ef;
            border-radius: 12px;
            background: linear-gradient(135deg, #f8fbfe 0%, #eef6fd 100%);
            box-shadow: 0 10px 20px rgba(31, 45, 61, 0.06);
            flex-wrap: wrap;
        }

        .agenda-compressed-daybar-nav,
        .agenda-compressed-daybar-actions {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }

        .agenda-compressed-daybar-copy {
            min-width: 0;
            flex: 1 1 260px;
        }

        .agenda-compressed-daybar-kicker {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            margin-bottom: 4px;
            color: #527086;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.05em;
            text-transform: uppercase;
        }

        .agenda-compressed-daybar-label-wrap {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }

        .agenda-compressed-daybar-label {
            color: #1f2d3d;
            font-size: 20px;
            font-weight: 800;
            line-height: 1.2;
            text-transform: capitalize;
        }

        .agenda-day-note-indicator {
            display: inline-flex;
            width: 9px;
            height: 9px;
            border-radius: 50%;
            background: #f39c12;
            box-shadow: 0 0 0 3px rgba(243, 156, 18, 0.18);
            flex: 0 0 auto;
            vertical-align: middle;
        }

        .agenda-day-note-indicator.is-hidden {
            display: none;
        }

        .agenda-compressed-daybar .btn {
            min-height: 40px;
            border-radius: 10px !important;
            font-weight: 700;
        }

        .agenda-view-help {
            margin: 8px 0 0;
            color: #6b7886;
            font-size: 12px;
            line-height: 1.45;
        }

        .agenda-team-shell {
            display: none;
            min-height: 720px;
        }

        .agenda-team-day-toolbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 12px;
            padding: 14px 16px;
            border: 1px solid #d8e5ef;
            border-radius: 14px;
            background: linear-gradient(135deg, #eef7ff 0%, #f8fbfe 100%);
            box-shadow: 0 10px 24px rgba(31, 45, 61, 0.08);
        }

        .agenda-team-day-toolbar-main {
            display: flex;
            align-items: center;
            gap: 12px;
            flex: 1 1 auto;
            min-width: 0;
            justify-content: flex-end;
        }

        .agenda-team-day-toolbar-copy {
            min-width: 0;
            text-align: right;
        }

        .agenda-team-day-toolbar-kicker {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            margin-bottom: 6px;
            color: #4f6b7d;
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            justify-content: flex-end;
            width: 100%;
        }

        .agenda-team-day-toolbar-headline {
            display: flex;
            align-items: baseline;
            justify-content: flex-end;
            gap: 14px;
            flex-wrap: wrap;
        }

        .agenda-team-day-toolbar-piece {
            color: #1f2d3d;
            font-size: 28px;
            font-weight: 800;
            line-height: 1.05;
            text-transform: capitalize;
        }

        .agenda-team-day-toolbar-year {
            margin-top: 6px;
            color: #5c7282;
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }

        .agenda-team-day-toolbar-actions {
            display: flex;
            align-items: center;
            gap: 8px;
            flex: 0 0 auto;
        }

        .agenda-team-day-toolbar-btn {
            min-width: 46px;
            min-height: 42px;
            border-radius: 10px;
            font-weight: 700;
            box-shadow: none;
        }

        .agenda-team-day-toolbar-btn.btn-primary {
            padding-left: 16px;
            padding-right: 16px;
        }

        .agenda-compressed-layout-enabled .agenda-calendar-control-stack {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .agenda-compressed-layout-enabled .agenda-compressed-daybar {
            display: flex;
            order: 1;
        }

        .agenda-compressed-layout-enabled .agenda-calendar-shell-main {
            order: 2;
        }

        .agenda-compressed-layout-enabled .agenda-calendar-viewbar {
            order: 3;
            margin-bottom: 0;
            padding: 12px 14px;
            justify-content: flex-start;
        }

        .agenda-compressed-layout-enabled .agenda-calendar-viewbar-copy {
            display: none;
        }

        .agenda-compressed-layout-enabled .agenda-view-switch--calendar {
            justify-content: flex-start;
            width: 100%;
        }

        .agenda-compressed-layout-enabled .agenda-calendar-actions {
            order: 4;
            margin-bottom: 0;
        }

        .agenda-compressed-layout-enabled .agenda-calendar-actions-buttons {
            justify-content: flex-start;
        }

        .agenda-compressed-layout-enabled #agendaCalendarActionsRow #btnAddExtraSlot {
            display: none !important;
        }

        .agenda-compressed-layout-enabled #agendaCalendarShell .fc-toolbar {
            display: none !important;
        }

        .agenda-compressed-layout-enabled .agenda-team-day-toolbar {
            display: none;
        }

        .agenda-team-summary {
            margin-bottom: 12px;
            padding: 12px 14px;
            border: 1px solid #d9edf7;
            border-radius: 10px;
            background: #f7fbfe;
            color: #3d5566;
            font-size: 13px;
            line-height: 1.5;
        }

        .agenda-team-board-wrap {
            --agenda-team-column-min-width: 220px;
            overflow: auto;
            max-height: 84vh;
            min-height: 680px;
            border: 1px solid #dde7ef;
            border-radius: 12px;
            background: #f8fafc;
        }

        .agenda-team-board-wrap.is-compact-stack {
            min-height: 0;
        }

        .agenda-team-board {
            min-width: max-content;
        }

        .agenda-team-board.is-fit-columns {
            width: 100%;
            min-width: 0;
        }

        .agenda-team-board.is-fit-columns .agenda-team-grid {
            width: 100%;
        }

        .agenda-team-board.is-fit-columns .agenda-team-header {
            min-width: 0;
        }

        .agenda-team-board.is-mobile-list {
            width: 100%;
            min-width: 0;
        }

        .agenda-team-grid {
            display: grid;
            align-items: start;
        }

        .agenda-team-corner,
        .agenda-team-header {
            position: sticky;
            top: 0;
            z-index: 5;
            padding: 12px 10px;
            border-bottom: 1px solid #d8e3ec;
            background: var(--agenda-team-column-soft-bg, #eef5fb);
        }

        .agenda-team-corner {
            left: 0;
            z-index: 7;
            color: var(--agenda-team-secondary-text, #587184);
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 0.03em;
            text-transform: uppercase;
        }

        .agenda-team-header {
            min-width: var(--agenda-team-column-min-width, 220px);
            border-left: 1px solid var(--agenda-team-column-soft-border, #dde7ef);
            color: var(--agenda-team-secondary-text, var(--agenda-team-column-soft-text, #1f2d3d));
            box-sizing: border-box;
        }

        .agenda-team-header.is-selected {
            box-shadow: inset 0 0 0 2px var(--agenda-team-column-selected-ring, rgba(60, 141, 188, 0.18));
        }

        .agenda-team-header-name {
            color: var(--agenda-team-header-text, inherit);
            font-size: 14px;
            font-weight: 700;
            line-height: 1.35;
        }

        .agenda-team-header-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            margin-top: 6px;
        }

        .agenda-team-chip {
            display: inline-flex;
            align-items: center;
            padding: 4px 8px;
            border-radius: 999px;
            background: var(--agenda-team-column-chip-bg, #e8f1f8);
            color: var(--agenda-team-secondary-text, var(--agenda-team-column-chip-text, #406173));
            font-size: 11px;
            font-weight: 700;
            line-height: 1;
        }

        .agenda-team-chip.is-selected {
            background: var(--agenda-team-column-entry-bg, #3c8dbc);
            color: var(--agenda-appointment-text, var(--agenda-team-column-entry-text, #fff));
        }

        .agenda-team-chip.is-locked {
            background: #fbe9e7;
            color: var(--agenda-warning-text, #c0392b);
        }

        .agenda-team-time-axis,
        .agenda-team-column-body {
            position: relative;
            background: var(--agenda-team-column-bg, #fff);
        }

        .agenda-team-time-axis {
            position: sticky;
            left: 0;
            z-index: 4;
            min-width: 82px;
            border-right: 1px solid #dde7ef;
        }

        .agenda-team-column-body {
            border-left: 1px solid var(--agenda-team-column-soft-border, #eef2f7);
            --agenda-team-step-height: 52px;
            min-width: 0;
            box-sizing: border-box;
        }

        .agenda-team-column-body.is-compact-stack {
            display: flex;
            flex-direction: column;
            gap: 8px;
            padding: 0 8px 8px;
            min-height: 0;
        }

        .agenda-team-column-body.is-compact-stack::before {
            display: none;
        }

        .agenda-team-column-body.is-compact-stack.is-day-locked {
            padding-top: 42px;
        }

        .agenda-team-column-body::before {
            content: '';
            position: absolute;
            inset: 0;
            background-image: linear-gradient(to bottom, var(--agenda-team-column-guide-line, rgba(148, 163, 184, 0.22)) 1px, transparent 1px);
            background-size: 100% var(--agenda-team-step-height);
            pointer-events: none;
        }

        .agenda-team-column-body.is-day-locked {
            background: #fff7f7;
        }

        .agenda-team-column-body.is-day-locked::after {
            content: 'Giornata bloccata';
            position: absolute;
            top: 10px;
            right: 10px;
            z-index: 3;
            padding: 4px 8px;
            border-radius: 999px;
            background: rgba(217, 83, 79, 0.12);
            color: var(--agenda-warning-text, #c0392b);
            font-size: 11px;
            font-weight: 700;
        }

        .agenda-team-time-marker {
            position: absolute;
            left: 0;
            right: 0;
            transform: translateY(-50%);
            padding-right: 10px;
            color: var(--agenda-team-secondary-text, #6b7886);
            font-size: 12px;
            font-weight: 600;
            text-align: right;
            white-space: nowrap;
        }

        .agenda-team-slot-guide {
            position: absolute;
            left: 8px;
            right: 8px;
            z-index: 1;
            display: flex;
            align-items: flex-start;
            padding: 8px 10px;
            border: 1px solid var(--agenda-team-column-soft-border, rgba(216, 227, 236, 0.9));
            border-radius: 12px;
            background: linear-gradient(180deg, var(--agenda-team-column-empty-bg, rgba(248, 251, 254, 0.96)) 0%, rgba(255, 255, 255, 0.98) 100%);
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.9);
            pointer-events: none;
        }

        .agenda-team-slot-guide-time {
            display: inline-flex;
            align-items: center;
            padding: 3px 8px;
            border-radius: 999px;
            background: var(--agenda-team-column-chip-bg, rgba(88, 113, 132, 0.09));
            color: var(--agenda-team-secondary-text, var(--agenda-team-column-guide-text, #5b7284));
            font-size: 11px;
            font-weight: 700;
            font-variant-numeric: tabular-nums;
            white-space: nowrap;
        }

        .agenda-team-entry {
            position: absolute;
            left: 8px;
            right: 8px;
            z-index: 2;
            padding: 10px 12px;
            border-radius: 14px;
            box-shadow: 0 12px 24px var(--agenda-team-column-shadow, rgba(31, 45, 61, 0.12));
            overflow: hidden;
            cursor: pointer;
        }

        .agenda-team-column-body.is-compact-stack .agenda-team-entry {
            position: relative;
            left: auto;
            right: auto;
            top: auto;
            margin: 0;
        }

        .agenda-team-entry-content {
            display: flex;
            flex-direction: column;
            align-items: stretch;
            justify-content: flex-start;
            gap: 6px;
            min-height: 100%;
            text-align: left;
        }

        .agenda-team-entry-title {
            display: flex;
            align-items: flex-start;
            flex-wrap: wrap;
            gap: 6px;
            color: inherit;
            font-size: 13px;
            font-weight: 700;
            line-height: 1.4;
            white-space: normal;
        }

        .agenda-team-entry-time {
            flex: 0 0 auto;
            display: inline-flex;
            align-items: center;
            padding: 3px 8px;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.18);
            font-variant-numeric: tabular-nums;
        }

        .agenda-team-entry.has-visit-type-color {
            background: var(--agenda-slot-bg, #3c8dbc);
            border: 1px solid var(--agenda-slot-border, #2f74a0);
            color: var(--agenda-appointment-text, var(--agenda-slot-text, #fff));
            box-shadow: 0 12px 24px var(--agenda-slot-shadow, rgba(31, 45, 61, 0.14));
        }

        .agenda-team-entry.has-visit-type-color .agenda-team-entry-time {
            background: var(--agenda-team-pill-bg, rgba(255, 255, 255, 0.18));
            color: var(--agenda-appointment-text, var(--agenda-team-pill-text, inherit));
        }

        .agenda-team-entry-patient {
            min-width: 0;
            flex: 1 1 140px;
            white-space: normal;
            overflow-wrap: anywhere;
            word-break: break-word;
        }

        .agenda-team-entry-contact {
            display: block;
            color: inherit;
            font-size: 12px;
            font-weight: 600;
            line-height: 1.35;
            opacity: 0.96;
            white-space: normal;
            overflow-wrap: anywhere;
            word-break: break-word;
        }

        .agenda-team-entry-note {
            display: block;
            color: inherit;
            font-size: 12px;
            line-height: 1.35;
            opacity: 0.92;
            white-space: normal;
            overflow-wrap: anywhere;
            word-break: break-word;
        }

        .agenda-team-entry-free-slot {
            display: block;
            padding: 10px 12px;
            border: 1px dashed var(--agenda-team-column-free-border, rgba(60, 141, 188, 0.4));
            background: linear-gradient(180deg, var(--agenda-team-column-free-bg, rgba(235, 244, 251, 0.98)) 0%, rgba(246, 250, 254, 0.98) 100%);
            box-shadow: none;
            color: var(--agenda-free-slot-text, var(--agenda-team-column-free-text, #2e7eb0));
        }

        .agenda-team-entry-free-slot:hover,
        .agenda-team-entry-free-slot:focus {
            background: linear-gradient(180deg, var(--agenda-team-column-soft-bg, rgba(223, 237, 248, 1)) 0%, rgba(240, 247, 253, 1) 100%);
            border-color: var(--agenda-team-column-entry-border, rgba(60, 141, 188, 0.65));
            box-shadow: 0 10px 20px var(--agenda-team-column-shadow, rgba(31, 45, 61, 0.10));
            outline: none;
        }

        .agenda-team-entry-free-slot-chip-only {
            padding: 0;
            border: 0;
            background: transparent;
            box-shadow: none;
        }

        .agenda-team-entry-free-slot-chip-only:hover,
        .agenda-team-entry-free-slot-chip-only:focus {
            background: transparent;
            border-color: transparent;
            box-shadow: none;
            outline: none;
        }

        .agenda-team-entry-free-slot-chip-only .agenda-team-entry-content {
            display: block;
            min-height: 0;
        }

        .agenda-team-entry-free-slot-chip-only .agenda-team-entry-title {
            display: block;
        }

        .agenda-team-entry-free-slot-chip-only .agenda-team-entry-time {
            margin: 8px 0 0 10px;
        }

        .agenda-team-entry-free-slot-chip-only .agenda-team-entry-patient,
        .agenda-team-entry-free-slot-chip-only .agenda-team-entry-note {
            display: none;
        }

        .agenda-team-free-label {
            font-size: 13px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.03em;
        }

        .agenda-team-entry-closed {
            border: 1px solid rgba(217, 83, 79, 0.3);
            background: linear-gradient(180deg, rgba(253, 241, 240, 0.98) 0%, rgba(255, 247, 247, 0.98) 100%);
            box-shadow: none;
            color: var(--agenda-warning-text, #b03a34);
            cursor: default;
        }

        .agenda-team-entry-free-slot .agenda-team-entry-time {
            background: var(--agenda-team-column-chip-bg, rgba(60, 141, 188, 0.12));
            color: var(--agenda-free-slot-text, var(--agenda-team-column-free-text, #2a6f99));
        }

        .agenda-team-entry-closed .agenda-team-entry-time {
            background: rgba(192, 57, 43, 0.12);
            color: var(--agenda-warning-text, #b03a34);
        }

        .agenda-team-board.has-compact-slot-details .agenda-team-column-body.is-compact-stack .agenda-team-entry {
            min-height: 58px;
        }

        .agenda-team-board.has-compact-slot-details .agenda-team-column-body.is-compact-stack .agenda-team-entry:not(.agenda-team-entry-free-slot):not(.agenda-team-entry-closed) {
            padding: 10px 12px;
        }

        .agenda-team-board.has-compact-slot-details .agenda-team-column-body.is-compact-stack .agenda-team-entry-content {
            gap: 4px;
        }

        .agenda-team-board.has-compact-slot-details .agenda-team-column-body.is-compact-stack .agenda-team-entry:not(.agenda-team-entry-free-slot):not(.agenda-team-entry-closed) .agenda-team-entry-title {
            display: flex;
            align-items: center;
            flex-wrap: nowrap;
            gap: 6px;
        }

        .agenda-team-board.has-compact-slot-details .agenda-team-column-body.is-compact-stack .agenda-team-entry-time {
            margin-bottom: 0;
        }

        .agenda-team-board.has-compact-slot-details .agenda-team-column-body.is-compact-stack .agenda-team-entry-patient {
            display: block;
        }

        .agenda-team-board.has-compact-slot-details .agenda-team-column-body.is-compact-stack .agenda-team-entry:not(.agenda-team-entry-free-slot):not(.agenda-team-entry-closed) .agenda-team-entry-patient {
            flex: 1 1 0;
        }

        .agenda-team-board.has-compact-slot-details .agenda-team-column-body.is-compact-stack .agenda-team-entry-note {
            display: -webkit-box;
            overflow: hidden;
            -webkit-box-orient: vertical;
            -webkit-line-clamp: 3;
            line-clamp: 3;
        }

        .agenda-team-empty-column {
            position: absolute;
            top: 18px;
            left: 14px;
            right: 14px;
            z-index: 2;
            padding: 16px 14px;
            border: 1px solid var(--agenda-team-column-empty-border, #c9d8e4);
            border-radius: 10px;
            background: linear-gradient(180deg, var(--agenda-team-column-empty-bg, rgba(255, 255, 255, 0.98)) 0%, rgba(244, 249, 252, 0.98) 100%);
            box-shadow: 0 6px 16px var(--agenda-team-column-shadow, rgba(31, 45, 61, 0.08));
            color: var(--agenda-team-secondary-text, var(--agenda-team-column-empty-text, #425466));
            font-size: 16px;
            font-weight: 600;
            line-height: 1.55;
            width: calc(100% - 28px);
            max-width: calc(100% - 28px);
            min-width: 0;
            box-sizing: border-box;
            white-space: normal;
            overflow-wrap: anywhere;
            word-break: break-word;
        }

        .agenda-team-column-body.is-compact-stack .agenda-team-empty-column {
            position: relative;
            top: auto;
            left: auto;
            right: auto;
            margin: 0;
        }

        .agenda-team-board-wrap.is-mobile-list {
            min-height: 0;
            overflow-x: hidden;
            overflow-y: auto;
            overscroll-behavior-y: contain;
            -webkit-overflow-scrolling: touch;
            border: 0;
            background: transparent;
        }

        .agenda-team-mobile-list {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .agenda-team-mobile-summary {
            display: flex;
            gap: 8px;
            overflow-x: auto;
            padding: 2px 1px 4px;
            scrollbar-width: none;
            -ms-overflow-style: none;
        }

        .agenda-team-mobile-summary::-webkit-scrollbar {
            display: none;
        }

        .agenda-team-mobile-summary-card {
            display: flex;
            flex: 0 0 100%;
            min-width: 0;
            align-items: center;
            justify-content: center;
            gap: 5px;
            padding: 7px 6px;
            border: 1px solid var(--agenda-team-column-soft-border, #d8e4ed);
            border-radius: 12px;
            background: linear-gradient(180deg, var(--agenda-team-column-soft-bg, #f5f9fd) 0%, #ffffff 100%);
            box-shadow: 0 4px 10px rgba(31, 45, 61, 0.05);
        }

        .agenda-team-mobile-summary-card.is-selected {
            box-shadow: inset 0 0 0 1px var(--agenda-team-column-selected-ring, rgba(60, 141, 188, 0.3));
        }

        .agenda-team-mobile-summary-avatar {
            display: inline-flex;
            flex: 0 0 25px;
            width: 25px;
            height: 25px;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            background: var(--agenda-team-column-chip-bg, #e8f1f8);
            color: var(--agenda-team-header-text, #1f2d3d);
            font-size: 9px;
            font-weight: 800;
            letter-spacing: -0.02em;
        }

        .agenda-team-mobile-summary-name {
            min-width: 0;
            color: var(--agenda-team-header-text, #1f2d3d);
            font-size: 10px;
            font-weight: 700;
            line-height: 1.2;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .agenda-team-mobile-summary-meta {
            display: none;
        }

        .agenda-team-mobile-summary-status {
            display: none;
        }

        .agenda-team-mobile-section {
            min-width: 0;
            overflow: hidden;
            border: 1px solid #d8e4ed;
            border-radius: 18px;
            background: #ffffff;
            box-shadow: 0 10px 24px rgba(31, 45, 61, 0.08);
        }

        .agenda-team-mobile-section-header {
            display: flex;
            min-width: 0;
            align-items: center;
            justify-content: space-between;
            padding: 9px 11px;
            border-bottom: 1px solid #e5edf4;
            background: linear-gradient(180deg, #f7fafc 0%, #f2f6fa 100%);
        }

        .agenda-team-mobile-section-kicker {
            display: none;
        }

        .agenda-team-mobile-section-label {
            display: flex;
            align-items: center;
            gap: 4px;
            color: #1f2d3d;
            font-size: 12px;
            font-weight: 700;
            line-height: 1.2;
            font-variant-numeric: tabular-nums;
        }

        .agenda-team-mobile-section-separator {
            color: #95a5b2;
            font-size: 11px;
            line-height: 1;
        }

        .agenda-team-mobile-section-body {
            display: flex;
            min-width: 0;
            gap: 8px;
            padding: 10px;
            overflow-x: auto;
            overflow-y: hidden;
            overscroll-behavior-x: contain;
            scroll-snap-type: x proximity;
            scrollbar-width: thin;
            scrollbar-color: #c8d7e2 transparent;
            -webkit-overflow-scrolling: touch;
        }

        .agenda-team-mobile-professional-lane {
            display: flex;
            flex: 0 0 100%;
            min-width: 0;
            flex-direction: column;
            gap: 5px;
            scroll-snap-align: start;
        }

        .agenda-team-mobile-list.has-2-columns .agenda-team-mobile-summary-card,
        .agenda-team-mobile-list.has-2-columns .agenda-team-mobile-professional-lane {
            flex-basis: calc((100% - 8px) / 2);
        }

        .agenda-team-mobile-list.has-3-columns .agenda-team-mobile-summary-card,
        .agenda-team-mobile-list.has-3-columns .agenda-team-mobile-professional-lane {
            flex-basis: calc((100% - 16px) / 3);
        }

        .agenda-team-mobile-list.has-4-columns .agenda-team-mobile-summary-card,
        .agenda-team-mobile-list.has-4-columns .agenda-team-mobile-professional-lane {
            flex-basis: calc((100% - 24px) / 4);
        }

        .agenda-team-mobile-section-body::-webkit-scrollbar {
            height: 4px;
        }

        .agenda-team-mobile-section-body::-webkit-scrollbar-thumb {
            border-radius: 999px;
            background: #c8d7e2;
        }

        .agenda-team-mobile-entry {
            display: block;
            flex: 0 0 auto;
            width: 100%;
            min-width: 0;
            min-height: 62px;
            padding: 7px 5px;
            border: 1px solid var(--agenda-team-column-soft-border, #d8e4ed);
            border-radius: 9px;
            background: linear-gradient(180deg, var(--agenda-team-column-empty-bg, #fbfdff) 0%, #ffffff 100%);
            box-shadow: none;
            color: #1f2d3d;
            text-align: center;
        }

        button.agenda-team-mobile-entry {
            cursor: pointer;
            pointer-events: auto;
            touch-action: manipulation;
        }

        .agenda-team-mobile-entry.is-free {
            border-style: dashed;
        }

        .agenda-team-mobile-entry.is-locked {
            border-color: rgba(217, 83, 79, 0.3);
            background: linear-gradient(180deg, rgba(253, 241, 240, 0.98) 0%, rgba(255, 247, 247, 0.98) 100%);
            color: var(--agenda-warning-text, #b03a34);
        }

        .agenda-team-mobile-entry.is-booked {
            color: var(--agenda-appointment-text, var(--agenda-team-column-entry-text, #ffffff));
        }

        .agenda-team-mobile-entry.has-visit-type-color .agenda-team-mobile-entry-time,
        .agenda-team-mobile-entry.is-booked .agenda-team-mobile-entry-time {
            background: var(--agenda-team-pill-bg, rgba(255, 255, 255, 0.18));
            color: var(--agenda-team-pill-text, currentColor);
        }

        .agenda-team-mobile-entry-head {
            display: block;
            margin-bottom: 5px;
        }

        .agenda-team-mobile-entry-doctor {
            display: none;
        }

        .agenda-team-mobile-entry-time {
            display: inline-flex;
            max-width: 100%;
            align-items: center;
            justify-content: center;
            padding: 2px 4px;
            border-radius: 999px;
            background: var(--agenda-team-column-chip-bg, rgba(88, 113, 132, 0.12));
            color: var(--agenda-team-secondary-text, #587184);
            font-size: 8px;
            font-weight: 700;
            line-height: 1.15;
            overflow-wrap: anywhere;
        }

        .agenda-team-mobile-entry-title {
            color: inherit;
            font-size: 10px;
            font-weight: 700;
            line-height: 1.2;
            display: -webkit-box;
            overflow: hidden;
            -webkit-box-orient: vertical;
            -webkit-line-clamp: 2;
            line-clamp: 2;
            overflow-wrap: anywhere;
            word-break: break-word;
        }

        .agenda-team-mobile-entry-contact,
        .agenda-team-mobile-entry-note {
            margin-top: 4px;
            color: inherit;
            font-size: 11px;
            line-height: 1.45;
            opacity: 0.95;
            overflow-wrap: anywhere;
            word-break: break-word;
        }

        .agenda-team-mobile-entry-contact {
            display: none;
        }

        .agenda-team-mobile-entry-note {
            display: none;
        }

        .agenda-team-mobile-entry.is-empty {
            display: flex;
            align-items: center;
            justify-content: center;
            border-style: solid;
            background: #f7fafc;
            color: #8a9aa8;
        }

        .agenda-team-mobile-entry-empty-label {
            font-size: 9px;
            font-weight: 700;
            line-height: 1.2;
        }

        .agenda-team-mobile-empty-stack {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .agenda-team-mobile-empty-card {
            padding: 12px 14px;
            border: 1px solid var(--agenda-team-column-soft-border, #d8e4ed);
            border-radius: 16px;
            background: linear-gradient(180deg, var(--agenda-team-column-soft-bg, #f7fafc) 0%, #ffffff 100%);
            box-shadow: 0 8px 20px rgba(31, 45, 61, 0.06);
        }

        .agenda-team-mobile-empty-card-name {
            color: var(--agenda-team-header-text, #1f2d3d);
            font-size: 13px;
            font-weight: 700;
            line-height: 1.4;
        }

        .agenda-team-mobile-empty-card-message {
            margin-top: 6px;
            color: var(--agenda-team-secondary-text, #587184);
            font-size: 12px;
            line-height: 1.45;
            overflow-wrap: anywhere;
            word-break: break-word;
        }

        @media (min-width: 992px) {
            .agenda-layout {
                flex-wrap: nowrap;
            }

            .agenda-layout::before,
            .agenda-layout::after {
                display: none;
            }

            .agenda-layout > .agenda-sidebar-col,
            .agenda-layout > .agenda-main-col {
                float: none;
            }

            .agenda-layout > .agenda-sidebar-col {
                flex: 0 0 280px;
                width: 280px;
                max-width: 280px;
            }

            .agenda-layout.has-sidebar-custom-blocks > .agenda-sidebar-col {
                flex-basis: 360px;
                width: 360px;
                max-width: 360px;
            }

            .agenda-layout > .agenda-main-col {
                flex: 1 1 auto;
                width: auto;
                max-width: none;
                min-width: 0;
            }

            .agenda-layout.is-sidebar-collapsed > .agenda-sidebar-col {
                flex-basis: 86px;
                width: 86px;
                max-width: 86px;
            }

            .agenda-layout.is-sidebar-collapsed .agenda-sidebar-panels {
                opacity: 0;
                visibility: hidden;
                pointer-events: none;
                max-height: 0;
                overflow: hidden;
                margin: 0;
            }

            .agenda-layout.is-sidebar-collapsed .agenda-sidebar-toggle {
                min-height: 160px;
                flex-direction: column;
                gap: 10px;
                padding: 16px 8px;
            }

            .agenda-layout.is-sidebar-collapsed .agenda-sidebar-toggle-text {
                writing-mode: vertical-rl;
                transform: rotate(180deg);
                letter-spacing: 0.08em;
                text-transform: uppercase;
                font-size: 11px;
            }
        }

        @media (max-width: 991px) {
            .agenda-layout.is-sidebar-collapsed .agenda-sidebar-panels {
                display: none;
            }
        }

        @media (max-width: 767px) {
            .agenda-visit-type-color-footer {
                align-items: stretch;
            }

            .agenda-visit-type-color-custom {
                width: 100%;
                justify-content: space-between;
                margin-left: 0;
            }

            .agenda-visit-type-select-preview {
                align-items: flex-start;
            }

            .agenda-doctor-hero {
                padding: 18px 16px;
            }

            .agenda-doctor-label {
                font-size: 22px;
            }

            .agenda-doctor-select {
                max-width: 100%;
                font-size: 16px;
            }

            .agenda-calendar-viewbar {
                flex-direction: column;
                align-items: stretch;
                padding: 14px;
            }

            .agenda-view-switch--sidebar {
                grid-template-columns: 1fr;
            }

            .agenda-view-switch--calendar {
                justify-content: stretch;
            }

            .agenda-view-switch--calendar .btn {
                flex: 1 1 100%;
                min-width: 0;
            }

            .agenda-team-day-toolbar {
                flex-direction: column;
                align-items: stretch;
                padding: 14px;
            }

            .agenda-team-day-toolbar-main {
                justify-content: space-between;
            }

            .agenda-team-day-toolbar-copy {
                flex: 1 1 auto;
            }

            .agenda-team-day-toolbar-headline {
                gap: 10px;
            }

            .agenda-team-day-toolbar-piece {
                font-size: 24px;
            }

            .agenda-team-day-toolbar-year {
                font-size: 11px;
            }

            .agenda-team-day-toolbar-actions {
                justify-content: flex-start;
                width: 100%;
            }

            .agenda-team-day-toolbar-actions .agenda-team-day-toolbar-btn {
                flex: 0 0 auto;
            }

            .agenda-team-board-wrap {
                --agenda-team-column-min-width: 180px;
                min-height: 560px;
            }

            .agenda-team-board-wrap.is-mobile-list {
                min-height: 0;
            }

            .agenda-team-time-axis {
                min-width: 68px;
            }

            .agenda-team-entry,
            .agenda-team-slot-guide {
                left: 6px;
                right: 6px;
            }

            .agenda-team-entry {
                padding: 8px 10px;
            }

            .agenda-team-entry-title {
                font-size: 12px;
            }

            .agenda-team-entry-contact,
            .agenda-team-entry-note {
                font-size: 11px;
            }

            .agenda-team-mobile-section-header {
                padding: 9px 11px;
            }

            .agenda-team-mobile-section-body {
                padding: 8px;
            }

            .agenda-team-mobile-entry {
                padding: 7px 5px;
            }

            .agenda-compressed-daybar {
                align-items: stretch;
            }

            .agenda-compressed-daybar-nav,
            .agenda-compressed-daybar-actions {
                width: 100%;
                justify-content: space-between;
            }

            .agenda-compressed-daybar-nav .btn,
            .agenda-compressed-daybar-actions .btn {
                flex: 1 1 0;
            }

            .agenda-compressed-daybar-label {
                font-size: 18px;
            }
        }

    </style>
</head>
<body
    class="skin-blue sidebar-mini<?= $agendaCompressedLayoutEnabled ? ' agenda-compressed-layout-enabled' : '' ?>"
    <?= $agendaTextThemeInlineStyle !== '' ? 'style="' . esc($agendaTextThemeInlineStyle, 'attr') . '"' : '' ?>
>
<div class="wrapper">

    <?= view('partials/header', ['menu_items' => $menu_items ?? []]) ?>

    <aside class="main-sidebar" style="display:none">
        <section class="sidebar"></section>
    </aside>

    <div class="content-wrapper">
        <section class="content-header">
            <h1>Agenda</h1>
            <ol class="breadcrumb">
                <li><a href="#"><i class="fa fa-dashboard"></i> Home</a></li>
                <li class="active">Agenda</li>
            </ol>
        </section>

        <section class="content">
            <?php
            $selectedDoctorLabel = '';
            foreach (($medici ?? []) as $m) {
                $heroIdDot = (int)(is_object($m) ? ($m->id_dot ?? 0) : ($m['id_dot'] ?? 0));
                if ($heroIdDot !== (int)($selectedDot ?? 0)) {
                    continue;
                }

                $selectedDoctorLabel = trim((string)(is_object($m)
                    ? ($m->label ?? (($m->cognome ?? '') . ' ' . ($m->nome ?? '')))
                    : ($m['label'] ?? (($m['cognome'] ?? '') . ' ' . ($m['nome'] ?? '')))));
                break;
            }
            ?>

            <div class="<?= esc(implode(' ', $agendaLayoutClasses)) ?>" id="agendaLayout">

                <div class="col-md-2 agenda-sidebar-col" id="agendaSidebarCol">
                    <div class="agenda-sidebar-shell">
                        <div class="agenda-sidebar-toggle-wrap">
                            <button
                                type="button"
                                class="btn btn-default agenda-sidebar-toggle"
                                id="btnToggleAgendaSidebar"
                                aria-expanded="<?= $agendaCompressedLayoutEnabled ? 'false' : 'true' ?>"
                                aria-controls="agendaSidebarPanels"
                                title="<?= $agendaCompressedLayoutEnabled ? 'Apri il pannello agenda' : 'Chiudi il pannello agenda' ?>"
                            >
                                <i class="fa <?= $agendaCompressedLayoutEnabled ? 'fa-angle-double-right' : 'fa-angle-double-left' ?> agenda-sidebar-toggle-icon" id="btnToggleAgendaSidebarIcon" aria-hidden="true"></i>
                                <span class="agenda-sidebar-toggle-text" id="btnToggleAgendaSidebarText"><?= $agendaCompressedLayoutEnabled ? 'Apri menu' : 'Riduci menu' ?></span>
                            </button>
                        </div>

                        <div id="agendaSidebarPanels" class="agenda-sidebar-panels">
                    <div id="agendaSidebarBlock-sidebar_menu" class="agenda-sidebar-stack-item">
                    <div class="box box-solid">
                        <div class="box-header with-border">
                            <h3 class="box-title">Menu</h3>
                            <div class="box-tools">
                                <button class="btn btn-box-tool" data-widget="collapse">
                                    <i class="fa fa-minus"></i>
                                </button>
                            </div>
                        </div>

           <div class="box-body no-padding">
    <?= view('agenda/partials/menu_laterale', ['menuAgenda' => $menuAgenda ?? ($menu ?? [])]) ?>
</div>
                    </div>
                    </div>

                    <div id="agendaSidebarBlock-sidebar_calendar" class="agenda-sidebar-stack-item">
                    <div class="box box-primary">
                        <div class="box-header with-border">
                            <h3 class="box-title"><i class="fa fa-filter"></i> Filtri agenda</h3>
                        </div>
                        <div class="box-body agenda-toolbar">
                            <div class="form-group">
                                <label for="agenda_date">
                                    Data
                                    <span
                                        id="agendaDateNoteIndicator"
                                        class="agenda-day-note-indicator is-hidden"
                                        data-agenda-day-note-indicator
                                        aria-hidden="true"
                                        title="Sono presenti note del giorno per questa data."
                                    ></span>
                                </label>
                                <input type="date" id="agenda_date" class="form-control" value="<?= esc($selectedDate ?? date('Y-m-d')) ?>">
                            </div>

                            <div class="agenda-mini-calendar">
                                <div class="agenda-mini-calendar-header">
                                    <button type="button" class="agenda-mini-calendar-nav" id="agendaMiniCalendarPrevMonth" aria-label="Mese precedente">
                                        <i class="fa fa-chevron-left"></i>
                                    </button>
                                    <div class="agenda-mini-calendar-title" id="agendaMiniCalendarTitle">Mese</div>
                                    <button type="button" class="agenda-mini-calendar-nav" id="agendaMiniCalendarNextMonth" aria-label="Mese successivo">
                                        <i class="fa fa-chevron-right"></i>
                                    </button>
                                </div>
                                <div class="agenda-mini-calendar-weekdays">
                                    <div class="agenda-mini-calendar-weekday">Lun</div>
                                    <div class="agenda-mini-calendar-weekday">Mar</div>
                                    <div class="agenda-mini-calendar-weekday">Mer</div>
                                    <div class="agenda-mini-calendar-weekday">Gio</div>
                                    <div class="agenda-mini-calendar-weekday">Ven</div>
                                    <div class="agenda-mini-calendar-weekday">Sab</div>
                                    <div class="agenda-mini-calendar-weekday">Dom</div>
                                </div>
                                <div class="agenda-mini-calendar-grid" id="agendaMiniCalendarGrid"></div>
                                <div class="agenda-mini-calendar-legend">
                                    <span class="agenda-mini-calendar-day-dot" aria-hidden="true"></span>
                                    Giorni con almeno uno slot libero e giornata non bloccata
                                </div>
                                <div class="agenda-mini-calendar-status" id="agendaMiniCalendarStatus"></div>
                            </div>

                            <div class="form-group">
                                <label for="view_mode">Vista</label>
                                <select id="view_mode" class="form-control sr-only">
                                    <option value="day" <?= (($viewMode ?? 'day') === 'day') ? 'selected' : '' ?>>Giorno</option>
                                    <option value="week" <?= (($viewMode ?? 'day') === 'week') ? 'selected' : '' ?>>Settimana</option>
                                    <?php if (!empty($teamDayViewEnabled)): ?>
                                        <option value="team_day" <?= (($viewMode ?? 'day') === 'team_day') ? 'selected' : '' ?>>Giorno team</option>
                                    <?php endif; ?>
                                </select>
                                <div class="agenda-view-switch agenda-view-switch--sidebar" role="group" aria-label="Vista agenda">
                                    <button type="button" class="btn btn-default agenda-view-btn" data-view-mode="day">
                                        <i class="fa fa-sun-o"></i> Giorno
                                    </button>
                                    <button type="button" class="btn btn-default agenda-view-btn" data-view-mode="week">
                                        <i class="fa fa-calendar"></i> Settimana
                                    </button>
                                    <?php if (!empty($teamDayViewEnabled)): ?>
                                        <button type="button" class="btn btn-default agenda-view-btn" data-view-mode="team_day">
                                            <i class="fa fa-columns"></i> Giorno Team
                                        </button>
                                    <?php endif; ?>
                                </div>
                                <p class="agenda-view-help">
                                    <?php if (!empty($teamDayViewEnabled)): ?>
                                        Giorno Team mostra insieme le agende giornaliere di tutti i professionisti visibili, mentre note, blocchi e domiciliari restano agganciati al professionista selezionato in alto.
                                    <?php else: ?>
                                        Scegli la vista con cui navigare l'agenda del professionista selezionato.
                                    <?php endif; ?>
                                </p>
                            </div>

                            <div class="row" style="margin-bottom:10px;">
                                <div class="col-xs-4">
                                    <button type="button" class="btn btn-default btn-block" id="btnPrevDay">
                                        <i class="fa fa-chevron-left"></i>
                                    </button>
                                </div>
                                <div class="col-xs-4">
                                    <button type="button" class="btn btn-primary btn-block" id="btnToday">
                                        Oggi
                                    </button>
                                </div>
                                <div class="col-xs-4">
                                    <button type="button" class="btn btn-default btn-block" id="btnNextDay">
                                        <i class="fa fa-chevron-right"></i>
                                    </button>
                                </div>
                            </div>

                            <div class="form-group">
                                <button type="button" class="btn btn-primary btn-block" id="btnReloadAgenda">
                                    <i class="fa fa-refresh"></i> Aggiorna agenda
                                </button>
                            </div>

                            <div class="form-group" style="margin-bottom:0;">
                                <button type="button" class="btn btn-info btn-block" id="btnOpenNoteModal">
                                    <i class="fa fa-sticky-note"></i> Nuova nota
                                </button>
                            </div>

                            <?php if ($agendaConsoleUrl !== null): ?>
                            <div class="form-group" style="margin-top:10px; margin-bottom:0;">
                                <a href="<?= esc($agendaConsoleUrl) ?>" class="btn btn-default btn-block" id="btnOpenOperationalCenter">
                                    <i class="fa fa-briefcase"></i> Vai al centro operativo
                                </a>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    </div>
                    <div id="agendaSidebarCustomBlocks" class="agenda-sidebar-custom-blocks"></div>
                        </div>
                    </div>
                </div>

                <div class="col-md-10 agenda-main-col" id="agendaMainCol">
                    <div
                        id="agendaHomeBlockOrderMeta"
                        data-layout-items="<?= esc($agendaHomeLayoutMetaJson, 'attr') ?>"
                        style="display:none;"
                    ></div>
                    <div
                        id="agendaSidebarBlockOrderMeta"
                        data-order-keys="<?= esc($agendaSidebarOrderMetaJson, 'attr') ?>"
                        style="display:none;"
                    ></div>

                    <div id="agendaHomeMainColumn">
                    <div id="agendaHomeBlock-doctor_selector" data-home-block-key="doctor_selector" data-home-placement="<?= esc($agendaHomeBlockPlacement('doctor_selector'), 'attr') ?>" class="agenda-home-block" style="<?= esc($agendaHomeBlockHiddenStyle('doctor_selector'), 'attr') ?>">
                        <div class="agenda-doctor-hero">
                            <div class="agenda-doctor-kicker">
                                <i class="fa fa-user-md"></i> Professionista
                            </div>
                            <label class="agenda-doctor-label" for="id_dot">Seleziona il dottore o infermiere da visualizzare in agenda</label>
                            <p class="agenda-doctor-help">
                                Calendario, note e operazioni dell'agenda si aggiornano in base al professionista selezionato.
                            </p>
                            <select id="id_dot" class="form-control agenda-doctor-select">
                                <?php foreach (($medici ?? []) as $m): ?>
                                    <?php
                                        $idDot = is_object($m) ? $m->id_dot : $m['id_dot'];
                                        $label = is_object($m)
                                            ? ($m->label ?? (($m->cognome ?? '') . ' ' . ($m->nome ?? '')))
                                            : ($m['label'] ?? (($m['cognome'] ?? '') . ' ' . ($m['nome'] ?? '')));
                                    ?>
                                    <option value="<?= esc($idDot) ?>" <?= ((int)($selectedDot ?? 0) === (int)$idDot ? 'selected' : '') ?>>
                                        <?= esc($label) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if ($selectedDoctorLabel !== ''): ?>
                                <div class="agenda-doctor-current">
                                    In visualizzazione: <strong><?= esc($selectedDoctorLabel) ?></strong>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div id="agendaHomeBlock-patient_search" data-home-block-key="patient_search" data-home-placement="<?= esc($agendaHomeBlockPlacement('patient_search'), 'attr') ?>" class="agenda-home-block" style="<?= esc($agendaHomeBlockHiddenStyle('patient_search'), 'attr') ?>">
                    <div class="box box-info agenda-patient-lookup-box">
                        <div class="box-header with-border">
                            <h3 class="box-title"><i class="fa fa-search"></i> Cerca paziente nell'agenda</h3>
                            <?php if (!empty($sharedAgendaPatientsEnabled)): ?>
                                <div class="box-tools">
                                    <span class="label label-success">Ricerca condivisa team</span>
                                </div>
                            <?php elseif ($selectedDoctorLabel !== ''): ?>
                                <div class="box-tools">
                                    <span class="label label-default">Solo <?= esc($selectedDoctorLabel) ?></span>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="box-body">
                            <div class="row">
                                <div class="col-md-5 col-sm-12">
                                    <div class="form-group agenda-patient-search-wrap">
                                        <label for="agendaPatientSearch">Nome o cognome del paziente</label>
                                        <input
                                            type="text"
                                            id="agendaPatientSearch"
                                            class="form-control"
                                            autocomplete="off"
                                            placeholder="<?= !empty($sharedAgendaPatientsEnabled) ? 'Ricerca rapida tra i pazienti condivisi del team' : 'Ricerca rapida tra i pazienti del professionista' ?>"
                                        >
                                        <input type="hidden" id="agendaPatientSearchIdPaziente" value="">
                                        <div id="agendaPatientAutocomplete" class="agenda-autocomplete d-none"></div>
                                    </div>
                                    <p class="help-block" style="margin-bottom:8px;">
                                        <?= !empty($sharedAgendaPatientsEnabled)
                                            ? 'Cerca per nome o cognome tra i pazienti condivisi del team; dopo la selezione vedi gli appuntamenti passati e futuri su tutte le agende dei medici.'
                                            : 'Cerca per nome o cognome tra i pazienti del professionista selezionato; dopo la selezione vedi gli appuntamenti passati e futuri.' ?>
                                    </p>
                                    <div id="agendaPatientSelectedSummary" class="agenda-patient-selected-summary" style="display:none;"></div>
                                </div>
                                <div class="col-md-7 col-sm-12">
                                    <div class="agenda-patient-history-panel">
                                        <div class="agenda-patient-history-header">
                                            <strong id="agendaPatientAppointmentsTitle">Appuntamenti del paziente</strong>
                                            <button type="button" class="btn btn-default btn-xs" id="btnClearAgendaPatientSearch" style="display:none;">
                                                <i class="fa fa-times"></i> Pulisci
                                            </button>
                                        </div>
                                        <div id="agendaPatientAppointmentsList" class="agenda-patient-history-list">
                                            <div class="agenda-patient-history-empty">
                                                <?= !empty($sharedAgendaPatientsEnabled)
                                                    ? 'Seleziona un paziente per vedere gli appuntamenti passati e futuri su tutte le agende dei medici.'
                                                    : 'Seleziona un paziente per vedere gli appuntamenti passati e futuri del professionista attuale.' ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    </div>

                    <div id="agendaHomeBlock-day_note" data-home-block-key="day_note" data-home-placement="<?= esc($agendaHomeBlockPlacement('day_note'), 'attr') ?>" class="agenda-home-block" style="<?= esc($agendaHomeBlockHiddenStyle('day_note'), 'attr') ?>">
                    <div class="box box-default agenda-day-note-box">
                        <div class="box-header with-border">
                            <h3 class="box-title">
                                <i class="fa fa-pencil-square-o"></i> Note del giorno
                            </h3>
                        </div>
                        <div class="box-body">
                            <div class="form-group" style="margin-bottom:0;">
                                <label for="nota_giorno_text" class="sr-only">Note del giorno</label>
                                <textarea
                                    id="nota_giorno_text"
                                    class="form-control"
                                    rows="3"
                                    placeholder="Scrivi qui una nota libera per il giorno selezionato. Si salva automaticamente quando esci dal campo."
                                ></textarea>
                                <div style="margin-top:6px;">
                                    <small id="nota_giorno_status" class="text-muted"></small>
                                </div>
                            </div>
                        </div>
                    </div>
                    </div>

                    <div id="agendaHomeBlock-calendar" data-home-block-key="calendar" data-home-placement="<?= esc($agendaHomeBlockPlacement('calendar'), 'attr') ?>" class="agenda-home-block" style="<?= esc($agendaHomeBlockHiddenStyle('calendar'), 'attr') ?>">
                    <div class="row">
                        <div id="agendaCalendarPrimaryCol" class="<?= !empty($domiciliariAbilitati) ? 'col-lg-7 col-md-6 col-sm-12' : 'col-lg-12 col-md-12 col-sm-12' ?>">
                            <div class="box box-primary">
                                <div class="box-header with-border<?= $agendaCompressedLayoutEnabled ? ' agenda-calendar-header-compact' : '' ?>">
                                    <?php if (!$agendaCompressedLayoutEnabled): ?>
                                    <h3 class="box-title">
                                        <i class="fa fa-calendar"></i> Calendario
                                        <?php if ($selectedDoctorLabel !== ''): ?>
                                            <span class="agenda-box-subtitle"><?= esc($selectedDoctorLabel) ?></span>
                                        <?php endif; ?>
                                    </h3>
                                    <?php endif; ?>
                                    <div class="box-tools">
                                        <button type="button" class="btn btn-box-tool" data-widget="collapse">
                                            <i class="fa fa-minus"></i>
                                        </button>
                                    </div>
                               </div>
                               <div class="box-body">
    <div class="agenda-calendar-control-stack">
        <div class="row agenda-calendar-actions" id="agendaCalendarActionsRow">
            <div class="col-sm-12">
                <div class="agenda-calendar-actions-buttons">
                    <button type="button" class="btn btn-default" id="btnPrintDayAgenda">
                        <i class="fa fa-file-pdf-o"></i> <span id="btnPrintDayAgendaLabel">Stampa PDF giorno</span>
                    </button>

                    <button type="button" class="btn btn-warning" id="btnAddExtraSlot">
                        <i class="fa fa-plus"></i> Aggiungi slot extra
                    </button>

                    <?php if (!empty($domiciliariAbilitati)): ?>
                    <button type="button" class="btn btn-warning" id="btnBlockDayDomiciliari" style="display:none;">
                        <i class="fa fa-home"></i> Blocca domiciliari
                    </button>
                    <?php endif; ?>

                    <button type="button" class="btn btn-danger" id="btnBlockDayAgenda" style="display:none;">
                        <i class="fa fa-lock"></i> Blocca giornata
                    </button>
                </div>
            </div>
        </div>

        <div class="agenda-calendar-viewbar" id="agendaCalendarViewbar">
            <div class="agenda-calendar-viewbar-copy">
                <span class="agenda-calendar-viewbar-kicker">
                    <i class="fa fa-eye"></i> Vista agenda
                </span>
            </div>
            <div class="agenda-view-switch agenda-view-switch--calendar" role="group" aria-label="Vista agenda rapida sopra il calendario">
                <button type="button" class="btn btn-default agenda-view-btn" data-view-mode="day">
                    <i class="fa fa-sun-o"></i> Giorno
                </button>
                <button type="button" class="btn btn-default agenda-view-btn" data-view-mode="week">
                    <i class="fa fa-calendar"></i> Settimana
                </button>
                <?php if (!empty($teamDayViewEnabled)): ?>
                    <button type="button" class="btn btn-default agenda-view-btn" data-view-mode="team_day">
                        <i class="fa fa-columns"></i> Giorno Team
                    </button>
                <?php endif; ?>
            </div>
        </div>

        <div class="agenda-compressed-daybar" id="agendaCompressedDaybar">
            <div class="agenda-compressed-daybar-nav" role="group" aria-label="Cambia giorno agenda">
                <button type="button" class="btn btn-default" id="btnCompressedPrevDay" aria-label="Giorno precedente">
                    <i class="fa fa-chevron-left"></i>
                </button>
                <button type="button" class="btn btn-primary" id="btnCompressedToday">
                    Oggi
                </button>
                <button type="button" class="btn btn-default" id="btnCompressedNextDay" aria-label="Giorno successivo">
                    <i class="fa fa-chevron-right"></i>
                </button>
            </div>
            <div class="agenda-compressed-daybar-copy">
                <div class="agenda-compressed-daybar-kicker">
                    <i class="fa fa-calendar-o" id="agendaCompressedDaybarKickerIcon"></i>
                    <span id="agendaCompressedDaybarKickerText">Giorno agenda</span>
                </div>
                <div class="agenda-compressed-daybar-label-wrap">
                    <div class="agenda-compressed-daybar-label" id="agendaCompressedDaybarLabel">-</div>
                    <span
                        id="agendaCompressedDayNoteIndicator"
                        class="agenda-day-note-indicator is-hidden"
                        data-agenda-day-note-indicator
                        aria-hidden="true"
                        title="Sono presenti note del giorno per questa data."
                    ></span>
                </div>
            </div>
            <div class="agenda-compressed-daybar-actions">
                <button type="button" class="btn btn-warning" id="btnCompressedAddExtraSlot">
                    <i class="fa fa-plus"></i> Aggiungi slot extra
                </button>
            </div>
        </div>

        <div class="agenda-calendar-shell-main" id="agendaCalendarShellMain">
            <div id="agendaCalendarShell" class="agenda-calendar-shell">
                <div id="calendar"></div>
                <div id="agendaCalendarLoading" class="agenda-calendar-loading" aria-hidden="true">
                    <div class="agenda-calendar-loading-box">
                        <i class="fa fa-spinner fa-spin" aria-hidden="true"></i>
                        <div class="agenda-calendar-loading-title">Caricamento agenda</div>
                        <div class="agenda-calendar-loading-note" id="agendaCalendarLoadingText">
                            Sto aggiornando il calendario del professionista selezionato.
                        </div>
                    </div>
                </div>
            </div>
            <div id="agendaTeamDayShell" class="agenda-calendar-shell agenda-team-shell">
                <div class="agenda-team-day-toolbar">
                    <div class="agenda-team-day-toolbar-actions">
                        <button type="button" class="btn btn-primary agenda-team-day-toolbar-btn" id="btnTeamDayToday">
                            Oggi
                        </button>
                    </div>
                    <div class="agenda-team-day-toolbar-main">
                        <button type="button" class="btn btn-default agenda-team-day-toolbar-btn" id="btnTeamDayPrev" aria-label="Giorno precedente">
                            <i class="fa fa-chevron-left"></i>
                        </button>
                        <div class="agenda-team-day-toolbar-copy">
                            <div class="agenda-team-day-toolbar-kicker">
                                <i class="fa fa-calendar-o"></i> Agenda del team
                            </div>
                            <div class="agenda-team-day-toolbar-headline">
                                <span class="agenda-team-day-toolbar-piece" id="agendaTeamDayCurrentWeekday">-</span>
                                <span class="agenda-team-day-toolbar-piece" id="agendaTeamDayCurrentDayNumber">-</span>
                                <span class="agenda-team-day-toolbar-piece" id="agendaTeamDayCurrentMonth">-</span>
                                <span
                                    id="agendaTeamDayNoteIndicator"
                                    class="agenda-day-note-indicator is-hidden"
                                    data-agenda-day-note-indicator
                                    aria-hidden="true"
                                    title="Sono presenti note del giorno per questa data."
                                ></span>
                            </div>
                            <div class="agenda-team-day-toolbar-year" id="agendaTeamDayCurrentYear">-</div>
                        </div>
                        <button type="button" class="btn btn-default agenda-team-day-toolbar-btn" id="btnTeamDayNext" aria-label="Giorno successivo">
                            <i class="fa fa-chevron-right"></i>
                        </button>
                    </div>
                </div>
                <?php if (!$agendaCompressedLayoutEnabled): ?>
                <div class="agenda-team-summary">
                    <i class="fa fa-columns"></i> Vista giornaliera del team: qui vedi in parallelo tutti i professionisti visibili. Le azioni laterali restano riferite al professionista selezionato in alto.
                </div>
                <?php endif; ?>
                <div class="agenda-team-board-wrap">
                    <div id="agendaTeamDayBoard" class="agenda-team-board"></div>
                </div>
                <div id="agendaTeamDayLoading" class="agenda-calendar-loading" aria-hidden="true">
                    <div class="agenda-calendar-loading-box">
                        <i class="fa fa-spinner fa-spin" aria-hidden="true"></i>
                        <div class="agenda-calendar-loading-title">Caricamento vista team</div>
                        <div class="agenda-calendar-loading-note" id="agendaTeamDayLoadingText">
                            Sto aggiornando la vista giornaliera del team.
                        </div>
                    </div>
                </div>
            </div>
            <div id="agendaNoSlotsMessage" class="alert alert-info" style="display:none; margin-top:10px; margin-bottom:0;">
                Nessuna agenda impostata per il giorno selezionato.
            </div>
        </div>
    </div>
</div>
                            </div>
                        </div>

                        <?php if (!empty($domiciliariAbilitati)): ?>
                            <div class="col-lg-5 col-md-6 col-sm-12" id="boxVisiteDomiciliariCol">
                                <div class="box box-primary" id="boxVisiteDomiciliariPanel">
                                    <div class="box-header with-border">
                                        <h3 class="box-title"><i class="fa fa-home"></i> Visite domiciliari</h3>
                                        <div class="box-tools pull-right">
                                            <button type="button" class="btn btn-success btn-xs" id="btnNuovaVisita">
                                                <i class="fa fa-plus"></i> Nuova visita
                                            </button>
                                            <button type="button" class="btn btn-box-tool" data-widget="collapse" style="margin-left:4px;">
                                                <i class="fa fa-minus"></i>
                                            </button>
                                        </div>
                                    </div>
                                    <div class="box-body no-padding">
                                        <div class="table-responsive">
                                            <table class="table table-striped table-hover agenda-domiciliari-table" id="tabellaVisiteDomiciliari">
                                                <thead>
                                                <tr>
                                                    <th style="width:130px;">Inserita il</th>
                                                    <th>Paziente</th>
                                                    <th>Recapiti</th>
                                                    <th>Indirizzo</th>
                                                    <th style="width:100px;">Azioni</th>
                                                </tr>
                                                </thead>
                                                <tbody id="domiciliariList">
                                                <tr>
                                                    <td colspan="5" class="text-center text-muted">Caricamento...</td>
                                                </tr>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                    <div class="box-footer text-right">
                                        <div id="domiciliariLockNotice" class="agenda-domiciliari-lock-notice">
                                            La giornata agenda e bloccata: non puoi inserire o modificare le visite domiciliari per questo giorno.
                                        </div>
                                        <span class="text-muted small">Le visite domiciliari sono separate dal calendario</span>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                    </div>

                    <div id="agendaHomeBlock-memo" data-home-block-key="memo" data-home-placement="<?= esc($agendaHomeBlockPlacement('memo'), 'attr') ?>" class="agenda-home-block" style="<?= esc($agendaHomeBlockHiddenStyle('memo'), 'attr') ?>">
                    <div class="row">
                        <div class="col-xs-12">
                            <div class="box box-primary">
                                <div class="box-header with-border">
                                    <h3 class="box-title">
                                        <i class="fa fa-sticky-note"></i>
                                        <?= $sharedMemoManagementEnabled ? 'Memo condivise dello spazio' : 'Memo del dottore' ?>
                                    </h3>
                                    <div class="box-tools">
                                        <button type="button" class="btn btn-xs btn-primary" id="btnOpenNoteModalTop">
                                            <i class="fa fa-plus"></i> Nuova nota
                                        </button>
                                        <button type="button" class="btn btn-xs btn-default" id="btnPrintMemoPdf">
                                            <i class="fa fa-file-pdf-o"></i> Stampa PDF memo
                                        </button>
                                        <button type="button" class="btn btn-box-tool" data-widget="collapse">
                                            <i class="fa fa-minus"></i>
                                        </button>
                                    </div>
                                </div>

                                <div class="box-body">
                                    <div id="noteList">
                                        <div class="text-center text-muted" style="padding:20px;">Caricamento...</div>
                                    </div>
                                </div>

                                <div class="box-footer text-right">
                                    <span class="text-muted small">
                                        <?= $sharedMemoManagementEnabled
                                            ? 'Le memo possono essere viste e gestite da tutti i dottori dello spazio. In ogni card trovi sempre il dottore assegnato.'
                                            : 'Le note restano sempre visibili per il dottore selezionato' ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                    </div>
                    <div
                        id="agendaHomeEmptyState"
                        class="alert alert-info agenda-home-empty-state"
                        style="<?= $agendaHomeHasVisibleBlocks ? 'display:none;' : '' ?>"
                    >
                        Tutti i blocchi della home agenda sono nascosti. Puoi riattivarli da Spazio &gt; Funzioni.
                    </div>
                    </div>
                </div>
            </div>
        </section>
    </div>

    <footer class="main-footer">
        <div class="pull-right hidden-xs"><b>Version</b> 2.0</div>
        <strong>AmbulatorioFacile</strong>
    </footer>

    <aside class="control-sidebar control-sidebar-dark"></aside>
    <div class="control-sidebar-bg"></div>
</div>

<?php
$renderAppointmentModalBlock = static function (string $blockKey) use (
    $visitTypesFeatureEnabled,
    $visitTypeSelectionOptionalEnabled,
    $personalCommitmentsFeatureEnabled,
    $customAppointmentTimeFeatureEnabled,
    $patientSmsReminderPreferenceAvailable,
    $appointmentDocumentWorkflowVisible,
    $appointmentBillingWorkflowEnabled,
    $appointmentTsWorkflowEnabled
): string {
    ob_start();

    switch ($blockKey) {
        case 'time':
            ?>
            <div class="appointment-modal-block" data-appointment-block-key="time">
                <div class="row">
                    <div class="col-md-6 form-group">
                        <label for="app_ora_inizio">Ora inizio</label>
                        <input type="text" id="app_ora_inizio" class="form-control" readonly>
                    </div>

                    <div class="col-md-6 form-group">
                        <label for="app_ora_fine">Ora fine</label>
                        <input type="text" id="app_ora_fine" class="form-control" readonly>
                        <?php if (!empty($personalCommitmentsFeatureEnabled)): ?>
                        <select id="app_personal_commitment_end" class="form-control" style="display:none;"></select>
                        <p id="app_personal_commitment_time_help" class="help-block" style="display:none; margin:6px 0 0;">
                            Scegli fino a dove bloccare gli slot consecutivi disponibili.
                        </p>
                        <?php endif; ?>
                    </div>

                    <?php if (!empty($personalCommitmentsFeatureEnabled)): ?>
                    <div class="col-md-12">
                        <div id="app_personal_commitment_duration_info" class="agenda-appointment-coverage" style="display:none;"></div>
                    </div>
                    <?php endif; ?>

                    <?php if (!empty($customAppointmentTimeFeatureEnabled)): ?>
                    <div class="col-md-12">
                        <div class="appointment-custom-time-panel" id="appointmentCustomTimePanel">
                            <div class="appointment-custom-time-toggle">
                                <div>
                                    <div class="checkbox">
                                        <label for="app_custom_time_enabled">
                                            <input type="checkbox" id="app_custom_time_enabled" value="1">
                                            <strong>Orario personalizzato</strong>
                                        </label>
                                    </div>
                                    <p class="help-block">
                                        Scegli l ora reale di inizio e di fine: verranno riservati automaticamente tutti gli slot coinvolti.
                                    </p>
                                </div>
                            </div>

                            <div class="appointment-custom-time-fields" id="appointmentCustomTimeFields" style="display:none;">
                                <div class="row">
                                    <div class="col-sm-6 form-group">
                                        <label for="app_custom_start_time">Dalle</label>
                                        <input type="time" id="app_custom_start_time" class="form-control" step="300">
                                    </div>

                                    <div class="col-sm-6 form-group">
                                        <label for="app_custom_end_time">Alle</label>
                                        <input type="time" id="app_custom_end_time" class="form-control" step="300">
                                    </div>
                                </div>

                                <div id="app_custom_time_coverage" class="agenda-appointment-coverage appointment-custom-time-summary"></div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php
            break;

        case 'visit_type':
            if (empty($visitTypesFeatureEnabled)) {
                break;
            }
            ?>
            <div class="appointment-modal-block" data-appointment-block-key="visit_type">
                <div class="row">
                    <div class="col-md-8 form-group">
                        <label for="app_id_tipo_visita">Tipo visita</label>
                        <select id="app_id_tipo_visita" class="form-control">
                            <option value="">Seleziona tipo visita</option>
                        </select>
                        <p id="app_visit_type_help" class="help-block" style="margin:6px 0 0;">
                            <?= !empty($visitTypeSelectionOptionalEnabled)
                                ? 'Facoltativo: se lo lasci vuoto, l appuntamento occupa solo lo slot cliccato.'
                                : 'Selezionalo per applicare durata automatica e regole del tipo visita.' ?>
                        </p>
                        <div id="app_visit_type_preview" class="agenda-visit-type-select-preview is-empty">
                            <span id="app_visit_type_preview_sample" class="agenda-visit-type-select-sample"></span>
                            <div class="agenda-visit-type-select-copy">
                                <span id="app_visit_type_preview_label" class="agenda-visit-type-select-label">Nessun tipo visita selezionato</span>
                                <span id="app_visit_type_preview_meta" class="agenda-visit-type-select-meta">
                                    <?= !empty($visitTypeSelectionOptionalEnabled)
                                        ? 'Se non selezioni un tipo visita, l appuntamento usera solo lo slot cliccato.'
                                        : 'Seleziona un tipo visita per definire durata e copertura dell appuntamento.' ?>
                                </span>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-4 form-group">
                        <label for="app_durata_visita">Durata</label>
                        <input type="text" id="app_durata_visita" class="form-control" readonly>
                        <div id="app_slot_copertura_info" class="agenda-appointment-coverage"></div>
                    </div>
                </div>
            </div>
            <?php
            break;

        case 'patient_entry':
            ?>
            <div class="appointment-modal-block" data-appointment-block-key="patient_entry">
                <div class="row">
                    <?php if (!empty($personalCommitmentsFeatureEnabled)): ?>
                    <div class="col-md-12 form-group">
                        <label>Tipo prenotazione</label>
                        <div class="btn-group appointment-mode-switch" id="appointmentModeSwitch" role="group" aria-label="Tipo prenotazione">
                            <button type="button" class="btn btn-default is-active" data-appointment-mode="standard">Paziente</button>
                            <button type="button" class="btn btn-default" data-appointment-mode="personal_commitment">Impegno personale</button>
                        </div>
                        <p class="help-block" style="margin:6px 0 0;">
                            Gli impegni personali bloccano piu slot consecutivi con una voce speciale rossa, senza creare un paziente reale.
                        </p>
                    </div>

                    <div class="col-md-12">
                        <div id="appointmentPersonalCommitmentNotice" class="appointment-personal-commitment-note" style="display:none;">
                            <strong>Impegno personale</strong>
                            Questo blocco usa una voce speciale condivisa nello studio. Puoi scegliere liberamente l ora di fine in base agli slot consecutivi davvero disponibili.
                        </div>
                    </div>
                    <?php endif; ?>

                    <div id="appointmentPatientFields">
                    <div class="col-md-12 form-group" style="position:relative;">
                        <label for="searchPatient">Cerca o cambia paziente</label>
                        <div class="input-group">
                            <input type="text" id="searchPatient" class="form-control" autocomplete="off" placeholder="Cerca per nome, cognome o codice">
                            <span class="input-group-btn">
                                <button type="button" class="btn btn-default" id="btnNewAppointmentPatient">
                                    <i class="fa fa-user-plus"></i> Nuovo paziente
                                </button>
                            </span>
                        </div>
                        <div id="patientAutocomplete" class="agenda-autocomplete d-none"></div>
                        <div id="appointmentLinkedPatientInfo" class="help-block text-muted" style="margin-bottom:0;"></div>
                    </div>

                    <div class="col-md-6 form-group">
                        <label for="app_cognome">Cognome</label>
                        <input type="text" id="app_cognome" class="form-control">
                    </div>

                    <div class="col-md-6 form-group">
                        <label for="app_nome">Nome</label>
                        <input type="text" id="app_nome" class="form-control">
                    </div>

                    <div class="col-md-4 form-group">
                        <label for="app_telefono">Telefono</label>
                        <input type="text" id="app_telefono" class="form-control">
                    </div>

                    <div class="col-md-4 form-group">
                        <label for="app_cellulare">Cellulare</label>
                        <input type="text" id="app_cellulare" class="form-control">
                    </div>

                    <div class="col-md-4 form-group">
                        <label for="app_email">Email</label>
                        <input type="email" id="app_email" class="form-control">
                    </div>

                    <?php if (!empty($patientSmsReminderPreferenceAvailable)): ?>
                    <div class="col-md-12">
                        <div class="checkbox" style="margin:0 0 4px;">
                            <label>
                                <input type="checkbox" id="app_appointment_reminder_sms_enabled" value="1">
                                Attiva promemoria appuntamento via SMS per questo paziente
                            </label>
                        </div>
                        <p class="help-block" style="margin:0 0 12px;">
                            La preferenza resta salvata sul paziente e la ritrovi nei prossimi appuntamenti.
                        </p>
                    </div>
                    <?php endif; ?>
                    </div>

                    <div class="col-md-12 form-group">
                        <label for="app_note">Note</label>
                        <textarea id="app_note" rows="4" class="form-control"></textarea>
                    </div>
                </div>
            </div>
            <?php
            break;

        case 'document_workflow':
            if (!$appointmentDocumentWorkflowVisible) {
                break;
            }
            ?>
            <div class="appointment-modal-block" data-appointment-block-key="document_workflow">
                <div id="appointmentDocumentWorkflow" class="appointment-document-workflow-panel">
                    <div class="appointment-document-workflow-copy">
                        <div class="appointment-document-workflow-title">Azioni documento</div>
                        <div class="appointment-document-workflow-note">
                            Da qui apri subito il flusso giusto senza reinserire il paziente: nome, recapiti e collegamento allo spazio vengono riportati automaticamente.
                        </div>
                        <div id="appointmentDocumentWorkflowHint"></div>
                    </div>
                    <div class="appointment-document-workflow-buttons">
                        <button type="button" class="btn appointment-document-workflow-action appointment-document-workflow-action-billing" id="btnAppointmentBillingWorkflow" style="<?= $appointmentBillingWorkflowEnabled ? '' : 'display:none;' ?>"<?= $appointmentBillingWorkflowEnabled ? ' disabled' : '' ?>>
                            <span class="appointment-document-workflow-action-icon">
                                <i class="fa fa-file-text-o"></i>
                            </span>
                            <span class="appointment-document-workflow-action-copy">
                                <span class="appointment-document-workflow-action-title">Apri fattura</span>
                                <span class="appointment-document-workflow-action-text">Crea il documento di fatturazione gia precompilato e pronto anche per l eventuale invio a TS.</span>
                            </span>
                        </button>
                        <button type="button" class="btn appointment-document-workflow-action appointment-document-workflow-action-ts" id="btnAppointmentTsWorkflow" style="<?= $appointmentTsWorkflowEnabled ? '' : 'display:none;' ?>"<?= $appointmentTsWorkflowEnabled ? ' disabled' : '' ?>>
                            <span class="appointment-document-workflow-action-icon">
                                <i class="fa fa-paper-plane-o"></i>
                            </span>
                            <span class="appointment-document-workflow-action-copy">
                                <span class="appointment-document-workflow-action-title">Apri solo TS</span>
                                <span class="appointment-document-workflow-action-text">Salta la fattura e prepara subito il documento TS con il paziente gia agganciato.</span>
                            </span>
                        </button>
                    </div>
                </div>
            </div>
            <?php
            break;
    }

    return trim((string) ob_get_clean());
};

$appointmentModalBlockHtmlByKey = [];
foreach (['time', 'visit_type', 'patient_entry', 'document_workflow'] as $appointmentModalBlockKey) {
    $blockHtml = $renderAppointmentModalBlock($appointmentModalBlockKey);
    if ($blockHtml !== '') {
        $appointmentModalBlockHtmlByKey[$appointmentModalBlockKey] = $blockHtml;
    }
}

$appointmentModalRenderOrderKeys = [];
foreach ($appointmentModalEffectiveLayoutItems as $layoutItem) {
    $blockKey = trim((string) ($layoutItem['key'] ?? ''));
    if (
        $blockKey !== ''
        && isset($appointmentModalBlockHtmlByKey[$blockKey])
        && !in_array($blockKey, $appointmentModalRenderOrderKeys, true)
    ) {
        $appointmentModalRenderOrderKeys[] = $blockKey;
    }
}

if ($appointmentModalRenderOrderKeys === []) {
    $appointmentModalRenderOrderKeys = array_keys($appointmentModalBlockHtmlByKey);
}
?>

<div class="modal fade" id="appointmentModal" tabindex="-1" role="dialog" aria-hidden="true" data-backdrop="static" data-keyboard="false">
    <div class="modal-dialog modal-lg">
        <div class="modal-content border-0 shadow">
            <div class="modal-header">
                <button type="button" class="close btn-close-appointment-modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
                <h4 class="modal-title">Gestione appuntamento</h4>
            </div>

            <div class="modal-body">
                <input type="hidden" id="app_id_slot">
                <input type="hidden" id="app_id_dot">
                <input type="hidden" id="app_id_paziente">
                <input type="hidden" id="app_token_lock">
                <input type="hidden" id="app_id_appuntamento">
                <input type="hidden" id="app_origine_slot">
                <input type="hidden" id="app_paz_spec">
                <input type="hidden" id="app_special_mode" value="standard">

                <div class="appointment-modal-layout" id="appointmentModalLayout">
                    <?php foreach ($appointmentModalRenderOrderKeys as $appointmentModalRenderOrderKey): ?>
                        <?= $appointmentModalBlockHtmlByKey[$appointmentModalRenderOrderKey] ?? '' ?>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="modal-footer appointment-modal-footer">
                <div class="appointment-modal-footer-left">
                    <button type="button" class="btn btn-danger" id="btnDeleteExtraSlot" style="display:none;">
                        <i class="fa fa-trash"></i> Elimina slot extra
                    </button>

                    <button type="button" class="btn btn-danger" id="btnDeleteAppointment" style="display:none;">
                        <i class="fa fa-trash"></i> Elimina appuntamento
                    </button>
                </div>

                <div class="appointment-modal-footer-right">
                    <button type="button" class="btn btn-default" id="btnCancelAppointmentModal">
                        <i class="fa fa-times"></i> Chiudi
                    </button>

                    <button type="button" class="btn btn-primary" id="btnSaveAppointment">
                        <i class="fa fa-check"></i> Conferma prenotazione
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="noteModal" tabindex="-1" role="dialog" aria-hidden="true" data-backdrop="static" data-keyboard="false">
    <div class="modal-dialog modal-lg">
        <div class="modal-content border-0 shadow">
            <div class="modal-header">
                <button type="button" class="close btn-close-note-modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
                <h4 class="modal-title" id="noteModalTitle">Nuova nota</h4>
            </div>

            <div class="modal-body">
                <input type="hidden" id="nota_id_nota">
                <input type="hidden" id="nota_id_dot">
                <input type="hidden" id="nota_id_paziente">

                <div class="row">
                    <?php if ($sharedMemoManagementEnabled): ?>
                    <div class="col-md-8 form-group">
                        <label for="nota_doctor_select">Dottore assegnato *</label>
                        <select id="nota_doctor_select" class="form-control">
                            <?php foreach ($memoDoctorSelectOptions as $doctorOption): ?>
                                <option value="<?= (int) ($doctorOption['id_dot'] ?? 0) ?>">
                                    <?= esc((string) ($doctorOption['label'] ?? '')) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>

                    <div class="col-md-4 form-group">
                        <label for="nota_data_inizio_validita">Data inizio validitÃ  *</label>
                        <input type="date" id="nota_data_inizio_validita" class="form-control" value="<?= esc(date('Y-m-d')) ?>">
                    </div>

                    <div class="col-md-8 form-group" style="position:relative;">
                        <label for="nota_cliente">Cliente *</label>
                        <input type="text" id="nota_cliente" class="form-control" autocomplete="off" placeholder="Inserisci liberamente o cerca un paziente">
                        <div id="notePatientAutocomplete" class="agenda-autocomplete d-none"></div>
                    </div>

                    <div class="col-md-6 form-group">
                        <label for="nota_telefono">Telefono *</label>
                        <input type="text" id="nota_telefono" class="form-control">
                    </div>

                    <div class="col-md-6 form-group">
                        <label for="nota_cellulare">Cellulare *</label>
                        <input type="text" id="nota_cellulare" class="form-control">
                    </div>

                    <div class="col-md-8 form-group">
                        <label for="nota_indirizzo">Indirizzo</label>
                        <input type="text" id="nota_indirizzo" class="form-control">
                    </div>

                    <div class="col-md-4 form-group">
                        <label for="nota_citta">CittÃ </label>
                        <input type="text" id="nota_citta" class="form-control">
                    </div>

                    <div class="col-md-12 form-group">
                        <label for="nota_note">Note</label>
                        <textarea id="nota_note" rows="5" class="form-control"></textarea>
                    </div>

                    <div class="col-md-12 form-group" style="margin-bottom:0;">
                        <div class="checkbox" style="margin:0;">
                            <label>
                                <input type="checkbox" id="nota_fatta" value="1"> Segna come fatta
                            </label>
                        </div>
                    </div>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-danger pull-left" id="btnDeleteNote" style="display:none;">
                    <i class="fa fa-trash"></i> Elimina nota
                </button>

                <button type="button" class="btn btn-default btn-close-note-modal">
                    <i class="fa fa-times"></i> Chiudi
                </button>

                <button type="button" class="btn btn-primary" id="btnSaveNote">
                    <i class="fa fa-save"></i> Salva nota
                </button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="extraSlotModal" tabindex="-1" role="dialog" aria-hidden="true" data-backdrop="static" data-keyboard="false">
    <div class="modal-dialog" role="document">
        <div class="modal-content border-0 shadow">
            <div class="modal-header">
                <button type="button" class="close btn-close-extra-slot-modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
                <h4 class="modal-title">Aggiungi slot extra</h4>
            </div>
            <div class="modal-body">
                <div class="alert alert-danger" id="extraSlotModalError" style="display:none; margin-bottom:15px;"></div>
                <p class="text-muted" style="margin-bottom:15px;">
                    Inserisci solo ora di inizio e ora di fine. La durata dello slot verrÃ  calcolata automaticamente.
                </p>

                <div class="form-group" id="extra_slot_doctor_group" style="display:none;">
                    <label for="extra_slot_id_dot">Professionista</label>
                    <select id="extra_slot_id_dot" class="form-control">
                        <option value="">Seleziona il professionista</option>
                        <?php foreach (($medici ?? []) as $m): ?>
                            <?php
                                $extraSlotDoctorId = is_object($m) ? $m->id_dot : $m['id_dot'];
                                $extraSlotDoctorLabel = is_object($m)
                                    ? ($m->label ?? (($m->cognome ?? '') . ' ' . ($m->nome ?? '')))
                                    : ($m['label'] ?? (($m['cognome'] ?? '') . ' ' . ($m['nome'] ?? '')));
                            ?>
                            <option value="<?= esc($extraSlotDoctorId) ?>">
                                <?= esc($extraSlotDoctorLabel) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="help-block" style="margin-bottom:0;">
                        In Giorno Team lo slot extra viene aggiunto al professionista scelto qui, anche se in alto e selezionato un altro medico.
                    </p>
                </div>

                <div class="row">
                    <div class="col-sm-6 form-group">
                        <label for="extra_slot_ora_inizio">Ora inizio</label>
                        <input type="time" id="extra_slot_ora_inizio" class="form-control">
                    </div>

                    <div class="col-sm-6 form-group">
                        <label for="extra_slot_ora_fine">Ora fine</label>
                        <input type="time" id="extra_slot_ora_fine" class="form-control">
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-default btn-close-extra-slot-modal">
                    <i class="fa fa-times"></i> Annulla
                </button>
                <button type="button" class="btn btn-warning" id="btnSaveExtraSlotModal">
                    <i class="fa fa-check"></i> Inserisci slot extra
                </button>
            </div>
        </div>
    </div>
</div>

<?php if (!empty($domiciliariAbilitati)): ?>
<div class="modal fade" id="modalVisitaDomiciliare" tabindex="-1" role="dialog" aria-hidden="true" data-backdrop="static" data-keyboard="false">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <button type="button" class="close btnChiudiVisita" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                <h4 class="modal-title">Visita domiciliare</h4>
            </div>
            <div class="modal-body">
                <input type="hidden" id="vd_id_visita">
                <input type="hidden" id="vd_id_dot" value="<?= (int)($selectedDot ?? 0) ?>">
                <input type="hidden" id="vd_id_paziente">

                <div class="form-group" style="position:relative;">
                    <label for="vd_search_paziente">Cerca paziente</label>
                    <input type="text" id="vd_search_paziente" class="form-control" autocomplete="off" placeholder="Cerca per nome, cognome o codice">
                    <div id="vd_search_results" class="agenda-autocomplete-vd d-none"></div>
                </div>

                <div class="row">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label for="vd_cognome">Cognome</label>
                            <input type="text" id="vd_cognome" class="form-control">
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label for="vd_nome">Nome</label>
                            <input type="text" id="vd_nome" class="form-control">
                        </div>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-6">
                        <div class="form-group">
                            <label for="vd_telefono">Telefono</label>
                            <input type="text" id="vd_telefono" class="form-control">
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group">
                            <label for="vd_cellulare">Cellulare</label>
                            <input type="text" id="vd_cellulare" class="form-control">
                        </div>
                    </div>
                </div>

                <div class="form-group">
                    <label for="vd_indirizzo">Indirizzo</label>
                    <input type="text" id="vd_indirizzo" class="form-control">
                </div>

                <div class="form-group">
                    <label for="vd_citta">CittÃ </label>
                    <input type="text" id="vd_citta" class="form-control">
                </div>

                <div class="form-group" style="margin-bottom:0;">
                    <label for="vd_note">Note</label>
                    <textarea id="vd_note" class="form-control" rows="4"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-danger pull-left" id="btnEliminaVisita" style="display:none;">
                    <i class="fa fa-trash"></i> Elimina
                </button>
                <button type="button" class="btn btn-default btnChiudiVisita">
                    <i class="fa fa-times"></i> Annulla
                </button>
                <button type="button" class="btn btn-success" id="btnSalvaVisita">
                    <i class="fa fa-save"></i> Salva
                </button>
                <button type="button" class="btn btn-primary" id="btnAggiornaVisita" style="display:none;">
                    <i class="fa fa-pencil"></i> Modifica
                </button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
var giornoBloccato = false;
var memoGiornoBloccato = false;
var domiciliareGiornoBloccato = false;
var canBloccareGiorno = false;
var agendaBlockedDaysMap = {};
var agendaMemoBlockedDaysMap = {};
var agendaDomiciliaryBlockedDaysMap = {};
var notaGiornoDirty = false;
var notaGiornoUltimoValore = '';
window.AGENDA_CONFIG = {
    baseUrl: "<?= base_url('agenda') ?>",
    selectedDot: <?= (int)($selectedDot ?? 0) ?>,
    selectedDate: "<?= esc($selectedDate ?? date('Y-m-d')) ?>",
    viewMode: "<?= esc($viewMode ?? 'day') ?>",
    focusedAppointmentId: <?= (int)($focusedAppointmentId ?? 0) ?>,
    notificationContext: "<?= esc((string)($notificationContext ?? '')) ?>",
    domiciliariAbilitati: <?= !empty($domiciliariAbilitati) ? 'true' : 'false' ?>,
    teamDayViewEnabled: <?= !empty($teamDayViewEnabled) ? 'true' : 'false' ?>,
    teamDaySingleSlotHeightFeatureEnabled: <?= !empty($teamDaySingleSlotHeightFeatureEnabled) ? 'true' : 'false' ?>,
    teamDayCompactSlotDetailsFeatureEnabled: <?= !empty($teamDayCompactSlotDetailsFeatureEnabled) ? 'true' : 'false' ?>,
    agendaAutoRefreshFeatureEnabled: <?= !empty($agendaAutoRefreshFeatureEnabled) ? 'true' : 'false' ?>,
    skipEmptyAgendaDaysEnabled: <?= !empty($skipEmptyAgendaDaysEnabled) ? 'true' : 'false' ?>,
    compressedLayoutEnabled: <?= $agendaCompressedLayoutEnabled ? 'true' : 'false' ?>,
    sharedMemoManagementEnabled: <?= $sharedMemoManagementEnabled ? 'true' : 'false' ?>,
    sharedAgendaPatientsEnabled: <?= !empty($sharedAgendaPatientsEnabled) ? 'true' : 'false' ?>,
    visitTypesFeatureEnabled: <?= !empty($visitTypesFeatureEnabled) ? 'true' : 'false' ?>,
    visitTypeSelectionOptionalEnabled: <?= !empty($visitTypeSelectionOptionalEnabled) ? 'true' : 'false' ?>,
    personalCommitmentsFeatureEnabled: <?= !empty($personalCommitmentsFeatureEnabled) ? 'true' : 'false' ?>,
    customAppointmentTimeFeatureEnabled: <?= !empty($customAppointmentTimeFeatureEnabled) ? 'true' : 'false' ?>,
    personalCommitmentSpecialCode: "IMPEGNO_PERSONALE",
    personalCommitmentLabel: "Impegno personale",
    appointmentBillingWorkflowEnabled: <?= !empty($appointmentDocumentActions['billing_enabled']) ? 'true' : 'false' ?>,
    appointmentTsWorkflowEnabled: <?= !empty($appointmentDocumentActions['ts_enabled']) ? 'true' : 'false' ?>,
    patientSmsReminderPreferenceAvailable: <?= !empty($patientSmsReminderPreferenceAvailable) ? 'true' : 'false' ?>,
    appointmentBillingWorkflowUrlBase: "<?= site_url('agenda/fatturazione-da-appuntamento') ?>",
    appointmentTsWorkflowUrlBase: "<?= site_url('agenda/ts-da-appuntamento') ?>",
    csrfTokenName: "<?= esc(csrf_token()) ?>",
    csrfTokenValue: "<?= esc(csrf_hash()) ?>"
};
</script>

<script src="<?= base_url('public/plugins/slimScroll/jquery.slimscroll.min.js') . $assetVersion('public/plugins/slimScroll/jquery.slimscroll.min.js') ?>"></script>
<script src="<?= base_url('public/plugins/fastclick/fastclick.min.js') . $assetVersion('public/plugins/fastclick/fastclick.min.js') ?>"></script>
<script src="<?= base_url('public/dist/js/app.min.js') . $assetVersion('public/dist/js/app.min.js') ?>"></script>
<script src="<?= base_url('public/plugins/iCheck/icheck.min.js') . $assetVersion('public/plugins/iCheck/icheck.min.js') ?>"></script>

<script src="<?= base_url('public/plugins/daterangepicker/moment.min.js') . $assetVersion('public/plugins/daterangepicker/moment.min.js') ?>"></script>
<script src="<?= base_url('public/plugins/fullcalendar/fullcalendar.min.js') . $assetVersion('public/plugins/fullcalendar/fullcalendar.min.js') ?>"></script>
<script>
(function() {
    var layout = document.getElementById('agendaLayout');
    var meta = document.getElementById('agendaHomeBlockOrderMeta');
    var leftColumn = document.getElementById('agendaSidebarCustomBlocks');
    var mainColumn = document.getElementById('agendaHomeMainColumn');
    var emptyState = document.getElementById('agendaHomeEmptyState');

    if (!layout || !meta || !leftColumn || !mainColumn) {
        return;
    }

    var fallbackLayout = [
        { key: 'doctor_selector', column: 'main' },
        { key: 'patient_search', column: 'main' },
        { key: 'day_note', column: 'main' },
        { key: 'calendar', column: 'main' },
        { key: 'memo', column: 'main' }
    ];
    var layoutItems = [];

    try {
        layoutItems = JSON.parse(meta.getAttribute('data-layout-items') || '[]');
    } catch (error) {
        layoutItems = [];
    }

    if (!Array.isArray(layoutItems) || layoutItems.length === 0) {
        layoutItems = fallbackLayout;
    }

    var blocksByKey = {
        doctor_selector: document.getElementById('agendaHomeBlock-doctor_selector'),
        day_note: document.getElementById('agendaHomeBlock-day_note'),
        patient_search: document.getElementById('agendaHomeBlock-patient_search'),
        calendar: document.getElementById('agendaHomeBlock-calendar'),
        memo: document.getElementById('agendaHomeBlock-memo')
    };
    var placedBlocks = {};

    layoutItems.forEach(function(layoutItem) {
        if (!layoutItem || typeof layoutItem !== 'object') {
            return;
        }

        var blockKey = String(layoutItem.key || '').trim();
        if (blockKey === '' || !blocksByKey[blockKey]) {
            return;
        }

        var columnKey = String(layoutItem.column || 'main').trim().toLowerCase();
        if (['main', 'left', 'hidden'].indexOf(columnKey) === -1) {
            columnKey = 'main';
        }

        if (columnKey === 'hidden') {
            blocksByKey[blockKey].style.display = 'none';
            mainColumn.appendChild(blocksByKey[blockKey]);
            placedBlocks[blockKey] = true;
            return;
        }

        var targetColumn = columnKey === 'left' ? leftColumn : mainColumn;
        blocksByKey[blockKey].style.display = '';
        targetColumn.appendChild(blocksByKey[blockKey]);
        placedBlocks[blockKey] = true;
    });

    Object.keys(blocksByKey).forEach(function(blockKey) {
        if (!placedBlocks[blockKey] && blocksByKey[blockKey]) {
            blocksByKey[blockKey].style.display = '';
            mainColumn.appendChild(blocksByKey[blockKey]);
        }
    });

    var hasVisibleLeftBlocks = Array.prototype.some.call(leftColumn.children, function(node) {
        return node && node.style.display !== 'none';
    });
    var hasVisibleMainBlocks = Array.prototype.some.call(mainColumn.children, function(node) {
        return node && node.id !== 'agendaHomeEmptyState' && node.style.display !== 'none';
    });

    layout.classList.toggle('has-sidebar-custom-blocks', hasVisibleLeftBlocks);

    if (emptyState) {
        emptyState.style.display = hasVisibleLeftBlocks || hasVisibleMainBlocks ? 'none' : '';
    }
})();
</script>
<script>
(function() {
    var meta = document.getElementById('agendaSidebarBlockOrderMeta');
    var sidebarPanels = document.getElementById('agendaSidebarPanels');
    var customHost = document.getElementById('agendaSidebarCustomBlocks');

    if (!meta || !sidebarPanels || !customHost) {
        return;
    }

    var fallbackOrder = [
        'sidebar_menu',
        'sidebar_calendar',
        'doctor_selector',
        'patient_search',
        'day_note',
        'calendar',
        'memo'
    ];
    var orderKeys = [];

    try {
        orderKeys = JSON.parse(meta.getAttribute('data-order-keys') || '[]');
    } catch (error) {
        orderKeys = [];
    }

    if (!Array.isArray(orderKeys) || orderKeys.length === 0) {
        orderKeys = fallbackOrder;
    }

    var blocksByKey = {
        sidebar_menu: document.getElementById('agendaSidebarBlock-sidebar_menu'),
        sidebar_calendar: document.getElementById('agendaSidebarBlock-sidebar_calendar'),
        doctor_selector: document.getElementById('agendaHomeBlock-doctor_selector'),
        patient_search: document.getElementById('agendaHomeBlock-patient_search'),
        day_note: document.getElementById('agendaHomeBlock-day_note'),
        calendar: document.getElementById('agendaHomeBlock-calendar'),
        memo: document.getElementById('agendaHomeBlock-memo')
    };
    var fixedKeys = {
        sidebar_menu: true,
        sidebar_calendar: true
    };
    var placedKeys = {};

    function appendSidebarNode(node) {
        if (!node) {
            return;
        }

        node.classList.add('agenda-sidebar-stack-item');
        sidebarPanels.appendChild(node);
    }

    function isDynamicLeftNode(node) {
        return !!(node && node.parentElement === customHost);
    }

    orderKeys.forEach(function(orderKey) {
        var blockKey = String(orderKey || '').trim();
        var node = blocksByKey[blockKey] || null;
        if (!node) {
            return;
        }

        if (fixedKeys[blockKey]) {
            appendSidebarNode(node);
            placedKeys[blockKey] = true;
            return;
        }

        if (!isDynamicLeftNode(node)) {
            return;
        }

        appendSidebarNode(node);
        placedKeys[blockKey] = true;
    });

    Object.keys(blocksByKey).forEach(function(blockKey) {
        if (placedKeys[blockKey] || fixedKeys[blockKey]) {
            return;
        }

        var node = blocksByKey[blockKey] || null;
        if (!isDynamicLeftNode(node)) {
            return;
        }

        appendSidebarNode(node);
    });
})();
</script>
<script>
if (window.moment && window.moment.fn) {
    if (typeof window.moment.fn.isSameOrBefore !== 'function') {
        window.moment.fn.isSameOrBefore = function(input, units) {
            return this.isSame(input, units) || this.isBefore(input, units);
        };
    }

    if (typeof window.moment.fn.isSameOrAfter !== 'function') {
        window.moment.fn.isSameOrAfter = function(input, units) {
            return this.isSame(input, units) || this.isAfter(input, units);
        };
    }
}

if (window.moment && typeof window.moment.locale === 'function') {
    window.moment.locale('it', {
        months: ['gennaio', 'febbraio', 'marzo', 'aprile', 'maggio', 'giugno', 'luglio', 'agosto', 'settembre', 'ottobre', 'novembre', 'dicembre'],
        monthsShort: ['gen', 'feb', 'mar', 'apr', 'mag', 'giu', 'lug', 'ago', 'set', 'ott', 'nov', 'dic'],
        weekdays: ['domenica', 'lunedi', 'martedi', 'mercoledi', 'giovedi', 'venerdi', 'sabato'],
        weekdaysShort: ['dom', 'lun', 'mar', 'mer', 'gio', 'ven', 'sab'],
        weekdaysMin: ['do', 'lu', 'ma', 'me', 'gi', 've', 'sa'],
        longDateFormat: {
            LT: 'HH:mm',
            LTS: 'HH:mm:ss',
            L: 'DD/MM/YYYY',
            LL: 'D MMMM YYYY',
            LLL: 'D MMMM YYYY HH:mm',
            LLLL: 'dddd D MMMM YYYY HH:mm'
        },
        relativeTime: {
            future: 'tra %s',
            past: '%s fa',
            s: 'alcuni secondi',
            m: 'un minuto',
            mm: '%d minuti',
            h: 'un ora',
            hh: '%d ore',
            d: 'un giorno',
            dd: '%d giorni',
            M: 'un mese',
            MM: '%d mesi',
            y: 'un anno',
            yy: '%d anni'
        },
        week: {
            dow: 1,
            doy: 4
        }
    });
}

if (window.jQuery && window.jQuery.fullCalendar) {
    var agendaItalianLocale = {
        buttonText: {
            today: 'oggi',
            month: 'mese',
            week: 'settimana',
            day: 'giorno',
            list: 'agenda'
        },
        allDayText: 'Tutto il giorno',
        eventLimitText: 'altro',
        noEventsMessage: 'Nessun evento da visualizzare'
    };

    if (typeof window.jQuery.fullCalendar.locale === 'function') {
        window.jQuery.fullCalendar.locale('it', agendaItalianLocale);
    } else if (typeof window.jQuery.fullCalendar.lang === 'function') {
        window.jQuery.fullCalendar.lang('it', agendaItalianLocale);
    }
}
</script>

<script>
var agendaCalendarStep = 15;
var agendaCalendarBaseStep = 15;
var agendaMinTime = '08:00:00';
var agendaMaxTime = '18:00:00';
var agendaSlotFixedHeightPx = 60;
var agendaCurrentSlots = [];
var vdAutocompleteTimer = null;
var appointmentModalDate = '';
var appointmentSearchFocusRequested = false;
var appointmentSaveXhr = null;
var extraSlotSaveXhr = null;
var extraSlotSaveDefaultHtml = '';
// Evita che risposte AJAX vecchie sovrascrivano il giorno appena caricato.
var agendaStatusXhr = null;
var agendaStatusRequestSeq = 0;
var agendaCalendarXhr = null;
var agendaCalendarRequestSeq = 0;
var agendaTeamDayXhr = null;
var agendaTeamDayRequestSeq = 0;
var agendaTeamDayLastResponse = null;
var agendaTeamSlotIndex = {};
var agendaTeamAllSlots = [];
var agendaAvailabilityJumpXhr = null;
var agendaMiniCalendarMonth = null;
var agendaMiniCalendarAvailabilityMap = {};
var agendaMiniCalendarAvailabilityCounts = {};
var agendaMiniCalendarXhr = null;
var agendaMiniCalendarRequestSeq = 0;
var agendaVisitTypes = <?= json_encode(array_values(is_array($visitTypes ?? null) ? $visitTypes : []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
var agendaVisitTypeColorPalette = ['#3C8DBC', '#16A085', '#5E72E4', '#EB6B56', '#8E44AD', '#F39C12', '#27AE60', '#C0392B', '#2C82C9', '#D35400'];
var appointmentModalSlot = null;
var appointmentStandardDraftCache = null;

function supportsAgendaVisitTypes() {
    return !!window.AGENDA_CONFIG.visitTypesFeatureEnabled;
}

function supportsAgendaPersonalCommitments() {
    return !!window.AGENDA_CONFIG.personalCommitmentsFeatureEnabled;
}

function supportsAgendaCustomAppointmentTime() {
    return !!window.AGENDA_CONFIG.customAppointmentTimeFeatureEnabled;
}

function isAppointmentCustomTimeEnabled() {
    return supportsAgendaCustomAppointmentTime()
        && !isAppointmentPersonalCommitmentMode()
        && $('#app_custom_time_enabled').is(':checked');
}

function getAgendaPersonalCommitmentSpecialCode() {
    return $.trim(window.AGENDA_CONFIG.personalCommitmentSpecialCode || 'IMPEGNO_PERSONALE').toUpperCase();
}

function getAgendaPersonalCommitmentLabel() {
    return $.trim(window.AGENDA_CONFIG.personalCommitmentLabel || 'Impegno personale');
}

function isAppointmentPersonalCommitmentMode() {
    return supportsAgendaPersonalCommitments()
        && $.trim(String($('#app_special_mode').val() || '')) === 'personal_commitment';
}

function formatAgendaDurationMinutes(totalMinutes) {
    totalMinutes = parseInt(totalMinutes || 0, 10) || 0;
    if (totalMinutes <= 0) {
        return '';
    }

    if (totalMinutes < 60) {
        return totalMinutes + ' minuti';
    }

    var hours = Math.floor(totalMinutes / 60);
    var minutes = totalMinutes % 60;
    if (minutes <= 0) {
        return hours === 1 ? '1 ora' : (hours + ' ore');
    }

    return hours + 'h ' + minutes + 'm';
}

function isAgendaVisitTypeSelectionOptional() {
    return supportsAgendaVisitTypes() && !!window.AGENDA_CONFIG.visitTypeSelectionOptionalEnabled;
}

function isAgendaVisitTypeSelectionRequired() {
    return supportsAgendaVisitTypes() && !isAgendaVisitTypeSelectionOptional();
}

function supportsTeamDayView() {
    return !!window.AGENDA_CONFIG.teamDayViewEnabled;
}

function supportsAgendaTeamDaySingleSlotHeightFeature() {
    return !!window.AGENDA_CONFIG.teamDaySingleSlotHeightFeatureEnabled;
}

function supportsAgendaTeamDayCompactSlotDetailsFeature() {
    return !!window.AGENDA_CONFIG.teamDayCompactSlotDetailsFeatureEnabled;
}

function supportsAgendaSkipEmptyDaysNavigation() {
    return !!window.AGENDA_CONFIG.skipEmptyAgendaDaysEnabled;
}

function getSelectedAgendaMoment() {
    var rawValue = $.trim($('#agenda_date').val() || window.AGENDA_CONFIG.selectedDate || '');
    var selected = moment(rawValue, 'YYYY-MM-DD', true);

    if (!selected.isValid()) {
        selected = moment(window.AGENDA_CONFIG.selectedDate, 'YYYY-MM-DD', true);
    }

    if (!selected.isValid()) {
        selected = moment();
    }

    return selected.clone().locale('it');
}

function syncAgendaTeamDayToolbar() {
    var $weekday = $('#agendaTeamDayCurrentWeekday');
    var $dayNumber = $('#agendaTeamDayCurrentDayNumber');
    var $month = $('#agendaTeamDayCurrentMonth');
    var $year = $('#agendaTeamDayCurrentYear');

    if (!$weekday.length || !$dayNumber.length || !$month.length || !$year.length) {
        return;
    }

    var selected = getSelectedAgendaMoment();
    var weekdayLabel = selected.format('dddd');
    var dayNumberLabel = selected.format('D');
    var monthLabel = selected.format('MMMM');
    var yearLabel = selected.format('YYYY');

    if (weekdayLabel) {
        weekdayLabel = weekdayLabel.charAt(0).toUpperCase() + weekdayLabel.slice(1);
    }

    if (monthLabel) {
        monthLabel = monthLabel.charAt(0).toUpperCase() + monthLabel.slice(1);
    }

    $weekday.text(weekdayLabel || '-');
    $dayNumber.text(dayNumberLabel || '-');
    $month.text(monthLabel || '-');
    $year.text(yearLabel || '-');
}

function syncAgendaCompressedDaybar() {
    var $label = $('#agendaCompressedDaybarLabel');
    var $kickerText = $('#agendaCompressedDaybarKickerText');
    var $kickerIcon = $('#agendaCompressedDaybarKickerIcon');

    if (!$label.length || !$kickerText.length || !$kickerIcon.length) {
        return;
    }

    var selected = getSelectedAgendaMoment();
    var activeView = normalizeAgendaViewModeValue($('#view_mode').val());
    var label = '';
    var kickerText = 'Giorno agenda';
    var kickerIcon = 'fa-calendar-o';

    if (activeView === 'team_day') {
        kickerText = 'Agenda del team';
        kickerIcon = 'fa-columns';
    } else if (activeView === 'week') {
        kickerText = 'Settimana agenda';
        kickerIcon = 'fa-calendar';
    }

    if (activeView === 'week') {
        var weekStart = selected.clone().startOf('isoWeek');
        var weekEnd = selected.clone().endOf('isoWeek');
        label = 'Dal ' + weekStart.format('D MMMM') + ' al ' + weekEnd.format('D MMMM YYYY');
    } else {
        var weekdayLabel = selected.format('dddd');
        if (weekdayLabel) {
            weekdayLabel = weekdayLabel.charAt(0).toUpperCase() + weekdayLabel.slice(1);
        }

        label = weekdayLabel + ' ' + selected.format('D MMMM YYYY');
    }

    $kickerText.text(kickerText);
    $kickerIcon.attr('class', 'fa ' + kickerIcon);
    $label.text(label);
}

function hasAgendaDayNoteValue(value) {
    return $.trim(String(value || '')) !== '';
}

function syncAgendaDayNoteIndicators(value) {
    var hasNote = hasAgendaDayNoteValue(value);

    $('[data-agenda-day-note-indicator]').toggleClass('is-hidden', !hasNote);
}

function buildAgendaAvailabilityNavigationMessage(direction, view) {
    var normalizedDirection = direction === 'prev' ? 'prev' : 'next';
    var normalizedView = normalizeAgendaViewModeValue(view);

    if (normalizedView === 'week') {
        return normalizedDirection === 'prev'
            ? 'Sto cercando la settimana precedente con agenda.'
            : 'Sto cercando la settimana successiva con agenda.';
    }

    if (normalizedView === 'team_day') {
        return normalizedDirection === 'prev'
            ? 'Sto cercando il giorno precedente del team con agenda.'
            : 'Sto cercando il giorno successivo del team con agenda.';
    }

    return normalizedDirection === 'prev'
        ? 'Sto cercando il giorno precedente con agenda.'
        : 'Sto cercando il giorno successivo con agenda.';
}

function navigateAgendaToNearestAvailableDay(direction) {
    direction = direction === 'prev' ? 'prev' : 'next';

    var filtri = leggiFiltriAgenda();
    var currentDate = $.trim(filtri.data || '');
    var idDot = parseInt($('#id_dot').val(), 10) || 0;

    if (!moment(currentDate, 'YYYY-MM-DD', true).isValid()) {
        showAgendaToast('La data selezionata non e valida.', 'error');
        return;
    }

    if (filtri.view !== 'team_day' && idDot <= 0) {
        showAgendaToast('Seleziona un professionista per continuare.', 'error');
        return;
    }

    if (agendaAvailabilityJumpXhr && agendaAvailabilityJumpXhr.readyState !== 4) {
        agendaAvailabilityJumpXhr.abort();
    }

    setAgendaCalendarLoading(true, buildAgendaAvailabilityNavigationMessage(direction, filtri.view));

    agendaAvailabilityJumpXhr = $.get("<?= base_url('agenda/prossimo-giorno-disponibile') ?>", {
        id_dot: idDot,
        data: currentDate,
        view: filtri.view,
        direction: direction
    }, function(res) {
        agendaAvailabilityJumpXhr = null;

        if (!res || !res.status) {
            setAgendaCalendarLoading(false);
            showAgendaToast((res && res.message) ? res.message : 'Impossibile cercare il prossimo giorno con agenda.', 'error');
            return;
        }

        if (!res.found || !moment(res.date, 'YYYY-MM-DD', true).isValid()) {
            setAgendaCalendarLoading(false);
            showAgendaToast(res.message || 'Non ci sono altri giorni con agenda in questa direzione.', 'error');
            return;
        }

        $('#agenda_date').val(res.date);
        caricaTutto();
    }, 'json').fail(function(xhr, textStatus) {
        agendaAvailabilityJumpXhr = null;

        if (textStatus === 'abort') {
            return;
        }

        setAgendaCalendarLoading(false);

        var message = 'Impossibile cercare il prossimo giorno con agenda.';
        if (xhr && xhr.responseJSON && xhr.responseJSON.message) {
            message = xhr.responseJSON.message;
        }

        showAgendaToast(message, 'error');
    });
}

function navigateAgendaSelectedDay(dayOffset) {
    if (!supportsAgendaSkipEmptyDaysNavigation()) {
        if (isTeamDayViewActive()) {
            var selected = getSelectedAgendaMoment();
            $('#agenda_date').val(selected.add(dayOffset, 'days').format('YYYY-MM-DD'));
            caricaTutto();
            return;
        }

        if ($('#calendar').data('fullCalendar')) {
            $('#calendar').fullCalendar(dayOffset < 0 ? 'prev' : 'next');
            setTimeout(syncCalendarDateAndReload, 0);
            return;
        }

        var fallbackDate = dayOffset < 0
            ? moment($('#agenda_date').val()).subtract(1, 'days').format('YYYY-MM-DD')
            : moment($('#agenda_date').val()).add(1, 'days').format('YYYY-MM-DD');
        $('#agenda_date').val(fallbackDate);
        caricaTutto();
        return;
    }

    navigateAgendaToNearestAvailableDay(dayOffset < 0 ? 'prev' : 'next');
}

function navigateAgendaToday() {
    $('#agenda_date').val(moment().format('YYYY-MM-DD'));
    caricaTutto();
}

function isSharedMemoManagementEnabled() {
    return !!window.AGENDA_CONFIG.sharedMemoManagementEnabled;
}

function normalizeAgendaVisitTypesRows(rows) {
    var normalized = [];

    $.each(rows || [], function(_, row) {
        var id = parseInt((row && row.id_tipo_visita) || 0, 10) || 0;
        if (id <= 0) {
            return true;
        }

        normalized.push({
            id_tipo_visita: id,
            nome: $.trim((row && row.nome) || ''),
            durata_minuti: parseInt((row && row.durata_minuti) || 0, 10) || 0,
            usa_colore_tipo_visita_slot: agendaVisitTypeUsesOwnColor(row) ? 1 : 0,
            attivo: parseInt((row && row.attivo) || 0, 10) === 1 ? 1 : 0,
            ordinamento: parseInt((row && row.ordinamento) || 0, 10) || 0,
            colore: normalizeAgendaVisitTypeColor(row && row.colore ? row.colore : '')
        });

        return true;
    });

    normalized.sort(function(leftRow, rightRow) {
        if (leftRow.attivo !== rightRow.attivo) {
            return rightRow.attivo - leftRow.attivo;
        }
        if (leftRow.ordinamento !== rightRow.ordinamento) {
            return leftRow.ordinamento - rightRow.ordinamento;
        }
        return String(leftRow.nome || '').localeCompare(String(rightRow.nome || ''), 'it');
    });

    return normalized;
}

function normalizeAgendaVisitTypeColor(value) {
    var normalized = $.trim(String(value || '')).toUpperCase();
    return /^#[0-9A-F]{6}$/.test(normalized) ? normalized : '';
}

function agendaVisitTypeUsesOwnColor(row) {
    if (!row || typeof row !== 'object') {
        return true;
    }

    if (!Object.prototype.hasOwnProperty.call(row, 'usa_colore_tipo_visita_slot')) {
        return true;
    }

    var parsed = parseInt(row.usa_colore_tipo_visita_slot, 10);
    return isNaN(parsed) ? true : parsed !== 0;
}

function getAgendaVisitTypeUseOwnColorInputValue() {
    var $field = $('#visitTypeUseOwnColor');
    if (!$field.length) {
        return 1;
    }

    return $field.is(':checked') ? 1 : 0;
}

function getSuggestedAgendaVisitTypeColor() {
    return agendaVisitTypeColorPalette[(agendaVisitTypes || []).length % agendaVisitTypeColorPalette.length] || '#3C8DBC';
}

function setAgendaVisitTypeColor(value) {
    var normalized = normalizeAgendaVisitTypeColor(value) || getSuggestedAgendaVisitTypeColor();

    $('#visitTypeColor').val(normalized);
    $('#visitTypeColorCustom').val(normalized.toLowerCase());
    $('#visitTypeColorSample').css('background', normalized);
    $('#visitTypeColorValue').text(normalized);

    $('#visitTypeColorPalette').find('.agenda-visit-type-color-swatch').each(function() {
        var $swatch = $(this);
        $swatch.toggleClass('is-selected', String($swatch.data('color') || '').toUpperCase() === normalized);
    });
}

function renderAgendaVisitTypeColorPalette() {
    var html = '';

    $.each(agendaVisitTypeColorPalette, function(_, color) {
        html += '<button type="button" class="agenda-visit-type-color-swatch"'
            + ' data-color="' + escapeHtml(color) + '"'
            + ' style="--visit-type-color:' + escapeHtml(color) + ';"'
            + ' aria-label="Seleziona colore ' + escapeHtml(color) + '"></button>';
    });

    $('#visitTypeColorPalette').html(html);
}

function parseAgendaHexColor(value) {
    var normalized = normalizeAgendaVisitTypeColor(value);
    if (normalized === '') {
        return null;
    }

    return {
        r: parseInt(normalized.substr(1, 2), 16),
        g: parseInt(normalized.substr(3, 2), 16),
        b: parseInt(normalized.substr(5, 2), 16)
    };
}

function mixAgendaRgbColor(sourceRgb, targetRgb, ratio) {
    var mixRatio = Math.max(0, Math.min(1, parseFloat(ratio) || 0));

    return {
        r: Math.round(sourceRgb.r + ((targetRgb.r - sourceRgb.r) * mixRatio)),
        g: Math.round(sourceRgb.g + ((targetRgb.g - sourceRgb.g) * mixRatio)),
        b: Math.round(sourceRgb.b + ((targetRgb.b - sourceRgb.b) * mixRatio))
    };
}

function agendaRgbToCss(rgb, alpha) {
    if (!rgb) {
        return '';
    }

    if (typeof alpha === 'number') {
        return 'rgba(' + rgb.r + ', ' + rgb.g + ', ' + rgb.b + ', ' + alpha + ')';
    }

    return 'rgb(' + rgb.r + ', ' + rgb.g + ', ' + rgb.b + ')';
}

function getAgendaVisitTypeContrastColor(rgb) {
    if (!rgb) {
        return '#FFFFFF';
    }

    var yiq = ((rgb.r * 299) + (rgb.g * 587) + (rgb.b * 114)) / 1000;
    return yiq >= 166 ? '#1F2D3D' : '#FFFFFF';
}

function buildAgendaVisitTypeVisualStyle(color) {
    var normalized = normalizeAgendaVisitTypeColor(color);
    var rgb = parseAgendaHexColor(normalized);
    if (!rgb) {
        return null;
    }

    var darkRgb = { r: 31, g: 45, b: 61 };
    var whiteRgb = { r: 255, g: 255, b: 255 };
    var textColor = getAgendaVisitTypeContrastColor(rgb);
    var borderRgb = mixAgendaRgbColor(rgb, darkRgb, 0.18);
    var hoverBorderRgb = mixAgendaRgbColor(rgb, darkRgb, 0.28);
    var hoverBgRgb = textColor === '#FFFFFF'
        ? mixAgendaRgbColor(rgb, whiteRgb, 0.08)
        : mixAgendaRgbColor(rgb, darkRgb, 0.06);
    var softBgRgb = mixAgendaRgbColor(rgb, whiteRgb, 0.88);
    var softBorderRgb = mixAgendaRgbColor(rgb, whiteRgb, 0.70);
    var softTextRgb = mixAgendaRgbColor(rgb, darkRgb, 0.58);
    var shadowRgb = mixAgendaRgbColor(rgb, darkRgb, 0.45);

    return {
        color: normalized,
        textColor: textColor,
        cssVars: {
            '--agenda-slot-bg': normalized,
            '--agenda-slot-border': agendaRgbToCss(borderRgb),
            '--agenda-slot-hover-bg': agendaRgbToCss(hoverBgRgb),
            '--agenda-slot-hover-border': agendaRgbToCss(hoverBorderRgb),
            '--agenda-slot-hover-shadow': '0 10px 22px ' + agendaRgbToCss(shadowRgb, 0.22),
            '--agenda-slot-text': textColor,
            '--agenda-slot-soft-bg': agendaRgbToCss(softBgRgb),
            '--agenda-slot-soft-border': agendaRgbToCss(softBorderRgb),
            '--agenda-slot-soft-text': agendaRgbToCss(softTextRgb),
            '--agenda-slot-shadow': agendaRgbToCss(shadowRgb, 0.20),
            '--agenda-team-pill-bg': textColor === '#FFFFFF' ? 'rgba(255, 255, 255, 0.18)' : 'rgba(31, 45, 61, 0.10)',
            '--agenda-team-pill-text': textColor === '#FFFFFF' ? '#FFFFFF' : '#1F2D3D'
        }
    };
}

function getAgendaVisitTypeColorById(idTipoVisita) {
    var row = getAgendaVisitTypeById(idTipoVisita);
    if (!agendaVisitTypeUsesOwnColor(row)) {
        return '';
    }
    return normalizeAgendaVisitTypeColor(row && row.colore ? row.colore : '');
}

function getAgendaVisitTypeVisualStyleById(idTipoVisita) {
    var color = getAgendaVisitTypeColorById(idTipoVisita);
    return color !== '' ? buildAgendaVisitTypeVisualStyle(color) : null;
}

function applyAgendaVisitTypeVisualStyle($elements, visualStyle) {
    if (!$elements || !$elements.length || !visualStyle || !visualStyle.cssVars) {
        return;
    }

    $elements.addClass('has-visit-type-color').each(function() {
        var element = this;
        $.each(visualStyle.cssVars, function(propertyName, propertyValue) {
            element.style.setProperty(propertyName, propertyValue);
        });
    });
}

function buildAgendaVisitTypeInlineStyle(visualStyle) {
    if (!visualStyle || !visualStyle.cssVars) {
        return '';
    }

    var cssText = '';
    $.each(visualStyle.cssVars, function(propertyName, propertyValue) {
        if (String(propertyValue || '') !== '') {
            cssText += propertyName + ':' + propertyValue + ';';
        }
    });

    return cssText;
}

function updateAppointmentVisitTypePreview(row) {
    var $preview = $('#app_visit_type_preview');
    if (!$preview.length) {
        return;
    }

    var $sample = $('#app_visit_type_preview_sample');
    var $label = $('#app_visit_type_preview_label');
    var $meta = $('#app_visit_type_preview_meta');

    if (!row) {
        $preview.addClass('is-empty');
        $sample.css('background', '#dbe7ef');
        $label.text('Nessun tipo visita selezionato');
        $meta.text(isAgendaVisitTypeSelectionOptional()
            ? 'Se non selezioni un tipo visita, l appuntamento usera solo lo slot cliccato.'
            : 'Seleziona un tipo visita per definire durata e copertura dell appuntamento.');
        return;
    }

    var color = normalizeAgendaVisitTypeColor(row.colore) || getSuggestedAgendaVisitTypeColor();
    var duration = parseInt((row && row.durata_minuti) || 0, 10) || 0;
    var usesOwnColor = agendaVisitTypeUsesOwnColor(row);
    var durationLabel = duration > 0 ? (duration + ' minuti') : 'Durata non definita';

    $preview.removeClass('is-empty');
    $sample.css('background', usesOwnColor ? color : '#dbe7ef');
    $label.text($.trim((row && row.nome) || 'Tipo visita'));
    $meta.text(usesOwnColor
        ? (durationLabel + ' - Gli slot useranno il colore ' + color)
        : (durationLabel + ' - Gli slot manterranno il colore standard'));
}

function getAgendaVisitTypeById(idTipoVisita) {
    var lookupId = parseInt(idTipoVisita || 0, 10) || 0;
    var found = null;

    $.each(agendaVisitTypes || [], function(_, row) {
        if ((parseInt((row && row.id_tipo_visita) || 0, 10) || 0) === lookupId) {
            found = row;
            return false;
        }
        return true;
    });

    return found;
}

function getActiveAgendaVisitTypes() {
    return $.grep(agendaVisitTypes || [], function(row) {
        return parseInt((row && row.attivo) || 0, 10) === 1;
    });
}

function getAgendaSlotActualDurationMinutes(slot) {
    var explicitDuration = parseInt((slot && slot.durata_slot_minuti) || 0, 10) || 0;
    if (explicitDuration > 0) {
        return explicitDuration;
    }

    var startMoment = parseAgendaMoment(slot && slot.ora_inizio ? slot.ora_inizio : '');
    var endMoment = parseAgendaMoment(slot && slot.ora_fine ? slot.ora_fine : '');
    if (!startMoment || !endMoment || !startMoment.isValid() || !endMoment.isValid()) {
        return 0;
    }

    return Math.max(0, endMoment.diff(startMoment, 'minutes'));
}

function buildAgendaVisitTypeDisplay(row, options) {
    options = options || {};
    var label = $.trim((row && row.tipo_visita_label) || '');
    var duration = parseInt((row && row.appointment_durata_minuti) || (row && row.durata_minuti) || 0, 10) || 0;
    var includeDuration = options.includeDuration !== false;

    if (label === '' && duration <= 0) {
        return '';
    }

    if (label !== '' && duration > 0 && includeDuration) {
        return label + ' (' + duration + ' min)';
    }

    if (label !== '') {
        return label;
    }

    if (!includeDuration) {
        return '';
    }

    return duration + ' min';
}

function isAgendaCoveredSecondarySlot(slot) {
    var appointmentId = parseInt((slot && slot.id_appuntamento) || 0, 10) || 0;
    if (appointmentId <= 0) {
        return false;
    }

    var isPrimary = parseInt((slot && slot.appointment_is_primary_slot) || 0, 10) || 0;
    return isPrimary !== 1;
}

function getAgendaPrimaryCoveredSlot(slot, slotPool) {
    if (!slot || !isAgendaCoveredSecondarySlot(slot)) {
        return slot || null;
    }

    var appointmentId = parseInt((slot && slot.id_appuntamento) || 0, 10) || 0;
    if (appointmentId <= 0) {
        return slot;
    }

    var currentDotId = String((slot && slot.id_dot) || '');
    var currentDate = '';
    var currentStart = parseAgendaMoment(slot && slot.ora_inizio ? slot.ora_inizio : '');
    if (currentStart && currentStart.isValid()) {
        currentDate = currentStart.format('YYYY-MM-DD');
    }

    var primarySlot = null;
    $.each($.isArray(slotPool) ? slotPool : [], function(_, row) {
        var rowAppointmentId = parseInt((row && row.id_appuntamento) || 0, 10) || 0;
        if (rowAppointmentId !== appointmentId) {
            return true;
        }

        if ((parseInt((row && row.appointment_is_primary_slot) || 0, 10) || 0) !== 1) {
            return true;
        }

        if (currentDotId !== '' && String((row && row.id_dot) || '') !== currentDotId) {
            return true;
        }

        if (currentDate !== '') {
            var rowStart = parseAgendaMoment(row && row.ora_inizio ? row.ora_inizio : '');
            if (!rowStart || !rowStart.isValid() || rowStart.format('YYYY-MM-DD') !== currentDate) {
                return true;
            }
        }

        primarySlot = row;
        return false;
    });

    return primarySlot || slot;
}

function getAgendaSlotVisualStartMoment(slot) {
    var explicitStart = $.trim((slot && slot.appointment_ora_inizio) || '');
    if (explicitStart !== '' && !isAgendaCoveredSecondarySlot(slot)) {
        var appointmentStart = parseAgendaMoment(explicitStart);
        if (appointmentStart && appointmentStart.isValid()) {
            return appointmentStart;
        }
    }

    return parseAgendaMoment(slot && slot.ora_inizio ? slot.ora_inizio : '');
}

function getAgendaSlotVisualEndMoment(slot) {
    var explicitEnd = $.trim((slot && slot.appointment_ora_fine) || '');
    if (explicitEnd !== '' && !isAgendaCoveredSecondarySlot(slot)) {
        var appointmentEnd = parseAgendaMoment(explicitEnd);
        if (appointmentEnd && appointmentEnd.isValid()) {
            return appointmentEnd;
        }
    }

    return parseAgendaMoment(slot && slot.ora_fine ? slot.ora_fine : '');
}

function renderAgendaVisitTypesBox() {
    var $list = $('#agendaVisitTypesList');
    if (!$list.length || !supportsAgendaVisitTypes()) {
        return;
    }

    agendaVisitTypes = normalizeAgendaVisitTypesRows(agendaVisitTypes);

    if (!agendaVisitTypes.length) {
        $list.html('<div class="agenda-visit-type-empty">Nessun tipo visita configurato. Aggiungine almeno uno per poter prenotare appuntamenti multi-slot.</div>');
        return;
    }

    var html = '';

    $.each(agendaVisitTypes, function(_, row) {
        var active = parseInt((row && row.attivo) || 0, 10) === 1;
        var rowColor = normalizeAgendaVisitTypeColor(row && row.colore ? row.colore : '') || getSuggestedAgendaVisitTypeColor();
        var slotColorModeLabel = agendaVisitTypeUsesOwnColor(row)
            ? 'colore tipo visita'
            : 'colore slot standard';
        html += '<div class="agenda-visit-type-row">';
        html += '  <div>';
        html += '    <div class="agenda-visit-type-title-row">';
        html += '      <span class="agenda-visit-type-color" style="background:' + escapeHtml(rowColor) + ';"></span>';
        html += '      <div class="agenda-visit-type-title">' + escapeHtml((row && row.nome) || '') + '</div>';
        html += '    </div>';
        html += '    <div class="agenda-visit-type-meta">' + escapeHtml(String((row && row.durata_minuti) || 0) + ' minuti - ' + slotColorModeLabel) + '</div>';
        html += '  </div>';
        html += '  <div class="agenda-visit-type-actions text-right">';
        html += '    <span class="label label-' + (active ? 'success' : 'default') + '">' + (active ? 'attivo' : 'spento') + '</span>';
        html += '    <button type="button" class="btn btn-default btn-xs js-edit-visit-type" data-id="' + escapeHtml(row.id_tipo_visita) + '"><i class="fa fa-pencil"></i></button>';
        html += '    <button type="button" class="btn btn-default btn-xs js-toggle-visit-type" data-id="' + escapeHtml(row.id_tipo_visita) + '" data-active="' + (active ? '0' : '1') + '">';
        html += '      <i class="fa ' + (active ? 'fa-toggle-off' : 'fa-toggle-on') + '"></i>';
        html += '    </button>';
        html += '  </div>';
        html += '</div>';
    });

    $list.html(html);
}

function resetAgendaVisitTypeForm() {
    $('#visitTypeId').val('');
    $('#visitTypeName').val('');
    $('#visitTypeDuration').val('');
    setAgendaVisitTypeColor(getSuggestedAgendaVisitTypeColor());
    $('#visitTypeUseOwnColor').prop('checked', true);
    $('#btnSaveVisitType').html('<i class="fa fa-save"></i> Salva tipo visita');
    $('#btnCancelVisitTypeEdit').hide();
}

function populateAgendaVisitTypeForm(row) {
    if (!row) {
        resetAgendaVisitTypeForm();
        return;
    }

    $('#visitTypeId').val((row && row.id_tipo_visita) || '');
    $('#visitTypeName').val((row && row.nome) || '');
    $('#visitTypeDuration').val((row && row.durata_minuti) || '');
    setAgendaVisitTypeColor((row && row.colore) || '');
    $('#visitTypeUseOwnColor').prop('checked', agendaVisitTypeUsesOwnColor(row));
    $('#btnSaveVisitType').html('<i class="fa fa-save"></i> Salva modifica');
    $('#btnCancelVisitTypeEdit').show();
    $('#visitTypeName').trigger('focus');
}

function fillAppointmentVisitTypeSelect(selectedId) {
    var $select = $('#app_id_tipo_visita');
    if (!$select.length) {
        return;
    }

    agendaVisitTypes = normalizeAgendaVisitTypesRows(agendaVisitTypes);

    var currentId = parseInt(selectedId || 0, 10) || 0;
    var activeRows = getActiveAgendaVisitTypes();
    var currentRow = currentId > 0 ? getAgendaVisitTypeById(currentId) : null;

    if (
        currentId <= 0
        && !isAgendaVisitTypeSelectionOptional()
        && appointmentModalSlot
        && !($.trim($('#app_id_appuntamento').val() || ''))
    ) {
        if (activeRows.length === 1) {
            currentId = parseInt((activeRows[0] && activeRows[0].id_tipo_visita) || 0, 10) || 0;
            currentRow = getAgendaVisitTypeById(currentId);
        }
    }

    var rows = activeRows.slice(0);
    if (currentRow && parseInt((currentRow && currentRow.attivo) || 0, 10) !== 1) {
        rows.push(currentRow);
    }

    var html = '<option value="">' + escapeHtml(
        isAgendaVisitTypeSelectionOptional()
            ? 'Nessun tipo visita (usa solo questo slot)'
            : 'Seleziona tipo visita'
    ) + '</option>';
    $.each(rows, function(_, row) {
        var duration = parseInt((row && row.durata_minuti) || 0, 10) || 0;
        var label = $.trim((row && row.nome) || '');
        var isActive = parseInt((row && row.attivo) || 0, 10) === 1;
        html += '<option value="' + escapeHtml((row && row.id_tipo_visita) || '') + '"' + (currentId === (parseInt((row && row.id_tipo_visita) || 0, 10) || 0) ? ' selected' : '') + '>';
        html += escapeHtml(label + ' - ' + duration + ' min' + (isActive ? '' : ' (spento)'));
        html += '</option>';
    });

    $select.html(html);
    if (currentId > 0) {
        $select.val(String(currentId));
    }

    updateAppointmentVisitTypePreview(getAgendaVisitTypeById($select.val()));
}

function getAgendaSlotsForAppointmentModal() {
    if (isTeamDayViewActive()) {
        return $.isArray(agendaTeamAllSlots) ? agendaTeamAllSlots : [];
    }

    return $.isArray(agendaCurrentSlots) ? agendaCurrentSlots : [];
}

function computeAppointmentCoverageForSlot(slot, durationMinutes, currentAppointmentId) {
    var baseSlot = slot || appointmentModalSlot;
    var requestedDuration = parseInt(durationMinutes || 0, 10) || 0;
    var appointmentId = parseInt(currentAppointmentId || 0, 10) || 0;

    if (!baseSlot || requestedDuration <= 0) {
        return {
            ok: false,
            message: 'Seleziona un tipo visita valido.'
        };
    }

    var startMoment = parseAgendaMoment(baseSlot && baseSlot.ora_inizio ? baseSlot.ora_inizio : '');
    if (!startMoment || !startMoment.isValid()) {
        return {
            ok: false,
            message: 'Slot iniziale non valido.'
        };
    }

    var contextSlots = $.grep(getAgendaSlotsForAppointmentModal(), function(row) {
        var rowStart = parseAgendaMoment(row && row.ora_inizio ? row.ora_inizio : '');
        return rowStart
            && rowStart.isValid()
            && String((row && row.id_dot) || '') === String((baseSlot && baseSlot.id_dot) || '')
            && rowStart.format('YYYY-MM-DD') === startMoment.format('YYYY-MM-DD');
    });

    contextSlots.sort(function(leftRow, rightRow) {
        var leftMoment = parseAgendaMoment(leftRow && leftRow.ora_inizio ? leftRow.ora_inizio : '');
        var rightMoment = parseAgendaMoment(rightRow && rightRow.ora_inizio ? rightRow.ora_inizio : '');
        if (!leftMoment || !rightMoment || !leftMoment.isValid() || !rightMoment.isValid()) {
            return 0;
        }
        return leftMoment.valueOf() - rightMoment.valueOf();
    });

    var baseSlotId = parseInt((baseSlot && baseSlot.id_slot) || 0, 10) || 0;
    var startIndex = -1;

    $.each(contextSlots, function(index, row) {
        if ((parseInt((row && row.id_slot) || 0, 10) || 0) === baseSlotId) {
            startIndex = index;
            return false;
        }
        return true;
    });

    if (startIndex < 0) {
        return {
            ok: false,
            message: 'Non riesco a trovare lo slot di partenza nel calendario corrente.'
        };
    }

    var coveredRows = [];
    var totalDuration = 0;
    var expectedStart = startMoment.clone();

    for (var index = startIndex; index < contextSlots.length; index++) {
        var row = contextSlots[index];
        var rowStart = parseAgendaMoment(row && row.ora_inizio ? row.ora_inizio : '');
        var rowEnd = parseAgendaMoment(row && row.ora_fine ? row.ora_fine : '');

        if (!rowStart || !rowEnd || !rowStart.isValid() || !rowEnd.isValid()) {
            return {
                ok: false,
                message: 'Uno degli slot consecutivi ha orari non validi.'
            };
        }

        if (index > startIndex && !rowStart.isSame(expectedStart)) {
            break;
        }

        var rowState = $.trim(String((row && row.stato) || '')).toUpperCase();
        var rowAppointmentId = parseInt((row && row.id_appuntamento) || 0, 10) || 0;
        var occupiedByOtherAppointment = rowAppointmentId > 0 && rowAppointmentId !== appointmentId;

        if (rowState === 'CHIUSO') {
            return {
                ok: false,
                message: 'La copertura richiesta entra in una giornata bloccata.'
            };
        }

        if (occupiedByOtherAppointment) {
            return {
                ok: false,
                message: 'Gli slot consecutivi necessari non sono tutti liberi.'
            };
        }

        coveredRows.push(row);
        totalDuration += getAgendaSlotActualDurationMinutes(row);
        expectedStart = rowEnd.clone();

        if (totalDuration === requestedDuration) {
            return {
                ok: true,
                slotIds: $.map(coveredRows, function(coveredRow) {
                    return parseInt((coveredRow && coveredRow.id_slot) || 0, 10) || 0;
                }),
                count: coveredRows.length,
                startMoment: startMoment,
                endMoment: rowEnd.clone(),
                message: 'Copre ' + coveredRows.length + ' slot consecutivi fino alle ' + rowEnd.format('HH:mm') + '.'
            };
        }

        if (totalDuration > requestedDuration) {
            return {
                ok: false,
                message: 'La durata scelta non e compatibile con la griglia degli slot in questo punto dell agenda.'
            };
        }
    }

    return {
        ok: false,
        message: 'Non ci sono abbastanza slot consecutivi liberi per la durata selezionata.'
    };
}

function buildSingleSlotAppointmentCoverage(slot) {
    var baseSlot = slot || appointmentModalSlot;
    var startMoment = parseAgendaMoment(baseSlot && baseSlot.ora_inizio ? baseSlot.ora_inizio : '');
    var endMoment = parseAgendaMoment(baseSlot && baseSlot.ora_fine ? baseSlot.ora_fine : '');
    var slotId = parseInt((baseSlot && baseSlot.id_slot) || 0, 10) || 0;
    var slotDuration = getAgendaSlotActualDurationMinutes(baseSlot);

    if (!baseSlot || slotId <= 0 || !startMoment || !startMoment.isValid() || !endMoment || !endMoment.isValid() || slotDuration <= 0) {
        return {
            ok: false,
            message: 'Slot iniziale non valido.'
        };
    }

    return {
        ok: true,
        slotIds: [slotId],
        count: 1,
        startMoment: startMoment,
        endMoment: endMoment,
        message: 'Occupa solo lo slot selezionato fino alle ' + endMoment.format('HH:mm') + '.'
    };
}

function getAppointmentRequestedDurationMinutes() {
    if (isAppointmentPersonalCommitmentMode()) {
        return parseInt($('#app_personal_commitment_end').val() || 0, 10) || 0;
    }

    if (supportsAgendaVisitTypes()) {
        var selectedType = getAgendaVisitTypeById($('#app_id_tipo_visita').val());
        var selectedDuration = parseInt((selectedType && selectedType.durata_minuti) || 0, 10) || 0;
        if (selectedDuration > 0) {
            return selectedDuration;
        }
    }

    var storedDuration = parseInt((appointmentModalSlot && appointmentModalSlot.appointment_durata_minuti) || 0, 10) || 0;
    return storedDuration > 0 ? storedDuration : getAgendaSlotActualDurationMinutes(appointmentModalSlot);
}

function computeCustomAppointmentCoverage(slot, customStartTime, customEndTime, fallbackDurationMinutes, currentAppointmentId) {
    var baseSlot = slot || appointmentModalSlot;
    var fallbackDuration = parseInt(fallbackDurationMinutes || 0, 10) || 0;
    var appointmentId = parseInt(currentAppointmentId || 0, 10) || 0;
    var normalizedTime = $.trim(String(customStartTime || ''));
    var normalizedEndTime = $.trim(String(customEndTime || ''));
    var baseStart = parseAgendaMoment(baseSlot && baseSlot.ora_inizio ? baseSlot.ora_inizio : '');
    var baseEnd = parseAgendaMoment(baseSlot && baseSlot.ora_fine ? baseSlot.ora_fine : '');

    if (!baseSlot || !baseStart || !baseEnd || !baseStart.isValid() || !baseEnd.isValid()) {
        return {
            ok: false,
            message: 'Slot iniziale non valido.'
        };
    }

    if (!/^(?:[01]\d|2[0-3]):[0-5]\d$/.test(normalizedTime)) {
        return {
            ok: false,
            message: 'Inserisci un ora iniziale personalizzata valida.'
        };
    }

    var startMoment = moment(baseStart.format('YYYY-MM-DD') + ' ' + normalizedTime, 'YYYY-MM-DD HH:mm', true);
    if (
        !startMoment.isValid()
        || startMoment.isBefore(baseStart)
        || !startMoment.isBefore(baseEnd)
    ) {
        return {
            ok: false,
            message: 'L ora personalizzata deve rientrare nello slot iniziale selezionato.'
        };
    }

    var endMoment = null;
    if (normalizedEndTime !== '') {
        if (!/^(?:[01]\d|2[0-3]):[0-5]\d$/.test(normalizedEndTime)) {
            return {
                ok: false,
                message: 'Inserisci un ora finale personalizzata valida.'
            };
        }

        endMoment = moment(baseStart.format('YYYY-MM-DD') + ' ' + normalizedEndTime, 'YYYY-MM-DD HH:mm', true);
    } else if (fallbackDuration > 0) {
        endMoment = startMoment.clone().add(fallbackDuration, 'minutes');
    }

    if (
        !endMoment
        || !endMoment.isValid()
        || !endMoment.isAfter(startMoment)
        || endMoment.format('YYYY-MM-DD') !== startMoment.format('YYYY-MM-DD')
    ) {
        return {
            ok: false,
            message: 'L ora finale personalizzata deve essere successiva all ora iniziale.'
        };
    }

    var contextSlots = $.grep(getAgendaSlotsForAppointmentModal(), function(row) {
        var rowStart = parseAgendaMoment(row && row.ora_inizio ? row.ora_inizio : '');
        var rowEnd = parseAgendaMoment(row && row.ora_fine ? row.ora_fine : '');
        return rowStart
            && rowEnd
            && rowStart.isValid()
            && rowEnd.isValid()
            && String((row && row.id_dot) || '') === String((baseSlot && baseSlot.id_dot) || '')
            && rowStart.format('YYYY-MM-DD') === startMoment.format('YYYY-MM-DD')
            && rowStart.isBefore(endMoment)
            && rowEnd.isAfter(startMoment);
    });

    contextSlots.sort(function(leftRow, rightRow) {
        var leftMoment = parseAgendaMoment(leftRow && leftRow.ora_inizio ? leftRow.ora_inizio : '');
        var rightMoment = parseAgendaMoment(rightRow && rightRow.ora_inizio ? rightRow.ora_inizio : '');
        return leftMoment.valueOf() - rightMoment.valueOf();
    });

    var baseSlotId = parseInt((baseSlot && baseSlot.id_slot) || 0, 10) || 0;
    if (!contextSlots.length) {
        return {
            ok: false,
            message: 'L ora personalizzata deve partire dallo slot selezionato.'
        };
    }

    var coverageCursor = startMoment.clone();
    var primarySlotFound = false;
    var slotIds = [];
    var slotLabels = [];

    for (var index = 0; index < contextSlots.length; index++) {
        var row = contextSlots[index];
        var rowStart = parseAgendaMoment(row && row.ora_inizio ? row.ora_inizio : '');
        var rowEnd = parseAgendaMoment(row && row.ora_fine ? row.ora_fine : '');
        var rowState = $.trim(String((row && row.stato) || '')).toUpperCase();
        var rowAppointmentId = parseInt((row && row.id_appuntamento) || 0, 10) || 0;
        var occupiedByOtherAppointment = rowAppointmentId > 0 && rowAppointmentId !== appointmentId;

        if (rowStart.isAfter(coverageCursor)) {
            return {
                ok: false,
                message: 'L intervallo attraversa una fascia senza slot disponibili.'
            };
        }

        if (rowState === 'CHIUSO') {
            return {
                ok: false,
                message: 'La fascia richiesta entra in una giornata bloccata.'
            };
        }

        if (occupiedByOtherAppointment) {
            return {
                ok: false,
                message: 'Uno degli slot coinvolti e gia occupato.'
            };
        }

        if ((parseInt((row && row.id_slot) || 0, 10) || 0) === baseSlotId) {
            primarySlotFound = true;
        }

        slotIds.push(parseInt((row && row.id_slot) || 0, 10) || 0);
        slotLabels.push(rowStart.format('HH:mm') + '-' + rowEnd.format('HH:mm'));
        if (rowEnd.isAfter(coverageCursor)) {
            coverageCursor = rowEnd.clone();
        }
    }

    if (!primarySlotFound) {
        return {
            ok: false,
            message: 'L ora personalizzata deve partire dallo slot selezionato.'
        };
    }

    if (coverageCursor.isBefore(endMoment)) {
        return {
            ok: false,
            message: 'Non ci sono abbastanza slot disponibili per completare l appuntamento.'
        };
    }

    return {
        ok: true,
        slotIds: slotIds,
        slotLabels: slotLabels,
        count: slotIds.length,
        startMoment: startMoment,
        endMoment: endMoment,
        durationMinutes: endMoment.diff(startMoment, 'minutes'),
        message: 'Occupa ' + slotIds.length + ' slot: ' + slotLabels.join(', ') + '. Orario reale ' + startMoment.format('HH:mm') + '-' + endMoment.format('HH:mm') + '.'
    };
}

function refreshAppointmentCustomTimeCoverage(durationMinutes) {
    var $fields = $('#appointmentCustomTimeFields');
    var $start = $('#app_custom_start_time');
    var $end = $('#app_custom_end_time');
    var $coverage = $('#app_custom_time_coverage');

    if (!supportsAgendaCustomAppointmentTime() || !isAppointmentCustomTimeEnabled()) {
        $fields.hide();
        $coverage.removeClass('is-ok is-error').text('');
        $end.val('');
        return null;
    }

    $fields.show();

    if ($.trim($start.val() || '') === '') {
        var explicitStart = parseAgendaMoment(
            (appointmentModalSlot && appointmentModalSlot.appointment_custom_ora_inizio)
            || (appointmentModalSlot && appointmentModalSlot.ora_inizio)
            || ''
        );
        $start.val(explicitStart && explicitStart.isValid() ? explicitStart.format('HH:mm') : '');
    }

    var coverage = computeCustomAppointmentCoverage(
        appointmentModalSlot,
        $start.val(),
        $end.val(),
        durationMinutes,
        $('#app_id_appuntamento').val()
    );

    if (!coverage || !coverage.ok) {
        $coverage
            .removeClass('is-ok')
            .addClass('is-error')
            .text((coverage && coverage.message) || 'Intervallo personalizzato non valido.');
        return coverage;
    }

    $end.val(coverage.endMoment.format('HH:mm'));
    $coverage
        .removeClass('is-error')
        .addClass('is-ok')
        .text(coverage.message);
    setAppointmentSlotTimeSummary(appointmentModalSlot || null, coverage);

    return coverage;
}

function refreshAppointmentVisitTypePreview() {
    if (isAppointmentPersonalCommitmentMode()) {
        return refreshAppointmentPersonalCommitmentCoverage();
    }

    var $duration = $('#app_durata_visita');
    var $coverage = $('#app_slot_copertura_info');
    var currentAppointmentId = parseInt($('#app_id_appuntamento').val() || 0, 10) || 0;

    if (!supportsAgendaVisitTypes()) {
        var singleCoverage = buildSingleSlotAppointmentCoverage(appointmentModalSlot);
        var singleDuration = getAppointmentRequestedDurationMinutes();
        setAppointmentSlotTimeSummary(appointmentModalSlot || null, singleCoverage);

        if (isAppointmentCustomTimeEnabled()) {
            return refreshAppointmentCustomTimeCoverage(singleDuration);
        }

        refreshAppointmentCustomTimeCoverage(singleDuration);
        return singleCoverage;
    }

    var selectedType = getAgendaVisitTypeById($('#app_id_tipo_visita').val());
    updateAppointmentVisitTypePreview(selectedType);

    if (!selectedType) {
        if (!isAgendaVisitTypeSelectionOptional()) {
            $duration.val('');
            $coverage.removeClass('is-ok').addClass('is-error').text('Seleziona il tipo visita.');
            setAppointmentSlotTimeSummary(appointmentModalSlot || null, null);
            refreshAppointmentCustomTimeCoverage(0);
            return {
                ok: false,
                message: 'Seleziona il tipo visita.'
            };
        }

        var fallbackCoverage = buildSingleSlotAppointmentCoverage(appointmentModalSlot);
        var fallbackDuration = getAgendaSlotActualDurationMinutes(appointmentModalSlot);
        $duration.val(fallbackDuration > 0 ? (fallbackDuration + ' minuti') : '');
        setAppointmentSlotTimeSummary(appointmentModalSlot || null, fallbackCoverage);

        if (isAppointmentCustomTimeEnabled()) {
            return refreshAppointmentCustomTimeCoverage(fallbackDuration);
        }

        refreshAppointmentCustomTimeCoverage(fallbackDuration);

        if (!fallbackCoverage || !fallbackCoverage.ok) {
            $coverage.removeClass('is-ok').addClass('is-error').text((fallbackCoverage && fallbackCoverage.message) || 'Slot iniziale non valido.');
            return fallbackCoverage;
        }

        $coverage.removeClass('is-error').addClass('is-ok').text(fallbackCoverage.message || '');
        return fallbackCoverage;
    }

    var durationMinutes = parseInt((selectedType && selectedType.durata_minuti) || 0, 10) || 0;
    $duration.val(durationMinutes > 0 ? (durationMinutes + ' minuti') : '');

    if (isAppointmentCustomTimeEnabled()) {
        $coverage.removeClass('is-error').addClass('is-ok').text('La copertura viene calcolata sull orario personalizzato.');
        return refreshAppointmentCustomTimeCoverage(durationMinutes);
    }

    refreshAppointmentCustomTimeCoverage(durationMinutes);

    var coverage = computeAppointmentCoverageForSlot(appointmentModalSlot, durationMinutes, currentAppointmentId);
    setAppointmentSlotTimeSummary(appointmentModalSlot || null, coverage);

    if (!coverage || !coverage.ok) {
        $coverage.removeClass('is-ok').addClass('is-error').text((coverage && coverage.message) || 'Durata non compatibile.');
        return coverage;
    }

    $coverage.removeClass('is-error').addClass('is-ok').text(coverage.message || '');
    return coverage;
}

function normalizeAgendaViewModeValue(value) {
    var normalized = $.trim(String(value || '')).toLowerCase();

    if (normalized === 'week') {
        return 'week';
    }

    if (normalized === 'team_day' && supportsTeamDayView()) {
        return 'team_day';
    }

    return 'day';
}

function isTeamDayViewActive() {
    return normalizeAgendaViewModeValue($('#view_mode').val()) === 'team_day';
}

function setAgendaViewMode(view) {
    var normalized = normalizeAgendaViewModeValue(view);
    $('#view_mode').val(normalized);
    window.AGENDA_CONFIG.viewMode = normalized;
    syncAgendaViewButtons();
    return normalized;
}

function syncAgendaViewButtons() {
    var activeView = normalizeAgendaViewModeValue($('#view_mode').val());

    $('.agenda-view-btn').each(function() {
        var $button = $(this);
        var buttonView = normalizeAgendaViewModeValue($button.data('view-mode'));
        var isActive = buttonView === activeView;

        $button
            .toggleClass('btn-primary', isActive)
            .toggleClass('btn-default', !isActive)
            .toggleClass('active', isActive)
            .attr('aria-pressed', isActive ? 'true' : 'false');
    });

    syncAgendaPrintButtonLabel(activeView);
    syncAgendaCompressedDaybar();
}

function syncAgendaPrintButtonLabel(activeView) {
    var normalized = normalizeAgendaViewModeValue(activeView || $('#view_mode').val());
    var label = 'Stampa PDF giorno';

    if (normalized === 'week') {
        label = 'Stampa PDF settimana';
    } else if (normalized === 'team_day') {
        label = 'Stampa PDF giorno team';
    }

    $('#btnPrintDayAgendaLabel').text(label);
}

function setAgendaSidebarCollapsed(isCollapsed, options) {
    var $layout = $('#agendaLayout');
    var $button = $('#btnToggleAgendaSidebar');
    var $icon = $('#btnToggleAgendaSidebarIcon');
    var $text = $('#btnToggleAgendaSidebarText');

    if (!$layout.length || !$button.length) {
        return;
    }

    options = options || {};
    isCollapsed = !!isCollapsed;

    $layout.toggleClass('is-sidebar-collapsed', isCollapsed);
    $button
        .attr('aria-expanded', isCollapsed ? 'false' : 'true')
        .attr('title', isCollapsed ? 'Apri il pannello agenda' : 'Chiudi il pannello agenda');

    if ($text.length) {
        $text.text(isCollapsed ? 'Apri menu' : 'Riduci menu');
    }

    if ($icon.length) {
        $icon
            .removeClass('fa-angle-double-left fa-angle-double-right')
            .addClass(isCollapsed ? 'fa-angle-double-right' : 'fa-angle-double-left');
    }

    if (options.realign === false) {
        return;
    }

    setTimeout(function() {
        riallineaRenderingCalendario();
    }, 240);
}

function toggleAgendaSidebar() {
    setAgendaSidebarCollapsed(!$('#agendaLayout').hasClass('is-sidebar-collapsed'));
}

function toggleAgendaTeamDayLayout(isTeamDay) {
    var $primaryCol = $('#agendaCalendarPrimaryCol');
    var $domiciliariCol = $('#boxVisiteDomiciliariCol');

    if (!$primaryCol.length) {
        return;
    }

    if (isTeamDay && $domiciliariCol.length) {
        $primaryCol.removeClass('col-lg-7 col-md-6').addClass('col-lg-12 col-md-12');
        $domiciliariCol.removeClass('col-lg-5 col-md-6').addClass('col-lg-12 col-md-12');
        return;
    }

    if ($domiciliariCol.length) {
        $primaryCol.removeClass('col-lg-12 col-md-12').addClass('col-lg-7 col-md-6');
        $domiciliariCol.removeClass('col-lg-12 col-md-12').addClass('col-lg-5 col-md-6');
    }
}

function toggleAgendaCalendarShells(view) {
    var normalized = normalizeAgendaViewModeValue(view);
    var isTeamDay = normalized === 'team_day';

    $('#agendaCalendarShell').toggle(!isTeamDay);
    $('#agendaTeamDayShell').toggle(isTeamDay);
    $('#agendaNoSlotsMessage').hide();
    toggleAgendaTeamDayLayout(isTeamDay);
    syncAgendaViewButtons();
}

function setAgendaCalendarLoading(isLoading, message) {
    var isTeamDay = isTeamDayViewActive();
    var $shell = isTeamDay ? $('#agendaTeamDayShell') : $('#agendaCalendarShell');
    var $overlay = isTeamDay ? $('#agendaTeamDayLoading') : $('#agendaCalendarLoading');
    var $allShells = $('#agendaCalendarShell, #agendaTeamDayShell');
    var $allOverlays = $('#agendaCalendarLoading, #agendaTeamDayLoading');

    if (!$shell.length || !$overlay.length) {
        return;
    }

    if (message) {
        $('#agendaCalendarLoadingText, #agendaTeamDayLoadingText').text(message);
    }

    $allShells.removeClass('is-loading');
    $allOverlays.attr('aria-hidden', 'true');
    $shell.toggleClass('is-loading', !!isLoading);
    $overlay.attr('aria-hidden', isLoading ? 'false' : 'true');
}

function setAgendaMiniCalendarStatus(message) {
    $('#agendaMiniCalendarStatus').text($.trim(message || ''));
}

function getAgendaMiniCalendarSelectedMoment() {
    var selected = moment($('#agenda_date').val(), 'YYYY-MM-DD', true);

    if (!selected.isValid()) {
        selected = moment(window.AGENDA_CONFIG.selectedDate, 'YYYY-MM-DD', true);
    }

    if (!selected.isValid()) {
        selected = moment();
    }

    return selected.startOf('day');
}

function getAgendaMiniCalendarMonthMoment() {
    if (agendaMiniCalendarMonth && agendaMiniCalendarMonth.isValid()) {
        return agendaMiniCalendarMonth.clone().startOf('month');
    }

    return getAgendaMiniCalendarSelectedMoment().clone().startOf('month');
}

function formatAgendaMiniCalendarTitle(monthMoment) {
    var label = monthMoment.clone().locale('it').format('MMMM YYYY');
    return label.charAt(0).toUpperCase() + label.slice(1);
}

function renderAgendaMiniCalendar() {
    var $grid = $('#agendaMiniCalendarGrid');
    if (!$grid.length) {
        return;
    }

    var selectedDate = getAgendaMiniCalendarSelectedMoment();
    var monthMoment = getAgendaMiniCalendarMonthMoment();
    var today = moment().startOf('day');
    var gridStart = monthMoment.clone().startOf('month');
    var gridEnd = monthMoment.clone().endOf('month');
    var html = '';

    while (gridStart.isoWeekday() !== 1) {
        gridStart.subtract(1, 'day');
    }

    while (gridEnd.isoWeekday() !== 7) {
        gridEnd.add(1, 'day');
    }

    $('#agendaMiniCalendarTitle').text(formatAgendaMiniCalendarTitle(monthMoment));

    for (var cursor = gridStart.clone(); cursor.isSameOrBefore(gridEnd, 'day'); cursor.add(1, 'day')) {
        var dateKey = cursor.format('YYYY-MM-DD');
        var isSelected = cursor.isSame(selectedDate, 'day');
        var isToday = cursor.isSame(today, 'day');
        var isOutside = !cursor.isSame(monthMoment, 'month');
        var hasAvailability = !!agendaMiniCalendarAvailabilityMap[dateKey];
        var freeCount = parseInt(agendaMiniCalendarAvailabilityCounts[dateKey], 10) || 0;
        var classes = ['agenda-mini-calendar-day'];

        if (isSelected) {
            classes.push('is-selected');
        }
        if (isToday) {
            classes.push('is-today');
        }
        if (isOutside) {
            classes.push('is-outside');
        }

        var title = cursor.format('DD/MM/YYYY');
        if (hasAvailability) {
            title += freeCount === 1
                ? ' - 1 slot libero'
                : ' - ' + freeCount + ' slot liberi';
        }

        html += ''
            + '<button type="button" class="' + classes.join(' ') + '"'
            + ' data-date="' + dateKey + '"'
            + ' title="' + escapeHtml(title) + '">'
            + '  <span class="agenda-mini-calendar-day-number">' + cursor.date() + '</span>'
            + '  <span class="agenda-mini-calendar-day-dot-wrap">'
            +       (hasAvailability ? '<span class="agenda-mini-calendar-day-dot" aria-hidden="true"></span>' : '')
            + '  </span>'
            + '</button>';
    }

    $grid.html(html);
}

function caricaDisponibilitaMiniCalendario(options) {
    options = options || {};

    if (options.alignToSelectedDate !== false) {
        agendaMiniCalendarMonth = getAgendaMiniCalendarSelectedMoment().clone().startOf('month');
    } else if (!agendaMiniCalendarMonth || !agendaMiniCalendarMonth.isValid()) {
        agendaMiniCalendarMonth = getAgendaMiniCalendarSelectedMoment().clone().startOf('month');
    }

    renderAgendaMiniCalendar();

    var idDot = parseInt($('#id_dot').val(), 10) || 0;
    var monthMoment = getAgendaMiniCalendarMonthMoment();
    var monthKey = monthMoment.format('YYYY-MM');

    if (idDot <= 0) {
        agendaMiniCalendarAvailabilityMap = {};
        agendaMiniCalendarAvailabilityCounts = {};
        setAgendaMiniCalendarStatus('Seleziona un professionista per vedere le disponibilita.');
        renderAgendaMiniCalendar();
        return;
    }

    if (agendaMiniCalendarXhr && agendaMiniCalendarXhr.readyState !== 4) {
        agendaMiniCalendarXhr.abort();
    }

    var requestSeq = ++agendaMiniCalendarRequestSeq;
    setAgendaMiniCalendarStatus('Aggiorno le disponibilita del mese...');

    agendaMiniCalendarXhr = $.get("<?= base_url('agenda/disponibilita-mese') ?>", {
        id_dot: idDot,
        mese: monthKey
    }, function(res) {
        if (requestSeq !== agendaMiniCalendarRequestSeq) {
            return;
        }

        agendaMiniCalendarAvailabilityMap = {};
        agendaMiniCalendarAvailabilityCounts = {};

        if (!res || !res.status) {
            setAgendaMiniCalendarStatus('Impossibile aggiornare i pallini verdi in questo momento.');
            renderAgendaMiniCalendar();
            return;
        }

        $.each(res.dates || [], function(_, dateKey) {
            if (!dateKey) {
                return;
            }

            agendaMiniCalendarAvailabilityMap[String(dateKey)] = true;
        });

        $.each(res.counts || {}, function(dateKey, count) {
            agendaMiniCalendarAvailabilityCounts[String(dateKey)] = parseInt(count, 10) || 0;
        });

        setAgendaMiniCalendarStatus('');
        renderAgendaMiniCalendar();
    }, 'json').fail(function(xhr, textStatus) {
        if (textStatus === 'abort' || requestSeq !== agendaMiniCalendarRequestSeq) {
            return;
        }

        agendaMiniCalendarAvailabilityMap = {};
        agendaMiniCalendarAvailabilityCounts = {};
        setAgendaMiniCalendarStatus('Impossibile aggiornare i pallini verdi in questo momento.');
        renderAgendaMiniCalendar();
    });
}

function cambiaMeseMiniCalendario(delta) {
    var monthMoment = getAgendaMiniCalendarMonthMoment().add(delta, 'month').startOf('month');
    agendaMiniCalendarMonth = monthMoment;
    caricaDisponibilitaMiniCalendario({
        alignToSelectedDate: false
    });
}

function showAgendaToast(message, type) {
    message = $.trim(message || '');
    if (message === '') {
        return;
    }

    var tone = (type || 'success') === 'error' ? 'danger' : (type || 'success');
    var $container = $('#agendaToastContainer');

    if (!$container.length) {
        $container = $('<div id="agendaToastContainer"></div>').css({
            position: 'fixed',
            top: '72px',
            right: '20px',
            width: '420px',
            maxWidth: 'calc(100vw - 32px)',
            zIndex: 1065
        });
        $('body').append($container);
    }

    var $toast = $('<div class="alert alert-' + tone + '"></div>').css({
        boxShadow: '0 10px 25px rgba(0, 0, 0, 0.15)',
        marginBottom: '10px'
    }).text(message);

    $container.append($toast);

    window.setTimeout(function() {
        $toast.fadeOut(200, function() {
            $(this).remove();

            if (!$container.children().length) {
                $container.remove();
            }
        });
    }, 2200);
}

function setExtraSlotModalError(message) {
    var $error = $('#extraSlotModalError');
    message = $.trim(message || '');

    if (message === '') {
        $error.hide().text('');
        return;
    }

    $error.text(message).show();
}

function shouldUseExtraSlotDoctorSelector() {
    return isTeamDayViewActive();
}

function syncExtraSlotTargetDoctorField() {
    var requiresDoctorSelection = shouldUseExtraSlotDoctorSelector();
    var $group = $('#extra_slot_doctor_group');
    var $select = $('#extra_slot_id_dot');

    if (!$group.length || !$select.length) {
        return;
    }

    $group.toggle(requiresDoctorSelection);
    $select.prop('required', requiresDoctorSelection);

    if (!requiresDoctorSelection) {
        $select.val('');
    }
}

function getExtraSlotTargetDoctorId() {
    if (shouldUseExtraSlotDoctorSelector()) {
        return parseInt($('#extra_slot_id_dot').val(), 10) || 0;
    }

    return parseInt($('#id_dot').val(), 10) || 0;
}

var extraSlotTeamDayDoctorContext = null;

function prepareExtraSlotTeamDayDoctorContext() {
    if (!shouldUseExtraSlotDoctorSelector()) {
        extraSlotTeamDayDoctorContext = null;
        return true;
    }

    var targetDoctorId = getExtraSlotTargetDoctorId();
    if (targetDoctorId <= 0) {
        setExtraSlotModalError('Seleziona il professionista a cui aggiungere lo slot extra.');
        return false;
    }

    extraSlotTeamDayDoctorContext = {
        idDot: $('#id_dot').val(),
        giornoBloccato: giornoBloccato
    };

    $('#id_dot').val(String(targetDoctorId));
    giornoBloccato = false;
    return true;
}

function restoreExtraSlotTeamDayDoctorContext() {
    if (!extraSlotTeamDayDoctorContext) {
        return;
    }

    $('#id_dot').val(extraSlotTeamDayDoctorContext.idDot);
    giornoBloccato = extraSlotTeamDayDoctorContext.giornoBloccato;
    extraSlotTeamDayDoctorContext = null;
}

function resetExtraSlotModal() {
    $('#extra_slot_id_dot').val('');
    $('#extra_slot_ora_inizio').val('');
    $('#extra_slot_ora_fine').val('');
    syncExtraSlotTargetDoctorField();
    setExtraSlotModalError('');
    setExtraSlotSavingState(false);
}

function setExtraSlotSavingState(isSaving) {
    if (!extraSlotSaveDefaultHtml) {
        extraSlotSaveDefaultHtml = $('#btnSaveExtraSlotModal').html();
    }

    $('#btnSaveExtraSlotModal')
        .prop('disabled', !!isSaving)
        .html(isSaving
            ? '<i class="fa fa-spinner fa-spin"></i> Inserimento...'
            : extraSlotSaveDefaultHtml);

    $('.btn-close-extra-slot-modal').prop('disabled', !!isSaving);
}

function calculateExtraSlotDurationMinutes(oraInizio, oraFine) {
    var startParts = String(oraInizio || '').split(':');
    var endParts = String(oraFine || '').split(':');

    if (startParts.length < 2 || endParts.length < 2) {
        return 0;
    }

    var startMinutes = (parseInt(startParts[0], 10) * 60) + parseInt(startParts[1], 10);
    var endMinutes = (parseInt(endParts[0], 10) * 60) + parseInt(endParts[1], 10);

    if (isNaN(startMinutes) || isNaN(endMinutes)) {
        return 0;
    }

    return endMinutes - startMinutes;
}

function renderAgendaBlockDayButton() {
    if (canBloccareGiorno) {
        $('#btnBlockDayAgenda').show();

        if (giornoBloccato) {
            $('#btnBlockDayAgenda')
                .removeClass('btn-danger')
                .addClass('btn-success')
                .prop('disabled', false)
                .html('<i class="fa fa-unlock"></i> Sblocca giornata');
        } else {
            $('#btnBlockDayAgenda')
                .removeClass('btn-success')
                .addClass('btn-danger')
                .prop('disabled', false)
                .html('<i class="fa fa-lock"></i> Blocca giornata');
        }

        return;
    }

    $('#btnBlockDayAgenda').hide();
}

function renderDomiciliariBlockDayButton() {
    var $button = $('#btnBlockDayDomiciliari');

    if (!$button.length) {
        return;
    }

    if (!window.AGENDA_CONFIG.domiciliariAbilitati || !canBloccareGiorno) {
        $button.hide();
        return;
    }

    var blockedByAgenda = giornoBloccato;
    var title = blockedByAgenda
        ? 'La giornata agenda e bloccata: le domiciliari sono gia ferme insieme al resto.'
        : '';

    $button.show();

    if (domiciliareGiornoBloccato) {
        $button
            .removeClass('btn-warning')
            .addClass('btn-success')
            .html('<i class="fa fa-unlock"></i> Sblocca domiciliari');
    } else {
        $button
            .removeClass('btn-success')
            .addClass('btn-warning')
            .html('<i class="fa fa-home"></i> Blocca domiciliari');
    }

    $button
        .prop('disabled', blockedByAgenda)
        .toggleClass('disabled', blockedByAgenda)
        .attr('title', title);
}

function normalizeAgendaDateKey(value) {
    var raw = $.trim(String(value || ''));

    if (raw === '') {
        return '';
    }

    var match = raw.match(/^(\d{4}-\d{2}-\d{2})/);
    if (match && match[1]) {
        return match[1];
    }

    var parsed = moment(raw);
    return parsed.isValid() ? parsed.format('YYYY-MM-DD') : '';
}

function getSelectedAgendaDateKey() {
    return normalizeAgendaDateKey($('#agenda_date').val() || window.AGENDA_CONFIG.selectedDate);
}

function buildAgendaBlockedDayMap(sourceMap, fallbackDateKey, fallbackValue) {
    var out = {};

    if (sourceMap && typeof sourceMap === 'object') {
        $.each(sourceMap, function(key, value) {
            var normalizedKey = normalizeAgendaDateKey(key);

            if (normalizedKey === '') {
                return true;
            }

            out[normalizedKey] = !!value;
            return true;
        });
    }

    if (fallbackDateKey !== '' && typeof out[fallbackDateKey] === 'undefined') {
        out[fallbackDateKey] = !!fallbackValue;
    }

    return out;
}

function isAgendaDateFlaggedInMap(map, dateValue, fallbackValue) {
    var dateKey = normalizeAgendaDateKey(dateValue);

    if (dateKey !== '' && typeof map[dateKey] !== 'undefined') {
        return !!map[dateKey];
    }

    return !!fallbackValue;
}

function isAgendaDayBlockedByDate(dateValue) {
    return isAgendaDateFlaggedInMap(agendaBlockedDaysMap, dateValue, giornoBloccato);
}

function getAgendaTeamDaySlotBlockedState(slot) {
    if (
        normalizeAgendaViewModeValue($('#view_mode').val()) !== 'team_day'
        || !agendaTeamDayLastResponse
        || !$.isArray(agendaTeamDayLastResponse.columns)
    ) {
        return null;
    }

    var slotDoctorId = String((slot && slot.id_dot) || '');
    if (slotDoctorId === '') {
        return null;
    }

    var blockedState = null;
    $.each(agendaTeamDayLastResponse.columns, function(_, column) {
        if (String((column && column.id_dot) || '') !== slotDoctorId) {
            return true;
        }

        blockedState = !!column.giorno_bloccato;
        return false;
    });

    return blockedState;
}

function isAgendaSlotDayBlocked(slot, fallbackDateValue) {
    var teamDayBlockedState = getAgendaTeamDaySlotBlockedState(slot);
    if (teamDayBlockedState !== null) {
        return teamDayBlockedState;
    }

    var dateValue = estraiDataSlot(slot) || normalizeAgendaDateKey(fallbackDateValue) || getSelectedAgendaDateKey();
    return isAgendaDayBlockedByDate(dateValue);
}

function applyAgendaStateResponse(res) {
    var selectedDateKey = getSelectedAgendaDateKey();

    agendaBlockedDaysMap = buildAgendaBlockedDayMap(
        res && res.giorni_bloccati_map,
        selectedDateKey,
        !!(res && res.giorno_bloccato)
    );
    agendaMemoBlockedDaysMap = buildAgendaBlockedDayMap(
        res && res.memo_giorni_bloccati_map,
        selectedDateKey,
        !!(res && res.memo_giorno_bloccato)
    );
    agendaDomiciliaryBlockedDaysMap = buildAgendaBlockedDayMap(
        res && res.domiciliare_giorni_bloccati_map,
        selectedDateKey,
        !!(res && res.domiciliare_giorno_bloccato)
    );

    giornoBloccato = isAgendaDateFlaggedInMap(agendaBlockedDaysMap, selectedDateKey, !!(res && res.giorno_bloccato));
    memoGiornoBloccato = isAgendaDateFlaggedInMap(agendaMemoBlockedDaysMap, selectedDateKey, !!(res && res.memo_giorno_bloccato));
    domiciliareGiornoBloccato = isAgendaDateFlaggedInMap(
        agendaDomiciliaryBlockedDaysMap,
        selectedDateKey,
        !!(res && res.domiciliare_giorno_bloccato)
    );
    canBloccareGiorno = !!(res && res.can_bloccare);

    renderAgendaBlockDayButton();
    renderDomiciliariBlockDayButton();
    applicaStatoGiornoBloccato();
}

function estraiDataSlot(slot, eventObj) {
    var dataSlot = ((slot && slot.data_slot) || '').toString().trim();
    if (dataSlot) {
        var matchData = dataSlot.match(/^(\d{4}-\d{2}-\d{2})/);
        if (matchData && matchData[1]) {
            return matchData[1];
        }
    }

    var oraInizio = ((slot && slot.ora_inizio) || '').toString().trim();
    if (oraInizio) {
        var matchOra = oraInizio.match(/^(\d{4}-\d{2}-\d{2})/);
        if (matchOra && matchOra[1]) {
            return matchOra[1];
        }
    }

    var evStart = eventObj && eventObj.start ? moment(eventObj.start) : null;
    if (evStart && evStart.isValid()) {
        return evStart.format('YYYY-MM-DD');
    }

    return '';
}

function setCalendarNoSlotsMode(enabled, message) {
    if (enabled) {
        clearAgendaSlotLayer();
        $('#calendar').addClass('agenda-no-slots');
        $('#agendaCalendarShell').addClass('agenda-no-slots-shell');
        $('#agendaNoSlotsMessage')
            .text(message || 'Nessuna agenda impostata per il giorno selezionato.')
            .show();
    } else {
        $('#calendar').removeClass('agenda-no-slots');
        $('#agendaCalendarShell').removeClass('agenda-no-slots-shell');
        $('#agendaNoSlotsMessage').hide();
    }
}

function clearAgendaSlotLayer() {
    $('#calendar').find('.agenda-slot-layer').remove();
}

function parseAgendaMoment(value) {
    var raw = ((value || '') + '').trim();
    if (raw === '') {
        return null;
    }

    var parsed = moment(raw.replace(' ', 'T'));
    return parsed.isValid() ? parsed : null;
}

function normalizeAgendaSpecialCode(value) {
    return $.trim(String(value || '').replace(/\s+/g, ' ')).toUpperCase();
}

function isAgendaPersonalCommitment(slot, pazSpec) {
    var specialCode = normalizeAgendaSpecialCode(pazSpec);
    if (specialCode === getAgendaPersonalCommitmentSpecialCode()) {
        return true;
    }

    var cognome = $.trim(((slot && slot.cognome) || '').toString()).toUpperCase();
    var nome = $.trim(((slot && slot.nome) || '').toString()).toUpperCase();
    var combined = $.trim((cognome + ' ' + nome).replace(/\s+/g, ' '));

    return combined === getAgendaPersonalCommitmentLabel().toUpperCase();
}

function isAgendaSpecialPatient(slot, pazSpec) {
    if ($.trim((pazSpec || '').toString()) !== '') {
        return true;
    }

    var cognome = $.trim(((slot && slot.cognome) || '').toString()).toUpperCase();
    var nome = $.trim(((slot && slot.nome) || '').toString()).toUpperCase();
    var combined = $.trim((cognome + ' ' + nome).replace(/\s+/g, ' '));
    var specialTokens = ['DDD', 'STOP', 'INFO', 'INF', 'URG', 'CER', 'DOT'];

    for (var i = 0; i < specialTokens.length; i++) {
        var token = specialTokens[i];
        if (cognome === token || nome === token || combined.indexOf(token + ' ') === 0) {
            return true;
        }
    }

    return false;
}

function getAgendaAppointmentDisplayName(slot, pazSpec) {
    if (isAgendaPersonalCommitment(slot, pazSpec)) {
        return getAgendaPersonalCommitmentLabel();
    }

    return $.trim((((slot && slot.cognome) || '') + ' ' + ((slot && slot.nome) || '')).replace(/\s+/g, ' '));
}

function getAgendaSpecialPatientColor(pazSpec, slot) {
    return isAgendaPersonalCommitment(slot, pazSpec) ? '#c9302c' : '#2e8b57';
}

function getAgendaSlotAmbulatorioLabel(slot) {
    return $.trim((((slot && slot.ambulatorio_label) || (slot && slot.ambulatorio) || '') + '').toString());
}

function getAgendaSlotStanzaLabel(slot) {
    var stanza = $.trim((((slot && slot.stanza) || '') + '').toString());

    if (stanza === '') {
        return '';
    }

    return /^stanza\b/i.test(stanza) ? stanza : ('Stanza ' + stanza);
}

function buildAgendaSlotLocationLines(slot) {
    var lines = [];
    var ambulatorio = getAgendaSlotAmbulatorioLabel(slot);
    var stanza = getAgendaSlotStanzaLabel(slot);

    if (ambulatorio !== '') {
        lines.push(ambulatorio);
    }

    if (stanza !== '') {
        lines.push(stanza);
    }

    return lines;
}

function buildAgendaSlotLocationText(slot, separator) {
    var lines = buildAgendaSlotLocationLines(slot);

    if (!lines.length) {
        return '';
    }

    return lines.join(typeof separator === 'string' ? separator : ' - ');
}

function timeValueToMinutes(value) {
    var raw = ((value || '') + '').trim();
    var match = raw.match(/(\d{2}):(\d{2})(?::(\d{2}))?/);

    if (!match) {
        return 0;
    }

    return (parseInt(match[1], 10) * 60) + parseInt(match[2], 10) + ((parseInt(match[3] || '0', 10) || 0) / 60);
}

function getAgendaLocationColumnWidth(dayWidth, dayCount) {
    var availableWidth = Math.max((parseFloat(dayWidth) || 0) - 8, 0);

    if (availableWidth <= 0) {
        return 0;
    }

    var width = Math.round(availableWidth * (dayCount > 1 ? 0.33 : 0.24));
    var minWidth = dayCount > 1 ? 36 : 88;
    var maxWidth = dayCount > 1 ? 76 : 148;
    var minTitleWidth = dayCount > 1 ? 46 : 140;

    width = Math.max(minWidth, Math.min(maxWidth, width));

    if ((availableWidth - width) < minTitleWidth) {
        width = Math.max(dayCount > 1 ? 30 : 72, availableWidth - minTitleWidth);
    }

    width = Math.max(Math.min(width, availableWidth - 18), 0);

    return Math.round(width);
}

function agendaVisualMinuteKey(value) {
    var minute = parseFloat(value);

    if (!isFinite(minute)) {
        return '';
    }

    return (Math.round(minute * 1000) / 1000).toFixed(3);
}

function buildAgendaVisualScale(entries, minMinutes, maxMinutes) {
    var rowHeightPx = parseInt(agendaSlotFixedHeightPx, 10) || 60;
    var anchorMinutes = [];
    var seenAnchorKeys = {};
    var baseRowIndexByAnchor = {};
    var maxRowsByAnchor = {};

    $.each(entries || [], function(_, entry) {
        if (!entry || entry.isHidden || entry.durationMinutes <= 0 || entry.displayDurationMinutes <= 0) {
            return true;
        }

        var anchorMinutesValue = parseFloat(
            typeof entry.clusterAnchorStartMinutes !== 'undefined'
                ? entry.clusterAnchorStartMinutes
                : entry.startMinutes
        );
        var minuteKey = agendaVisualMinuteKey(anchorMinutesValue);

        if (minuteKey === '') {
            return true;
        }

        if (!seenAnchorKeys[minuteKey]) {
            seenAnchorKeys[minuteKey] = true;
            anchorMinutes.push(anchorMinutesValue);
        }

        var requiredRows = (parseInt(entry.stackIndex, 10) || 0) + 1;
        maxRowsByAnchor[minuteKey] = Math.max(maxRowsByAnchor[minuteKey] || 0, requiredRows);

        return true;
    });

    anchorMinutes.sort(function(leftMinute, rightMinute) {
        return leftMinute - rightMinute;
    });

    if (!anchorMinutes.length) {
        var fallbackStart = isFinite(minMinutes) ? minMinutes : 0;
        var fallbackKey = agendaVisualMinuteKey(fallbackStart);
        anchorMinutes.push(fallbackStart);
        maxRowsByAnchor[fallbackKey] = 1;
    }

    var cursorRows = 0;
    $.each(anchorMinutes, function(_, minute) {
        var anchorKey = agendaVisualMinuteKey(minute);
        baseRowIndexByAnchor[anchorKey] = cursorRows;
        cursorRows += Math.max(maxRowsByAnchor[anchorKey] || 1, 1);
        return true;
    });

    return {
        rowHeightPx: rowHeightPx,
        anchorMinutes: anchorMinutes,
        totalMinutes: Math.max(cursorRows * rowHeightPx, rowHeightPx),
        getLineTopForAnchor: function(minute) {
            var anchorKey = agendaVisualMinuteKey(minute);
            return (baseRowIndexByAnchor[anchorKey] || 0) * rowHeightPx;
        },
        getTopForEntry: function(entry) {
            if (!entry) {
                return 0;
            }

            var anchorMinutesValue = parseFloat(
                typeof entry.clusterAnchorStartMinutes !== 'undefined'
                    ? entry.clusterAnchorStartMinutes
                    : entry.startMinutes
            );
            var anchorKey = agendaVisualMinuteKey(anchorMinutesValue);
            var baseRowIndex = baseRowIndexByAnchor[anchorKey] || 0;
            var stackIndex = parseInt(entry.stackIndex, 10) || 0;

            return (baseRowIndex + stackIndex) * rowHeightPx;
        }
    };
}

function buildAgendaVisualMarkers(entries, visualScale) {
    var markers = [];
    var seenMarkerRows = {};

    $.each(entries || [], function(_, entry) {
        if (!entry || entry.isHidden || entry.durationMinutes <= 0 || entry.displayDurationMinutes <= 0) {
            return true;
        }

        var lineTop = visualScale.getTopForEntry(entry);
        var rowKey = agendaVisualMinuteKey(lineTop);
        if (rowKey === '' || seenMarkerRows[rowKey]) {
            return true;
        }

        seenMarkerRows[rowKey] = true;
        var label = moment().startOf('day').add(entry.startMinutes, 'minutes').format('HH:mm');

        markers.push({
            visualMinutes: lineTop,
            label: label,
            tooltip: label,
            isCollapsed: false
        });

        return true;
    });

    markers.sort(function(leftMarker, rightMarker) {
        return leftMarker.visualMinutes - rightMarker.visualMinutes;
    });

    return markers;
}

function buildCalendarDayKeys(viewName, currentDate) {
    var current = moment(currentDate);
    if (!current.isValid()) {
        return [];
    }

    if (viewName === 'agendaWeek') {
        var start = current.clone().startOf('isoWeek');
        var days = [];

        for (var i = 0; i < 7; i++) {
            days.push(start.clone().add(i, 'days').format('YYYY-MM-DD'));
        }

        return days;
    }

    return [current.format('YYYY-MM-DD')];
}

function getCalendarViewInfo() {
    if (!$('#calendar').data('fullCalendar')) {
        return null;
    }

    var view = $('#calendar').fullCalendar('getView');
    var currentDate = $('#calendar').fullCalendar('getDate');
    var currentMoment = moment(currentDate);

    if (!view || !currentMoment.isValid()) {
        return null;
    }

    return {
        name: view.name || 'agendaDay',
        title: view.title || '',
        currentDate: currentMoment,
        dayKeys: buildCalendarDayKeys(view.name || 'agendaDay', currentMoment)
    };
}

function renderAgendaSlotLayer() {
    clearAgendaSlotLayer();

    if (!$('#calendar').data('fullCalendar')) {
        return;
    }

    var viewInfo = getCalendarViewInfo();
    if (!viewInfo || !viewInfo.dayKeys.length) {
        return;
    }
    var $calendar = $('#calendar');
    var $view = $calendar.find('.fc-view-container .fc-view');
    var $timeGrid = $view.find('.fc-time-grid');

    if (!$timeGrid.length) {
        return;
    }

    var $slats = $timeGrid.find('.fc-slats').first();
    var axisWidth = Math.max($slats.find('.fc-axis').first().outerWidth() || 0, 84);
    var totalWidth = $timeGrid.innerWidth() || 0;
    var contentWidth = totalWidth - axisWidth;
    var dayCount = viewInfo.dayKeys.length;

    if (contentWidth <= 0 || dayCount <= 0) {
        return;
    }

    var dayWidth = contentWidth / dayCount;
    var minMinutes = timeValueToMinutes(agendaMinTime);
    var maxMinutes = timeValueToMinutes(agendaMaxTime);

    if (maxMinutes <= minMinutes) {
        maxMinutes = minMinutes + 60;
    }

    var totalMinutes = maxMinutes - minMinutes;
    var dayIndexMap = {};
    var nextSlotStartMap = buildAgendaNextSlotStartMap(agendaCurrentSlots);

    $.each(viewInfo.dayKeys, function(index, dayKey) {
        dayIndexMap[dayKey] = index;
    });

    var slotEntries = buildAgendaSlotEntries(agendaCurrentSlots, nextSlotStartMap, dayIndexMap);
    var visualScale = buildAgendaVisualScale(slotEntries, minMinutes, maxMinutes);
    var totalVisualMinutes = parseFloat(visualScale.totalMinutes) || 0;
    var slotHeightPx = parseInt(visualScale.rowHeightPx, 10) || parseInt(agendaSlotFixedHeightPx, 10) || 60;
    var hasLocationColumn = false;
    var locationColumnWidth = 0;

    $.each(slotEntries, function(_, entry) {
        if (entry.isHidden || entry.durationMinutes <= 0 || entry.displayDurationMinutes <= 0) {
            return true;
        }

        if (buildAgendaSlotLocationLines(entry.slot).length > 0) {
            hasLocationColumn = true;
            return false;
        }

        return true;
    });

    if (hasLocationColumn) {
        locationColumnWidth = getAgendaLocationColumnWidth(dayWidth, dayCount);
    }

    if (totalVisualMinutes <= 0) {
        totalVisualMinutes = slotHeightPx;
    }

    var gridHeight = totalVisualMinutes;
    $timeGrid.css({
        height: gridHeight + 'px',
        minHeight: gridHeight + 'px',
        maxHeight: gridHeight + 'px',
        overflowX: 'hidden',
        overflowY: 'hidden'
    });
    $slats.css({
        height: gridHeight + 'px',
        minHeight: gridHeight + 'px',
        maxHeight: gridHeight + 'px'
    });
    $view.find('.fc-time-grid-container, .fc-scroller').css({
        height: gridHeight + 'px',
        minHeight: gridHeight + 'px',
        maxHeight: gridHeight + 'px',
        overflowX: 'hidden',
        overflowY: 'hidden'
    });

    var $layer = $('<div class="agenda-slot-layer"></div>').css({
        height: gridHeight + 'px'
    });
    var $axisOverlay = $('<div class="agenda-axis-overlay"></div>').css({
        width: axisWidth + 'px'
    });

    if (giornoBloccato) {
        $layer.addClass('is-locked');
    }

    var boundaryMinutesMap = {};

    if (!slotEntries.length) {
        boundaryMinutesMap[minMinutes] = true;
        boundaryMinutesMap[maxMinutes] = true;
    }

    for (var dayIndex = 0; dayIndex < dayCount; dayIndex++) {
        $layer.append(
            $('<div class="agenda-grid-day-column"></div>').css({
                left: (axisWidth + (dayIndex * dayWidth)) + 'px',
                width: dayWidth + 'px'
            })
        );
    }

    $.each(slotEntries, function(_, entry) {
        if (entry.isHidden || entry.durationMinutes <= 0 || entry.displayDurationMinutes <= 0) {
            return true;
        }

        boundaryMinutesMap[entry.startMinutes] = true;
        boundaryMinutesMap[entry.startMinutes + entry.displayDurationMinutes] = true;

        return true;
    });

    var visualMarkers = buildAgendaVisualMarkers(slotEntries, visualScale);

    $.each(visualMarkers, function(_, marker) {
        var lineTop = marker.visualMinutes;
        if (lineTop < 0 || lineTop > gridHeight) {
            return true;
        }

        var labelTop = Math.max(Math.min(lineTop - 10, gridHeight - 20), 2);
        if (marker.visualMinutes === 0) {
            labelTop = 6;
        }

        $layer.append(
            $('<div class="agenda-grid-hour-line"></div>').css({
                left: axisWidth + 'px',
                top: lineTop + 'px'
            })
        );

        $axisOverlay.append(
            $('<div class="agenda-grid-axis-label"></div>')
                .text(marker.label)
                .attr('title', marker.tooltip || marker.label)
                .toggleClass('is-collapsed-gap', !!marker.isCollapsed)
                .css({
                    top: labelTop + 'px'
                })
        );

        return true;
    });

    $.each(slotEntries, function(_, entry) {
        var slot = entry.slot;

        if (entry.isHidden || entry.durationMinutes <= 0 || entry.displayDurationMinutes <= 0) {
            return true;
        }

        var top = visualScale.getTopForEntry(entry);
        var height = slotHeightPx;
        var availableWidth = Math.max(dayWidth - 4, 24);
        var width = availableWidth;
        var left = axisWidth + (entry.dayIndex * dayWidth) + 2;
        var timeLabel = entry.slotStart.format('HH:mm');
        var stato = ((slot.stato || '') + '').toUpperCase();
        var pazSpec = $.trim(slot.paz_spec || '');
        var isSpecialPatient = isAgendaSpecialPatient(slot, pazSpec);
        var noteEvento = buildAppointmentNoteDisplay(slot);
        var nominativo = getAgendaAppointmentDisplayName(slot, pazSpec);
        var cellulareEvento = $.trim(slot.cellulare || '');
        var telefonoEvento = $.trim(slot.telefono || '');
        var recapitoEvento = cellulareEvento !== '' ? cellulareEvento : telefonoEvento;
        var locationLines = buildAgendaSlotLocationLines(slot);
        var locationText = locationLines.length ? locationLines.join(' - ') : '';
        var recapitoTooltip = cellulareEvento !== ''
            ? ('Cell: ' + cellulareEvento)
            : (telefonoEvento !== '' ? ('Tel: ' + telefonoEvento) : '');
        var hasAppointment = !!slot.id_appuntamento || (stato !== 'LIBERO' && stato !== 'BLOCCATO' && stato !== 'CHIUSO');
        var slotDayBlocked = isAgendaSlotDayBlocked(slot, entry.dayKey);
        var visitTypeVisualStyle = (!slotDayBlocked && hasAppointment) ? getAgendaVisitTypeVisualStyleById(slot.id_tipo_visita) : null;
        var titolo = 'Slot libero';
        var extraClass = 'is-free';
        var titoloVisuale = titolo;
        var tooltipParts = [timeLabel];

        if (slotDayBlocked && hasAppointment) {
            titolo = nominativo !== '' ? nominativo : 'Appuntamento';
            extraClass = 'is-day-blocked';
        } else if (slotDayBlocked || stato === 'CHIUSO') {
            titolo = 'Giornata bloccata';
            extraClass = 'is-day-blocked';
        } else if (stato === 'BLOCCATO') {
            titolo = 'Slot libero';
            extraClass = 'is-free';
        } else if ((stato === 'LIBERO' || stato === 'BLOCCATO') && !hasAppointment) {
            titolo = 'Slot libero';
            extraClass = 'is-free';
        } else {
            titolo = nominativo !== '' ? nominativo : 'Appuntamento';
            extraClass = 'is-booked';

            if (isSpecialPatient) {
                extraClass += ' is-booked-spec';
                if (isAgendaPersonalCommitment(slot, pazSpec)) {
                    extraClass += ' is-personal-commitment';
                }
            }
        }

        titoloVisuale = titolo;
        if (recapitoEvento !== '' && hasAppointment) {
            titoloVisuale += ' - ' + recapitoEvento;
        }
        if (noteEvento !== '' && hasAppointment) {
            titoloVisuale += ' - ' + noteEvento;
        }

        if (locationText !== '') {
            tooltipParts.push(locationText);
        }
        tooltipParts.push(titolo);
        if (recapitoTooltip !== '' && hasAppointment) {
            tooltipParts.push(recapitoTooltip);
        }
        if (noteEvento !== '' && hasAppointment) {
            tooltipParts.push(noteEvento);
        }

        var locationBoxWidth = hasLocationColumn ? Math.min(locationColumnWidth, Math.max(width - 18, 0)) : 0;
        var locationStateClass = 'is-free';
        var $locationBox = null;
        var slotCss = {
            top: top + 'px',
            left: left + 'px',
            width: width + 'px',
            height: height + 'px'
        };

        if (locationBoxWidth > 0) {
            if (extraClass.indexOf('is-personal-commitment') !== -1) {
                locationStateClass = 'is-personal-commitment';
            } else if (extraClass.indexOf('is-booked-spec') !== -1) {
                locationStateClass = 'is-booked-spec';
            } else if (extraClass.indexOf('is-day-blocked') !== -1) {
                locationStateClass = 'is-day-blocked';
            } else if (extraClass.indexOf('is-booked') !== -1) {
                locationStateClass = 'is-booked';
            }

            slotCss.paddingLeft = (locationBoxWidth + 10) + 'px';
        }

        if (locationBoxWidth > 0) {
            $locationBox = $('<div class="agenda-slot-location"></div>')
                .addClass(locationStateClass)
                .toggleClass('is-empty', locationText === '')
                .attr('title', locationText !== '' ? locationText : 'Ambulatorio e stanza non indicati')
                .css({
                    top: top + 'px',
                    left: left + 'px',
                    width: locationBoxWidth + 'px',
                    height: height + 'px'
                });

            if (locationLines.length) {
                if (locationLines[0]) {
                    $locationBox.append($('<div class="agenda-slot-location-amb"></div>').text(locationLines[0]));
                }
                if (locationLines[1]) {
                    $locationBox.append($('<div class="agenda-slot-location-room"></div>').text(locationLines[1]));
                }
            } else {
                $locationBox.append($('<div class="agenda-slot-location-room"></div>').text('-'));
            }

            if (visitTypeVisualStyle && extraClass.indexOf('is-booked') !== -1) {
                applyAgendaVisitTypeVisualStyle($locationBox, visitTypeVisualStyle);
            }

            $layer.append($locationBox);
        }

        if ((stato === 'LIBERO' || stato === 'BLOCCATO') && !hasAppointment && !slotDayBlocked) {
            var freeSlotTooltip = tooltipParts.join(' - ');
            var $freeSlot = $('<button type="button" class="agenda-custom-slot is-free"></button>')
                .attr('title', freeSlotTooltip)
                .attr('aria-label', freeSlotTooltip)
                .css(slotCss)
                .append($('<div class="agenda-custom-slot-time"></div>').text(timeLabel))
                .append($('<div class="agenda-custom-slot-title"></div>').text(titoloVisuale))
                .on('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();

                    if (slotDayBlocked) {
                        return false;
                    }

                    apriSlotLiberoDaSlot(slot);
                    return false;
                });

            if (((slot.origine_slot || '') + '').toUpperCase() === 'EXTRA') {
                $freeSlot.addClass('is-extra');
            }

            $layer.append($freeSlot);
            return true;
        }

        var $slot = $('<button type="button" class="agenda-custom-slot"></button>')
            .addClass(extraClass)
            .attr('title', tooltipParts.join(' - '))
            .attr('aria-label', tooltipParts.join(' - '))
            .css(slotCss)
            .append($('<div class="agenda-custom-slot-time"></div>').text(timeLabel))
            .append($('<div class="agenda-custom-slot-title"></div>').text(titoloVisuale));

        if (visitTypeVisualStyle && extraClass.indexOf('is-booked') !== -1) {
            applyAgendaVisitTypeVisualStyle($slot, visitTypeVisualStyle);
        }

        if (isAgendaSearchFocusedSlot(slot)) {
            $slot.addClass('is-search-focus');
        }

        if (slot.id_appuntamento) {
            $slot.addClass('has-print-action');
            $slot.append(
                $('<button type="button" class="agenda-slot-print-btn" title="Stampa promemoria appuntamento" aria-label="Stampa promemoria appuntamento"></button>')
                    .append($('<img>', {
                        src: "<?= base_url('public/assets/images/ticket.png') ?>",
                        alt: '',
                        'aria-hidden': 'true'
                    }))
                    .on('click', function(e) {
                        e.preventDefault();
                        e.stopPropagation();
                        stampaPromemoriaAppuntamento(slot);
                        return false;
                    })
            );
        }

        $slot.on('click', function(e) {
            e.preventDefault();
            e.stopPropagation();

            if (slotDayBlocked) {
                return false;
            }

            if ((stato === 'LIBERO' || stato === 'BLOCCATO') && !hasAppointment) {
                apriSlotLiberoDaSlot(slot);
                return false;
            }

            riempiModaleDaEvento(slot);
            $('#appointmentModal').modal('show');
            return false;
        });

        $layer.append($slot);
        return true;
    });

    $layer.append($axisOverlay);
    $timeGrid.append($layer);
    focusAgendaSearchAppointmentSlotIfNeeded();
}

function buildAgendaNextSlotStartMap(slots) {
    var perDay = {};
    var out = {};

    $.each(slots || [], function(_, slot) {
        var slotStart = parseAgendaMoment(slot.ora_inizio);
        if (!slotStart) {
            return true;
        }

        var dayKey = slotStart.format('YYYY-MM-DD');
        var startMinutes = (slotStart.hours() * 60) + slotStart.minutes() + (slotStart.seconds() / 60);
        perDay[dayKey] = perDay[dayKey] || {};
        perDay[dayKey][startMinutes] = true;
        return true;
    });

    $.each(perDay, function(dayKey, minutesMap) {
        var minutes = Object.keys(minutesMap)
            .map(function(value) { return parseFloat(value); })
            .filter(function(value) { return !isNaN(value); })
            .sort(function(leftValue, rightValue) { return leftValue - rightValue; });

        for (var index = 0; index < minutes.length; index++) {
            out[dayKey + '|' + minutes[index]] = (index + 1 < minutes.length) ? minutes[index + 1] : null;
        }
    });

    return out;
}

function buildAgendaSlotEntries(slots, nextSlotStartMap, dayIndexMap) {
    var entries = [];

    $.each(slots || [], function(_, slot) {
        if (isAgendaCoveredSecondarySlot(slot)) {
            return true;
        }

        var slotStart = getAgendaSlotVisualStartMoment(slot);
        var slotEnd = getAgendaSlotVisualEndMoment(slot);

        if (!slotStart || !slotEnd) {
            return true;
        }

        var dayKey = slotStart.format('YYYY-MM-DD');
        if (typeof dayIndexMap[dayKey] === 'undefined') {
            return true;
        }

        var startMinutes = (slotStart.hours() * 60) + slotStart.minutes() + (slotStart.seconds() / 60);
        var endMinutes = (slotEnd.hours() * 60) + slotEnd.minutes() + (slotEnd.seconds() / 60);
        var durationMinutes = endMinutes - startMinutes;
        var displayDurationMinutes = getAgendaDisplayDurationMinutes(
            slot,
            durationMinutes,
            startMinutes,
            nextSlotStartMap[dayKey + '|' + startMinutes]
        );
        var stato = ((slot.stato || '') + '').toUpperCase();
        var hasAppointment = !!slot.id_appuntamento || (stato !== 'LIBERO' && stato !== 'BLOCCATO' && stato !== 'CHIUSO');

        if (durationMinutes <= 0 || displayDurationMinutes <= 0) {
            return true;
        }

        entries.push({
            slot: slot,
            slotStart: slotStart,
            slotEnd: slotEnd,
            dayKey: dayKey,
            dayIndex: dayIndexMap[dayKey],
            startMinutes: startMinutes,
            endMinutes: endMinutes,
            durationMinutes: durationMinutes,
            displayDurationMinutes: displayDurationMinutes,
            hasAppointment: hasAppointment,
            isFreeWithoutAppointment: !hasAppointment && (stato === 'LIBERO' || stato === 'BLOCCATO'),
            isHidden: false,
            clusterAnchorStartMinutes: startMinutes,
            stackIndex: 0,
            stackSize: 1,
            overlapColumn: 0,
            overlapColumns: 1
        });

        return true;
    });

    applyAgendaSlotOverlapLayout(entries);

    return entries;
}

function applyAgendaSlotOverlapLayout(entries) {
    var byDay = {};

    $.each(entries || [], function(_, entry) {
        if (!entry) {
            return true;
        }

        entry.isHidden = false;
        byDay[entry.dayKey] = byDay[entry.dayKey] || [];
        byDay[entry.dayKey].push(entry);
        return true;
    });

    $.each(byDay, function(_, dayEntries) {
        applyAgendaDayOverlapLayout(dayEntries);
    });
}

function applyAgendaDayOverlapLayout(dayEntries) {
    if (!dayEntries || !dayEntries.length) {
        return;
    }

    dayEntries.sort(function(leftEntry, rightEntry) {
        if (leftEntry.startMinutes !== rightEntry.startMinutes) {
            return leftEntry.startMinutes - rightEntry.startMinutes;
        }

        var leftOrigin = (((leftEntry.slot || {}).origine_slot || '') + '').toUpperCase();
        var rightOrigin = (((rightEntry.slot || {}).origine_slot || '') + '').toUpperCase();
        if (leftOrigin !== rightOrigin) {
            if (leftOrigin === 'CONFIG') {
                return -1;
            }
            if (rightOrigin === 'CONFIG') {
                return 1;
            }
        }

        if (!!leftEntry.hasAppointment !== !!rightEntry.hasAppointment) {
            return leftEntry.hasAppointment ? -1 : 1;
        }

        return leftEntry.endMinutes - rightEntry.endMinutes;
    });

    var clusterEntries = [];
    var clusterEndMinutes = null;

    function flushCluster() {
        if (!clusterEntries.length) {
            return;
        }

        var anchorStartMinutes = clusterEntries[0].startMinutes;
        $.each(clusterEntries, function(index, clusterEntry) {
            clusterEntry.clusterAnchorStartMinutes = anchorStartMinutes;
            clusterEntry.stackIndex = index;
            clusterEntry.stackSize = clusterEntries.length;
            clusterEntry.overlapColumn = 0;
            clusterEntry.overlapColumns = 1;
            clusterEntry.isHidden = false;
            return true;
        });

        clusterEntries = [];
        clusterEndMinutes = null;
    }

    function pushClusterEntry(entry) {
        clusterEntries.push(entry);
        if (clusterEndMinutes === null || entry.endMinutes > clusterEndMinutes) {
            clusterEndMinutes = entry.endMinutes;
        }
    }

    $.each(dayEntries, function(_, entry) {
        if (!clusterEntries.length) {
            pushClusterEntry(entry);
            return true;
        }

        if (entry.startMinutes < clusterEndMinutes) {
            pushClusterEntry(entry);
            return true;
        }

        flushCluster();
        pushClusterEntry(entry);
        return true;
    });

    flushCluster();
}

function agendaEntriesOverlap(leftEntry, rightEntry) {
    if (!leftEntry || !rightEntry) {
        return false;
    }

    return leftEntry.startMinutes < rightEntry.endMinutes
        && leftEntry.endMinutes > rightEntry.startMinutes;
}

function getAgendaDisplayDurationMinutes(slot, actualDurationMinutes, startMinutes, nextStartMinutes) {
    var duration = parseInt(actualDurationMinutes, 10) || 0;
    if (duration <= 0) {
        return 0;
    }

    var origine = ((slot && slot.origine_slot) ? String(slot.origine_slot) : '').toUpperCase();
    if (origine === 'EXTRA' && agendaCalendarStep > 0 && duration < agendaCalendarStep) {
        var displayDuration = agendaCalendarStep;
        if (typeof nextStartMinutes === 'number' && nextStartMinutes > startMinutes) {
            displayDuration = Math.min(displayDuration, nextStartMinutes - startMinutes);
        }

        if (displayDuration > duration) {
            return displayDuration;
        }
    }

    return duration;
}

function caricaNotaGiorno() {
    syncAgendaDayNoteIndicators('');

    $.get("<?= base_url('agenda/get-nota-giorno') ?>", {
        id_dot: $('#id_dot').val(),
        data: $('#agenda_date').val()
    }, function(res) {
        if (!res.status) {
            $('#nota_giorno_text').val('');
            $('#nota_giorno_status').text('');
            notaGiornoUltimoValore = '';
            syncAgendaDayNoteIndicators('');
            return;
        }

        var testo = res.nota || '';
        $('#nota_giorno_text').val(testo);
        $('#nota_giorno_status').text('');
        notaGiornoUltimoValore = testo;
        notaGiornoDirty = false;
        syncAgendaDayNoteIndicators(testo);

        applicaStatoGiornoBloccato();
    }, 'json');
}
function salvaNotaGiorno(callbackSilenziosa) {
    var testo = $('#nota_giorno_text').val();

    if (memoGiornoBloccato) {
        $('#nota_giorno_status').text('Giorno bloccato per memo');
        return;
    }

    if (testo === notaGiornoUltimoValore) {
        if (typeof callbackSilenziosa === 'function') {
            callbackSilenziosa(true);
        }
        return;
    }

    $('#nota_giorno_status').removeClass('text-danger text-success')
        .addClass('text-muted')
        .text('Salvataggio...');

    $.post("<?= base_url('agenda/salva-nota-giorno') ?>", {
        id_dot: $('#id_dot').val(),
        data: $('#agenda_date').val(),
        nota: testo
    }, function(res) {
        if (!res.status) {
            $('#nota_giorno_status').removeClass('text-muted text-success')
                .addClass('text-danger')
                .text(res.message || 'Errore nel salvataggio');
            if (typeof callbackSilenziosa === 'function') {
                callbackSilenziosa(false);
            }
            return;
        }

        notaGiornoUltimoValore = testo;
        notaGiornoDirty = false;
        syncAgendaDayNoteIndicators(testo);

        $('#nota_giorno_status').removeClass('text-muted text-danger')
            .addClass('text-success')
            .text('Salvata');

        setTimeout(function() {
            if ($('#nota_giorno_status').text() === 'Salvata') {
                $('#nota_giorno_status').removeClass('text-success').addClass('text-muted').text('');
            }
        }, 1200);

        if (typeof callbackSilenziosa === 'function') {
            callbackSilenziosa(true);
        }
    }, 'json');
}
function escapeHtml(text) {
    return $('<div>').text(text == null ? '' : text).html();
}

function nl2br(str) {
    return (str || '').replace(/\n/g, '<br>');
}

function getAgendaTicketUrl(idAppuntamento) {
    return "<?= base_url('agenda/stampa-ticket-appuntamento') ?>/" + encodeURIComponent(idAppuntamento || 0);
}

function stampaPromemoriaAppuntamento(slot) {
    var idAppuntamento = parseInt(slot && slot.id_appuntamento ? slot.id_appuntamento : 0, 10) || 0;
    if (idAppuntamento <= 0) {
        return;
    }

    var url = getAgendaTicketUrl(idAppuntamento);
    var userAgent = (window.navigator.userAgent || '').toLowerCase();

    if (userAgent.indexOf('chrome') > -1) {
        var $host = $('#agendaTicketPrintHost');
        if (!$host.length) {
            $host = $('<div id="agendaTicketPrintHost" style="display:none;"></div>');
            $('body').append($host);
        }

        $host.empty();

        var $iframe = $('<iframe>', {
            id: 'agendaTicketPrintFrame',
            name: 'agendaTicketPrintFrame',
            src: url
        });

        $host.append($iframe);
        $iframe.on('load', function() {
            try {
                if (this.contentWindow) {
                    this.contentWindow.focus();
                    this.contentWindow.print();
                }
            } catch (e) {
                window.open(url, '_blank');
            }
        });

        return;
    }

    window.open(url, '_blank');
}

function isMemoActionBlocked() {
    return memoGiornoBloccato;
}

function isDomiciliariActionBlocked() {
    return giornoBloccato || domiciliareGiornoBloccato;
}

function applicaStatoGiornoBloccato() {
    if (giornoBloccato) {
        $('#calendar').addClass('agenda-day-locked');
    } else {
        $('#calendar').removeClass('agenda-day-locked');
    }

    var disableExtraSlotButtons = !shouldUseExtraSlotDoctorSelector() && giornoBloccato;
    $('#btnAddExtraSlot, #btnCompressedAddExtraSlot').prop('disabled', disableExtraSlotButtons);

    var sharedMemoEnabled = isSharedMemoManagementEnabled();
    var memoDisabled = isMemoActionBlocked();
    var domDisabled = isDomiciliariActionBlocked();
    var domTitle = giornoBloccato
        ? 'La giornata agenda e bloccata: anche le domiciliari sono bloccate.'
        : (domiciliareGiornoBloccato ? 'Giorno bloccato per domiciliari' : '');

    $('#btnOpenNoteModal, #btnOpenNoteModalTop')
        .prop('disabled', !sharedMemoEnabled && memoDisabled)
        .toggleClass('disabled', !sharedMemoEnabled && memoDisabled)
        .attr('title', !sharedMemoEnabled && memoGiornoBloccato ? 'Giorno bloccato per memo' : '');

    $('#btnNuovaVisita')
        .prop('disabled', domDisabled)
        .toggleClass('disabled', domDisabled)
        .attr('title', domTitle);

    $('#boxVisiteDomiciliariPanel').toggleClass('agenda-domiciliari-day-locked', giornoBloccato);
    $('#domiciliariLockNotice').toggle(giornoBloccato);

    $('#nota_giorno_text')
        .prop('disabled', memoDisabled)
        .attr('title', memoGiornoBloccato ? 'Giorno bloccato per memo' : '');

    if (sharedMemoEnabled) {
        $('#noteList .btn, #noteList input, #noteList textarea, #noteList select').prop('disabled', false);
        $('#noteList .agenda-note-card[data-memo-blocked="1"] .btn, #noteList .agenda-note-card[data-memo-blocked="1"] input, #noteList .agenda-note-card[data-memo-blocked="1"] textarea, #noteList .agenda-note-card[data-memo-blocked="1"] select')
            .prop('disabled', true);
    } else {
        $('#noteList .btn, #noteList input, #noteList textarea, #noteList select').prop('disabled', memoDisabled);
    }

    $('#domiciliariList .btn, #domiciliariList input, #domiciliariList textarea, #domiciliariList select')
        .prop('disabled', domDisabled)
        .attr('title', domTitle);
}

function eseguiCallbackAgenda(callback) {
    if (typeof callback === 'function') {
        callback();
    }
}

function aggiornaStatoGiorno(onComplete) {
    if (agendaStatusXhr && agendaStatusXhr.readyState !== 4) {
        agendaStatusXhr.abort();
    }

    var requestSeq = ++agendaStatusRequestSeq;

    agendaStatusXhr = $.get("<?= base_url('agenda/stato-giorno') ?>", {
        id_dot: $('#id_dot').val(),
        data: $('#agenda_date').val()
    }, function(res) {
        if (requestSeq !== agendaStatusRequestSeq) {
            return;
        }

        if (!res.status) {
            applyAgendaStateResponse({});
            if ($('#calendar').data('fullCalendar')) {
                $('#calendar').fullCalendar('rerenderEvents');
                renderAgendaSlotLayer();
            }
            eseguiCallbackAgenda(onComplete);
            return;
        }

        applyAgendaStateResponse(res);

        if ($('#calendar').data('fullCalendar')) {
            $('#calendar').fullCalendar('rerenderEvents');
            renderAgendaSlotLayer();
        }
        eseguiCallbackAgenda(onComplete);
    }, 'json').fail(function(xhr, textStatus) {
        if (textStatus === 'abort' || requestSeq !== agendaStatusRequestSeq) {
            return;
        }

        applyAgendaStateResponse({});

        if ($('#calendar').data('fullCalendar')) {
            $('#calendar').fullCalendar('rerenderEvents');
            renderAgendaSlotLayer();
        }

        eseguiCallbackAgenda(onComplete);
    });
}

function inizializzaCalendario(stepMinuti, minTime, maxTime) {
    agendaCalendarStep = stepMinuti || agendaCalendarBaseStep;
    agendaMinTime = minTime || '08:00:00';
    agendaMaxTime = maxTime || '18:00:00';
    var compressedLayoutEnabled = !!window.AGENDA_CONFIG.compressedLayoutEnabled;

    if ($('#calendar').data('fullCalendar')) {
        $('#calendar').fullCalendar('destroy');
    }

    $('#calendar').fullCalendar({
        header: compressedLayoutEnabled
            ? {
                left: '',
                center: 'title',
                right: ''
            }
            : {
                left: 'prev,today,next',
                center: 'title',
                right: ''
            },
        locale: 'it',
        defaultView: 'agendaDay',
        defaultDate: $('#agenda_date').val() || window.AGENDA_CONFIG.selectedDate,
        allDaySlot: false,
        height: 'auto',
        contentHeight: 'auto',
        editable: false,
        droppable: false,
        selectable: false,
        selectHelper: false,
        eventLimit: true,
        minTime: agendaMinTime,
        maxTime: agendaMaxTime,
        slotDuration: '00:' + String(agendaCalendarStep).padStart(2, '0') + ':00',
        snapDuration: '00:' + String(agendaCalendarStep).padStart(2, '0') + ':00',
        slotLabelInterval: '00:' + String(agendaCalendarStep).padStart(2, '0') + ':00',
        events: [],

        eventRender: function(event, element) {
            var slot = event.extendedProps || event;

            if (isAgendaSlotDayBlocked(slot, estraiDataSlot(slot, event))) {
                element.css({
                    'background-color': '#d9534f',
                    'border-color': '#d9534f',
                    'color': '#ffffff',
                    'cursor': 'not-allowed',
                    'opacity': '0.95'
                });
            }
        },

        eventClick: function(event) {
            var slot = event.extendedProps || event;
            var slotDayBlocked = isAgendaSlotDayBlocked(slot, estraiDataSlot(slot, event));

            if (slotDayBlocked) {
                return false;
            }

            if ((slot.stato === 'LIBERO' || slot.stato === 'BLOCCATO') && !slot.id_appuntamento) {
                var startLibero = moment((slot.ora_inizio || '').replace(' ', 'T'));

                if (startLibero.isValid()) {
                    appointmentModalDate = startLibero.format('YYYY-MM-DD');
                    $('#agenda_date').val(appointmentModalDate);
                    apriSlotLiberoDaSelezione(startLibero);
                }

                return false;
            }

            var dataSlot = estraiDataSlot(slot, event);
            if (dataSlot) {
                $('#agenda_date').val(dataSlot);
                appointmentModalDate = dataSlot;
            }
            riempiModaleDaEvento(slot);
            $('#appointmentModal').modal('show');
        },

        eventAfterAllRender: function() {
            setTimeout(renderAgendaSlotLayer, 0);
        }
    });

    bindClickSlotVuoti();
}

function bindClickSlotVuoti() {
    $('#calendar').off('click', '.fc-time-grid .fc-slats td');
}

function leggiFiltriAgenda() {
    var data = (($('#agenda_date').val() || '').toString().trim());
    var view = normalizeAgendaViewModeValue($('#view_mode').val());

    if (moment(data, 'YYYY-MM-DD', true).isValid()) {
        data = moment(data, 'YYYY-MM-DD').format('YYYY-MM-DD');
    } else if (!isTeamDayViewActive() && $('#calendar').data('fullCalendar')) {
        var currentDate = $('#calendar').fullCalendar('getDate');
        if (currentDate && moment(currentDate).isValid()) {
            data = moment(currentDate).format('YYYY-MM-DD');
        }
    }

    if (!moment(data, 'YYYY-MM-DD', true).isValid()) {
        data = window.AGENDA_CONFIG.selectedDate;
    }

    $('#agenda_date').val(data);
    setAgendaViewMode(view);
    window.AGENDA_CONFIG.selectedDate = data;
    window.AGENDA_CONFIG.viewMode = view;
    syncAgendaTeamDayToolbar();
    syncAgendaCompressedDaybar();

    return {
        data: data,
        view: view
    };
}

function syncCalendarDateAndReload() {
    if (isTeamDayViewActive()) {
        caricaTutto();
        return;
    }

    var currentView = $('#calendar').fullCalendar('getView');
    var currentDate = $('#calendar').fullCalendar('getDate');

    if (currentView && currentView.name) {
        setAgendaViewMode(currentView.name === 'agendaWeek' ? 'week' : 'day');
    }

    if (!currentDate || !moment(currentDate).isValid()) {
        return;
    }

    $('#agenda_date').val(moment(currentDate).format('YYYY-MM-DD'));
    caricaTutto();
}

function sincronizzaVistaCalendario(view, data) {
    view = normalizeAgendaViewModeValue(view);
    toggleAgendaCalendarShells(view);

    if (view === 'team_day') {
        return;
    }

    if (!$('#calendar').data('fullCalendar')) {
        return;
    }

    var targetView = view === 'week' ? 'agendaWeek' : 'agendaDay';
    var currentView = $('#calendar').fullCalendar('getView');

    if (!currentView || currentView.name !== targetView) {
        $('#calendar').fullCalendar('changeView', targetView);
    }

    $('#calendar').fullCalendar('gotoDate', data);
}

function riallineaRenderingCalendario() {
    if (isTeamDayViewActive()) {
        if (agendaTeamDayLastResponse) {
            renderAgendaTeamDay(agendaTeamDayLastResponse);
        }
        return;
    }

    if (!$('#calendar').data('fullCalendar')) {
        clearAgendaSlotLayer();
        return;
    }

    setTimeout(function() {
        if (!$('#calendar').data('fullCalendar')) {
            clearAgendaSlotLayer();
            return;
        }

        $('#calendar').fullCalendar('render');
        $('#calendar').fullCalendar('updateSize');
        $('#calendar').fullCalendar('rerenderEvents');
        renderAgendaSlotLayer();
    }, 0);
}

function setAppointmentCustomTimeState(enabled, startTime, endTime) {
    if (!supportsAgendaCustomAppointmentTime()) {
        return;
    }

    var active = !!enabled;
    $('#app_custom_time_enabled').prop('checked', active);
    $('#app_custom_start_time').val($.trim(String(startTime || '')));
    $('#app_custom_end_time').val($.trim(String(endTime || '')));
    $('#appointmentCustomTimeFields').toggle(active && !isAppointmentPersonalCommitmentMode());
    $('#app_custom_time_coverage').removeClass('is-error is-ok').text('');
}

function resetAppointmentModal() {
    appointmentStandardDraftCache = null;
    appointmentModalSlot = null;
    $('#app_id_slot').val('');
    $('#app_id_dot').val('');
    $('#app_id_paziente').val('');
    $('#app_token_lock').val('');
    $('#app_id_appuntamento').val('');
    $('#app_origine_slot').val('');
    $('#app_paz_spec').val('');
    $('#app_special_mode').val('standard');
    $('#app_ora_inizio').val('');
    $('#app_ora_fine').val('');
    setAppointmentCustomTimeState(false, '', '');
    $('#app_personal_commitment_end').html('').hide();
    $('#app_personal_commitment_time_help').hide();
    $('#app_personal_commitment_duration_info').hide().removeClass('is-error is-ok').text('');

    $('#searchPatient').val('');
    $('#app_cognome').val('');
    $('#app_nome').val('');
    $('#app_telefono').val('');
    $('#app_cellulare').val('');
    $('#app_email').val('');
    $('#app_note').val('');
    setAppointmentReminderSmsPreference(false);
    $('#app_id_tipo_visita').val('');
    $('#app_durata_visita').val('');
    $('#app_slot_copertura_info').removeClass('is-error is-ok').text('');

    $('#patientAutocomplete').addClass('d-none').html('');
    appointmentModalDate = '';

    setAppointmentModalEditingState(false);
    setAppointmentExtraSlotState(null);
    setAppointmentLinkedPatient('', '');
    setAppointmentMode('standard');
    setAppointmentSavingState(false);
    refreshAppointmentDocumentWorkflowState();
}

function setAppointmentModalActionsDisabled(isDisabled) {
    $('#btnDeleteAppointment, #btnDeleteExtraSlot, #btnAppointmentBillingWorkflow, #btnAppointmentTsWorkflow, #btnCancelAppointmentModal, .btn-close-appointment-modal, #btnSaveAppointment')
        .prop('disabled', !!isDisabled);
}

function supportsAppointmentBillingWorkflow() {
    return !!window.AGENDA_CONFIG.appointmentBillingWorkflowEnabled;
}

function supportsAppointmentTsWorkflow() {
    return !!window.AGENDA_CONFIG.appointmentTsWorkflowEnabled;
}

function supportsPatientSmsReminderPreference() {
    return !!window.AGENDA_CONFIG.patientSmsReminderPreferenceAvailable;
}

function setAppointmentReminderSmsPreference(enabled) {
    if (!supportsPatientSmsReminderPreference()) {
        return;
    }

    $('#app_appointment_reminder_sms_enabled').prop('checked', !!enabled);
}

function setAppointmentSpecialPatientCode(value) {
    $('#app_paz_spec').val($.trim(String(value || '')));
}

function getAppointmentSpecialPatientCode() {
    return normalizeAgendaSpecialCode($('#app_paz_spec').val() || '');
}

function captureAppointmentStandardPatientDraft() {
    return {
        idPaziente: $.trim($('#app_id_paziente').val() || ''),
        patientLabel: $.trim($('#appointmentLinkedPatientInfo').data('patient-label') || ''),
        searchValue: $.trim($('#searchPatient').val() || ''),
        cognome: $('#app_cognome').val() || '',
        nome: $('#app_nome').val() || '',
        telefono: $('#app_telefono').val() || '',
        cellulare: $('#app_cellulare').val() || '',
        email: $('#app_email').val() || '',
        reminderSmsEnabled: supportsPatientSmsReminderPreference() && $('#app_appointment_reminder_sms_enabled').is(':checked') ? 1 : 0,
        idTipoVisita: $('#app_id_tipo_visita').val() || '',
        pazSpec: $.trim($('#app_paz_spec').val() || '')
    };
}

function applyAppointmentStandardPatientDraft(draft) {
    var normalizedDraft = draft || {};
    setAppointmentLinkedPatient(normalizedDraft.idPaziente || '', normalizedDraft.patientLabel || '');
    $('#searchPatient').val(normalizedDraft.searchValue || '');
    $('#app_cognome').val(normalizedDraft.cognome || '');
    $('#app_nome').val(normalizedDraft.nome || '');
    $('#app_telefono').val(normalizedDraft.telefono || '');
    $('#app_cellulare').val(normalizedDraft.cellulare || '');
    $('#app_email').val(normalizedDraft.email || '');
    setAppointmentReminderSmsPreference(parseInt(normalizedDraft.reminderSmsEnabled || 0, 10) === 1);
    setAppointmentSpecialPatientCode(normalizedDraft.pazSpec || '');
    if ($('#app_id_tipo_visita').length) {
        $('#app_id_tipo_visita').val(normalizedDraft.idTipoVisita || '');
    }
}

function clearAppointmentStandardPatientDraftFields() {
    setAppointmentLinkedPatient('', '');
    $('#searchPatient').val('');
    $('#app_cognome').val('');
    $('#app_nome').val('');
    $('#app_telefono').val('');
    $('#app_cellulare').val('');
    $('#app_email').val('');
    setAppointmentReminderSmsPreference(false);
    setAppointmentSpecialPatientCode('');
}

function buildPersonalCommitmentCoverageOptions(slot, currentAppointmentId) {
    var baseSlot = slot || appointmentModalSlot;
    var appointmentId = parseInt(currentAppointmentId || 0, 10) || 0;
    if (!baseSlot) {
        return [];
    }

    var startMoment = getAgendaSlotVisualStartMoment(baseSlot);
    if (!startMoment || !startMoment.isValid()) {
        return [];
    }

    var contextSlots = $.grep(getAgendaSlotsForAppointmentModal(), function(row) {
        var rowStart = parseAgendaMoment(row && row.ora_inizio ? row.ora_inizio : '');
        return rowStart
            && rowStart.isValid()
            && String((row && row.id_dot) || '') === String((baseSlot && baseSlot.id_dot) || '')
            && rowStart.format('YYYY-MM-DD') === startMoment.format('YYYY-MM-DD');
    });

    contextSlots.sort(function(leftRow, rightRow) {
        var leftMoment = parseAgendaMoment(leftRow && leftRow.ora_inizio ? leftRow.ora_inizio : '');
        var rightMoment = parseAgendaMoment(rightRow && rightRow.ora_inizio ? rightRow.ora_inizio : '');
        if (!leftMoment || !rightMoment || !leftMoment.isValid() || !rightMoment.isValid()) {
            return 0;
        }
        return leftMoment.valueOf() - rightMoment.valueOf();
    });

    var baseSlotId = parseInt((baseSlot && baseSlot.id_slot) || 0, 10) || 0;
    var startIndex = -1;
    $.each(contextSlots, function(index, row) {
        if ((parseInt((row && row.id_slot) || 0, 10) || 0) === baseSlotId) {
            startIndex = index;
            return false;
        }
        return true;
    });

    if (startIndex < 0) {
        return [];
    }

    var options = [];
    var totalDuration = 0;
    var coveredCount = 0;
    var expectedStart = startMoment.clone();

    for (var index = startIndex; index < contextSlots.length; index++) {
        var row = contextSlots[index];
        var rowStart = parseAgendaMoment(row && row.ora_inizio ? row.ora_inizio : '');
        var rowEnd = parseAgendaMoment(row && row.ora_fine ? row.ora_fine : '');

        if (!rowStart || !rowEnd || !rowStart.isValid() || !rowEnd.isValid()) {
            break;
        }

        if (index > startIndex && !rowStart.isSame(expectedStart)) {
            break;
        }

        var rowState = $.trim(String((row && row.stato) || '')).toUpperCase();
        var rowAppointmentId = parseInt((row && row.id_appuntamento) || 0, 10) || 0;
        var occupiedByOtherAppointment = rowAppointmentId > 0 && rowAppointmentId !== appointmentId;

        if (rowState === 'CHIUSO' || occupiedByOtherAppointment) {
            break;
        }

        totalDuration += getAgendaSlotActualDurationMinutes(row);
        coveredCount += 1;
        expectedStart = rowEnd.clone();

        if (totalDuration <= 0) {
            continue;
        }

        options.push({
            durationMinutes: totalDuration,
            count: coveredCount,
            endMoment: rowEnd.clone()
        });
    }

    return options;
}

function refreshAppointmentPersonalCommitmentCoverage() {
    var $endSelect = $('#app_personal_commitment_end');
    var $info = $('#app_personal_commitment_duration_info');
    var $help = $('#app_personal_commitment_time_help');
    var currentAppointmentId = parseInt($('#app_id_appuntamento').val() || 0, 10) || 0;

    if (!$endSelect.length) {
        return {
            ok: false,
            message: 'Controllo ora fine non disponibile.'
        };
    }

    var currentValue = parseInt($endSelect.val() || 0, 10) || 0;
    var options = buildPersonalCommitmentCoverageOptions(appointmentModalSlot, currentAppointmentId);

    if (!options.length) {
        $endSelect.html('').hide();
        $help.show();
        $info
            .show()
            .removeClass('is-ok')
            .addClass('is-error')
            .text('Non ci sono slot consecutivi disponibili per questo impegno personale.');
        setAppointmentSlotTimeSummary(appointmentModalSlot || null, null);
        return {
            ok: false,
            message: 'Non ci sono slot consecutivi disponibili per questo impegno personale.'
        };
    }

    var html = '';
    var selectedDuration = currentValue;
    var selectedFound = false;

    $.each(options, function(_, option) {
        var durationMinutes = parseInt(option.durationMinutes || 0, 10) || 0;
        if (durationMinutes <= 0 || !option.endMoment || !option.endMoment.isValid()) {
            return true;
        }

        if (durationMinutes === selectedDuration) {
            selectedFound = true;
        }

        html += '<option value="' + escapeHtml(durationMinutes) + '">';
        html += escapeHtml(option.endMoment.format('HH:mm') + ' - ' + formatAgendaDurationMinutes(durationMinutes) + ' - ' + option.count + ' slot');
        html += '</option>';
        return true;
    });

    if (!selectedFound) {
        selectedDuration = parseInt((options[0] && options[0].durationMinutes) || 0, 10) || 0;
    }

    $endSelect.html(html).val(selectedDuration > 0 ? String(selectedDuration) : '').show();
    $help.show();

    var coverage = computeAppointmentCoverageForSlot(appointmentModalSlot, selectedDuration, currentAppointmentId);
    setAppointmentSlotTimeSummary(appointmentModalSlot || null, coverage);

    if ($('#app_durata_visita').length) {
        $('#app_durata_visita').val(selectedDuration > 0 ? formatAgendaDurationMinutes(selectedDuration) : '');
    }

    if (!coverage || !coverage.ok) {
        $info
            .show()
            .removeClass('is-ok')
            .addClass('is-error')
            .text((coverage && coverage.message) ? coverage.message : 'Durata non compatibile con la griglia agenda.');
        return coverage;
    }

    $info
        .show()
        .removeClass('is-error')
        .addClass('is-ok')
        .text('Blocca ' + coverage.count + ' slot consecutivi fino alle ' + coverage.endMoment.format('HH:mm') + ' (' + formatAgendaDurationMinutes(selectedDuration) + ').');

    return coverage;
}

function setAppointmentMode(mode, options) {
    options = options || {};

    if (!supportsAgendaPersonalCommitments()) {
        mode = 'standard';
    }

    mode = mode === 'personal_commitment' ? 'personal_commitment' : 'standard';

    var previousMode = $.trim(String($('#app_special_mode').val() || 'standard'));
    var isPersonal = mode === 'personal_commitment';
    var $visitTypeBlock = $('[data-appointment-block-key="visit_type"]');

    if (!isPersonal && previousMode === 'personal_commitment') {
        if (appointmentStandardDraftCache) {
            applyAppointmentStandardPatientDraft(appointmentStandardDraftCache);
        } else {
            clearAppointmentStandardPatientDraftFields();
        }
    }

    if (isPersonal && previousMode !== 'personal_commitment') {
        appointmentStandardDraftCache = captureAppointmentStandardPatientDraft();
    }

    $('#app_special_mode').val(mode);

    $('#appointmentModeSwitch [data-appointment-mode]')
        .removeClass('is-active active')
        .addClass('btn-default');
    $('#appointmentModeSwitch [data-appointment-mode="' + mode + '"]')
        .addClass('is-active active')
        .removeClass('btn-default');

    $('#appointmentPatientFields').toggle(!isPersonal);
    $('#appointmentPersonalCommitmentNotice').toggle(isPersonal);
    $('#app_personal_commitment_end').toggle(isPersonal);
    $('#app_personal_commitment_time_help').toggle(isPersonal);
    $('#app_personal_commitment_duration_info').toggle(isPersonal);
    $('#app_ora_fine').toggle(!isPersonal);
    $('#appointmentCustomTimePanel').toggle(!isPersonal);
    $('#app_custom_time_enabled, #app_custom_start_time').prop('disabled', isPersonal);

    if (isPersonal) {
        $('#appointmentCustomTimeFields').hide();
        $('#app_custom_time_coverage').removeClass('is-error is-ok').text('');
    }

    if ($visitTypeBlock.length) {
        $visitTypeBlock.toggle(!isPersonal);
    }

    $('#app_id_tipo_visita').prop('disabled', isPersonal);

    if (isPersonal) {
        setAppointmentSpecialPatientCode(getAgendaPersonalCommitmentSpecialCode());
        setAppointmentLinkedPatient($('#app_id_paziente').val() || '', getAgendaPersonalCommitmentLabel());
        $('#searchPatient').val(getAgendaPersonalCommitmentLabel());
        $('#app_cognome').val('Impegno');
        $('#app_nome').val('personale');
        $('#app_telefono').val('');
        $('#app_cellulare').val('');
        $('#app_email').val('');
        setAppointmentReminderSmsPreference(false);
        $('#patientAutocomplete').addClass('d-none').html('');
        if (appointmentModalSlot) {
            refreshAppointmentPersonalCommitmentCoverage();
        }
    } else {
        if ($visitTypeBlock.length) {
            fillAppointmentVisitTypeSelect($('#app_id_tipo_visita').val() || '');
        }
        if (appointmentModalSlot || $.trim($('#app_id_appuntamento').val() || '') !== '') {
            refreshAppointmentVisitTypePreview();
        }
    }

    renderAppointmentLinkedPatientInfo();
    refreshAppointmentDocumentWorkflowState();
}

function buildAppointmentDocumentWorkflowUrl(mode, appointmentId) {
    appointmentId = parseInt(appointmentId || 0, 10) || 0;
    if (appointmentId <= 0) {
        return '';
    }

    var base = mode === 'billing'
        ? $.trim(window.AGENDA_CONFIG.appointmentBillingWorkflowUrlBase || '')
        : $.trim(window.AGENDA_CONFIG.appointmentTsWorkflowUrlBase || '');

    if (base === '') {
        return '';
    }

    var query = $.param({
        id_dot: $('#id_dot').val() || window.AGENDA_CONFIG.selectedDot || 0,
        data: $('#agenda_date').val() || window.AGENDA_CONFIG.selectedDate || '',
        view: normalizeAgendaViewModeValue($('#view_mode').val()),
        focus_appointment: appointmentId
    });

    return base.replace(/\/$/, '') + '/' + encodeURIComponent(appointmentId) + (query ? ('?' + query) : '');
}

function buildAppointmentDocumentWorkflowPayload() {
    var draftLabel = isAppointmentPersonalCommitmentMode()
        ? getAgendaPersonalCommitmentLabel()
        : getAppointmentPatientLabel($('#app_cognome').val(), $('#app_nome').val());

    var payload = {
        draft_id_client: $('#app_id_paziente').val() || '',
        draft_cognome: $('#app_cognome').val() || '',
        draft_nome: $('#app_nome').val() || '',
        draft_patient_label: draftLabel,
        draft_telefono: $('#app_telefono').val() || '',
        draft_cellulare: $('#app_cellulare').val() || '',
        draft_email: $('#app_email').val() || '',
        draft_note: $('#app_note').val() || '',
        id_dot: $('#id_dot').val() || window.AGENDA_CONFIG.selectedDot || 0,
        data: $('#agenda_date').val() || window.AGENDA_CONFIG.selectedDate || '',
        view: normalizeAgendaViewModeValue($('#view_mode').val())
    };

    var csrfTokenName = $.trim(window.AGENDA_CONFIG.csrfTokenName || '');
    var csrfTokenValue = $.trim(window.AGENDA_CONFIG.csrfTokenValue || '');
    if (csrfTokenName !== '' && csrfTokenValue !== '') {
        payload[csrfTokenName] = csrfTokenValue;
    }

    return payload;
}

function submitAppointmentDocumentWorkflow(mode) {
    var appointmentId = parseInt($('#app_id_appuntamento').val() || 0, 10) || 0;
    var targetUrl = buildAppointmentDocumentWorkflowUrl(mode, appointmentId);

    if (targetUrl === '') {
        alert(mode === 'billing'
            ? 'Salva prima l appuntamento per aprire la fatturazione da questo slot.'
            : 'Salva prima l appuntamento per aprire il documento TS da questo slot.');
        return;
    }

    var payload = buildAppointmentDocumentWorkflowPayload();
    payload.focus_appointment = appointmentId;

    var $form = $('<form method="post" style="display:none;"></form>').attr('action', targetUrl);
    $.each(payload, function(key, value) {
        $('<input type="hidden">')
            .attr('name', key)
            .val(value == null ? '' : String(value))
            .appendTo($form);
    });

    $('body').append($form);
    $form.trigger('submit');
}

function refreshAppointmentDocumentWorkflowState() {
    var billingEnabled = supportsAppointmentBillingWorkflow();
    var tsEnabled = supportsAppointmentTsWorkflow();
    var appointmentId = parseInt($('#app_id_appuntamento').val() || 0, 10) || 0;
    var hasSpecialPatient = getAppointmentSpecialPatientCode() !== '' || isAppointmentPersonalCommitmentMode();
    var $wrapper = $('#appointmentDocumentWorkflow');
    var $billingButton = $('#btnAppointmentBillingWorkflow');
    var $tsButton = $('#btnAppointmentTsWorkflow');
    var $hint = $('#appointmentDocumentWorkflowHint');

    if (!$wrapper.length) {
        return;
    }

    if (!billingEnabled && !tsEnabled) {
        $wrapper.hide();
        return;
    }

    $wrapper.css('display', 'block');
    $billingButton.toggle(billingEnabled);
    $tsButton.toggle(tsEnabled);

    if (appointmentId <= 0) {
        $billingButton.prop('disabled', true);
        $tsButton.prop('disabled', true);
        $hint.text('Le azioni documento si attivano dopo il primo salvataggio dell appuntamento.');
        return;
    }

    if (hasSpecialPatient) {
        $billingButton.prop('disabled', true);
        $tsButton.prop('disabled', true);
        $hint.text('Il paziente speciale non puo generare documenti fiscali o TS. Collega prima un paziente reale.');
        return;
    }

    $billingButton.prop('disabled', false);
    $tsButton.prop('disabled', false);

    if (billingEnabled && tsEnabled) {
        $hint.text('I pulsanti aprono i moduli portando dietro i dati attuali del paziente da questo modal. Se qualche dato fiscale manca, lo completi nel passaggio successivo.');
        return;
    }

    if (billingEnabled) {
        $hint.text('Apri una nuova fattura gia precompilata con i dati attuali del paziente e dell appuntamento.');
        return;
    }

    $hint.text('Apri un nuovo documento TS gia precompilato con i dati attuali del paziente e dell appuntamento.');
}

function setAppointmentSavingState(isSaving) {
    var $saveButton = $('#btnSaveAppointment');
    if (!$saveButton.length) {
        return;
    }

    var defaultHtml = $saveButton.data('default-html');
    if (!defaultHtml) {
        defaultHtml = $saveButton.html();
        $saveButton.data('default-html', defaultHtml);
    }

    $saveButton
        .prop('disabled', !!isSaving)
        .html(isSaving
            ? '<i class="fa fa-spinner fa-spin"></i> Salvataggio...'
            : defaultHtml);

    setAppointmentModalActionsDisabled(!!isSaving);
}

function isAppointmentExtraSlot() {
    return $.trim(String($('#app_origine_slot').val() || '')).toUpperCase() === 'EXTRA';
}

function setAppointmentExtraSlotState(slot) {
    var origine = ((slot && slot.origine_slot) ? String(slot.origine_slot) : '');
    var isExtra = $.trim(origine).toUpperCase() === 'EXTRA';

    $('#app_origine_slot').val(origine);
    $('#btnDeleteExtraSlot').toggle(isExtra);
}

function setAppointmentSlotTimeSummary(slot) {
    var coverage = arguments.length > 1 ? arguments[1] : null;
    var startMoment = coverage && coverage.ok && coverage.startMoment
        ? coverage.startMoment
        : getAgendaSlotVisualStartMoment(slot);
    var endMoment = coverage && coverage.ok && coverage.endMoment
        ? coverage.endMoment
        : getAgendaSlotVisualEndMoment(slot);

    $('#app_ora_inizio').val(startMoment && startMoment.isValid() ? startMoment.format('HH:mm') : '');
    $('#app_ora_fine').val(endMoment && endMoment.isValid() ? endMoment.format('HH:mm') : '');
}

function normalizeAppointmentPatientName(value) {
    var normalized = $.trim(String(value || '').replace(/\s+/g, ' '));

    if (normalized === '') {
        return '';
    }

    if (typeof normalized.toLocaleUpperCase === 'function') {
        return normalized.toLocaleUpperCase('it-IT');
    }

    return normalized.toUpperCase();
}

function getAppointmentPatientLabel(cognome, nome) {
    return $.trim((
        normalizeAppointmentPatientName(cognome) + ' ' + normalizeAppointmentPatientName(nome)
    ).replace(/\s+/g, ' '));
}

function setAppointmentLinkedPatient(idPaziente, label) {
    $('#app_id_paziente').val(idPaziente || '');
    $('#appointmentLinkedPatientInfo').data('patient-label', $.trim(label || ''));
    renderAppointmentLinkedPatientInfo();
}

function renderAppointmentLinkedPatientInfo() {
    var $info = $('#appointmentLinkedPatientInfo');
    if (!$info.length) {
        return;
    }

    var idPaziente = $.trim($('#app_id_paziente').val() || '');
    var label = $.trim($info.data('patient-label') || '');
    var liveLabel = getAppointmentPatientLabel($('#app_cognome').val(), $('#app_nome').val());

    if (liveLabel !== '') {
        label = liveLabel;
    }

    if (isAppointmentPersonalCommitmentMode()) {
        $info
            .removeClass('text-muted text-primary')
            .addClass('text-danger')
            .html(
                '<i class="fa fa-calendar-times-o"></i> Voce speciale: <strong>' + escapeHtml(getAgendaPersonalCommitmentLabel()) + '</strong>. ' +
                'Non crea un paziente reale e puo bloccare piu slot consecutivi.'
            );
        return;
    }

    if (idPaziente !== '') {
        $info
            .removeClass('text-muted text-danger')
            .addClass('text-primary')
            .html(
                '<i class="fa fa-link"></i> Paziente collegato: <strong>' + escapeHtml(label || ('ID ' + idPaziente)) + '</strong>. ' +
                'Se modifichi i dati qui sotto aggiorni anche l\\\'anagrafica del paziente.'
            );
        return;
    }

    $info
        .removeClass('text-primary text-danger')
        .addClass('text-muted')
        .html(
            '<i class="fa fa-user-plus"></i> Nessun paziente collegato. Salvando verra creato un nuovo paziente e collegato all\\\'appuntamento.'
        );
}

function setAppointmentModalEditingState(isEditing) {
    var $saveButton = $('#btnSaveAppointment');
    var buttonHtml = isEditing
        ? '<i class="fa fa-save"></i> Salva modifiche'
        : '<i class="fa fa-check"></i> Conferma prenotazione';

    $('#appointmentModal .modal-title').text(isEditing ? 'Modifica appuntamento' : 'Gestione appuntamento');
    $('#btnDeleteAppointment').toggle(!!isEditing);
    $saveButton
        .data('default-html', buttonHtml)
        .html(buttonHtml)
        .show();

    refreshAppointmentDocumentWorkflowState();
}

function refreshAgendaAfterAppointmentChange() {
    caricaTutto({
        showCalendarLoader: false
    });
}

function focusAppointmentPatientSearch() {
    var $searchPatient = $('#searchPatient');
    if (!$searchPatient.length) {
        return;
    }

    setTimeout(function() {
        $searchPatient.trigger('focus');
        $searchPatient.trigger('select');
    }, 80);
}

function requestAppointmentPatientSearchFocus() {
    appointmentSearchFocusRequested = true;
}

function chiudiModalAppuntamentoELiberaSlot() {
    var token = $('#app_token_lock').val();

    if (appointmentModalDate) {
        $('#agenda_date').val(appointmentModalDate);
    }

    if (!token) {
        $('#appointmentModal').modal('hide');
        resetAppointmentModal();
        return;
    }

    $.post("<?= base_url('agenda/unlock-slot') ?>", {
        token_lock: token
    }, function() {
        $('#appointmentModal').modal('hide');
        resetAppointmentModal();
        caricaTutto();
    }, 'json');
}

function inviaUnlockBeaconSePresente() {
    var token = $('#app_token_lock').val();

    if (!token || !window.navigator || typeof window.navigator.sendBeacon !== 'function') {
        return false;
    }

    var payload = new FormData();
    payload.append('token_lock', token);

    return window.navigator.sendBeacon("<?= base_url('agenda/unlock-slot') ?>", payload);
}

function completeExtraSlotDeletion(message) {
    var finalize = function() {
        $('#appointmentModal').modal('hide');
        resetAppointmentModal();
        caricaTutto();
        showAgendaToast(message || 'Slot extra eliminato correttamente.', 'success');
    };

    var token = $('#app_token_lock').val();
    if (!token) {
        finalize();
        return;
    }

    $.post("<?= base_url('agenda/unlock-slot') ?>", {
        token_lock: token
    }, function() {}, 'json').always(finalize);
}

function deleteCurrentExtraSlot(forceDelete, slotIds) {
    var idSlot = parseInt($('#app_id_slot').val(), 10) || 0;
    var idDot = parseInt($('#app_id_dot').val(), 10) || 0;
    var ids = $.isArray(slotIds) ? slotIds : (idSlot ? [idSlot] : []);

    if (!idDot || !ids.length) {
        showAgendaToast('ID slot non trovato.', 'error');
        return;
    }

    setAppointmentModalActionsDisabled(true);

    $.post("<?= base_url('agenda/elimina-slot-extra-selezionati') ?>", {
        id_dot: idDot,
        slot_ids: ids,
        force_delete: forceDelete ? 1 : 0
    }, function(res) {
        if (res && res.requires_confirmation) {
            setAppointmentModalActionsDisabled(false);

            if (!confirm((res.message || 'Sono presenti appuntamenti attivi su questo slot extra.') + ' Confermi di voler eliminare anche gli appuntamenti collegati?')) {
                return;
            }

            deleteCurrentExtraSlot(true, res.deletable_slot_ids || ids);
            return;
        }

        if (!res || !res.status) {
            showAgendaToast((res && res.message) ? res.message : 'Errore durante l\'eliminazione dello slot extra.', 'error');
            return;
        }

        completeExtraSlotDeletion(res.message || 'Slot extra eliminato correttamente.');
    }, 'json').fail(function(xhr) {
        var message = 'Errore durante l\'eliminazione dello slot extra.';

        if (xhr && xhr.responseJSON && xhr.responseJSON.message) {
            message = xhr.responseJSON.message;
        }

        showAgendaToast(message, 'error');
    }).always(function() {
        setAppointmentModalActionsDisabled(false);
    });
}

function trovaSlotLiberoPerData(startMoment) {
    for (var i = 0; i < agendaCurrentSlots.length; i++) {
        var slot = agendaCurrentSlots[i];

        if (slot.stato !== 'LIBERO' && slot.stato !== 'BLOCCATO') {
            continue;
        }

        var slotStart = moment((slot.ora_inizio || '').replace(' ', 'T'));
        var slotEnd = moment((slot.ora_fine || '').replace(' ', 'T'));

        if (startMoment.isSame(slotStart)) {
            return slot;
        }

        if (startMoment.isAfter(slotStart) && startMoment.isBefore(slotEnd)) {
            return slot;
        }
    }

    return null;
}

function apriSlotLiberoDaSelezione(startMoment) {
    if (startMoment && startMoment.isValid() && isAgendaDayBlockedByDate(startMoment.format('YYYY-MM-DD'))) {
        return;
    }

    var slot = trovaSlotLiberoPerData(startMoment);

    if (!slot) {
        return;
    }

    apriSlotLiberoDaSlot(slot);
}

function apriSlotLiberoDaSlot(slot) {
    if (!slot || isAgendaSlotDayBlocked(slot)) {
        return;
    }

    resetAppointmentModal();
    appointmentModalSlot = slot;

    var startMoment = parseAgendaMoment(slot.ora_inizio);
    if (startMoment && startMoment.isValid()) {
        appointmentModalDate = startMoment.format('YYYY-MM-DD');
        $('#agenda_date').val(appointmentModalDate);
    }
    setAppointmentModalEditingState(false);

    $('#app_id_slot').val(slot.id_slot || '');
    $('#app_id_dot').val(slot.id_dot || '');
    setAppointmentSlotTimeSummary(slot);
    var newSlotStart = parseAgendaMoment(slot.ora_inizio || '');
    setAppointmentCustomTimeState(false, newSlotStart && newSlotStart.isValid() ? newSlotStart.format('HH:mm') : '', '');
    setAppointmentExtraSlotState(slot);
    fillAppointmentVisitTypeSelect(0);
    setAppointmentMode('standard');
    refreshAppointmentVisitTypePreview();

    $.post("<?= base_url('agenda/lock-slot') ?>", {
        id_slot: slot.id_slot
    }, function(res) {
        if (!res.status) {
            alert(res.message || 'Slot non disponibile');
            return;
        }

        $('#app_token_lock').val(res.token_lock || '');
        requestAppointmentPatientSearchFocus();
        $('#appointmentModal').modal('show');
    }, 'json');
}

function riempiModaleDaEvento(slot) {
    resetAppointmentModal();
    appointmentModalSlot = slot;

    var dataSlot = estraiDataSlot(slot, null);
    var linkedPatientId = slot.id_cliente_collegato || slot.id_client || slot.id_paziente || '';
    var pazSpec = $.trim(slot.paz_spec || '');
    var cognome = normalizeAppointmentPatientName(slot.cognome || '');
    var nome = normalizeAppointmentPatientName(slot.nome || '');
    var isPersonalCommitment = isAgendaPersonalCommitment(slot, pazSpec);
    var patientLabel = isPersonalCommitment
        ? getAgendaPersonalCommitmentLabel()
        : getAppointmentPatientLabel(cognome, nome);
    if (dataSlot !== '') {
        appointmentModalDate = dataSlot;
    }

    $('#app_id_slot').val(slot.id_slot || '');
    $('#app_id_dot').val(slot.id_dot || '');
    $('#app_id_appuntamento').val(slot.id_appuntamento || '');
    $('#app_token_lock').val('');
    setAppointmentSpecialPatientCode(pazSpec);
    setAppointmentSlotTimeSummary(slot);

    $('#app_cognome').val(cognome);
    $('#app_nome').val(nome);
    $('#app_telefono').val(slot.telefono || '');
    $('#app_cellulare').val(slot.cellulare || '');
    $('#app_email').val(slot.email || '');
    $('#app_note').val(slot.note || '');
    setAppointmentReminderSmsPreference(parseInt(slot.appointment_reminder_sms_enabled || 0, 10) === 1);
    $('#searchPatient').val('');
    setAppointmentExtraSlotState(slot);
    setAppointmentLinkedPatient(linkedPatientId, patientLabel);
    fillAppointmentVisitTypeSelect(slot.id_tipo_visita || '');
    var customStartMoment = parseAgendaMoment(slot.appointment_custom_ora_inizio || '');
    var customEndMoment = parseAgendaMoment(slot.appointment_ora_fine || '');
    setAppointmentCustomTimeState(
        !!(customStartMoment && customStartMoment.isValid()),
        customStartMoment && customStartMoment.isValid() ? customStartMoment.format('HH:mm') : '',
        customStartMoment && customStartMoment.isValid() && customEndMoment && customEndMoment.isValid()
            ? customEndMoment.format('HH:mm')
            : ''
    );
    if (isPersonalCommitment) {
        appointmentStandardDraftCache = null;
    }
    setAppointmentMode(isPersonalCommitment ? 'personal_commitment' : 'standard');
    refreshAppointmentVisitTypePreview();

    if (slot.id_appuntamento) {
        setAppointmentModalEditingState(true);
    }

    refreshAppointmentDocumentWorkflowState();
}

function caricaSlotCalendario(options) {
    options = options || {};

    var idDot = $('#id_dot').val();
    var data = $('#agenda_date').val();
    var view = normalizeAgendaViewModeValue($('#view_mode').val());
    var showLoader = options.showLoader !== false;

    if (view === 'team_day') {
        caricaSlotCalendarioTeamDay({
            showLoader: showLoader
        });
        return;
    }

    if (agendaTeamDayXhr && agendaTeamDayXhr.readyState !== 4) {
        agendaTeamDayXhr.abort();
    }

    if (agendaCalendarXhr && agendaCalendarXhr.readyState !== 4) {
        agendaCalendarXhr.abort();
    }

    var requestSeq = ++agendaCalendarRequestSeq;
    setCalendarNoSlotsMode(false);

    if (showLoader) {
        setAgendaCalendarLoading(true, 'Sto aggiornando il calendario del professionista selezionato.');
    }

    agendaCalendarXhr = $.get("<?= base_url('agenda/calendario') ?>", {
        id_dot: idDot,
        data: data,
        view: view,
        _ts: Date.now()
    }, function(res) {
        if (requestSeq !== agendaCalendarRequestSeq) {
            return;
        }

        if (!res.status) {
            clearAgendaSlotLayer();
            setCalendarNoSlotsMode(true, res.message || 'Errore durante il caricamento dell\'agenda.');
            return;
        }

        applyAgendaStateResponse(res);
        agendaCurrentSlots = res.slots || [];
        var hasSlots = !!res.has_slots;

        var nuovoStep = parseInt(res.grid_duration, 10) || agendaCalendarBaseStep;
        var nuovoMin = res.min_time || '08:00:00';
        var nuovoMax = res.max_time || '18:00:00';

        if (
            !$('#calendar').data('fullCalendar') ||
            agendaCalendarStep !== nuovoStep ||
            agendaMinTime !== nuovoMin ||
            agendaMaxTime !== nuovoMax
        ) {
            inizializzaCalendario(nuovoStep, nuovoMin, nuovoMax);
        }

        sincronizzaVistaCalendario(view, data);

        if (!hasSlots) {
            $('#calendar').fullCalendar('removeEvents');
            clearAgendaSlotLayer();
            setCalendarNoSlotsMode(true, res.message || '');
            applicaStatoGiornoBloccato();
            return;
        }

        var eventi = [];

        $.each(agendaCurrentSlots, function(i, slot) {
            if (isAgendaCoveredSecondarySlot(slot)) {
                return true;
            }

            var titolo = '';
            var colore = '#3c8dbc';
            var classe = 'evento-prenotato';
            var startMoment = getAgendaSlotVisualStartMoment(slot);
            var endMoment = getAgendaSlotVisualEndMoment(slot);
            var oraEvento = startMoment && startMoment.isValid() ? startMoment.format('HH:mm') : '';
            var stato = ((slot.stato || '') + '').toUpperCase();
            var pazSpec = $.trim(slot.paz_spec || '');
            var isSpecialPatient = isAgendaSpecialPatient(slot, pazSpec);
            var hasAppointment = !!slot.id_appuntamento || (stato !== 'LIBERO' && stato !== 'BLOCCATO' && stato !== 'CHIUSO');
            var nominativo = getAgendaAppointmentDisplayName(slot, pazSpec);
            var noteEvento = buildAppointmentNoteDisplay(slot);
            var slotDayBlocked = isAgendaSlotDayBlocked(slot);
            var visitTypeColor = (!slotDayBlocked && hasAppointment) ? getAgendaVisitTypeColorById(slot.id_tipo_visita) : '';

            if ((stato === 'LIBERO' || stato === 'BLOCCATO') && !hasAppointment && !slotDayBlocked) {
                return true;
            } else if (slotDayBlocked && hasAppointment) {
                titolo = oraEvento;

                if (nominativo !== '') {
                    titolo += '\n' + nominativo;
                }
                if (noteEvento !== '') {
                    titolo += ' - ' + noteEvento;
                }

                colore = '#d9534f';
                classe = 'evento-prenotato evento-bloccato-giorno';
            } else if (slotDayBlocked || stato === 'CHIUSO') {
                titolo = 'Giornata bloccata';
                colore = '#d9534f';
                classe = 'evento-bloccato-giorno';
            } else {
                titolo = oraEvento;

                if (nominativo !== '') {
                    titolo += '\n' + nominativo;
                }
                if (noteEvento !== '') {
                    titolo += ' - ' + noteEvento;
                }

                colore = '#3c8dbc';
                classe = 'evento-prenotato';

                if (isSpecialPatient) {
                    colore = getAgendaSpecialPatientColor(pazSpec, slot) || '#2e8b57';
                    classe = 'evento-prenotato evento-prenotato-spec';
                }

                if (visitTypeColor !== '') {
                    colore = visitTypeColor;
                    classe = 'evento-prenotato';
                }
            }

            if (slotDayBlocked && !hasAppointment) {
                colore = '#d9534f';
                classe = 'evento-bloccato-giorno';
            }

            eventi.push({
                id: slot.id_slot,
                title: titolo,
                start: startMoment && startMoment.isValid() ? startMoment.format() : (slot.ora_inizio || '').replace(' ', 'T'),
                end: endMoment && endMoment.isValid() ? endMoment.format() : (slot.ora_fine || '').replace(' ', 'T'),
                color: colore,
                allDay: false,
                className: classe,
                extendedProps: slot
            });
        });

        $('#calendar').fullCalendar('removeEvents');
        $('#calendar').fullCalendar('addEventSource', eventi);
        riallineaRenderingCalendario();
        applicaStatoGiornoBloccato();
    }, 'json').fail(function(xhr, textStatus) {
        if (textStatus === 'abort' || requestSeq !== agendaCalendarRequestSeq) {
            return;
        }

        clearAgendaSlotLayer();
        setCalendarNoSlotsMode(true, 'Errore nel caricamento dell\'agenda. Riprova tra un attimo.');
    }).always(function() {
        if (requestSeq !== agendaCalendarRequestSeq) {
            return;
        }

        setAgendaCalendarLoading(false);
    });
}

function getAgendaTimeMinutesFromValue(value) {
    var match = $.trim(String(value || '')).match(/(\d{2}):(\d{2})/);

    if (!match) {
        return 0;
    }

    return (parseInt(match[1], 10) * 60) + parseInt(match[2], 10);
}

function formatAgendaTeamMinutesLabel(totalMinutes) {
    var hours = Math.floor(totalMinutes / 60);
    var minutes = totalMinutes % 60;

    return String(hours).padStart(2, '0') + ':' + String(minutes).padStart(2, '0');
}

function getAgendaTeamDayBounds(minTime, maxTime) {
    var startMinutes = getAgendaTimeMinutesFromValue(minTime || '08:00:00');
    var endMinutes = getAgendaTimeMinutesFromValue(maxTime || '18:00:00');

    if (endMinutes <= startMinutes) {
        endMinutes = startMinutes + 60;
    }

    return {
        startMinutes: startMinutes,
        endMinutes: endMinutes,
        totalMinutes: endMinutes - startMinutes
    };
}

function getAgendaTeamDayPixelsPerMinute(totalMinutes, stepMinutes) {
    var normalizedStep = Math.max(5, parseInt(stepMinutes, 10) || agendaCalendarBaseStep);
    var basePixelsPerMinute = 2.35;

    if (totalMinutes <= 360) {
        basePixelsPerMinute = 2.8;
    } else if (totalMinutes <= 540) {
        basePixelsPerMinute = 2.55;
    }

    return Math.max(basePixelsPerMinute, 60 / normalizedStep);
}

function getAgendaTeamDayEntryHeight(durationMinutes, pixelsPerMinute, fallbackHeight) {
    var rawHeight = Math.max(Math.round((parseInt(durationMinutes, 10) || 0) * pixelsPerMinute), 0);
    var safeFallbackHeight = Math.max(parseInt(fallbackHeight, 10) || 0, 44);

    if (rawHeight <= 0) {
        return safeFallbackHeight;
    }

    return Math.max(rawHeight - 6, Math.min(safeFallbackHeight, rawHeight));
}

function renderAgendaTeamDayTimeMarkers(bounds, pixelsPerMinute) {
    var html = '';
    var firstMarker = Math.floor(bounds.startMinutes / 60) * 60;

    for (var minute = firstMarker; minute <= bounds.endMinutes; minute += 60) {
        var top = (minute - bounds.startMinutes) * pixelsPerMinute;
        if (top < 0) {
            continue;
        }

        html += '<div class="agenda-team-time-marker" style="top:' + top + 'px;">'
            + escapeHtml(formatAgendaTeamMinutesLabel(minute))
            + '</div>';
    }

    return html;
}

function buildAgendaTeamColumnInlineStyle(column) {
    var theme = column && column.column_theme ? column.column_theme : null;
    var cssVars = theme && theme.css_vars ? theme.css_vars : null;
    var cssText = '';

    if (!cssVars) {
        return cssText;
    }

    $.each(cssVars, function(propertyName, propertyValue) {
        if (String(propertyValue || '') !== '') {
            cssText += propertyName + ':' + propertyValue + ';';
        }
    });

    return cssText;
}

function renderAgendaTeamDayHeader(column, extraStyle) {
    var chips = '';
    var columnStyle = buildAgendaTeamColumnInlineStyle(column);
    extraStyle = $.trim(String(extraStyle || ''));
    if (extraStyle !== '') {
        columnStyle += extraStyle;
    }
    var styleAttr = columnStyle !== '' ? ' style="' + columnStyle + '"' : '';

    if (column.is_selected) {
        chips += '<span class="agenda-team-chip is-selected">Professionista attivo</span>';
    }

    if (column.giorno_bloccato) {
        chips += '<span class="agenda-team-chip is-locked">Giornata bloccata</span>';
    } else if (!column.has_slots) {
        chips += '<span class="agenda-team-chip">Senza agenda</span>';
    }

    return ''
        + '<div class="agenda-team-header' + (column.is_selected ? ' is-selected' : '') + '"' + styleAttr + '>'
        + '  <div class="agenda-team-header-name">' + escapeHtml(column.label || ('Professionista ' + (column.id_dot || ''))) + '</div>'
        + '  <div class="agenda-team-header-meta">' + chips + '</div>'
        + '</div>';
}

function buildAgendaTeamDayBackgroundRows(bounds, pixelsPerMinute, stepMinutes, entryHeight) {
    if (supportsAgendaTeamDaySingleSlotHeightFeature()) {
        return '';
    }

    var html = '';
    var rowHeight = Math.max(parseInt(entryHeight, 10) || 0, 44);
    var safeStepMinutes = Math.max(5, parseInt(stepMinutes, 10) || agendaCalendarBaseStep);

    for (var minute = bounds.startMinutes; minute < bounds.endMinutes; minute += safeStepMinutes) {
        var top = Math.max(0, (minute - bounds.startMinutes) * pixelsPerMinute);

        html += '<div class="agenda-team-slot-guide"'
            + ' style="top:' + top + 'px;height:' + rowHeight + 'px;"'
            + ' aria-hidden="true">'
            + '  <span class="agenda-team-slot-guide-time">' + escapeHtml(formatAgendaTeamMinutesLabel(minute)) + '</span>'
            + '</div>';
    }

    return html;
}

function buildAgendaTeamEntryPrimaryContactLabel(slot) {
    var telefono = $.trim(((slot && slot.telefono) || '') + '');
    var cellulare = $.trim(((slot && slot.cellulare) || '') + '');

    if (cellulare !== '') {
        return 'Cell: ' + cellulare;
    }

    if (telefono !== '') {
        return 'Tel: ' + telefono;
    }

    return '';
}

function buildAgendaTeamEntryTooltip(orario, primaryLabel, slot, secondaryLabel) {
    var lines = [];
    var safeOrario = $.trim(String(orario || ''));
    var safePrimaryLabel = $.trim(String(primaryLabel || ''));
    var safeSecondaryLabel = $.trim(String(secondaryLabel || ''));
    var telefono = $.trim(((slot && slot.telefono) || '') + '');
    var cellulare = $.trim(((slot && slot.cellulare) || '') + '');

    if (safeOrario !== '') {
        lines.push(safeOrario);
    }

    if (safePrimaryLabel !== '') {
        lines.push('Paziente: ' + safePrimaryLabel);
    }

    if (telefono !== '') {
        lines.push('Telefono: ' + telefono);
    }

    if (cellulare !== '' && cellulare !== telefono) {
        lines.push('Cellulare: ' + cellulare);
    }

    if (safeSecondaryLabel !== '') {
        lines.push('Note: ' + safeSecondaryLabel);
    }

    return lines.join('\n');
}

function buildAgendaTeamEntryTitleAttr(text) {
    var safeText = String(text || '');
    return escapeHtml(safeText).replace(/\r?\n/g, '&#10;');
}

function buildAgendaTeamEntryContent(orario, primaryLabel, secondaryLabel, options) {
    options = options || {};
    var safeTimeLabel = $.trim(String(orario || ''));
    var safePrimaryLabel = $.trim(String(primaryLabel || ''));
    var safeSecondaryLabel = $.trim(String(secondaryLabel || ''));
    var safeContactLabel = $.trim(String(options.contactLabel || ''));

    return ''
        + '<div class="agenda-team-entry-content">'
        + '  <div class="agenda-team-entry-title">'
        + (safeTimeLabel !== ''
            ? '    <span class="agenda-team-entry-time">' + escapeHtml(safeTimeLabel) + '</span>'
            : '')
        + (safePrimaryLabel !== ''
            ? '<span class="agenda-team-entry-patient">' + escapeHtml(safePrimaryLabel) + '</span>'
            : '')
        + '  </div>'
        + (safeContactLabel !== ''
            ? '<div class="agenda-team-entry-contact">' + escapeHtml(safeContactLabel) + '</div>'
            : '')
        + (safeSecondaryLabel !== ''
            ? '<div class="agenda-team-entry-note">' + escapeHtml(safeSecondaryLabel) + '</div>'
            : '')
        + '</div>';
}

function isAgendaTeamDayMobileListMode() {
    if (window.matchMedia) {
        return window.matchMedia('(max-width: 767px)').matches;
    }

    return window.innerWidth <= 767;
}

function buildAgendaTeamSlotIndexKey(slot) {
    slot = slot || {};

    return [
        String(slot.id_dot || ''),
        String(slot.id_slot || ''),
        String(slot.ora_inizio || ''),
        String(slot.id_appuntamento || '')
    ].join('|');
}

function registerAgendaTeamDaySlotPool(columns) {
    agendaTeamSlotIndex = {};
    agendaTeamAllSlots = [];

    $.each($.isArray(columns) ? columns : [], function(_, column) {
        if (!$.isArray(column && column.slots)) {
            return true;
        }

        agendaTeamAllSlots = agendaTeamAllSlots.concat(column.slots);

        $.each(column.slots, function(_, slot) {
            var slotId = parseInt((slot && slot.id_slot) || 0, 10) || 0;
            var slotKey = buildAgendaTeamSlotIndexKey(slot);

            agendaTeamSlotIndex[slotKey] = slot;
            if (slotId > 0) {
                agendaTeamSlotIndex[String(slotId)] = slot;
            }
        });

        return true;
    });
}

function getAgendaTeamMobileProfessionalWords(label) {
    var words = $.trim(String(label || '')).split(/\s+/).filter(function(word) {
        return word !== '' && !/^professionist[ai]$/i.test(word);
    });

    return words.length ? words : ['Prof.'];
}

function getAgendaTeamMobileProfessionalShortLabel(label) {
    return getAgendaTeamMobileProfessionalWords(label)[0];
}

function getAgendaTeamMobileProfessionalInitials(label) {
    return getAgendaTeamMobileProfessionalWords(label)
        .slice(0, 2)
        .map(function(word) { return word.charAt(0).toUpperCase(); })
        .join('');
}

function buildAgendaTeamDayMobileSummary(columns) {
    var html = '<div class="agenda-team-mobile-summary">';

    $.each(columns, function(_, column) {
        var columnStyle = buildAgendaTeamColumnInlineStyle(column);
        var styleAttr = columnStyle !== '' ? ' style="' + columnStyle + '"' : '';
        var fullLabel = String(column.label || ('Professionista ' + (column.id_dot || '')));
        var shortLabel = getAgendaTeamMobileProfessionalShortLabel(fullLabel);
        var initials = getAgendaTeamMobileProfessionalInitials(fullLabel);
        var badges = '';
        var statusText = 'Agenda disponibile';

        if (column.is_selected) {
            badges += '<span class="agenda-team-chip is-selected">Attivo</span>';
        }

        if (column.giorno_bloccato) {
            badges += '<span class="agenda-team-chip is-locked">Bloccata</span>';
            statusText = 'Giornata bloccata';
        } else if (!column.has_slots) {
            badges += '<span class="agenda-team-chip">Senza agenda</span>';
            statusText = 'Nessuna agenda';
        }

        html += ''
            + '<div class="agenda-team-mobile-summary-card' + (column.is_selected ? ' is-selected' : '') + '"'
            + ' title="' + buildAgendaTeamEntryTitleAttr(fullLabel) + '"' + styleAttr + '>'
            + '  <span class="agenda-team-mobile-summary-avatar" aria-hidden="true">' + escapeHtml(initials) + '</span>'
            + '  <div class="agenda-team-mobile-summary-name">' + escapeHtml(shortLabel) + '</div>'
            + (badges !== ''
                ? '  <div class="agenda-team-mobile-summary-meta">' + badges + '</div>'
                : '')
            + '  <div class="agenda-team-mobile-summary-status">' + escapeHtml(statusText) + '</div>'
            + '</div>';
    });

    html += '</div>';

    return html;
}

function appendAgendaTeamDayMobileRecord(records, record) {
    var lastRecord = records.length ? records[records.length - 1] : null;

    if (
        record.mergeable
        && lastRecord
        && lastRecord.mergeable
        && lastRecord.kind === record.kind
        && lastRecord.columnKey === record.columnKey
        && lastRecord.hourStartMinutes === record.hourStartMinutes
        && lastRecord.endMinutes === record.startMinutes
    ) {
        lastRecord.endMinutes = record.endMinutes;
        lastRecord.timeLabel = formatAgendaTeamMinutesLabel(lastRecord.startMinutes) + ' - ' + formatAgendaTeamMinutesLabel(record.endMinutes);
        lastRecord.tooltipText = lastRecord.timeLabel + (record.kind === 'free' ? '\nSlot libero' : '\nFascia non disponibile');
        return;
    }

    records.push(record);
}

function buildAgendaTeamDayMobileRecords(columns) {
    var records = [];

    $.each(columns, function(_, column) {
        var slots = $.isArray(column && column.slots) ? column.slots.slice(0) : [];
        if (!slots.length) {
            return true;
        }

        slots.sort(function(leftSlot, rightSlot) {
            var leftStart = getAgendaSlotVisualStartMoment(leftSlot);
            var rightStart = getAgendaSlotVisualStartMoment(rightSlot);
            var leftStartMinutes = leftStart && leftStart.isValid() ? ((leftStart.hours() * 60) + leftStart.minutes()) : 0;
            var rightStartMinutes = rightStart && rightStart.isValid() ? ((rightStart.hours() * 60) + rightStart.minutes()) : 0;

            if (leftStartMinutes !== rightStartMinutes) {
                return leftStartMinutes - rightStartMinutes;
            }

            return (parseInt((leftSlot && leftSlot.id_slot) || 0, 10) || 0)
                - (parseInt((rightSlot && rightSlot.id_slot) || 0, 10) || 0);
        });

        var nextSlotStartMap = buildAgendaNextSlotStartMap(slots);
        var columnBaseStyle = buildAgendaTeamColumnInlineStyle(column);

        $.each(slots, function(_, slot) {
            if (isAgendaCoveredSecondarySlot(slot)) {
                return true;
            }

            var slotId = parseInt((slot && slot.id_slot) || 0, 10) || 0;
            var startMoment = getAgendaSlotVisualStartMoment(slot);
            var endMoment = getAgendaSlotVisualEndMoment(slot);

            if (!startMoment || !startMoment.isValid() || !endMoment || !endMoment.isValid()) {
                return true;
            }

            var startMinutes = (startMoment.hours() * 60) + startMoment.minutes();
            var endMinutes = (endMoment.hours() * 60) + endMoment.minutes();
            var durationMinutes = endMinutes - startMinutes;
            var dayKey = startMoment.format('YYYY-MM-DD');
            var displayDurationMinutes = getAgendaDisplayDurationMinutes(
                slot,
                durationMinutes,
                startMinutes,
                nextSlotStartMap[dayKey + '|' + startMinutes]
            );

            if (durationMinutes <= 0 || displayDurationMinutes <= 0) {
                return true;
            }

            var orario = startMoment.format('HH:mm');
            var orarioFine = endMoment.format('HH:mm');
            var orarioLabel = orario;
            if (orarioFine !== '' && orarioFine !== orario) {
                orarioLabel += ' - ' + orarioFine;
            }

            var stato = $.trim(String(slot.stato || '')).toUpperCase();
            var pazSpec = $.trim(slot.paz_spec || '');
            var isSpecialPatient = isAgendaSpecialPatient(slot, pazSpec);
            var hasAppointment = !!slot.id_appuntamento || (stato !== 'LIBERO' && stato !== 'BLOCCATO' && stato !== 'CHIUSO');
            var hourStartMinutes = Math.floor(startMinutes / 60) * 60;
            var record = {
                columnKey: String(column.id_dot || column.label || ''),
                columnLabel: String(column.label || ('Professionista ' + (column.id_dot || ''))),
                isSelected: !!column.is_selected,
                slotId: slotId,
                slotKey: buildAgendaTeamSlotIndexKey(slot),
                startMinutes: startMinutes,
                endMinutes: endMinutes,
                hourStartMinutes: hourStartMinutes,
                timeLabel: orarioLabel,
                styleText: columnBaseStyle,
                primaryLabel: '',
                secondaryLabel: '',
                contactLabel: '',
                tooltipText: '',
                extraClass: '',
                kind: '',
                interactiveType: '',
                mergeable: false
            };

            if ((stato === 'LIBERO' || stato === 'BLOCCATO') && !hasAppointment && !column.giorno_bloccato) {
                record.kind = 'free';
                record.interactiveType = 'free';
                record.extraClass = 'is-free';
                record.primaryLabel = 'Libero';
                record.secondaryLabel = 'Slot disponibile';
                record.tooltipText = orarioLabel + '\nSlot libero';
                appendAgendaTeamDayMobileRecord(records, record);
                return true;
            }

            if (stato === 'CHIUSO' || (column.giorno_bloccato && !hasAppointment)) {
                record.kind = 'locked';
                record.mergeable = true;
                record.extraClass = 'is-locked';
                record.primaryLabel = 'Bloccato';
                record.secondaryLabel = column.giorno_bloccato ? 'Giornata bloccata' : 'Fascia non disponibile';
                record.tooltipText = orarioLabel + '\nGiornata bloccata';
                appendAgendaTeamDayMobileRecord(records, record);
                return true;
            }

            var visitTypeVisualStyle = (!column.giorno_bloccato && hasAppointment) ? getAgendaVisitTypeVisualStyleById(slot.id_tipo_visita) : null;
            var nominativo = getAgendaAppointmentDisplayName(slot, pazSpec);
            var noteEvento = buildAppointmentNoteDisplay(slot);
            var compactNote = buildAppointmentNoteDisplay(slot, { includeVisitTypeDuration: false });
            var primaryLabel = nominativo !== '' ? nominativo : 'Appuntamento';
            var contactLabel = buildAgendaTeamEntryPrimaryContactLabel(slot);
            var color = column.giorno_bloccato ? '#d9534f' : (isSpecialPatient ? getAgendaSpecialPatientColor(pazSpec, slot) : '#3c8dbc');
            var bookedStyle = columnBaseStyle;

            record.kind = 'booked';
            record.interactiveType = column.giorno_bloccato ? '' : 'booked';
            record.extraClass = 'is-booked';
            record.primaryLabel = primaryLabel;
            record.secondaryLabel = compactNote !== '' ? compactNote : (noteEvento !== '' ? noteEvento : '');
            record.contactLabel = contactLabel;
            record.tooltipText = buildAgendaTeamEntryTooltip(orarioLabel, primaryLabel, slot, noteEvento);

            if (visitTypeVisualStyle) {
                record.extraClass += ' has-visit-type-color';
                bookedStyle += buildAgendaVisitTypeInlineStyle(visitTypeVisualStyle);
            } else if (column.giorno_bloccato) {
                bookedStyle += 'background:' + color + ';border:1px solid ' + color + ';color:var(--agenda-appointment-text, #fff);';
            } else if (isSpecialPatient) {
                bookedStyle += 'background:' + color + ';border:1px solid ' + color + ';color:var(--agenda-appointment-text, #fff);';
            } else {
                bookedStyle += 'background:var(--agenda-team-column-entry-bg, ' + color + ');'
                    + 'border:1px solid var(--agenda-team-column-entry-border, ' + color + ');'
                    + 'color:var(--agenda-appointment-text, var(--agenda-team-column-entry-text, #fff));';
            }

            record.styleText = bookedStyle;
            appendAgendaTeamDayMobileRecord(records, record);
            return true;
        });

        return true;
    });

    records.sort(function(leftRecord, rightRecord) {
        if (leftRecord.startMinutes !== rightRecord.startMinutes) {
            return leftRecord.startMinutes - rightRecord.startMinutes;
        }

        if (leftRecord.isSelected !== rightRecord.isSelected) {
            return leftRecord.isSelected ? -1 : 1;
        }

        return leftRecord.columnLabel.localeCompare(rightRecord.columnLabel, 'it', { sensitivity: 'base' });
    });

    return records;
}

function renderAgendaTeamDayMobileRecord(record) {
    var styleAttr = record.styleText !== '' ? ' style="' + record.styleText + '"' : '';
    var titleAttr = record.tooltipText !== ''
        ? ' title="' + buildAgendaTeamEntryTitleAttr(record.tooltipText) + '"'
        : '';
    var tagName = record.interactiveType === 'free' || record.interactiveType === 'booked' ? 'button' : 'div';
    var extraAttrs = '';

    if (tagName === 'button') {
        extraAttrs += ' type="button"';
        extraAttrs += ' class="agenda-team-mobile-entry ' + record.extraClass + ' js-agenda-team-' + record.interactiveType + '-slot"';
        extraAttrs += ' data-slot-key="' + escapeHtml(String(record.slotKey || '')) + '"';
        extraAttrs += ' data-slot-id="' + escapeHtml(String(record.slotId || '')) + '"';
        extraAttrs += ' aria-label="' + buildAgendaTeamEntryTitleAttr(record.tooltipText || record.primaryLabel || 'Slot agenda') + '"';
    } else {
        extraAttrs += ' class="agenda-team-mobile-entry ' + record.extraClass + '"';
    }

    return ''
        + '<' + tagName + extraAttrs + styleAttr + titleAttr + '>'
        + '  <div class="agenda-team-mobile-entry-head">'
        + '    <div class="agenda-team-mobile-entry-doctor">' + escapeHtml(record.columnLabel || '') + '</div>'
        + '    <span class="agenda-team-mobile-entry-time">' + escapeHtml(record.timeLabel || '') + '</span>'
        + '  </div>'
        + '  <div class="agenda-team-mobile-entry-title">' + escapeHtml(record.primaryLabel || '') + '</div>'
        + (record.contactLabel !== ''
            ? '  <div class="agenda-team-mobile-entry-contact">' + escapeHtml(record.contactLabel) + '</div>'
            : '')
        + (record.secondaryLabel !== ''
            ? '  <div class="agenda-team-mobile-entry-note">' + escapeHtml(record.secondaryLabel) + '</div>'
            : '')
        + '</' + tagName + '>';
}

function buildAgendaTeamDayMobileEmptyStates(columns) {
    var html = '<div class="agenda-team-mobile-empty-stack">';

    $.each(columns, function(_, column) {
        var columnStyle = buildAgendaTeamColumnInlineStyle(column);
        var styleAttr = columnStyle !== '' ? ' style="' + columnStyle + '"' : '';
        var message = $.trim(String(column.message || ''));

        if (message === '') {
            if (column.giorno_bloccato) {
                message = 'Giornata bloccata per il professionista.';
            } else if (!column.has_slots) {
                message = 'Nessuna agenda impostata per questo professionista.';
            } else {
                message = 'Nessuno slot visibile per questa giornata.';
            }
        }

        html += ''
            + '<div class="agenda-team-mobile-empty-card"' + styleAttr + '>'
            + '  <div class="agenda-team-mobile-empty-card-name">' + escapeHtml(column.label || ('Professionista ' + (column.id_dot || ''))) + '</div>'
            + '  <div class="agenda-team-mobile-empty-card-message">' + escapeHtml(message) + '</div>'
            + '</div>';
    });

    html += '</div>';

    return html;
}

function renderAgendaTeamDayMobile(columns, bounds) {
    var records = buildAgendaTeamDayMobileRecords(columns);
    var visibleColumns = Math.max(1, Math.min(columns.length, 4));
    var html = '<div class="agenda-team-mobile-list has-' + visibleColumns + '-columns'
        + (columns.length > visibleColumns ? ' has-overflow-columns' : '') + '">';

    html += buildAgendaTeamDayMobileSummary(columns);

    if (!records.length) {
        html += buildAgendaTeamDayMobileEmptyStates(columns);
        html += '</div>';
        return html;
    }

    var recordsByHour = {};
    var hourStarts = [];
    $.each(records, function(_, record) {
        var hourKey = String(record.hourStartMinutes);
        if (!recordsByHour[hourKey]) {
            recordsByHour[hourKey] = {};
            hourStarts.push(record.hourStartMinutes);
        }

        recordsByHour[hourKey][record.columnKey] = recordsByHour[hourKey][record.columnKey] || [];
        recordsByHour[hourKey][record.columnKey].push(record);
    });

    hourStarts.sort(function(leftHour, rightHour) {
        return leftHour - rightHour;
    });

    $.each(hourStarts, function(_, hourStartMinutes) {
        var hourEndMinutes = Math.min(hourStartMinutes + 60, bounds.endMinutes);
        var hourColumns = recordsByHour[String(hourStartMinutes)] || {};

        html += '  <div class="agenda-team-mobile-section">';
        html += '    <div class="agenda-team-mobile-section-header">';
        html += '      <div class="agenda-team-mobile-section-kicker">Fascia oraria</div>';
        html += '      <div class="agenda-team-mobile-section-label">';
        html += '        <span>' + escapeHtml(formatAgendaTeamMinutesLabel(hourStartMinutes)) + '</span>';
        html += '        <span class="agenda-team-mobile-section-separator">&ndash;</span>';
        html += '        <span>' + escapeHtml(formatAgendaTeamMinutesLabel(hourEndMinutes)) + '</span>';
        html += '      </div>';
        html += '    </div>';
        html += '    <div class="agenda-team-mobile-section-body">';

        $.each(columns, function(_, column) {
            var columnKey = String(column.id_dot || column.label || '');
            var columnRecords = hourColumns[columnKey] || [];
            var columnStyle = buildAgendaTeamColumnInlineStyle(column);
            var columnStyleAttr = columnStyle !== '' ? ' style="' + columnStyle + '"' : '';
            var columnLabel = String(column.label || ('Professionista ' + (column.id_dot || '')));

            html += '      <div class="agenda-team-mobile-professional-lane"'
                + ' aria-label="' + buildAgendaTeamEntryTitleAttr(columnLabel) + '">';

            if (columnRecords.length) {
                $.each(columnRecords, function(_, record) {
                    html += renderAgendaTeamDayMobileRecord(record);
                });
            } else {
                var emptyLabel = column.giorno_bloccato
                    ? 'Bloccata'
                    : (!column.has_slots ? 'No agenda' : 'Nessuno slot');
                html += '        <div class="agenda-team-mobile-entry is-empty"' + columnStyleAttr + '>';
                html += '          <span class="agenda-team-mobile-entry-empty-label">' + escapeHtml(emptyLabel) + '</span>';
                html += '        </div>';
            }

            html += '      </div>';
        });

        html += '    </div>';
        html += '  </div>';
    });

    html += '</div>';

    return html;
}

function bindAgendaTeamDayMobileScrollSync() {
    var $scrollers = $('#agendaTeamDayBoard .agenda-team-mobile-summary, #agendaTeamDayBoard .agenda-team-mobile-section-body');
    var isSyncing = false;

    $scrollers.off('scroll.agendaTeamMobileSync').on('scroll.agendaTeamMobileSync', function() {
        if (isSyncing) {
            return;
        }

        isSyncing = true;
        var scrollLeft = this.scrollLeft;

        $scrollers.not(this).each(function() {
            this.scrollLeft = scrollLeft;
        });

        if (window.requestAnimationFrame) {
            window.requestAnimationFrame(function() {
                isSyncing = false;
            });
        } else {
            isSyncing = false;
        }
    });
}

function buildAgendaTeamDayColumnEntries(column, bounds, pixelsPerMinute, entryHeight) {
    var slots = $.isArray(column.slots) ? column.slots : [];
    var compactSingleSlotHeight = supportsAgendaTeamDaySingleSlotHeightFeature();
    var compactSlotDetailsEnabled = compactSingleSlotHeight && supportsAgendaTeamDayCompactSlotDetailsFeature();
    var compactRenderedUntilMinutes = null;
    var renderSlots = slots;

    if (!slots.length) {
        return '<div class="agenda-team-empty-column">' + escapeHtml(column.message || 'Nessuna agenda impostata per questo professionista.') + '</div>';
    }

    if (compactSingleSlotHeight) {
        renderSlots = slots.slice(0).sort(function(leftSlot, rightSlot) {
            var leftStart = getAgendaSlotVisualStartMoment(leftSlot);
            var rightStart = getAgendaSlotVisualStartMoment(rightSlot);
            var leftEnd = getAgendaSlotVisualEndMoment(leftSlot);
            var rightEnd = getAgendaSlotVisualEndMoment(rightSlot);
            var leftStartMinutes = leftStart && leftStart.isValid() ? ((leftStart.hours() * 60) + leftStart.minutes()) : 0;
            var rightStartMinutes = rightStart && rightStart.isValid() ? ((rightStart.hours() * 60) + rightStart.minutes()) : 0;

            if (leftStartMinutes !== rightStartMinutes) {
                return leftStartMinutes - rightStartMinutes;
            }

            var leftDuration = leftEnd && leftEnd.isValid() ? (((leftEnd.hours() * 60) + leftEnd.minutes()) - leftStartMinutes) : 0;
            var rightDuration = rightEnd && rightEnd.isValid() ? (((rightEnd.hours() * 60) + rightEnd.minutes()) - rightStartMinutes) : 0;

            if (leftDuration !== rightDuration) {
                return rightDuration - leftDuration;
            }

            var leftHasAppointment = !!leftSlot.id_appuntamento
                || $.trim(String(leftSlot.stato || '')).toUpperCase() === 'PRENOTATO';
            var rightHasAppointment = !!rightSlot.id_appuntamento
                || $.trim(String(rightSlot.stato || '')).toUpperCase() === 'PRENOTATO';

            if (leftHasAppointment !== rightHasAppointment) {
                return leftHasAppointment ? -1 : 1;
            }

            return (parseInt(leftSlot.id_slot, 10) || 0) - (parseInt(rightSlot.id_slot, 10) || 0);
        });
    }

    var html = '';
    var nextSlotStartMap = buildAgendaNextSlotStartMap(slots);

    $.each(renderSlots, function(_, slot) {
        if (isAgendaCoveredSecondarySlot(slot)) {
            return true;
        }

        var slotId = parseInt(slot.id_slot, 10) || 0;
        var startMoment = getAgendaSlotVisualStartMoment(slot);
        var endMoment = getAgendaSlotVisualEndMoment(slot);

        if (!startMoment || !startMoment.isValid() || !endMoment || !endMoment.isValid()) {
            return true;
        }

        if (slotId > 0) {
            agendaTeamSlotIndex[String(slotId)] = slot;
        }

        var startMinutes = (startMoment.hours() * 60) + startMoment.minutes();
        var endMinutes = (endMoment.hours() * 60) + endMoment.minutes();
        var durationMinutes = endMinutes - startMinutes;
        var dayKey = startMoment.format('YYYY-MM-DD');
        var displayDurationMinutes = getAgendaDisplayDurationMinutes(
            slot,
            durationMinutes,
            startMinutes,
            nextSlotStartMap[dayKey + '|' + startMinutes]
        );

        if (durationMinutes <= 0 || displayDurationMinutes <= 0) {
            return true;
        }

        var height = getAgendaTeamDayEntryHeight(displayDurationMinutes, pixelsPerMinute, entryHeight);
        var orario = startMoment.format('HH:mm');
        var orarioFine = endMoment.format('HH:mm');
        var orarioLabel = orario;
        if (orarioFine !== '' && orarioFine !== orario) {
            orarioLabel += ' - ' + orarioFine;
        }
        var stato = $.trim(String(slot.stato || '')).toUpperCase();
        var pazSpec = $.trim(slot.paz_spec || '');
        var isSpecialPatient = isAgendaSpecialPatient(slot, pazSpec);
        var hasAppointment = !!slot.id_appuntamento || (stato !== 'LIBERO' && stato !== 'BLOCCATO' && stato !== 'CHIUSO');

        if (compactSingleSlotHeight && !hasAppointment && compactRenderedUntilMinutes !== null && startMinutes < compactRenderedUntilMinutes) {
            return true;
        }

        if (compactSingleSlotHeight) {
            if (compactSlotDetailsEnabled) {
                height = Math.max(parseInt(entryHeight, 10) || 0, 58);
            } else {
                height = Math.max(parseInt(entryHeight, 10) || 0, 44);
            }
        }
        var entryStyle = compactSingleSlotHeight
            ? (compactSlotDetailsEnabled ? 'min-height:' : 'height:') + height + 'px;'
            : 'top:' + Math.max(0, (startMinutes - bounds.startMinutes) * pixelsPerMinute) + 'px;height:' + height + 'px;';
        var visitTypeVisualStyle = (!column.giorno_bloccato && hasAppointment) ? getAgendaVisitTypeVisualStyleById(slot.id_tipo_visita) : null;
        var nominativo = getAgendaAppointmentDisplayName(slot, pazSpec);
        var noteEvento = buildAppointmentNoteDisplay(slot);
        var slotNoteEvento = compactSlotDetailsEnabled
            ? buildAppointmentNoteDisplay(slot, { includeVisitTypeDuration: false })
            : noteEvento;
        var primaryLabel = nominativo !== '' ? nominativo : 'Appuntamento';
        var compactContactLabel = compactSlotDetailsEnabled ? buildAgendaTeamEntryPrimaryContactLabel(slot) : '';
        var tooltipText = compactSlotDetailsEnabled
            ? buildAgendaTeamEntryTooltip(orarioLabel, primaryLabel, slot, noteEvento)
            : (orarioLabel + (nominativo !== '' ? (' ' + nominativo) : ''));
        var bookedTitleAttrs = compactSlotDetailsEnabled
            ? ' aria-label="' + buildAgendaTeamEntryTitleAttr(tooltipText || 'Appuntamento') + '"'
            : ' title="' + buildAgendaTeamEntryTitleAttr(tooltipText || 'Appuntamento') + '"';
        var bookedTimeLabel = orarioLabel;

        if ((stato === 'LIBERO' || stato === 'BLOCCATO') && !hasAppointment && !column.giorno_bloccato) {
            html += ''
                + '<button type="button"'
                + ' class="agenda-team-entry agenda-team-entry-free-slot js-agenda-team-free-slot"'
                + ' style="' + entryStyle + '"'
                + ' data-slot-id="' + slotId + '"'
                + ' title="' + buildAgendaTeamEntryTitleAttr(orarioLabel + '\nSlot libero') + '">'
                + buildAgendaTeamEntryContent(orarioLabel, 'Libero', 'Slot disponibile')
                + '</button>';
            if (compactSingleSlotHeight) {
                compactRenderedUntilMinutes = Math.max(compactRenderedUntilMinutes || 0, startMinutes + displayDurationMinutes);
            }
            return true;
        }

        if (stato === 'CHIUSO' || (column.giorno_bloccato && !hasAppointment)) {
            html += ''
                + '<div class="agenda-team-entry agenda-team-entry-closed"'
                + ' style="' + entryStyle + '"'
                + ' title="' + buildAgendaTeamEntryTitleAttr(orarioLabel + '\nGiornata bloccata') + '">'
                + buildAgendaTeamEntryContent(orarioLabel, 'Bloccato', 'Fascia non disponibile')
                + '</div>';
            if (compactSingleSlotHeight) {
                compactRenderedUntilMinutes = Math.max(compactRenderedUntilMinutes || 0, startMinutes + displayDurationMinutes);
            }
            return true;
        }

        var color = column.giorno_bloccato ? '#d9534f' : (isSpecialPatient ? getAgendaSpecialPatientColor(pazSpec, slot) : '#3c8dbc');
        var bookedStyle = entryStyle;
        var bookedClass = 'agenda-team-entry';

        if (visitTypeVisualStyle) {
            bookedClass += ' has-visit-type-color';
            bookedStyle += buildAgendaVisitTypeInlineStyle(visitTypeVisualStyle);
        } else {
            if (column.giorno_bloccato) {
                bookedStyle += 'background:' + color + ';border:1px solid ' + color + ';color:var(--agenda-appointment-text, #fff);';
            } else if (isSpecialPatient) {
                bookedStyle += 'background:' + color + ';border:1px solid ' + color + ';color:var(--agenda-appointment-text, #fff);';
            } else {
                bookedStyle += 'background:var(--agenda-team-column-entry-bg, ' + color + ');'
                    + 'border:1px solid var(--agenda-team-column-entry-border, ' + color + ');'
                    + 'color:var(--agenda-appointment-text, var(--agenda-team-column-entry-text, #fff));';
            }
        }

        if (column.giorno_bloccato) {
            html += ''
                + '<div class="' + bookedClass + '"'
                + ' style="' + bookedStyle + '"'
                + bookedTitleAttrs + '>'
                + buildAgendaTeamEntryContent(bookedTimeLabel, primaryLabel, slotNoteEvento, {
                    contactLabel: compactContactLabel
                })
                + '</div>';
            return true;
        }

        html += ''
            + '<button type="button"'
            + ' class="' + bookedClass + ' js-agenda-team-booked-slot"'
            + ' style="' + bookedStyle + '"'
            + ' data-slot-id="' + slotId + '"'
            + bookedTitleAttrs + '>'
            + buildAgendaTeamEntryContent(bookedTimeLabel, primaryLabel, slotNoteEvento, {
                contactLabel: compactContactLabel
            })
            + '</button>';

        return true;
    });

    return html;
}

function renderAgendaTeamDay(res) {
    agendaTeamSlotIndex = {};
    agendaTeamAllSlots = [];

    var $teamBoard = $('#agendaTeamDayBoard');
    var $teamBoardWrap = $teamBoard.closest('.agenda-team-board-wrap');
    var columns = $.isArray(res && res.columns) ? res.columns : [];
    if (!columns.length) {
        $teamBoardWrap.removeClass('is-compact-stack');
        $teamBoardWrap.removeClass('is-mobile-list');
        $teamBoard.removeClass('has-compact-slot-details is-fit-columns is-mobile-list');
        $teamBoard.html('<div class="alert alert-info" style="margin:12px;">Nessun professionista visibile per questa vista.</div>');
        return;
    }

    registerAgendaTeamDaySlotPool(columns);

    var compactSingleSlotHeight = supportsAgendaTeamDaySingleSlotHeightFeature();
    var compactSlotDetailsEnabled = compactSingleSlotHeight && supportsAgendaTeamDayCompactSlotDetailsFeature();
    var mobileListMode = isAgendaTeamDayMobileListMode();
    var fitColumnsToViewport = columns.length <= 4;
    var stepMinutes = Math.max(5, parseInt(res.grid_duration, 10) || agendaCalendarBaseStep);
    var bounds = getAgendaTeamDayBounds(res.min_time, res.max_time);
    var pixelsPerMinute = getAgendaTeamDayPixelsPerMinute(bounds.totalMinutes, stepMinutes);
    var totalHeight = Math.max(Math.round(bounds.totalMinutes * pixelsPerMinute), 640);
    var stepHeight = Math.max(Math.round(stepMinutes * pixelsPerMinute), 60);
    var entryHeight = Math.max(stepHeight - 6, 54);
    var compressedLayoutEnabled = !!window.AGENDA_CONFIG.compressedLayoutEnabled;
    var templateColumns = (!compressedLayoutEnabled && !compactSingleSlotHeight) ? '82px' : '';
    var html = '';

    if (mobileListMode) {
        $teamBoard.html(renderAgendaTeamDayMobile(columns, bounds));
        $teamBoard.removeClass('has-compact-slot-details is-fit-columns').addClass('is-mobile-list');
        $teamBoardWrap.removeClass('is-compact-stack').addClass('is-mobile-list');
        bindAgendaTeamDayMobileScrollSync();
        return;
    }

    $teamBoardWrap.removeClass('is-mobile-list');
    $teamBoard.removeClass('is-mobile-list');

    $.each(columns, function() {
        templateColumns += (templateColumns !== '' ? ' ' : '') + (
            fitColumnsToViewport
                ? 'minmax(0, 1fr)'
                : 'minmax(var(--agenda-team-column-min-width, 220px), 1fr)'
        );
    });

    html += '<div class="agenda-team-grid" style="grid-template-columns:' + templateColumns + ';">';
    if (!compressedLayoutEnabled && !compactSingleSlotHeight) {
        html += '<div class="agenda-team-corner">Orario</div>';
    }

    $.each(columns, function(index, column) {
        var removeLeadingBorder = index === 0 && (compressedLayoutEnabled || compactSingleSlotHeight);
        var headerExtraStyle = removeLeadingBorder ? 'border-left:0;' : '';
        html += renderAgendaTeamDayHeader(column, headerExtraStyle);
    });

    if (!compressedLayoutEnabled && !compactSingleSlotHeight) {
        html += '<div class="agenda-team-time-axis" style="height:' + totalHeight + 'px;">'
            + renderAgendaTeamDayTimeMarkers(bounds, pixelsPerMinute)
            + '</div>';
    }

    $.each(columns, function(index, column) {
        var columnStyle = buildAgendaTeamColumnInlineStyle(column);
        var columnBodyStyle = compactSingleSlotHeight
            ? ''
            : 'height:' + totalHeight + 'px;--agenda-team-step-height:' + stepHeight + 'px;';
        if (columnStyle !== '') {
            columnBodyStyle += columnStyle;
        }
        if (index === 0 && (compressedLayoutEnabled || compactSingleSlotHeight)) {
            columnBodyStyle += 'border-left:0;';
        }

        if ($.isArray(column && column.slots)) {
            agendaTeamAllSlots = agendaTeamAllSlots.concat(column.slots);
        }
        html += '<div class="agenda-team-column-body'
            + (column.giorno_bloccato ? ' is-day-locked' : '')
            + (compactSingleSlotHeight ? ' is-compact-stack' : '')
            + '"'
            + ' style="' + columnBodyStyle + '">'
            + buildAgendaTeamDayBackgroundRows(bounds, pixelsPerMinute, stepMinutes, stepHeight)
            + buildAgendaTeamDayColumnEntries(column, bounds, pixelsPerMinute, entryHeight)
            + '</div>';
    });

    html += '</div>';
    $teamBoard.html(html);
    $teamBoard.toggleClass('has-compact-slot-details', compactSlotDetailsEnabled);
    $teamBoard.toggleClass('is-fit-columns', fitColumnsToViewport);
    $teamBoardWrap.toggleClass('is-compact-stack', compactSingleSlotHeight);
}

function caricaSlotCalendarioTeamDay(options) {
    options = options || {};

    var idDot = $('#id_dot').val();
    var data = $('#agenda_date').val();
    var showLoader = options.showLoader !== false;

    if (agendaCalendarXhr && agendaCalendarXhr.readyState !== 4) {
        agendaCalendarXhr.abort();
    }

    if (agendaTeamDayXhr && agendaTeamDayXhr.readyState !== 4) {
        agendaTeamDayXhr.abort();
    }

    var requestSeq = ++agendaTeamDayRequestSeq;
    setCalendarNoSlotsMode(false);

    if (showLoader) {
        setAgendaCalendarLoading(true, 'Sto aggiornando la vista giornaliera del team.');
    }

    agendaTeamDayXhr = $.get("<?= base_url('agenda/calendario-team-day') ?>", {
        id_dot: idDot,
        data: data,
        _ts: Date.now()
    }, function(res) {
        if (requestSeq !== agendaTeamDayRequestSeq) {
            return;
        }

        if (!res || !res.status) {
            agendaTeamDayLastResponse = null;
            agendaTeamSlotIndex = {};
            applyAgendaStateResponse({});
            $('#agendaTeamDayBoard').html('<div class="alert alert-info" style="margin:12px;">' + escapeHtml((res && res.message) ? res.message : 'Errore durante il caricamento della vista team.') + '</div>');
            return;
        }

        agendaTeamDayLastResponse = res;
        applyAgendaStateResponse(res);
        agendaCalendarStep = parseInt(res.grid_duration, 10) || agendaCalendarBaseStep;
        agendaMinTime = res.min_time || '08:00:00';
        agendaMaxTime = res.max_time || '18:00:00';
        renderAgendaTeamDay(res);
        applicaStatoGiornoBloccato();
    }, 'json').fail(function(xhr, textStatus) {
        if (textStatus === 'abort' || requestSeq !== agendaTeamDayRequestSeq) {
            return;
        }

        agendaTeamDayLastResponse = null;
        agendaTeamSlotIndex = {};
        applyAgendaStateResponse({});
        $('#agendaTeamDayBoard').html('<div class="alert alert-info" style="margin:12px;">Errore nel caricamento della vista team. Riprova tra un attimo.</div>');
    }).always(function() {
        if (requestSeq !== agendaTeamDayRequestSeq) {
            return;
        }

        setAgendaCalendarLoading(false);
    });
}

function renderDomiciliariLaterali() {
    if (!window.AGENDA_CONFIG.domiciliariAbilitati) {
        return;
    }

    var idDot = $('#id_dot').val();
    $('#domiciliariList').html('<tr><td colspan="5" class="text-center text-muted">Caricamento...</td></tr>');

    $.get("<?= base_url('visite-domiciliari/lista') ?>/" + idDot, {
        data_agenda: $('#agenda_date').val()
    }, function(res) {
        var html = '';

        if (!res.status || !res.rows || !res.rows.length) {
            html = '<tr><td colspan="5" class="text-center text-muted">Nessuna visita domiciliare</td></tr>';
            $('#domiciliariList').html(html);
            applicaStatoGiornoBloccato();
            return;
        }

        $.each(res.rows, function(i, row) {
            var paziente = $.trim((row.cognome || '') + ' ' + (row.nome || ''));
            var recapiti = '';
            var indirizzo = '';
            var inserita = formatDomiciliareCreatedAt(row.data_creazione || '');

            if (row.telefono) {
                recapiti += '<div><strong>T:</strong> ' + escapeHtml(row.telefono) + '</div>';
            }
            if (row.cellulare) {
                recapiti += '<div><strong>C:</strong> ' + escapeHtml(row.cellulare) + '</div>';
            }
            if (recapiti === '') {
                recapiti = '<span class="text-muted">-</span>';
            }

            indirizzo += '<div>' + escapeHtml(row.indirizzo || '') + '</div>';
            indirizzo += '<div>' + escapeHtml(row.citta || '') + '</div>';

            html += '<tr>';
            html += '<td>' + (inserita || '<span class="text-muted">-</span>') + '</td>';
            html += '<td>';
            html += '<strong>' + escapeHtml(paziente) + '</strong>';
            if ((row.note || '') !== '') {
                html += '<span class="vd-note-preview">' + escapeHtml(row.note) + '</span>';
            }
            html += '</td>';
            html += '<td>' + recapiti + '</td>';
            html += '<td>' + indirizzo + '</td>';
            html += '<td class="vd-actions text-center">';
            html += '<button type="button" class="btn btn-xs btn-primary btnModificaVisita" data-id="' + row.id_visita + '"><i class="fa fa-pencil"></i></button> ';
            html += '<button type="button" class="btn btn-xs btn-danger btnEliminaVisitaRiga" data-id="' + row.id_visita + '"><i class="fa fa-trash"></i></button>';
            html += '</td>';
            html += '</tr>';
        });

        $('#domiciliariList').html(html);
        applicaStatoGiornoBloccato();
    }, 'json');
}

function formatDomiciliareCreatedAt(value) {
    value = $.trim((value || '').toString());
    if (!value) {
        return '';
    }

    var match = value.match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/);
    if (match) {
        return '<strong>' + match[3] + '/' + match[2] + '/' + match[1] + '</strong>' +
            '<div class="text-muted small">' + match[4] + ':' + match[5] + '</div>';
    }

    return escapeHtml(value);
}

function resetNoteModal() {
    $('#nota_id_nota').val('');
    syncNoteTargetDoctor($('#id_dot').val());
    $('#nota_id_paziente').val('');

    $('#nota_data_inizio_validita').val(moment().format('YYYY-MM-DD'));
    $('#nota_cliente').val('');
    $('#nota_telefono').val('');
    $('#nota_cellulare').val('');
    $('#nota_indirizzo').val('');
    $('#nota_citta').val('');
    $('#nota_note').val('');
    $('#nota_fatta').prop('checked', false);

    $('#notePatientAutocomplete').addClass('d-none').html('');
    $('#noteModalTitle').text('Nuova nota');
    $('#btnDeleteNote').hide();
}

function apriNuovaNota() {
    if (!isSharedMemoManagementEnabled() && isMemoActionBlocked()) {
        return;
    }

    resetNoteModal();
    syncNoteTargetDoctor($('#id_dot').val());
    $('#nota_data_inizio_validita').val(moment().format('YYYY-MM-DD'));
    $('#noteModal').modal('show');
}

function getClasseColoreNota(dataValidita) {
    var oggi = moment().format('YYYY-MM-DD');

    if (dataValidita < oggi) return 'note-scaduta';
    if (dataValidita > oggi) return 'note-futura';
    return 'note-oggi';
}

function formatNotaDate(value) {
    if (!value) {
        return '';
    }

    var parsed = moment(value, ['YYYY-MM-DD', 'YYYY-MM-DD HH:mm:ss', moment.ISO_8601], true);
    return parsed.isValid() ? parsed.format('DD/MM/YYYY') : escapeHtml(value);
}

function formatNotaDateTime(value) {
    if (!value) {
        return '';
    }

    var parsed = moment.utc(value, ['YYYY-MM-DD HH:mm:ss', 'YYYY-MM-DD HH:mm', moment.ISO_8601], true);
    if (!parsed.isValid()) {
        return escapeHtml(value);
    }

    try {
        return new Intl.DateTimeFormat('it-IT', {
            timeZone: 'Europe/Rome',
            day: '2-digit',
            month: '2-digit',
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
            hour12: false
        }).format(parsed.toDate()).replace(',', '');
    } catch (e) {
        return parsed.local().format('DD/MM/YYYY HH:mm');
    }
}

function syncNoteTargetDoctor(value) {
    var normalized = $.trim(String(value || $('#id_dot').val() || ''));

    $('#nota_id_dot').val(normalized);

    if (isSharedMemoManagementEnabled() && $('#nota_doctor_select').length) {
        $('#nota_doctor_select').val(normalized);
    }
}

function getNoteTargetDoctorId() {
    if (isSharedMemoManagementEnabled() && $('#nota_doctor_select').length) {
        var selectedDoctorId = $.trim(String($('#nota_doctor_select').val() || ''));
        if (selectedDoctorId !== '') {
            return selectedDoctorId;
        }
    }

    return $.trim(String($('#nota_id_dot').val() || $('#id_dot').val() || ''));
}

function isMemoActionBlockedForNoteRow(row) {
    return !!(row && row.memo_action_blocked);
}

function renderNoteLaterali() {
    var idDot = $('#id_dot').val();
    var loadingHtml = '<div class="text-center text-muted" style="padding:20px;">Caricamento...</div>';
    var errorHtml = '<div class="text-center text-danger" style="padding:20px;">Errore nel caricamento delle memo</div>';

    $('#noteList').html(loadingHtml);

    $.get("<?= base_url('agenda/note') ?>", {
        id_dot: idDot,
        agenda_data: $('#agenda_date').val()
    }, function(res) {
        var html = '';

        if (!res.status || !res.rows || !res.rows.length) {
            html = '<div class="text-center text-muted" style="padding:20px;">Nessuna nota presente</div>';
            $('#noteList').html(html);
            applicaStatoGiornoBloccato();
            return;
        }

        $.each(res.rows, function(i, row) {
            var colorClass = getClasseColoreNota(row.data_inizio_validita || '');
            var fatta = parseInt(row.fatta || 0, 10) === 1;
            var fattaChecked = fatta ? 'checked' : '';
            var fattaClass = fatta ? ' note-fatta' : '';

            var nominativo = $.trim(row.cliente || '');
            var telefono = row.telefono || '';
            var cellulare = row.cellulare || '';
            var indirizzo = row.indirizzo || '';
            var citta = row.citta || '';
            var note = row.note || '';
            var createdByUsername = getCreatedByUsername(row);
            var dataValidita = formatNotaDate(row.data_inizio_validita || '');
            var dataInserimento = formatNotaDateTime(row.created_at || '');
            var doctorLabel = $.trim(row.doctor_label || '');
            var noteBlocked = isMemoActionBlockedForNoteRow(row);
            var noteBlockedAttr = noteBlocked ? ' data-memo-blocked="1"' : ' data-memo-blocked="0"';
            var blockedDisabledAttr = noteBlocked ? ' disabled' : '';
            var blockedTitleAttr = noteBlocked
                ? ' title="' + escapeHtml('Giorno bloccato per le memo del dottore assegnato') + '"'
                : '';

            html += ''
                + '<div class="agenda-note-card ' + colorClass + fattaClass + '" data-id="' + escapeHtml(row.id_nota || '') + '"' + noteBlockedAttr + '>'
                + '   <div class="agenda-note-header">'
                + '       <div>'
                + '           <div class="agenda-note-title">'
                +               escapeHtml(nominativo !== '' ? nominativo : 'Senza cliente')
                +               (fatta ? ' <span class="label label-success">FATTA</span>' : '')
                +               (noteBlocked ? ' <span class="label label-danger">GIORNO BLOCCATO</span>' : '')
                + '           </div>'
                + '           <div class="agenda-note-meta">Valida dal: <strong>' + dataValidita + '</strong>'
                +               (doctorLabel !== '' ? ' | Dottore: <strong>' + escapeHtml(doctorLabel) + '</strong>' : '')
                +               (dataInserimento !== '' ? ' | Inserita il: <strong>' + dataInserimento + '</strong>' : '')
                +               (createdByUsername !== '' ? ' | Utente: <strong>' + escapeHtml(createdByUsername) + '</strong>' : '')
                + '           </div>'
                + '       </div>'
                + '       <div class="agenda-note-actions text-right">'
                + '           <label class="agenda-note-done-label" style="margin-right:8px;">'
                + '               <input type="checkbox" class="chkNotaFatta" data-id="' + escapeHtml(row.id_nota || '') + '" ' + fattaChecked + blockedDisabledAttr + blockedTitleAttr + '> Fatta'
                + '           </label>'
                + '           <button type="button" class="btn btn-xs btn-primary btnEditNote" data-id="' + escapeHtml(row.id_nota || '') + '"' + blockedDisabledAttr + blockedTitleAttr + '><i class="fa fa-pencil"></i></button>'
                + '           <button type="button" class="btn btn-xs btn-danger btnDeleteNoteRow" data-id="' + escapeHtml(row.id_nota || '') + '"' + blockedDisabledAttr + blockedTitleAttr + '><i class="fa fa-trash"></i></button>'
                + '       </div>'
                + '   </div>'
                + '   <div class="agenda-note-grid">'
                + '       <div class="row">'
                + '           <div class="col-sm-6"><span class="agenda-note-label">Telefono:</span> ' + escapeHtml(telefono) + '</div>'
                + '           <div class="col-sm-6"><span class="agenda-note-label">Cellulare:</span> ' + escapeHtml(cellulare) + '</div>'
                + '       </div>'
                + '       <div class="row">'
                + '           <div class="col-sm-8"><span class="agenda-note-label">Indirizzo:</span> ' + escapeHtml(indirizzo) + '</div>'
                + '           <div class="col-sm-4"><span class="agenda-note-label">CittÃ :</span> ' + escapeHtml(citta) + '</div>'
                + '       </div>'
                + '       <div class="row">'
                + '           <div class="col-sm-12"><span class="agenda-note-label">Note:</span> ' + nl2br(escapeHtml(note)) + '</div>'
                + '       </div>'
                + '   </div>'
                + '</div>';
        });

        $('#noteList').html(html);
        applicaStatoGiornoBloccato();
    }, 'json').fail(function() {
        $('#noteList').html(errorHtml);
        applicaStatoGiornoBloccato();
    });
}

var notePatientAutocompleteTimer = null;
var notePatientAutocompleteXhr = null;
var patientAutocompleteTimer = null;
var patientAutocompleteXhr = null;
var agendaPatientSearchTimer = null;
var agendaPatientSearchXhr = null;
var agendaPatientAppointmentsXhr = null;
var agendaFocusedAppointmentId = parseInt(window.AGENDA_CONFIG.focusedAppointmentId || 0, 10) || 0;
var agendaFocusedPatientId = 0;
var agendaShouldScrollToFocusedAppointment = agendaFocusedAppointmentId > 0;
var agendaNotificationFocusToastShown = false;

function shouldRunPatientAutocomplete(term) {
    var value = $.trim((term || '').toString());

    if (value === '') {
        return false;
    }

    if (value.length >= 2) {
        return true;
    }

    return /[^A-Za-z0-9]/.test(value);
}

function getAgendaPatientHistoryDefaultMessage() {
    if (isSharedAgendaPatientsEnabled()) {
        return 'La ricerca usa lo spazio condiviso dei pazienti; dopo la selezione qui vedi appuntamenti passati e futuri su tutte le agende dei medici.';
    }

    return 'La ricerca usa l\'anagrafica del professionista; dopo la selezione qui vedi appuntamenti passati e futuri del professionista attuale.';
}

function isSharedAgendaPatientsEnabled() {
    return !!window.AGENDA_CONFIG.sharedAgendaPatientsEnabled;
}

function getAgendaDoctorLabelFromRow(row) {
    var doctorLabel = $.trim((row && row.doctor_label) || '');
    if (doctorLabel !== '') {
        return doctorLabel;
    }

    var idDot = parseInt((row && row.id_dot) || 0, 10) || 0;
    if (idDot <= 0) {
        return '';
    }

    return $.trim($('#id_dot option[value="' + idDot + '"]').text() || '');
}

function setAgendaPatientHistoryPlaceholder(message) {
    $('#agendaPatientAppointmentsTitle').text('Appuntamenti del paziente');
    $('#agendaPatientAppointmentsList').html(
        '<div class="agenda-patient-history-empty">' +
        escapeHtml($.trim(message || '') || getAgendaPatientHistoryDefaultMessage()) +
        '</div>'
    );
}

function scrollAgendaCalendarIntoView() {
    var el = document.getElementById('agendaCalendarShell');
    if (!el || typeof el.scrollIntoView !== 'function') {
        return;
    }

    try {
        el.scrollIntoView({
            behavior: 'smooth',
            block: 'start'
        });
    } catch (e) {
        el.scrollIntoView(true);
    }
}

function getAgendaPatientAppointmentMoments(row) {
    var start = parseAgendaMoment(row && row.ora_inizio ? row.ora_inizio : '');
    var end = parseAgendaMoment(row && row.ora_fine ? row.ora_fine : '');

    if (!start && row && row.data_slot && row.ora_inizio_label) {
        start = parseAgendaMoment(row.data_slot + ' ' + row.ora_inizio_label + ':00');
    }

    if (!end && row && row.data_slot && row.ora_fine_label) {
        end = parseAgendaMoment(row.data_slot + ' ' + row.ora_fine_label + ':00');
    }

    return {
        start: start,
        end: end
    };
}

function getAgendaPatientAppointmentState(row) {
    var moments = getAgendaPatientAppointmentMoments(row);
    if (!moments.start) {
        return 'future';
    }

    var todayKey = moment().format('YYYY-MM-DD');
    if (moments.start.format('YYYY-MM-DD') === todayKey) {
        return 'today';
    }

    return moments.start.isBefore(moment()) ? 'past' : 'future';
}

function compareAgendaPatientAppointments(leftRow, rightRow, direction) {
    var leftMoments = getAgendaPatientAppointmentMoments(leftRow);
    var rightMoments = getAgendaPatientAppointmentMoments(rightRow);
    var leftTs = leftMoments.start ? leftMoments.start.valueOf() : 0;
    var rightTs = rightMoments.start ? rightMoments.start.valueOf() : 0;
    var diff = leftTs - rightTs;

    if (direction === 'desc') {
        diff *= -1;
    }

    if (diff !== 0) {
        return diff;
    }

    return (parseInt((leftRow && leftRow.id_appuntamento) || 0, 10) || 0) -
        (parseInt((rightRow && rightRow.id_appuntamento) || 0, 10) || 0);
}

function getAgendaPatientAppointmentBadge(state) {
    if (state === 'today') {
        return 'Oggi';
    }

    return state === 'past' ? 'Passato' : 'Futuro';
}

function truncateAgendaPatientText(value, maxLength) {
    var text = $.trim((value || '').toString());
    if (text.length <= maxLength) {
        return text;
    }

    return $.trim(text.substr(0, maxLength - 3)) + '...';
}

function getCreatedByUsername(row) {
    return $.trim((row && row.created_by_username) || '');
}

function getCreatedByLabel(row) {
    var username = getCreatedByUsername(row);
    return username !== '' ? ('Utente: ' + username) : '';
}

function buildAppointmentNoteDisplay(row, options) {
    options = options || {};
    var visitType = buildAgendaVisitTypeDisplay(row, {
        includeDuration: options.includeVisitTypeDuration !== false
    });
    var note = $.trim((row && row.note) || '');
    var createdByLabel = getCreatedByLabel(row);
    var pieces = [];

    if (visitType !== '') {
        pieces.push(visitType);
    }

    if (createdByLabel === '') {
        if (note !== '') {
            pieces.push(note);
        }
        return pieces.join(' - ');
    }

    if (note !== '' && note.toLowerCase().indexOf(createdByLabel.toLowerCase()) !== -1) {
        pieces.push(note);
        return pieces.join(' - ');
    }

    if (note !== '') {
        pieces.push(note);
    }

    pieces.push(createdByLabel);
    return pieces.join(' - ');
}

function buildAgendaPatientAppointmentText(row) {
    var pieces = [];
    var motivo = $.trim((row && row.motivo_visita) || '');
    var note = buildAppointmentNoteDisplay(row);
    var indirizzo = $.trim((row && row.indirizzo_visita) || '');
    var comune = $.trim((row && row.comune_visita) || '');
    var stato = $.trim((row && row.stato) || '');

    if (motivo !== '') {
        pieces.push(motivo);
    }

    if (note !== '' && note !== motivo) {
        pieces.push(note);
    }

    if (indirizzo !== '' || comune !== '') {
        pieces.push('Domicilio: ' + $.trim((indirizzo + ' ' + comune).replace(/\s+/g, ' ')));
    }

    if (stato !== '') {
        pieces.push('Stato: ' + stato);
    }

    return truncateAgendaPatientText(pieces.join(' - '), 180);
}

function renderAgendaPatientSelectedSummary(patient, rows) {
    var $summary = $('#agendaPatientSelectedSummary');
    var fullName = $.trim((((patient && patient.cognome) || '') + ' ' + ((patient && patient.nome) || '')).replace(/\s+/g, ' '));
    var fallbackLabel = $.trim(($('#agendaPatientSearch').data('selected-label') || $('#agendaPatientSearch').val() || '').toString());

    if (fullName === '') {
        fullName = fallbackLabel;
    }

    if (fullName === '') {
        $summary.hide().html('');
        return;
    }

    var counts = {
        today: 0,
        future: 0,
        past: 0
    };

    $.each(rows || [], function(_, row) {
        counts[getAgendaPatientAppointmentState(row)]++;
    });

    var parts = [];
    if (counts.today > 0) {
        parts.push('oggi ' + counts.today);
    }
    if (counts.future > 0) {
        parts.push('futuri ' + counts.future);
    }
    if (counts.past > 0) {
        parts.push('passati ' + counts.past);
    }
    if (!rows || !rows.length) {
        parts.push('nessun appuntamento trovato');
    }

    $summary
        .html(
            '<strong>' + escapeHtml(fullName) + '</strong>' +
            '<div>' + escapeHtml(parts.join(' - ')) + '</div>'
        )
        .show();
}

function renderAgendaPatientAppointments(patient, rows) {
    var safeRows = $.isArray(rows) ? rows.slice(0) : [];
    var patientId = parseInt((patient && patient.id_paziente) || 0, 10) || 0;
    var fullName = $.trim((((patient && patient.cognome) || '') + ' ' + ((patient && patient.nome) || '')).replace(/\s+/g, ' '));
    var fallbackLabel = $.trim(($('#agendaPatientSearch').data('selected-label') || $('#agendaPatientSearch').val() || '').toString());

    if (fullName === '') {
        fullName = fallbackLabel;
    }

    if (patientId <= 0) {
        patientId = parseInt($('#agendaPatientSearchIdPaziente').val() || 0, 10) || 0;
    }

    $('#agendaPatientSearchIdPaziente').val(patientId > 0 ? patientId : '');
    if (fullName !== '') {
        $('#agendaPatientSearch').val(fullName);
    }
    $('#agendaPatientAutocomplete').addClass('d-none').html('');
    $('#btnClearAgendaPatientSearch').toggle(patientId > 0);
    $('#agendaPatientAppointmentsTitle').text(fullName !== '' ? ('Appuntamenti di ' + fullName) : 'Appuntamenti del paziente');
    renderAgendaPatientSelectedSummary(patient, safeRows);

    if (!safeRows.length) {
        $('#agendaPatientAppointmentsList').html(
            '<div class="agenda-patient-history-empty">Nessun appuntamento trovato per il paziente selezionato.</div>'
        );
        return;
    }

    var groups = {
        today: [],
        future: [],
        past: []
    };

    $.each(safeRows, function(_, row) {
        groups[getAgendaPatientAppointmentState(row)].push(row);
    });

    groups.today.sort(function(leftRow, rightRow) {
        return compareAgendaPatientAppointments(leftRow, rightRow, 'asc');
    });
    groups.future.sort(function(leftRow, rightRow) {
        return compareAgendaPatientAppointments(leftRow, rightRow, 'asc');
    });
    groups.past.sort(function(leftRow, rightRow) {
        return compareAgendaPatientAppointments(leftRow, rightRow, 'desc');
    });

    function buildGroupHtml(title, state, groupRows) {
        if (!groupRows.length) {
            return '';
        }

        var html = '<div class="agenda-patient-history-group">';
        html += '<div class="agenda-patient-history-group-title">' + escapeHtml(title) + '</div>';

        $.each(groupRows, function(_, row) {
            var moments = getAgendaPatientAppointmentMoments(row);
            var start = moments.start;
            var end = moments.end;
            var appointmentId = parseInt((row && row.id_appuntamento) || 0, 10) || 0;
            var doctorId = parseInt((row && row.id_dot) || 0, 10) || 0;
            var doctorLabel = getAgendaDoctorLabelFromRow(row);
            var dateLabel = start ? start.format('DD/MM/YYYY') : ((row && row.data_slot) || '');
            var timeLabel = (row && row.ora_inizio_label ? row.ora_inizio_label : (start ? start.format('HH:mm') : ''))
                + ' - ' +
                (row && row.ora_fine_label ? row.ora_fine_label : (end ? end.format('HH:mm') : ''));
            var isSelected = agendaFocusedAppointmentId > 0 && agendaFocusedAppointmentId === appointmentId;
            var detailText = buildAgendaPatientAppointmentText(row);

            html += '<button type="button" class="agenda-patient-history-item is-' + state + (isSelected ? ' is-selected' : '') + '" ' +
                'data-appointment-id="' + escapeHtml(appointmentId) + '" ' +
                'data-patient-id="' + escapeHtml(patientId) + '" ' +
                'data-dot-id="' + escapeHtml(doctorId) + '" ' +
                'data-date="' + escapeHtml((row && row.data_slot) || '') + '">' +
                '<div class="agenda-patient-history-topline">' +
                '<span class="agenda-patient-history-date">' + escapeHtml(dateLabel + ' - ' + timeLabel) + '</span>' +
                '<span class="agenda-patient-history-badge">' + escapeHtml(getAgendaPatientAppointmentBadge(state)) + '</span>' +
                '</div>' +
                (isSharedAgendaPatientsEnabled() && doctorLabel !== ''
                    ? '<div class="agenda-patient-history-text">' + escapeHtml(doctorLabel) + '</div>'
                    : '') +
                '<div class="agenda-patient-history-text">' + escapeHtml(detailText !== '' ? detailText : 'Apri il giorno in agenda') + '</div>' +
                '</button>';
        });

        html += '</div>';
        return html;
    }

    var html = '';
    html += buildGroupHtml('Oggi', 'today', groups.today);
    html += buildGroupHtml('Futuri', 'future', groups.future);
    html += buildGroupHtml('Passati', 'past', groups.past);

    $('#agendaPatientAppointmentsList').html(html);
}

function clearAgendaPatientSearchSelection(options) {
    options = options || {};
    var hadFocus = agendaFocusedAppointmentId > 0 || agendaFocusedPatientId > 0;

    if (agendaPatientAppointmentsXhr && agendaPatientAppointmentsXhr.readyState !== 4) {
        agendaPatientAppointmentsXhr.abort();
    }

    if (!options.keepInput) {
        $('#agendaPatientSearch').val('');
    }

    $('#agendaPatientSearch').removeData('selected-label');
    $('#agendaPatientSearch').removeData('selected-cognome');
    $('#agendaPatientSearch').removeData('selected-nome');

    $('#agendaPatientSearchIdPaziente').val('');
    $('#agendaPatientAutocomplete').addClass('d-none').html('');
    $('#agendaPatientSelectedSummary').hide().html('');
    $('#btnClearAgendaPatientSearch').hide();

    if (!options.keepFocusState) {
        agendaFocusedAppointmentId = 0;
        agendaFocusedPatientId = 0;
        agendaShouldScrollToFocusedAppointment = false;

        if (hadFocus && $('#calendar').data('fullCalendar')) {
            riallineaRenderingCalendario();
        }
    }

    setAgendaPatientHistoryPlaceholder(options.message || '');
}

function isAgendaSearchFocusedSlot(slot) {
    var appointmentId = parseInt((slot && slot.id_appuntamento) || 0, 10) || 0;
    return agendaFocusedAppointmentId > 0 && appointmentId === agendaFocusedAppointmentId;
}

function focusAgendaSearchAppointmentSlotIfNeeded() {
    if (!agendaShouldScrollToFocusedAppointment) {
        return;
    }

    var $slot = $('#calendar .agenda-custom-slot.is-search-focus').first();
    agendaShouldScrollToFocusedAppointment = false;

    window.setTimeout(function() {
        if ($slot.length) {
            try {
                $slot.get(0).scrollIntoView({
                    behavior: 'smooth',
                    block: 'center'
                });
            } catch (e) {
                scrollAgendaCalendarIntoView();
            }

            if (
                !agendaNotificationFocusToastShown
                && String(window.AGENDA_CONFIG.notificationContext || '') === 'doctor_cross_booking'
            ) {
                showAgendaToast('Appuntamento della notifica aperto ed evidenziato in agenda.', 'success');
                agendaNotificationFocusToastShown = true;
            }

            $slot.trigger('focus');
            return;
        }

        scrollAgendaCalendarIntoView();
    }, 40);
}

function jumpToAgendaPatientAppointment($item) {
    var data = $.trim(($item.data('date') || '').toString());
    var appointmentId = parseInt($item.data('appointment-id') || 0, 10) || 0;
    var patientId = parseInt($item.data('patient-id') || 0, 10) || 0;
    var targetDotId = parseInt($item.data('dot-id') || 0, 10) || 0;
    var currentDotId = parseInt($('#id_dot').val() || 0, 10) || 0;

    if (!moment(data, 'YYYY-MM-DD', true).isValid()) {
        return;
    }

    if (targetDotId > 0 && targetDotId !== currentDotId) {
        if (!$('#id_dot option[value="' + targetDotId + '"]').length) {
            showAgendaToast('Non posso aprire l\'agenda del professionista associato a questo appuntamento.', 'error');
            return;
        }

        $('#id_dot').val(String(targetDotId));
        window.AGENDA_CONFIG.selectedDot = targetDotId;
        syncNoteTargetDoctor(targetDotId);
        $('#vd_id_dot').val(String(targetDotId));
    }

    agendaFocusedAppointmentId = appointmentId;
    agendaFocusedPatientId = patientId;
    agendaShouldScrollToFocusedAppointment = true;

    $('#agendaPatientAppointmentsList .agenda-patient-history-item').removeClass('is-selected');
    $item.addClass('is-selected');

    $('#agenda_date').val(data);
    setAgendaViewMode('day');
    scrollAgendaCalendarIntoView();
    caricaTutto({
        reloadDoctorPanels: targetDotId > 0 && targetDotId !== currentDotId
    });
}

function loadAgendaPatientAppointments(idPaziente) {
    var patientId = parseInt(idPaziente || 0, 10) || 0;
    var idDot = $('#id_dot').val();

    if (patientId <= 0) {
        clearAgendaPatientSearchSelection({
            keepInput: true
        });
        return;
    }

    if (agendaPatientAppointmentsXhr && agendaPatientAppointmentsXhr.readyState !== 4) {
        agendaPatientAppointmentsXhr.abort();
    }

    $('#agendaPatientAppointmentsTitle').text('Caricamento appuntamenti...');
    $('#btnClearAgendaPatientSearch').show();
    $('#agendaPatientAppointmentsList').html(
        '<div class="agenda-patient-history-empty">Sto caricando gli appuntamenti passati e futuri del paziente selezionato.</div>'
    );

    agendaPatientAppointmentsXhr = $.get("<?= base_url('agenda/appuntamenti-paziente') ?>", {
        id_dot: idDot,
        id_paziente: patientId
    }, function(res) {
        if (!res || !res.status) {
            setAgendaPatientHistoryPlaceholder((res && res.message) ? res.message : 'Impossibile caricare gli appuntamenti del paziente.');
            return;
        }

        renderAgendaPatientAppointments(res.patient || {}, res.rows || []);
    }, 'json').fail(function(xhr, textStatus) {
        if (textStatus === 'abort') {
            return;
        }

        var message = 'Impossibile caricare gli appuntamenti del paziente.';
        if (xhr && xhr.responseJSON && xhr.responseJSON.message) {
            message = xhr.responseJSON.message;
        }

        setAgendaPatientHistoryPlaceholder(message);
    });
}

function cercaPazientiAgenda(term) {
    var idDot = $('#id_dot').val();

    if (agendaPatientSearchTimer) {
        clearTimeout(agendaPatientSearchTimer);
    }

    if (agendaPatientSearchXhr && agendaPatientSearchXhr.readyState !== 4) {
        agendaPatientSearchXhr.abort();
    }

    if (!shouldRunPatientAutocomplete(term)) {
        $('#agendaPatientAutocomplete').addClass('d-none').html('');
        return;
    }

    agendaPatientSearchTimer = setTimeout(function() {
        agendaPatientSearchXhr = $.get("<?= base_url('agenda/cerca-pazienti') ?>", {
            id_dot: idDot,
            term: term
        }, function(res) {
            var html = '';

            if (!res.status || !res.rows || !res.rows.length) {
                $('#agendaPatientAutocomplete')
                    .removeClass('d-none')
                    .html('<div class="agenda-patient-search-item">Nessun paziente trovato</div>');
                return;
            }

            $.each(res.rows, function(_, row) {
                var fullName = $.trim(((row.cognome || '') + ' ' + (row.nome || '')).replace(/\s+/g, ' '));
                var secondary = $.trim(row.paz_spec || '');
                if (secondary === '') {
                    secondary = $.trim((row.cellulare || row.telefono || row.email || ''));
                }

                html += '<div class="agenda-patient-search-item' + (isAgendaSpecialPatient(row, row.paz_spec || '') ? ' is-special' : '') + '" ' +
                    'data-id="' + escapeHtml(row.id_paziente || '') + '" ' +
                    'data-cognome="' + escapeHtml(row.cognome || '') + '" ' +
                    'data-nome="' + escapeHtml(row.nome || '') + '">' +
                    '<strong>' + escapeHtml(fullName) + '</strong>' +
                    (secondary !== '' ? '<div class="small text-muted">' + escapeHtml(secondary) + '</div>' : '') +
                    '</div>';
            });

            $('#agendaPatientAutocomplete').removeClass('d-none').html(html);
        }, 'json').fail(function(xhr, textStatus) {
            if (textStatus === 'abort') {
                return;
            }

            var message = (xhr && xhr.responseJSON && xhr.responseJSON.message)
                ? xhr.responseJSON.message
                : 'Errore durante la ricerca';

            $('#agendaPatientAutocomplete')
                .removeClass('d-none')
                .html('<div class="agenda-patient-search-item">' + escapeHtml(message) + '</div>');
        });
    }, 220);
}

function cercaPazientiAutocompleteNote(term) {
    var idDot = getNoteTargetDoctorId();

    if (notePatientAutocompleteTimer) {
        clearTimeout(notePatientAutocompleteTimer);
    }

    if (notePatientAutocompleteXhr && notePatientAutocompleteXhr.readyState !== 4) {
        notePatientAutocompleteXhr.abort();
    }

    if (!shouldRunPatientAutocomplete(term)) {
        $('#notePatientAutocomplete').addClass('d-none').html('');
        return;
    }

    notePatientAutocompleteTimer = setTimeout(function() {
        notePatientAutocompleteXhr = $.get("<?= base_url('agenda/cerca-pazienti') ?>", {
            id_dot: idDot,
            term: term,
            memo_scope: isSharedMemoManagementEnabled() ? 1 : 0
        }, function(res) {
            var html = '';

            if (!res.status || !res.rows || !res.rows.length) {
                $('#notePatientAutocomplete')
                    .removeClass('d-none')
                    .html('<div class="note-autocomplete-item-empty">Nessun paziente trovato</div>');
                return;
            }

        $.each(res.rows, function(i, row) {
            var isSpecialPatient = isAgendaSpecialPatient(row, row.paz_spec || '');
            var nominativo = $.trim((row.cognome || '') + ' ' + (row.nome || ''));

            html += ''
                + '<div class="agenda-autocomplete-item note-patient-item' + (isSpecialPatient ? ' is-special' : '') + '"'
                + ' data-id="' + escapeHtml(row.id_paziente || '') + '"'
                + ' data-cliente="' + escapeHtml(nominativo) + '"'
                + ' data-telefono="' + escapeHtml(row.telefono || '') + '"'
                    + ' data-cellulare="' + escapeHtml(row.cellulare || '') + '"'
                    + ' data-indirizzo="' + escapeHtml(row.indirizzo || '') + '"'
                    + ' data-citta="' + escapeHtml(row.citta || '') + '">'
                    + '<strong>' + escapeHtml(nominativo) + '</strong>'
                    + '</div>';
            });

            $('#notePatientAutocomplete').removeClass('d-none').html(html);
        }, 'json').fail(function(xhr, textStatus) {
            if (textStatus === 'abort') {
                return;
            }

            var message = (xhr && xhr.responseJSON && xhr.responseJSON.message)
                ? xhr.responseJSON.message
                : 'Errore durante la ricerca';

            $('#notePatientAutocomplete')
                .removeClass('d-none')
                .html('<div class="note-autocomplete-item-empty">' + escapeHtml(message) + '</div>');
        });
    }, 220);
}

function apriModificaNota(idNota) {
    if (!isSharedMemoManagementEnabled() && isMemoActionBlocked()) {
        return;
    }

    resetNoteModal();

    $.get("<?= base_url('agenda/get-nota') ?>", {
        id_nota: idNota
    }, function(res) {
        if (!res.status || !res.row) {
            alert(res.message || 'Nota non trovata');
            return;
        }

        var row = res.row;

        if (isMemoActionBlockedForNoteRow(row)) {
            alert('Il giorno selezionato e bloccato per le memo del dottore assegnato a questa nota.');
            renderNoteLaterali();
            return;
        }

        $('#nota_id_nota').val(row.id_nota || '');
        syncNoteTargetDoctor(row.id_dot || $('#id_dot').val());
        $('#nota_id_paziente').val(row.id_paziente || '');
        $('#nota_data_inizio_validita').val(row.data_inizio_validita || '');
        $('#nota_cliente').val(row.cliente || '');
        $('#nota_telefono').val(row.telefono || '');
        $('#nota_cellulare').val(row.cellulare || '');
        $('#nota_indirizzo').val(row.indirizzo || '');
        $('#nota_citta').val(row.citta || '');
        $('#nota_note').val(row.note || '');
        $('#nota_fatta').prop('checked', parseInt(row.fatta || 0, 10) === 1);

        $('#noteModalTitle').text('Modifica nota');
        $('#btnDeleteNote').show();
        $('#noteModal').modal('show');
    }, 'json').fail(function(xhr) {
        var message = 'Errore nel caricamento della nota';

        if (xhr && xhr.responseJSON && xhr.responseJSON.message) {
            message = xhr.responseJSON.message;
        }

        alert(message);
    });
}

function salvaNotaCompleta() {
    if (!isSharedMemoManagementEnabled() && isMemoActionBlocked()) {
        return;
    }

    $.post("<?= base_url('agenda/salva-nota') ?>", {
        id_nota: $('#nota_id_nota').val(),
        id_dot: getNoteTargetDoctorId(),
        agenda_data: $('#agenda_date').val(),
        id_paziente: $('#nota_id_paziente').val(),
        data_inizio_validita: $('#nota_data_inizio_validita').val(),
        cliente: $('#nota_cliente').val(),
        telefono: $('#nota_telefono').val(),
        cellulare: $('#nota_cellulare').val(),
        indirizzo: $('#nota_indirizzo').val(),
        citta: $('#nota_citta').val(),
        note: $('#nota_note').val(),
        fatta: $('#nota_fatta').is(':checked') ? 1 : 0
    }, function(res) {
        alert(res.message || 'Operazione completata');

        if (res.status) {
            $('#noteModal').modal('hide');
            resetNoteModal();
            renderNoteLaterali();
        }
    }, 'json');
}

function eliminaNotaCompleta(idNota) {
    if ((!isSharedMemoManagementEnabled() && isMemoActionBlocked()) || !idNota) {
        return;
    }

    if (!confirm('Vuoi eliminare questa nota?')) {
        return;
    }

    $.post("<?= base_url('agenda/elimina-nota') ?>", {
        id_nota: idNota,
        agenda_data: $('#agenda_date').val()
    }, function(res) {
        if (!res.status) {
            alert(res.message || 'Errore eliminazione nota');
            return;
        }

        $('#noteModal').modal('hide');
        resetNoteModal();
        renderNoteLaterali();
    }, 'json');
}

function cercaPazientiAutocomplete(term) {
    var idDot = $('#id_dot').val();

    if (isAppointmentPersonalCommitmentMode()) {
        $('#patientAutocomplete').addClass('d-none').html('');
        return;
    }

    if (patientAutocompleteTimer) {
        clearTimeout(patientAutocompleteTimer);
    }

    if (patientAutocompleteXhr && patientAutocompleteXhr.readyState !== 4) {
        patientAutocompleteXhr.abort();
    }

    if (!shouldRunPatientAutocomplete(term)) {
        $('#patientAutocomplete').addClass('d-none').html('');
        return;
    }

    patientAutocompleteTimer = setTimeout(function() {
        patientAutocompleteXhr = $.get("<?= base_url('agenda/cerca-pazienti') ?>", {
            id_dot: idDot,
            term: term
        }, function(res) {
            var html = '';

            if (!res.status || !res.rows || !res.rows.length) {
                $('#patientAutocomplete')
                    .removeClass('d-none')
                    .html('<div class="agenda-autocomplete-item">Nessun paziente trovato</div>');
                return;
            }

        $.each(res.rows, function(i, row) {
            var isSpecialPatient = isAgendaSpecialPatient(row, row.paz_spec || '');
            html += '<div class="agenda-autocomplete-item' + (isSpecialPatient ? ' is-special' : '') + '" ' +
                'data-id="' + escapeHtml(row.id_paziente || '') + '" ' +
                'data-cognome="' + escapeHtml(row.cognome || '') + '" ' +
                'data-nome="' + escapeHtml(row.nome || '') + '" ' +
                    'data-paz-spec="' + escapeHtml(row.paz_spec || '') + '" ' +
                    'data-telefono="' + escapeHtml(row.telefono || '') + '" ' +
                    'data-cellulare="' + escapeHtml(row.cellulare || '') + '" ' +
                    'data-reminder-sms-enabled="' + escapeHtml(row.appointment_reminder_sms_enabled || 0) + '" ' +
                    'data-email="' + escapeHtml(row.email || '') + '">' +
                    '<strong>' + escapeHtml((row.cognome || '') + ' ' + (row.nome || '')) + '</strong>' +
                    '</div>';
            });

            $('#patientAutocomplete').removeClass('d-none').html(html);
        }, 'json').fail(function(xhr, textStatus) {
            if (textStatus === 'abort') {
                return;
            }

            var message = (xhr && xhr.responseJSON && xhr.responseJSON.message)
                ? xhr.responseJSON.message
                : 'Errore durante la ricerca';

            $('#patientAutocomplete')
                .removeClass('d-none')
                .html('<div class="agenda-autocomplete-item">' + escapeHtml(message) + '</div>');
        });
    }, 220);
}

function cercaPazientiAutocompleteVisite(term) {
    if (!window.AGENDA_CONFIG.domiciliariAbilitati) {
        return;
    }

    var idDot = $('#id_dot').val();

    if (vdAutocompleteTimer) {
        clearTimeout(vdAutocompleteTimer);
    }

    if (!shouldRunPatientAutocomplete(term)) {
        $('#vd_search_results').addClass('d-none').html('');
        return;
    }

    vdAutocompleteTimer = setTimeout(function() {
        $.get("<?= base_url('agenda/cerca-pazienti') ?>", {
            id_dot: idDot,
            term: term
        }, function(res) {
            var html = '';

            if (!res.status || !res.rows || !res.rows.length) {
                $('#vd_search_results')
                    .removeClass('d-none')
                    .html('<div class="agenda-autocomplete-vd-item">Nessun paziente trovato</div>');
                return;
            }

            $.each(res.rows, function(i, row) {
                var isSpecialPatient = isAgendaSpecialPatient(row, row.paz_spec || '');
                html += '<div class="agenda-autocomplete-vd-item' + (isSpecialPatient ? ' is-special' : '') + '" ' +
                    'data-id="' + escapeHtml(row.id_paziente || '') + '" ' +
                    'data-cognome="' + escapeHtml(row.cognome || '') + '" ' +
                    'data-nome="' + escapeHtml(row.nome || '') + '" ' +
                    'data-telefono="' + escapeHtml(row.telefono || '') + '" ' +
                    'data-cellulare="' + escapeHtml(row.cellulare || '') + '" ' +
                    'data-indirizzo="' + escapeHtml(row.indirizzo || '') + '" ' +
                    'data-citta="' + escapeHtml(row.citta || '') + '">' +
                    '<strong>' + escapeHtml((row.cognome || '') + ' ' + (row.nome || '')) + '</strong>' +
                    '</div>';
            });

            $('#vd_search_results').removeClass('d-none').html(html);
        }, 'json').fail(function(xhr, textStatus) {
            if (textStatus === 'abort') {
                return;
            }

            var message = (xhr && xhr.responseJSON && xhr.responseJSON.message)
                ? xhr.responseJSON.message
                : 'Errore durante la ricerca';

            $('#vd_search_results')
                .removeClass('d-none')
                .html('<div class="agenda-autocomplete-vd-item">' + escapeHtml(message) + '</div>');
        });
    }, 250);
}

function resetFormVisita() {
    if (!window.AGENDA_CONFIG.domiciliariAbilitati) {
        return;
    }

    $('#vd_id_visita').val('');
    $('#vd_id_paziente').val('');
    $('#vd_id_dot').val($('#id_dot').val());
    $('#vd_search_paziente').val('');
    $('#vd_cognome').val('');
    $('#vd_nome').val('');
    $('#vd_telefono').val('');
    $('#vd_cellulare').val('');
    $('#vd_indirizzo').val('');
    $('#vd_citta').val('');
    $('#vd_note').val('');
    $('#vd_search_results').addClass('d-none').html('');

    $('#btnSalvaVisita').show();
    $('#btnAggiornaVisita').hide();
    $('#btnEliminaVisita').hide();
}

function apriNuovaVisita() {
    if (!window.AGENDA_CONFIG.domiciliariAbilitati || isDomiciliariActionBlocked()) {
        return;
    }

    resetFormVisita();
    $('#modalVisitaDomiciliare .modal-title').text('Nuova visita domiciliare');
    $('#modalVisitaDomiciliare').modal('show');
}

function apriModificaVisita(idVisita) {
    if (!window.AGENDA_CONFIG.domiciliariAbilitati || isDomiciliariActionBlocked()) {
        return;
    }

    resetFormVisita();

    $.get("<?= base_url('visite-domiciliari/dettaglio') ?>/" + idVisita, function(res) {
        if (!res.status || !res.row) {
            alert('Visita non trovata');
            return;
        }

        var row = res.row;

        $('#vd_id_visita').val(row.id_visita || '');
        $('#vd_id_paziente').val(row.id_paziente || '');
        $('#vd_id_dot').val($('#id_dot').val());
        $('#vd_search_paziente').val($.trim((row.cognome || '') + ' ' + (row.nome || '')));
        $('#vd_cognome').val(row.cognome || '');
        $('#vd_nome').val(row.nome || '');
        $('#vd_telefono').val(row.telefono || '');
        $('#vd_cellulare').val(row.cellulare || '');
        $('#vd_indirizzo').val(row.indirizzo || '');
        $('#vd_citta').val(row.citta || '');
        $('#vd_note').val(row.note || '');

        $('#btnSalvaVisita').hide();
        $('#btnAggiornaVisita').show();
        $('#btnEliminaVisita').show();

        $('#modalVisitaDomiciliare .modal-title').text('Modifica visita domiciliare');
        $('#modalVisitaDomiciliare').modal('show');
    }, 'json');
}

function confermaInserimentoVisitaDomiciliare() {
    var agendaDateValue = $.trim($('#agenda_date').val() || '');
    var agendaDate = moment(agendaDateValue, 'YYYY-MM-DD', true);

    if (!agendaDate.isValid()) {
        return true;
    }

    var now = moment();
    var oggi = now.clone().startOf('day');
    var dataVisita = agendaDate.clone().startOf('day');

    if (dataVisita.isSame(oggi, 'day')) {
        var limiteOggi = oggi.clone().hour(10).minute(0).second(0).millisecond(0);

        if (!now.isAfter(limiteOggi)) {
            return true;
        }

        if (!window.confirm('Attenzione: stai inserendo una visita domiciliare per oggi dopo le 10. Vuoi continuare?')) {
            return false;
        }

        return window.confirm('Confermi comunque l\\\'inserimento della visita domiciliare, viste le disposizioni legali previste?');
    }

    if (dataVisita.isAfter(oggi, 'day')) {
        if (!window.confirm('Attenzione: stai inserendo una visita domiciliare per un giorno successivo a oggi. Vuoi continuare?')) {
            return false;
        }

        return window.confirm('Confermi comunque l\\\'inserimento della visita domiciliare, viste le disposizioni legali previste?');
    }

    return true;
}

function salvaVisitaDomiciliare() {
    if (!window.AGENDA_CONFIG.domiciliariAbilitati || isDomiciliariActionBlocked()) {
        return;
    }

    if (!confermaInserimentoVisitaDomiciliare()) {
        return;
    }

    $.post("<?= base_url('visite-domiciliari/salva') ?>", {
        id_dot: $('#id_dot').val(),
        data_agenda: $('#agenda_date').val(),
        id_paziente: $('#vd_id_paziente').val(),
        cognome: $('#vd_cognome').val(),
        nome: $('#vd_nome').val(),
        telefono: $('#vd_telefono').val(),
        cellulare: $('#vd_cellulare').val(),
        indirizzo: $('#vd_indirizzo').val(),
        citta: $('#vd_citta').val(),
        note: $('#vd_note').val()
    }, function(res) {
        alert(res.message || 'Operazione completata');

        if (res.status) {
            $('#modalVisitaDomiciliare').modal('hide');
            resetFormVisita();
            renderDomiciliariLaterali();
        }
    }, 'json');
}

function aggiornaVisitaDomiciliare() {
    if (!window.AGENDA_CONFIG.domiciliariAbilitati || isDomiciliariActionBlocked()) {
        return;
    }

    $.post("<?= base_url('visite-domiciliari/aggiorna') ?>", {
        id_visita: $('#vd_id_visita').val(),
        id_dot: $('#id_dot').val(),
        data_agenda: $('#agenda_date').val(),
        id_paziente: $('#vd_id_paziente').val(),
        cognome: $('#vd_cognome').val(),
        nome: $('#vd_nome').val(),
        telefono: $('#vd_telefono').val(),
        cellulare: $('#vd_cellulare').val(),
        indirizzo: $('#vd_indirizzo').val(),
        citta: $('#vd_citta').val(),
        note: $('#vd_note').val()
    }, function(res) {
        alert(res.message || 'Operazione completata');

        if (res.status) {
            $('#modalVisitaDomiciliare').modal('hide');
            resetFormVisita();
            renderDomiciliariLaterali();
        }
    }, 'json');
}

function eliminaVisitaDomiciliare(idVisita) {
    if (!window.AGENDA_CONFIG.domiciliariAbilitati || isDomiciliariActionBlocked() || !idVisita) {
        return;
    }

    if (!confirm('Vuoi eliminare questa visita domiciliare?')) {
        return;
    }

    $.post("<?= base_url('visite-domiciliari/elimina') ?>", {
        id_visita: idVisita,
        data_agenda: $('#agenda_date').val()
    }, function(res) {
        alert(res.message || 'Operazione completata');

        if (res.status) {
            $('#modalVisitaDomiciliare').modal('hide');
            resetFormVisita();
            renderDomiciliariLaterali();
        }
    }, 'json');
}

function caricaTutto(options) {
    options = options || {};

    var filtri = leggiFiltriAgenda();
    var showCalendarLoader = options.showCalendarLoader !== false;
    var reloadDoctorPanels = options.reloadDoctorPanels === true;
    var calendarLoadingMessage = filtri.view === 'team_day'
        ? 'Sto aggiornando la vista giornaliera del team.'
        : 'Sto aggiornando il calendario del professionista selezionato.';

    if (showCalendarLoader) {
        setAgendaCalendarLoading(true, calendarLoadingMessage);
    }

    sincronizzaVistaCalendario(filtri.view, filtri.data);
    caricaDisponibilitaMiniCalendario({
        alignToSelectedDate: true
    });
    caricaSlotCalendario({
        showLoader: showCalendarLoader
    });
    caricaNotaGiorno();

    if (window.AGENDA_CONFIG.domiciliariAbilitati) {
        renderDomiciliariLaterali();
    }

    if (reloadDoctorPanels) {
        renderNoteLaterali();
    }
}

$(function () {
    moment.locale('it');
    syncAgendaTeamDayToolbar();
    syncAgendaCompressedDaybar();
    setAgendaSidebarCollapsed(!!window.AGENDA_CONFIG.compressedLayoutEnabled, { realign: false });

    window.addEventListener('pagehide', function() {
        inviaUnlockBeaconSePresente();
    });

    window.addEventListener('beforeunload', function() {
        inviaUnlockBeaconSePresente();
    });

    $('#nota_giorno_text').on('input', function() {
    notaGiornoDirty = true;
    $('#nota_giorno_status').removeClass('text-danger text-success')
        .addClass('text-muted')
        .text('Modificata');
});

$('#nota_giorno_text').on('blur', function() {
    if (notaGiornoDirty) {
        salvaNotaGiorno();
    }
});
    $('#appointmentModal').modal({
        backdrop: 'static',
        keyboard: false,
        show: false
    });

    $('#appointmentModal').on('shown.bs.modal', function() {
        refreshAppointmentDocumentWorkflowState();
        if (!appointmentSearchFocusRequested) {
            return;
        }

        appointmentSearchFocusRequested = false;
        focusAppointmentPatientSearch();
    });

    $('#appointmentModal').on('hidden.bs.modal', function() {
        appointmentSearchFocusRequested = false;
    });

    $('#noteModal').modal({
        backdrop: 'static',
        keyboard: false,
        show: false
    });

    if (supportsAgendaVisitTypes()) {
        agendaVisitTypes = normalizeAgendaVisitTypesRows(agendaVisitTypes);
        renderAgendaVisitTypeColorPalette();
        renderAgendaVisitTypesBox();
        resetAgendaVisitTypeForm();
    }

    <?php if (!empty($domiciliariAbilitati)): ?>
    $('#modalVisitaDomiciliare').modal({
        backdrop: 'static',
        keyboard: false,
        show: false
    });
    <?php endif; ?>

    $(document).on('click', '.fc-time-grid .fc-slats td, .fc-time-grid .fc-content-skeleton td', function(e) {
        if (giornoBloccato) {
            e.preventDefault();
            e.stopPropagation();
            return false;
        }
    });

    $('#btnOpenNoteModal, #btnOpenNoteModalTop').on('click', function(e) {
        if (!isSharedMemoManagementEnabled() && isMemoActionBlocked()) {
            e.preventDefault();
            return false;
        }
        apriNuovaNota();
    });

    $(document).on('click', '#btnNuovaVisita', function(e) {
        if (isDomiciliariActionBlocked()) {
            e.preventDefault();
            return false;
        }
        apriNuovaVisita();
    });

    $(document).on('click', '.js-agenda-team-free-slot', function() {
        var slotKey = String($(this).attr('data-slot-key') || '');
        var slotId = String($(this).data('slot-id') || '');
        var slot = slotKey !== ''
            ? agendaTeamSlotIndex[slotKey]
            : agendaTeamSlotIndex[slotId];

        if (!slot) {
            return;
        }

        apriSlotLiberoDaSlot(slot);
    });

    $(document).on('click', '.js-agenda-team-booked-slot', function(e) {
        e.preventDefault();
        e.stopPropagation();

        var slotKey = String($(this).attr('data-slot-key') || '');
        var slotId = String($(this).attr('data-slot-id') || '');
        var slot = agendaTeamSlotIndex[slotKey] || agendaTeamSlotIndex[slotId];
        if (!slot) {
            return false;
        }

        riempiModaleDaEvento(getAgendaPrimaryCoveredSlot(slot, agendaTeamAllSlots));
        $('#appointmentModal').modal('show');
        return false;
    });

    $('#btnSaveVisitType').on('click', function() {
        if (!supportsAgendaVisitTypes()) {
            return;
        }

        var nome = $.trim($('#visitTypeName').val() || '');
        var durata = parseInt($('#visitTypeDuration').val() || 0, 10) || 0;
        var colore = normalizeAgendaVisitTypeColor($('#visitTypeColor').val());

        if (nome === '') {
            alert('Inserisci il nome del tipo visita.');
            $('#visitTypeName').trigger('focus');
            return;
        }

        if (durata <= 0) {
            alert('Inserisci una durata valida in minuti.');
            $('#visitTypeDuration').trigger('focus');
            return;
        }

        if (colore === '') {
            alert('Seleziona un colore valido per il tipo visita.');
            return;
        }

        $.post("<?= base_url('agenda/salva-tipo-visita') ?>", {
            id_tipo_visita: $('#visitTypeId').val(),
            nome: nome,
            durata_minuti: durata,
            colore: colore,
            usa_colore_tipo_visita_slot: getAgendaVisitTypeUseOwnColorInputValue(),
            attivo: 1
        }, function(res) {
            if (!res || !res.status) {
                alert((res && res.message) ? res.message : 'Errore durante il salvataggio del tipo visita.');
                return;
            }

            agendaVisitTypes = normalizeAgendaVisitTypesRows(res.rows || []);
            renderAgendaVisitTypesBox();
            resetAgendaVisitTypeForm();
            fillAppointmentVisitTypeSelect($('#app_id_tipo_visita').val() || '');
            refreshAppointmentVisitTypePreview();
            caricaSlotCalendario({
                showLoader: false
            });
            showAgendaToast(res.message || 'Tipo visita salvato correttamente.', 'success');
        }, 'json').fail(function(xhr) {
            var message = 'Errore durante il salvataggio del tipo visita.';
            if (xhr && xhr.responseJSON && xhr.responseJSON.message) {
                message = xhr.responseJSON.message;
            }
            alert(message);
        });
    });

    $('#btnCancelVisitTypeEdit').on('click', function() {
        resetAgendaVisitTypeForm();
    });

    $('#visitTypeColorPalette').on('click', '.agenda-visit-type-color-swatch', function() {
        setAgendaVisitTypeColor($(this).data('color') || '');
    });

    $('#visitTypeColorCustom').on('input change', function() {
        setAgendaVisitTypeColor($(this).val() || '');
    });

    $(document).on('click', '.js-edit-visit-type', function() {
        var row = getAgendaVisitTypeById($(this).data('id'));
        if (!row) {
            return;
        }

        populateAgendaVisitTypeForm(row);
    });

    $(document).on('click', '.js-toggle-visit-type', function() {
        if (!supportsAgendaVisitTypes()) {
            return;
        }

        var idTipoVisita = parseInt($(this).data('id') || 0, 10) || 0;
        var nextActive = parseInt($(this).data('active') || 0, 10) || 0;
        if (idTipoVisita <= 0) {
            return;
        }

        $.post("<?= base_url('agenda/toggle-tipo-visita') ?>", {
            id_tipo_visita: idTipoVisita,
            attivo: nextActive
        }, function(res) {
            if (!res || !res.status) {
                alert((res && res.message) ? res.message : 'Errore durante l\'aggiornamento del tipo visita.');
                return;
            }

            agendaVisitTypes = normalizeAgendaVisitTypesRows(res.rows || []);
            renderAgendaVisitTypesBox();
            fillAppointmentVisitTypeSelect($('#app_id_tipo_visita').val() || '');
            refreshAppointmentVisitTypePreview();
            caricaSlotCalendario({
                showLoader: false
            });
            showAgendaToast(res.message || 'Tipo visita aggiornato correttamente.', 'success');
        }, 'json').fail(function(xhr) {
            var message = 'Errore durante l\'aggiornamento del tipo visita.';
            if (xhr && xhr.responseJSON && xhr.responseJSON.message) {
                message = xhr.responseJSON.message;
            }
            alert(message);
        });
    });

    $('#btnDeleteAppointment').on('click', function() {
        var idApp = $('#app_id_appuntamento').val();

        if (!idApp) {
            alert('ID appuntamento non trovato.');
            return;
        }

        if (!confirm('Vuoi eliminare questo appuntamento?')) {
            return;
        }

        $.post("<?= base_url('agenda/elimina-appuntamento') ?>", {
            id_appuntamento: idApp
        }, function(res) {
            alert(res.message || 'Operazione completata');

            if (res.status) {
                $('#appointmentModal').modal('hide');
                resetAppointmentModal();
                caricaTutto();
            }
        }, 'json');
    });

    $('#btnAppointmentBillingWorkflow').on('click', function() {
        submitAppointmentDocumentWorkflow('billing');
    });

    $('#btnAppointmentTsWorkflow').on('click', function() {
        submitAppointmentDocumentWorkflow('ts');
    });

    $('#btnDeleteExtraSlot').on('click', function() {
        if (!isAppointmentExtraSlot()) {
            return;
        }

        var hasAppointment = $.trim($('#app_id_appuntamento').val() || '') !== '';
        var confirmMessage = hasAppointment
            ? 'Vuoi eliminare completamente questo slot extra? Se ci sono appuntamenti collegati verranno eliminati insieme allo slot.'
            : 'Vuoi eliminare completamente questo slot extra?';

        if (!confirm(confirmMessage)) {
            return;
        }

        deleteCurrentExtraSlot(false);
    });

    setAgendaViewMode($('#view_mode').val());
    toggleAgendaCalendarShells($('#view_mode').val());
    inizializzaCalendario(15, '08:00:00', '18:00:00');
    caricaTutto({ reloadDoctorPanels: true });

    $(window).on('resize.agendaCalendar', function() {
        riallineaRenderingCalendario();
    });

    $('#calendar').on('click', '.fc-prev-button, .fc-next-button, .fc-today-button, .fc-agendaDay-button, .fc-agendaWeek-button', function() {
        setTimeout(syncCalendarDateAndReload, 0);
    });

    $('#id_dot').on('change', function() {
        var url = "<?= base_url('agenda') ?>?id_dot=" + encodeURIComponent($('#id_dot').val()) +
                  "&data=" + encodeURIComponent($('#agenda_date').val()) +
                  "&view=" + encodeURIComponent($('#view_mode').val());

        window.location.href = url;
    });

    $('#btnToggleAgendaSidebar').on('click', function() {
        toggleAgendaSidebar();
    });

    $('.agenda-view-btn').on('click', function() {
        var nextView = normalizeAgendaViewModeValue($(this).data('view-mode'));
        if (nextView === normalizeAgendaViewModeValue($('#view_mode').val())) {
            return;
        }

        setAgendaViewMode(nextView);
        $('#view_mode').trigger('change');
    });

    $('#agenda_date, #view_mode').on('change', function() {
        syncAgendaViewButtons();
        setTimeout(caricaTutto, 0);
    });

    $('#btnReloadAgenda').on('click', function() {
        setTimeout(function() {
            caricaTutto({ reloadDoctorPanels: true });
        }, 0);
    });

    $('#agendaMiniCalendarPrevMonth').on('click', function() {
        cambiaMeseMiniCalendario(-1);
    });

    $('#agendaMiniCalendarNextMonth').on('click', function() {
        cambiaMeseMiniCalendario(1);
    });

    $('#agendaMiniCalendarGrid').on('click', '.agenda-mini-calendar-day', function() {
        var dateValue = $.trim($(this).data('date') || '');
        if (dateValue === '') {
            return;
        }

        $('#agenda_date').val(dateValue);
        setTimeout(caricaTutto, 0);
    });

    $('#btnToday').on('click', function() {
        if (isTeamDayViewActive()) {
            navigateAgendaToday();
            return;
        }

        if ($('#calendar').data('fullCalendar')) {
            $('#calendar').fullCalendar('today');
            setTimeout(syncCalendarDateAndReload, 0);
            return;
        }

        var oggi = moment().format('YYYY-MM-DD');
        $('#agenda_date').val(oggi);
        caricaTutto();
    });

    $('#btnPrevDay').on('click', function() {
        navigateAgendaSelectedDay(-1);
    });

    $('#btnNextDay').on('click', function() {
        navigateAgendaSelectedDay(1);
    });

    $('#btnCompressedToday').on('click', function() {
        $('#btnToday').trigger('click');
    });

    $('#btnCompressedPrevDay').on('click', function() {
        $('#btnPrevDay').trigger('click');
    });

    $('#btnCompressedNextDay').on('click', function() {
        $('#btnNextDay').trigger('click');
    });

    $('#btnTeamDayToday').on('click', function() {
        navigateAgendaToday();
    });

    $('#btnTeamDayPrev').on('click', function() {
        navigateAgendaSelectedDay(-1);
    });

    $('#btnTeamDayNext').on('click', function() {
        navigateAgendaSelectedDay(1);
    });

    $('#btnSaveNote').on('click', function() {
        salvaNotaCompleta();
    });

    extraSlotSaveDefaultHtml = $('#btnSaveExtraSlotModal').html();

    $('.btn-close-note-modal').on('click', function() {
        $('#noteModal').modal('hide');
        resetNoteModal();
    });

    $('#nota_doctor_select').on('change', function() {
        syncNoteTargetDoctor($(this).val());
        $('#nota_id_paziente').val('');
        $('#notePatientAutocomplete').addClass('d-none').html('');
    });

    $('.btn-close-extra-slot-modal').on('click', function() {
        if (extraSlotSaveXhr && extraSlotSaveXhr.readyState !== 4) {
            return;
        }

        $('#extraSlotModal').modal('hide');
        resetExtraSlotModal();
    });

    $('#btnDeleteNote').on('click', function() {
        eliminaNotaCompleta($('#nota_id_nota').val());
    });

    $('#nota_cliente').on('keyup', function() {
        $('#nota_id_paziente').val('');
        cercaPazientiAutocompleteNote($(this).val());
    });

    $(document).on('click', '.note-patient-item', function() {
        var idPaziente = $(this).data('id') || '';

        if (!idPaziente) {
            return;
        }

        $('#nota_id_paziente').val(idPaziente);
        $('#nota_cliente').val($(this).data('cliente') || '');
        $('#nota_telefono').val($(this).data('telefono') || '');
        $('#nota_cellulare').val($(this).data('cellulare') || '');
        $('#nota_indirizzo').val($(this).data('indirizzo') || '');
        $('#nota_citta').val($(this).data('citta') || '');

        $('#notePatientAutocomplete').addClass('d-none').html('');
    });

    $(document).on('click', '.btnEditNote', function() {
        apriModificaNota($(this).data('id'));
    });

    $(document).on('click', '.btnDeleteNoteRow', function() {
        eliminaNotaCompleta($(this).data('id'));
    });

    $(document).on('change', '.chkNotaFatta', function() {
        var $checkbox = $(this);
        var $card = $checkbox.closest('.agenda-note-card');
        var noteBlocked = String($card.data('memo-blocked') || '0') === '1';

        if ((!isSharedMemoManagementEnabled() && isMemoActionBlocked()) || noteBlocked) {
            renderNoteLaterali();
            return false;
        }

        var idNota = $(this).data('id');
        var fatta = $checkbox.is(':checked');

        if (fatta && !window.confirm('Sei sicuro di voler archiviare questa memo? Premi OK per continuare oppure Annulla per lasciarla attiva.')) {
            $checkbox.prop('checked', false);
            return false;
        }

        $checkbox.prop('disabled', true);

        $.post("<?= base_url('agenda/segna-nota-fatta') ?>", {
            id_nota: idNota,
            fatta: fatta ? 1 : 0,
            agenda_data: $('#agenda_date').val()
        }, function(res) {
            if (!res.status) {
                alert(res.message || 'Errore aggiornamento nota');
                renderNoteLaterali();
                return;
            }

            $card.fadeOut(200, function() {
                $(this).remove();

                if ($('#noteList .agenda-note-card').length === 0) {
                    $('#noteList').html('<div class="text-center text-muted" style="padding:20px;">Nessuna nota presente</div>');
                }
            });
        }, 'json').fail(function() {
            alert('Errore aggiornamento nota');
            renderNoteLaterali();
        }).always(function() {
            $checkbox.prop('disabled', false);
        });
    });

    $('#agendaPatientSearch').on('keyup', function(e) {
        if (e.key === 'Escape') {
            clearAgendaPatientSearchSelection();
            return;
        }

        var value = $(this).val();
        var hasSelectedPatient = $.trim($('#agendaPatientSearchIdPaziente').val() || '') !== '';

        if ($.trim(value) === '') {
            clearAgendaPatientSearchSelection();
            return;
        }

        if (hasSelectedPatient) {
            clearAgendaPatientSearchSelection({
                keepInput: true
            });
        }

        cercaPazientiAgenda(value);
    });

    $('#btnClearAgendaPatientSearch').on('click', function() {
        clearAgendaPatientSearchSelection();
        $('#agendaPatientSearch').trigger('focus');
    });

    $(document).on('click', '.agenda-patient-search-item', function() {
        var idPaziente = parseInt($(this).data('id') || 0, 10) || 0;
        var cognome = $.trim($(this).data('cognome') || '');
        var nome = $.trim($(this).data('nome') || '');
        var label = $.trim((cognome + ' ' + nome).replace(/\s+/g, ' '));

        if (idPaziente <= 0) {
            return;
        }

        agendaFocusedAppointmentId = 0;
        agendaFocusedPatientId = idPaziente;
        agendaShouldScrollToFocusedAppointment = false;

        $('#agendaPatientSearch').val(label);
        $('#agendaPatientSearch').data('selected-label', label);
        $('#agendaPatientSearch').data('selected-cognome', cognome);
        $('#agendaPatientSearch').data('selected-nome', nome);
        $('#agendaPatientSearchIdPaziente').val(idPaziente);
        $('#agendaPatientAutocomplete').addClass('d-none').html('');
        loadAgendaPatientAppointments(idPaziente);
    });

    $(document).on('click', '.agenda-patient-history-item', function() {
        jumpToAgendaPatientAppointment($(this));
    });

    $('#searchPatient').on('keyup', function() {
        cercaPazientiAutocomplete($(this).val());
    });

    $('#appointmentModeSwitch').on('click', '[data-appointment-mode]', function() {
        var nextMode = $.trim(String($(this).data('appointment-mode') || ''));
        setAppointmentMode(nextMode);

        if (nextMode === 'personal_commitment') {
            $('#app_personal_commitment_end').trigger('focus');
            return;
        }

        $('#app_cognome').trigger('focus');
    });

    $('#app_id_tipo_visita').on('change', function() {
        refreshAppointmentVisitTypePreview();
    });

    $('#app_custom_time_enabled').on('change', function() {
        $('#appointmentCustomTimeFields').toggle(isAppointmentCustomTimeEnabled());
        refreshAppointmentVisitTypePreview();

        if (isAppointmentCustomTimeEnabled()) {
            $('#app_custom_start_time').trigger('focus');
        }
    });

    $('#app_custom_start_time, #app_custom_end_time').on('input change', function() {
        refreshAppointmentVisitTypePreview();
    });

    $('#app_personal_commitment_end').on('change', function() {
        refreshAppointmentPersonalCommitmentCoverage();
    });

    $('#app_cognome, #app_nome').on('input', function() {
        var normalized = normalizeAppointmentPatientName($(this).val());
        if ($(this).val() !== normalized) {
            $(this).val(normalized);
        }
        renderAppointmentLinkedPatientInfo();
    });

    $('#btnNewAppointmentPatient').on('click', function() {
        setAppointmentLinkedPatient('', '');
        setAppointmentSpecialPatientCode('');
        $('#searchPatient').val('');
        $('#app_cognome').val('');
        $('#app_nome').val('');
        $('#app_telefono').val('');
        $('#app_cellulare').val('');
        $('#app_email').val('');
        setAppointmentReminderSmsPreference(false);
        $('#patientAutocomplete').addClass('d-none').html('');
        $('#app_cognome').trigger('focus');
    });

    $(document).on('click', '.agenda-autocomplete-item', function() {
        var idPaziente = $(this).data('id') || '';
        var cognome = normalizeAppointmentPatientName($(this).data('cognome') || '');
        var nome = normalizeAppointmentPatientName($(this).data('nome') || '');

        if (!idPaziente) {
            return;
        }

        setAppointmentLinkedPatient(idPaziente, getAppointmentPatientLabel(cognome, nome));
        setAppointmentSpecialPatientCode($(this).data('pazSpec') || '');
        $('#app_cognome').val(cognome);
        $('#app_nome').val(nome);
        $('#app_telefono').val($(this).data('telefono') || '');
        $('#app_cellulare').val($(this).data('cellulare') || '');
        $('#app_email').val($(this).data('email') || '');
        setAppointmentReminderSmsPreference(parseInt($(this).data('reminderSmsEnabled') || 0, 10) === 1);
        $('#searchPatient').val(getAppointmentPatientLabel(cognome, nome));

        $('#patientAutocomplete').addClass('d-none').html('');
    });

    $('#btnCancelAppointmentModal').on('click', function() {
        chiudiModalAppuntamentoELiberaSlot();
    });

    $('.btn-close-appointment-modal').on('click', function() {
        chiudiModalAppuntamentoELiberaSlot();
    });

    $('#btnSaveAppointment').on('click', function() {
        if (giornoBloccato) {
            return;
        }

        if (appointmentSaveXhr && appointmentSaveXhr.readyState !== 4) {
            return;
        }

        var isPersonalCommitment = isAppointmentPersonalCommitmentMode();
        var linkedPatientId = $.trim($('#app_id_paziente').val() || '');
        var searchPatientValue = $.trim($('#searchPatient').val() || '');
        var currentPatientLabel = getAppointmentPatientLabel($('#app_cognome').val(), $('#app_nome').val());
        var selectedPatientLabel = $.trim($('#appointmentLinkedPatientInfo').data('patient-label') || '');

        if (
            !isPersonalCommitment &&
            linkedPatientId !== '' &&
            searchPatientValue !== '' &&
            searchPatientValue !== currentPatientLabel &&
            searchPatientValue !== selectedPatientLabel
        ) {
            alert('Per cambiare paziente selezionalo dall\\\'elenco di ricerca oppure usa "Nuovo paziente" prima di salvare.');
            return;
        }

        var isUpdate = $.trim($('#app_id_appuntamento').val() || '') !== '';
        var requestUrl = isUpdate
            ? "<?= base_url('agenda/aggiorna-appuntamento') ?>"
            : "<?= base_url('agenda/salva-appuntamento') ?>";
        var successMessage = isUpdate
            ? 'Appuntamento aggiornato correttamente.'
            : 'Prenotazione confermata correttamente.';
        var coverage = null;

        if (supportsAgendaVisitTypes() || isPersonalCommitment || isAppointmentCustomTimeEnabled()) {
            coverage = refreshAppointmentVisitTypePreview();
            if (!coverage || !coverage.ok) {
                alert((coverage && coverage.message) ? coverage.message : 'La durata selezionata non e compatibile con gli slot disponibili.');
                if (!isPersonalCommitment && isAgendaVisitTypeSelectionRequired() && $.trim($('#app_id_tipo_visita').val() || '') === '') {
                    $('#app_id_tipo_visita').trigger('focus');
                } else if (isPersonalCommitment) {
                    $('#app_personal_commitment_end').trigger('focus');
                } else if (isAppointmentCustomTimeEnabled()) {
                    $('#app_custom_start_time').trigger('focus');
                }
                return;
            }
        }

        setAppointmentSavingState(true);

        var cognome = isPersonalCommitment ? 'Impegno' : normalizeAppointmentPatientName($('#app_cognome').val());
        var nome = isPersonalCommitment ? 'personale' : normalizeAppointmentPatientName($('#app_nome').val());
        $('#app_cognome').val(cognome);
        $('#app_nome').val(nome);

        appointmentSaveXhr = $.ajax({
            url: requestUrl,
            method: 'POST',
            dataType: 'json',
            data: {
                id_appuntamento: $('#app_id_appuntamento').val(),
                id_slot: $('#app_id_slot').val(),
                id_dot: $('#app_id_dot').val(),
                id_paziente: $('#app_id_paziente').val(),
                token_lock: $('#app_token_lock').val(),
                appointment_special_mode: isPersonalCommitment ? 'personal_commitment' : 'standard',
                custom_time_enabled: isAppointmentCustomTimeEnabled() ? 1 : 0,
                custom_start_time: isAppointmentCustomTimeEnabled() ? ($('#app_custom_start_time').val() || '') : '',
                custom_end_time: isAppointmentCustomTimeEnabled() ? ($('#app_custom_end_time').val() || '') : '',
                paz_spec: isPersonalCommitment ? getAgendaPersonalCommitmentSpecialCode() : ($('#app_paz_spec').val() || ''),
                cognome: cognome,
                nome: nome,
                telefono: isPersonalCommitment ? '' : $('#app_telefono').val(),
                cellulare: isPersonalCommitment ? '' : $('#app_cellulare').val(),
                email: isPersonalCommitment ? '' : $('#app_email').val(),
                appointment_reminder_sms_enabled: isPersonalCommitment
                    ? 0
                    : (supportsPatientSmsReminderPreference() && $('#app_appointment_reminder_sms_enabled').is(':checked') ? 1 : 0),
                note: $('#app_note').val(),
                id_tipo_visita: isPersonalCommitment ? '' : $('#app_id_tipo_visita').val(),
                durata_minuti: isPersonalCommitment ? ($('#app_personal_commitment_end').val() || '') : ''
            }
        }).done(function(res) {
            if (!res || !res.status) {
                alert((res && res.message) ? res.message : 'Errore durante il salvataggio della prenotazione.');
                return;
            }

            if (appointmentModalDate) {
                $('#agenda_date').val(appointmentModalDate);
            }

            $('#appointmentModal').modal('hide');
            resetAppointmentModal();
            refreshAgendaAfterAppointmentChange();
            showAgendaToast(res.message || successMessage, 'success');
        }).fail(function(xhr) {
            var message = isUpdate
                ? 'Errore durante l\'aggiornamento dell\'appuntamento.'
                : 'Errore durante il salvataggio della prenotazione.';

            if (xhr && xhr.responseJSON && xhr.responseJSON.message) {
                message = xhr.responseJSON.message;
            }

            alert(message);
        }).always(function() {
            appointmentSaveXhr = null;
            setAppointmentSavingState(false);
        });
    });

    $(document).on('click', '.btnChiudiVisita', function() {
        if (!window.AGENDA_CONFIG.domiciliariAbilitati) {
            return;
        }
        $('#modalVisitaDomiciliare').modal('hide');
        resetFormVisita();
    });

    $(document).on('keyup', '#vd_search_paziente', function() {
        if (!window.AGENDA_CONFIG.domiciliariAbilitati) {
            return;
        }
        $('#vd_id_paziente').val('');
        cercaPazientiAutocompleteVisite($(this).val());
    });

    $(document).on('click', '.agenda-autocomplete-vd-item', function() {
        var idPaziente = $(this).data('id') || '';

        if (!idPaziente) {
            return;
        }

        $('#vd_id_paziente').val(idPaziente);
        $('#vd_search_paziente').val((($(this).data('cognome') || '') + ' ' + ($(this).data('nome') || '')).trim());
        $('#vd_cognome').val($(this).data('cognome') || '');
        $('#vd_nome').val($(this).data('nome') || '');
        $('#vd_telefono').val($(this).data('telefono') || '');
        $('#vd_cellulare').val($(this).data('cellulare') || '');
        $('#vd_indirizzo').val($(this).data('indirizzo') || '');
        $('#vd_citta').val($(this).data('citta') || '');
        $('#vd_search_results').addClass('d-none').html('');
    });

    $(document).on('click', '#btnSalvaVisita', function() {
        salvaVisitaDomiciliare();
    });

    $(document).on('click', '#btnAggiornaVisita', function() {
        aggiornaVisitaDomiciliare();
    });

    $(document).on('click', '#btnEliminaVisita', function() {
        eliminaVisitaDomiciliare($('#vd_id_visita').val());
    });

    $(document).on('click', '.btnModificaVisita', function() {
        apriModificaVisita($(this).data('id'));
    });

    $(document).on('click', '.btnEliminaVisitaRiga', function() {
        eliminaVisitaDomiciliare($(this).data('id'));
    });

    $(document).on('click', function(e) {
        if (!$(e.target).closest('#agendaPatientSearch, #agendaPatientAutocomplete').length) {
            $('#agendaPatientAutocomplete').addClass('d-none').html('');
        }

        if (!$(e.target).closest('#searchPatient, #patientAutocomplete').length) {
            $('#patientAutocomplete').addClass('d-none').html('');
        }

        if (!$(e.target).closest('#nota_cliente, #notePatientAutocomplete').length) {
            $('#notePatientAutocomplete').addClass('d-none').html('');
        }

        if (window.AGENDA_CONFIG.domiciliariAbilitati) {
            if (!$(e.target).closest('#vd_search_paziente, #vd_search_results').length) {
                $('#vd_search_results').addClass('d-none').html('');
            }
        }
    });

    if (window.AGENDA_CONFIG.agendaAutoRefreshFeatureEnabled) {
        setInterval(function() {
            caricaTutto({ showCalendarLoader: false });
        }, 30000);
    }

    $('#btnPrintDayAgenda').on('click', function() {
        var url = "<?= base_url('agenda/stampa-pdf-giorno') ?>?id_dot=" +
            encodeURIComponent($('#id_dot').val()) +
            "&data=" + encodeURIComponent($('#agenda_date').val()) +
            "&view=" + encodeURIComponent($('#view_mode').val());

        window.open(url, '_blank');
    });

    $('#btnPrintMemoPdf').on('click', function() {
        var url = "<?= base_url('agenda/stampa-pdf-memo') ?>?id_dot=" +
            encodeURIComponent($('#id_dot').val());

        window.open(url, '_blank');
    });

    $('#btnBlockDayAgenda').on('click', function() {
        if (!canBloccareGiorno) {
            return;
        }

        var url = giornoBloccato
            ? "<?= base_url('agenda/sblocca-giorno') ?>"
            : "<?= base_url('agenda/blocca-giorno') ?>";

        var messaggio = giornoBloccato
            ? 'Vuoi sbloccare la giornata?'
            : 'Vuoi bloccare tutta lâ€™agenda per questo giorno? Dopo non sarÃ  piÃ¹ possibile inserire appuntamenti.';

        if (!confirm(messaggio)) {
            return;
        }

        $.post(url, {
            id_dot: $('#id_dot').val(),
            data: $('#agenda_date').val()
        }, function(res) {
            alert(res.message || 'Operazione completata');

            if (res.status) {
                caricaTutto();
            }
        }, 'json');
    });

    $('#btnBlockDayDomiciliari').on('click', function() {
        if (!canBloccareGiorno || giornoBloccato) {
            return;
        }

        var url = domiciliareGiornoBloccato
            ? "<?= base_url('agenda/sblocca-domiciliari-giorno') ?>"
            : "<?= base_url('agenda/blocca-domiciliari-giorno') ?>";

        var messaggio = domiciliareGiornoBloccato
            ? 'Vuoi sbloccare le domiciliari per questo giorno?'
            : 'Vuoi bloccare solo le domiciliari per questo giorno? L\'agenda restera libera.';

        if (!confirm(messaggio)) {
            return;
        }

        $.post(url, {
            id_dot: $('#id_dot').val(),
            data: $('#agenda_date').val()
        }, function(res) {
            alert(res.message || 'Operazione completata');

            if (res.status) {
                caricaTutto();
            }
        }, 'json');
    });

    $('#btnAddExtraSlot').on('click', function() {
        if (!shouldUseExtraSlotDoctorSelector() && giornoBloccato) {
            return;
        }

        resetExtraSlotModal();
        $('#extraSlotModal').modal('show');
        window.setTimeout(function() {
            if (shouldUseExtraSlotDoctorSelector()) {
                $('#extra_slot_id_dot').trigger('focus');
                return;
            }

            $('#extra_slot_ora_inizio').trigger('focus');
        }, 200);
    });

    $('#btnCompressedAddExtraSlot').on('click', function() {
        $('#btnAddExtraSlot').trigger('click');
    });

    $('#extra_slot_id_dot').on('change', function() {
        setExtraSlotModalError('');
    });

    $('#btnSaveExtraSlotModal').on('click.extraSlotTeamDayPrepare', function(e) {
        if (!prepareExtraSlotTeamDayDoctorContext()) {
            e.stopImmediatePropagation();
            return false;
        }

        window.setTimeout(function() {
            restoreExtraSlotTeamDayDoctorContext();
        }, 0);

        return true;
    });

    $('#btnSaveExtraSlotModal').on('click', function() {
        if (giornoBloccato) {
            setExtraSlotModalError('La giornata Ã¨ bloccata. Non puoi aggiungere slot extra.');
            return;
        }

        if (extraSlotSaveXhr && extraSlotSaveXhr.readyState !== 4) {
            return;
        }

        var oraInizio = $.trim($('#extra_slot_ora_inizio').val() || '');
        var oraFine = $.trim($('#extra_slot_ora_fine').val() || '');
        var re = /^([01]\d|2[0-3]):([0-5]\d)$/;

        if (!re.test(oraInizio)) {
            setExtraSlotModalError('Formato orario di inizio non valido. Usa HH:mm.');
            return;
        }

        if (!re.test(oraFine)) {
            setExtraSlotModalError('Formato orario di fine non valido. Usa HH:mm.');
            return;
        }

        if (calculateExtraSlotDurationMinutes(oraInizio, oraFine) <= 0) {
            setExtraSlotModalError('L\'ora fine deve essere successiva all\'ora inizio.');
            return;
        }

        setExtraSlotModalError('');
        setExtraSlotSavingState(true);

        extraSlotSaveXhr = $.ajax({
            url: "<?= base_url('agenda/aggiungi-slot-extra') ?>",
            method: 'POST',
            dataType: 'json',
            data: {
            id_dot: $('#id_dot').val(),
            data: $('#agenda_date').val(),
            ora_inizio: oraInizio,
            ora_fine: oraFine
            }
        }).done(function(res) {
            if (!res || !res.status) {
                setExtraSlotModalError((res && res.message) ? res.message : 'Errore durante l\'inserimento dello slot extra.');
                return;
            }

            $('#extraSlotModal').modal('hide');
            resetExtraSlotModal();
            caricaTutto();
            showAgendaToast(res.message || 'Slot extra aggiunto correttamente.', 'success');
        }).fail(function(xhr) {
            var message = 'Errore durante l\'inserimento dello slot extra.';

            if (xhr && xhr.responseJSON && xhr.responseJSON.message) {
                message = xhr.responseJSON.message;
            }

            setExtraSlotModalError(message);
        }).always(function() {
            extraSlotSaveXhr = null;
            setExtraSlotSavingState(false);
        });
    });

    $('#extra_slot_ora_inizio, #extra_slot_ora_fine').on('keydown', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            $('#btnSaveExtraSlotModal').trigger('click');
        }
    });
});
</script>
<script src="<?= base_url('public/js/agenda-menu.js') . $assetVersion('public/js/agenda-menu.js') ?>"></script>
</body>
</html>

