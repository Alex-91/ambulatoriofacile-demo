<?php

namespace App\Libraries;

final class WhatsappAppointmentNote
{
    public static function buildConfirmationNote(?\DateTimeInterface $occurredAt = null): string
    {
        $occurredAt = self::normalizeDateTime($occurredAt);

        return sprintf(
            'CONFERMATO TRAMITE WA IL %s (%s) ALLE ORE %s',
            $occurredAt->format('d-m-Y'),
            self::italianWeekdayLabel($occurredAt),
            $occurredAt->format('H:i:s')
        );
    }

    public static function buildCancellationNote(?\DateTimeInterface $occurredAt = null): string
    {
        $occurredAt = self::normalizeDateTime($occurredAt);

        return sprintf(
            'APPUNTAMENTO ANNULLATO TRAMITE WA IL %s (%s) ALLE ORE %s',
            $occurredAt->format('d-m-Y'),
            self::italianWeekdayLabel($occurredAt),
            $occurredAt->format('H:i:s')
        );
    }

    public static function appendToExisting(?string $existingNote, string $eventNote): string
    {
        $existingNote = trim((string)$existingNote);
        $eventNote = trim($eventNote);

        if ($existingNote === '') {
            return $eventNote;
        }

        if ($eventNote === '') {
            return $existingNote;
        }

        return $existingNote . ' | ' . $eventNote;
    }

    public static function hasWaConfirmation(?string $note): bool
    {
        return preg_match(
            '/CONFERMATO(?:\s+TRAMITE)?\s+WA\s+IL\s+\d{2}-\d{2}-\d{4}(?:\s+\([^)]+\))?\s+ALLE\s+ORE\s+\d{2}:\d{2}:\d{2}/iu',
            (string)$note
        ) === 1;
    }

    public static function parseLatestOutcome(?string $note): ?array
    {
        $note = (string)$note;
        if ($note === '') {
            return null;
        }

        $matches = [];

        self::collectMatches(
            $matches,
            $note,
            'confirm',
            '/CONFERMATO(?:\s+TRAMITE)?\s+WA\s+IL\s+(\d{2}-\d{2}-\d{4})(?:\s+\([^)]+\))?\s+ALLE\s+ORE\s+(\d{2}:\d{2}:\d{2})/iu'
        );

        self::collectMatches(
            $matches,
            $note,
            'cancel',
            '/ANNULLATO(?:[^\r\n]*?)TRAMITE\s+WA\s+IL\s+(\d{2}-\d{2}-\d{4})(?:\s+\([^)]+\))?\s+ALLE\s+ORE\s+(\d{2}:\d{2}:\d{2})/iu'
        );

        if ($matches === []) {
            return null;
        }

        usort(
            $matches,
            static fn(array $a, array $b): int => $a['offset'] <=> $b['offset']
        );

        $latest = $matches[array_key_last($matches)];

        return [
            'action' => $latest['action'],
            'occurred_at' => $latest['occurred_at'],
        ];
    }

    private static function collectMatches(array &$results, string $note, string $action, string $pattern): void
    {
        $matched = preg_match_all($pattern, $note, $captures, PREG_OFFSET_CAPTURE);
        if ($matched === false || $matched === 0) {
            return;
        }

        for ($i = 0; $i < $matched; $i++) {
            $date = $captures[1][$i][0] ?? '';
            $time = $captures[2][$i][0] ?? '';
            $offset = (int)($captures[0][$i][1] ?? -1);

            $occurredAt = \DateTimeImmutable::createFromFormat(
                'd-m-Y H:i:s',
                $date . ' ' . $time,
                new \DateTimeZone('Europe/Rome')
            );

            if ($occurredAt === false || $offset < 0) {
                continue;
            }

            $results[] = [
                'action' => $action,
                'occurred_at' => $occurredAt,
                'offset' => $offset,
            ];
        }
    }

    private static function normalizeDateTime(?\DateTimeInterface $occurredAt): \DateTimeImmutable
    {
        if ($occurredAt instanceof \DateTimeImmutable) {
            return $occurredAt->setTimezone(new \DateTimeZone('Europe/Rome'));
        }

        if ($occurredAt instanceof \DateTimeInterface) {
            return \DateTimeImmutable::createFromInterface($occurredAt)
                ->setTimezone(new \DateTimeZone('Europe/Rome'));
        }

        return new \DateTimeImmutable('now', new \DateTimeZone('Europe/Rome'));
    }

    private static function italianWeekdayLabel(\DateTimeInterface $occurredAt): string
    {
        $weekdays = [
            1 => 'Lunedi',
            2 => 'Martedi',
            3 => 'Mercoledi',
            4 => 'Giovedi',
            5 => 'Venerdi',
            6 => 'Sabato',
            7 => 'Domenica',
        ];

        return $weekdays[(int)$occurredAt->format('N')] ?? $occurredAt->format('l');
    }
}
