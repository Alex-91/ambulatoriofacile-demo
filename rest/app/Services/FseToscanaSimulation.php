<?php

namespace App\Services;

/**
 * Offline model, not a CART response parser. No transport, credentials or DB dependency.
 * Terminal confirmations below are synthetic test events, NEVER inferred from HTTP 200.
 */
final class FseToscanaSimulation
{
    private string $state = 'draft';
    private ?string $pending = null;
    private ?string $workflow = null;
    private bool $uncertain = false;
    private array $events = [];

    public function prepare(): void { $this->transition(['draft'], 'prepared', 'local_artifacts_checked'); }
    public function sign(): void { $this->transition(['prepared'], 'signed', 'synthetic_signature_checked'); }

    public function begin(string $operation, string $documentId = 'SYNTHETIC.1', ?string $previousId = null): void
    {
        if (!in_array($operation, ['create', 'replace', 'metadata', 'delete'], true)) throw new \RuntimeException('SIM_OPERATION');
        if ($operation === 'replace' && ($previousId === null || $previousId === '' || $previousId === $documentId)) throw new \RuntimeException('SIM_REPLACEMENT_ID');
        $this->transition(in_array($operation, ['create', 'replace'], true) ? ['signed'] : ['published'], 'pending', 'begin_' . $operation);
        $this->pending = $operation; $this->workflow = null; $this->uncertain = false;
    }

    public function reply(string $outcome, ?string $workflow = null): void
    {
        if ($this->state !== 'pending' || $this->uncertain || $this->workflow !== null) throw new \RuntimeException('SIM_REPLY_ORDER');
        if ($outcome === 'uncertain' || ($outcome === 'accepted' && !$workflow)) {
            $this->uncertain = true; $this->events[] = 'uncertain_no_retry'; return;
        }
        if ($outcome === 'rejected') {
            $this->state = in_array($this->pending, ['metadata', 'delete'], true) ? 'published' : 'rejected';
            $this->pending = null; $this->events[] = 'definitive_rejection'; return;
        }
        if ($outcome !== 'accepted') throw new \RuntimeException('SIM_OUTCOME');
        $this->workflow = $workflow; $this->events[] = 'accepted_not_published';
    }

    public function confirm(string $operation, string $workflow, bool $success): void
    {
        if ($this->state !== 'pending' || $this->uncertain || $operation !== $this->pending || !$this->workflow || $workflow !== $this->workflow) {
            throw new \RuntimeException('SIM_CORRELATION');
        }
        $this->state = $success ? ($operation === 'delete' ? 'deleted' : 'published')
            : (in_array($operation, ['metadata', 'delete'], true) ? 'published' : 'rejected');
        $this->pending = null; $this->events[] = $success ? 'synthetic_terminal_success' : 'synthetic_terminal_failure';
    }

    public function snapshot(): array
    {
        return ['simulation_only' => true, 'state' => $this->state, 'pending' => $this->pending,
            'uncertain' => $this->uncertain, 'workflow' => $this->workflow, 'events' => $this->events];
    }

    private function transition(array $allowed, string $next, string $event): void
    {
        if (!in_array($this->state, $allowed, true)) throw new \RuntimeException('SIM_STATE');
        $this->state = $next; $this->events[] = $event;
    }
}
