<?php

// Standalone regression: no framework bootstrap, database or real session.
namespace App\Controllers {
    class BaseController
    {
        protected $request;
    }
}

namespace {
    require dirname(__DIR__) . '/app/Services/PatientExcelImportService.php';
    require dirname(__DIR__) . '/app/Controllers/Agenda.php';

    final class TeamVisibilityProbe extends \App\Controllers\Agenda
    {
        public function __construct(private array $visible, private bool $enabled = true)
        {
            $this->request = new class {
                public function getGet(string $key) { return null; }
            };
        }

        protected function getOrderedVisibleDoctorsForCurrentUser(): array { return $this->visible; }
        protected function isTeamDayViewFeatureEnabled(): bool { return $this->enabled; }
        protected function getOrderedAgendaProfessionals(bool $respectTenantToggle = true): array
        {
            throw new \RuntimeException('Team view must never load unauthorized professionals.');
        }
        protected function respondCalendarioJson(array $payload, int $statusCode = 200)
        {
            return ['statusCode' => $statusCode, 'payload' => $payload];
        }
        public function doctors(): array { return $this->getTeamDayDoctorsForCurrentUser(); }
        public function allowed(): bool { return $this->canUseTeamDayView($this->doctors()); }
        public function requestedView(): string { return $this->normalizeAgendaViewMode('team_day', $this->allowed()); }
    }

    function checkTeamVisibility(bool $condition, string $message): void
    {
        if (!$condition) { throw new \RuntimeException($message); }
    }

    foreach ([[], [['id_dot' => 7]]] as $visible) {
        $controller = new TeamVisibilityProbe($visible);
        checkTeamVisibility(!$controller->allowed(), 'Team button must be hidden with fewer than two authorized agendas.');
        checkTeamVisibility($controller->requestedView() === 'day', 'Explicit team view must fall back to day.');
        $response = $controller->calendarioTeamDay();
        checkTeamVisibility($response['statusCode'] === 403, 'Direct team endpoint must deny access.');
        checkTeamVisibility($response['payload']['columns'] === [], 'Denied response must contain no agenda data.');
    }

    $visible = [['id_dot' => 7], ['id_dot' => 12]];
    $controller = new TeamVisibilityProbe($visible);
    checkTeamVisibility($controller->allowed(), 'Multiple authorized agendas must retain team view.');
    checkTeamVisibility($controller->doctors() === $visible, 'Team must contain exactly the authorized agendas in order.');
    checkTeamVisibility($controller->requestedView() === 'team_day', 'Authorized team selection must be preserved.');
    checkTeamVisibility(!(new TeamVisibilityProbe($visible, false))->allowed(), 'Disabled tenant feature must remain disabled.');

    echo "PASS: team visibility, authorized agendas, direct endpoint denial and view fallback\n";
}
