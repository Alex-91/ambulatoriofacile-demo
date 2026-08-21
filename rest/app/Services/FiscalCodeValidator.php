<?php

namespace App\Services;

use DateTimeImmutable;
use RuntimeException;
use Throwable;

final class FiscalCodeValidator
{
    private const MONTHS = [
        'A' => 1,
        'B' => 2,
        'C' => 3,
        'D' => 4,
        'E' => 5,
        'H' => 6,
        'L' => 7,
        'M' => 8,
        'P' => 9,
        'R' => 10,
        'S' => 11,
        'T' => 12,
    ];

    private const OMOCODIA_DIGITS = [
        'L' => '0',
        'M' => '1',
        'N' => '2',
        'P' => '3',
        'Q' => '4',
        'R' => '5',
        'S' => '6',
        'T' => '7',
        'U' => '8',
        'V' => '9',
    ];

    private const ODD_VALUES = [
        '0' => 1, '1' => 0, '2' => 5, '3' => 7, '4' => 9,
        '5' => 13, '6' => 15, '7' => 17, '8' => 19, '9' => 21,
        'A' => 1, 'B' => 0, 'C' => 5, 'D' => 7, 'E' => 9,
        'F' => 13, 'G' => 15, 'H' => 17, 'I' => 19, 'J' => 21,
        'K' => 2, 'L' => 4, 'M' => 18, 'N' => 20, 'O' => 11,
        'P' => 3, 'Q' => 6, 'R' => 8, 'S' => 12, 'T' => 14,
        'U' => 16, 'V' => 10, 'W' => 22, 'X' => 25, 'Y' => 24,
        'Z' => 23,
    ];

    /** @var array<string, array<string, mixed>> */
    private static array $referenceCache = [];

    /** @var array<string, mixed>|null */
    private ?array $referenceData;
    private string $referenceDataPath;

    /** @var array<string, list<array<string, string>>>|null */
    private ?array $birthplacesByCode = null;

    /** @var array<string, string>|null */
    private ?array $provinceAliases = null;

    /**
     * @param array<string, mixed>|null $referenceData
     */
    public function __construct(?array $referenceData = null, ?string $referenceDataPath = null)
    {
        $this->referenceData = $referenceData;
        $this->referenceDataPath = $referenceDataPath
            ?? APPPATH . 'Data' . DIRECTORY_SEPARATOR . 'italian-fiscal-birthplaces.json';
    }

    /**
     * @param array<string, mixed> $personalData
     * @return array{valid: bool, normalized: string, errors: list<string>, message: string}
     */
    public function validate(string $rawCode, array $personalData = []): array
    {
        $code = $this->normalizeFiscalCode($rawCode);
        if ($code === '') {
            return $this->result($code, []);
        }

        if (preg_match('/^[0-9]{11}$/', $code) === 1) {
            return $this->validateNumericCode($code, $personalData);
        }

        if (preg_match('/^[A-Z]{6}[0-9LMNPQRSTUV]{2}[ABCDEHLMPRST][0-9LMNPQRSTUV]{2}[A-Z][0-9LMNPQRSTUV]{3}[A-Z]$/', $code) !== 1) {
            return $this->result($code, [
                'Il codice fiscale deve avere 16 caratteri e rispettare la struttura prevista, comprese le eventuali omocodie.',
            ]);
        }

        $errors = [];
        if (!$this->hasValidControlCharacter($code)) {
            $errors[] = 'Il carattere di controllo finale non è corretto.';
        }

        $surname = $this->firstValue($personalData, ['cognome', 'surname']);
        if ($surname !== '' && substr($code, 0, 3) !== $this->personalNameCode($surname, false)) {
            $errors[] = 'Il codice fiscale non è coerente con il cognome inserito.';
        }

        $name = $this->firstValue($personalData, ['nome', 'name']);
        if ($name !== '' && substr($code, 3, 3) !== $this->personalNameCode($name, true)) {
            $errors[] = 'Il codice fiscale non è coerente con il nome inserito.';
        }

        $birthData = $this->decodeBirthData($code);
        if ($birthData === null) {
            $errors[] = 'La sezione relativa alla data di nascita non è formalmente valida.';
        }

        $birthDateValue = $this->firstValue($personalData, ['data_nascita', 'birth_date']);
        $birthDate = $birthDateValue !== '' ? $this->parseDate($birthDateValue) : null;
        if ($birthDateValue !== '' && $birthDate === null) {
            $errors[] = 'La data di nascita inserita non è valida.';
        } elseif ($birthDate !== null && $birthData !== null) {
            if (
                substr($birthDate->format('Y'), -2) !== $birthData['year']
                || (int) $birthDate->format('n') !== $birthData['month']
                || (int) $birthDate->format('j') !== $birthData['day']
            ) {
                $errors[] = 'Il codice fiscale non è coerente con la data di nascita inserita.';
            }
        }

        $genderValue = $this->firstValue($personalData, ['sesso', 'gender']);
        if ($genderValue !== '' && $birthData !== null) {
            $gender = $this->normalizeGender($genderValue);
            if ($gender === '') {
                $errors[] = 'Il sesso inserito non è riconosciuto: usa M o F.';
            } elseif ($gender !== $birthData['gender']) {
                $errors[] = 'Il codice fiscale non è coerente con il sesso inserito.';
            }
        }

        $birthplace = $this->firstValue($personalData, ['comune_nascita', 'birthplace']);
        $province = $this->firstValue($personalData, ['provincia_nascita', 'birth_province']);
        if ($birthplace !== '' || $province !== '') {
            try {
                $birthplaceErrors = $this->validateBirthplace(
                    $this->decodeBirthplaceCode($code),
                    $birthplace,
                    $province,
                    $birthDate
                );
                array_push($errors, ...$birthplaceErrors);
            } catch (Throwable $e) {
                $errors[] = 'Non è stato possibile consultare l’archivio locale dei luoghi di nascita.';
            }
        }

        return $this->result($code, array_values(array_unique($errors)));
    }

    /**
     * @param array<string, mixed> $personalData
     * @return array{valid: bool, normalized: string, errors: list<string>, message: string}
     */
    private function validateNumericCode(string $code, array $personalData): array
    {
        $errors = [];
        $sum = 0;

        for ($index = 0; $index < 10; $index++) {
            $digit = (int) $code[$index];
            if ($index % 2 === 0) {
                $sum += $digit;
                continue;
            }

            $doubled = $digit * 2;
            $sum += $doubled > 9 ? $doubled - 9 : $doubled;
        }

        $expectedControlDigit = (10 - ($sum % 10)) % 10;
        if ((int) $code[10] !== $expectedControlDigit) {
            $errors[] = 'Il carattere di controllo del codice fiscale numerico non è corretto.';
        }

        foreach (['cognome', 'nome', 'data_nascita', 'comune_nascita', 'provincia_nascita', 'sesso'] as $field) {
            if (trim((string) ($personalData[$field] ?? '')) !== '') {
                $errors[] = 'Un codice fiscale numerico di 11 cifre non è coerente con i dati di una persona fisica.';
                break;
            }
        }

        return $this->result($code, $errors);
    }

    private function hasValidControlCharacter(string $code): bool
    {
        $sum = 0;
        for ($index = 0; $index < 15; $index++) {
            $character = $code[$index];
            if ($index % 2 === 0) {
                $sum += self::ODD_VALUES[$character] ?? 0;
                continue;
            }

            $sum += ctype_digit($character)
                ? (int) $character
                : ord($character) - ord('A');
        }

        return $code[15] === chr(ord('A') + ($sum % 26));
    }

    /**
     * @return array{year: string, month: int, day: int, gender: string}|null
     */
    private function decodeBirthData(string $code): ?array
    {
        $year = $this->decodeNumericSegment(substr($code, 6, 2));
        $month = self::MONTHS[$code[8]] ?? 0;
        $encodedDay = $this->decodeNumericSegment(substr($code, 9, 2));

        if ($year === null || $encodedDay === null || $month <= 0) {
            return null;
        }

        $dayNumber = (int) $encodedDay;
        $gender = $dayNumber > 40 ? 'F' : 'M';
        $day = $gender === 'F' ? $dayNumber - 40 : $dayNumber;
        if ($day < 1 || $day > 31) {
            return null;
        }

        $yearNumber = (int) $year;
        $calendarDateExists = checkdate($month, $day, 1800 + $yearNumber)
            || checkdate($month, $day, 1900 + $yearNumber)
            || checkdate($month, $day, 2000 + $yearNumber);
        if (!$calendarDateExists) {
            return null;
        }

        return [
            'year' => $year,
            'month' => $month,
            'day' => $day,
            'gender' => $gender,
        ];
    }

    private function decodeBirthplaceCode(string $code): string
    {
        $digits = $this->decodeNumericSegment(substr($code, 12, 3));
        return $digits === null ? '' : $code[11] . $digits;
    }

    private function decodeNumericSegment(string $value): ?string
    {
        $decoded = '';
        foreach (str_split($value) as $character) {
            if (ctype_digit($character)) {
                $decoded .= $character;
                continue;
            }
            if (!isset(self::OMOCODIA_DIGITS[$character])) {
                return null;
            }
            $decoded .= self::OMOCODIA_DIGITS[$character];
        }

        return $decoded;
    }

    private function personalNameCode(string $value, bool $isName): string
    {
        $letters = preg_replace('/[^A-Z]/', '', $this->asciiUpper($value)) ?? '';
        $consonants = preg_replace('/[AEIOU]/', '', $letters) ?? '';
        $vowels = preg_replace('/[^AEIOU]/', '', $letters) ?? '';

        if ($isName && strlen($consonants) >= 4) {
            return $consonants[0] . $consonants[2] . $consonants[3];
        }

        return substr($consonants . $vowels . 'XXX', 0, 3);
    }

    /**
     * @return list<string>
     */
    private function validateBirthplace(
        string $birthplaceCode,
        string $birthplace,
        string $province,
        ?DateTimeImmutable $birthDate
    ): array {
        if ($birthplaceCode === '') {
            return ['Il codice del luogo di nascita contenuto nel codice fiscale non è valido.'];
        }

        $this->buildReferenceIndexes();
        $entries = $this->birthplacesByCode[$birthplaceCode] ?? [];
        if ($entries === []) {
            return ['Il luogo di nascita contenuto nel codice fiscale non è presente nell’archivio locale.'];
        }

        if ($birthDate !== null) {
            $date = $birthDate->format('Y-m-d');
            $validEntries = array_values(array_filter(
                $entries,
                fn (array $entry): bool => $this->isValidOnDate($entry, $date)
            ));
            if ($validEntries === []) {
                return ['Il codice del luogo di nascita non era valido alla data di nascita inserita.'];
            }
            $entries = $validEntries;
        }

        $errors = [];
        if ($birthplace !== '') {
            $birthplaceKey = $this->normalizeTextKey($birthplace);
            $matchesName = array_filter(
                $entries,
                fn (array $entry): bool => $this->normalizeTextKey($entry['name']) === $birthplaceKey
            );
            if ($matchesName === []) {
                $errors[] = 'Il codice fiscale non è coerente con il comune o Stato estero di nascita inserito.';
            }
        }

        if ($province !== '') {
            $provinceCode = $this->normalizeProvince($province);
            if ($provinceCode === '') {
                $errors[] = 'La provincia di nascita inserita non è riconosciuta.';
            } else {
                $matchesProvince = array_filter(
                    $entries,
                    static fn (array $entry): bool => $entry['province'] === $provinceCode
                );
                if ($matchesProvince === []) {
                    $errors[] = 'Il codice fiscale non è coerente con la provincia di nascita inserita.';
                }
            }
        }

        return $errors;
    }

    /**
     * @param array<string, string> $entry
     */
    private function isValidOnDate(array $entry, string $date): bool
    {
        $validFrom = $this->normalizeReferenceDate($entry['valid_from'] ?? '');
        $validTo = $this->normalizeReferenceDate($entry['valid_to'] ?? '');

        return ($validFrom === '' || $date >= $validFrom)
            && ($validTo === '' || $date <= $validTo);
    }

    private function normalizeReferenceDate(string $value): string
    {
        $value = trim($value);
        return preg_match('/^[12][0-9]{3}-[01][0-9]-[0-3][0-9]$/', $value) === 1
            ? $value
            : '';
    }

    private function normalizeProvince(string $value): string
    {
        $this->buildReferenceIndexes();
        $key = $this->normalizeTextKey($value);
        if (isset($this->provinceAliases[$key])) {
            return $this->provinceAliases[$key];
        }

        $candidate = strtoupper(trim($value));
        return preg_match('/^[A-Z]{2}$/', $candidate) === 1 ? $candidate : '';
    }

    private function buildReferenceIndexes(): void
    {
        if ($this->birthplacesByCode !== null && $this->provinceAliases !== null) {
            return;
        }

        $data = $this->referenceData();
        $this->birthplacesByCode = [];
        foreach ((array) ($data['f'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }

            $code = strtoupper(trim((string) ($row['b'] ?? '')));
            $name = trim((string) ($row['n'] ?? ''));
            if (preg_match('/^[A-Z][0-9]{3}$/', $code) !== 1 || $name === '') {
                continue;
            }

            $this->birthplacesByCode[$code][] = [
                'name' => $name,
                'province' => strtoupper(trim((string) ($row['p'] ?? ''))),
                'valid_from' => trim((string) ($row['d'] ?? '')),
                'valid_to' => trim((string) ($row['u'] ?? '')),
            ];
        }

        $this->provinceAliases = ['EE' => 'EE'];
        foreach ((array) ($data['p'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $code = strtoupper(trim((string) ($row['c'] ?? '')));
            if ($code === '') {
                continue;
            }
            $this->provinceAliases[$this->normalizeTextKey($code)] = $code;
            $name = trim((string) ($row['n'] ?? ''));
            if ($name !== '') {
                $this->provinceAliases[$this->normalizeTextKey($name)] = $code;
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function referenceData(): array
    {
        if ($this->referenceData !== null) {
            return $this->referenceData;
        }

        if (isset(self::$referenceCache[$this->referenceDataPath])) {
            return self::$referenceCache[$this->referenceDataPath];
        }

        if (!is_file($this->referenceDataPath)) {
            throw new RuntimeException('Archivio luoghi di nascita non trovato.');
        }

        $decoded = json_decode((string) file_get_contents($this->referenceDataPath), true);
        if (!is_array($decoded) || !isset($decoded['f']) || !is_array($decoded['f'])) {
            throw new RuntimeException('Archivio luoghi di nascita non valido.');
        }

        self::$referenceCache[$this->referenceDataPath] = $decoded;
        return self::$referenceCache[$this->referenceDataPath];
    }

    private function parseDate(string $value): ?DateTimeImmutable
    {
        $value = trim($value);
        foreach (['!Y-m-d', '!d/m/Y', '!d-m-Y'] as $format) {
            $date = DateTimeImmutable::createFromFormat($format, $value);
            $errors = DateTimeImmutable::getLastErrors();
            if ($date !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
                return $date;
            }
        }

        return null;
    }

    private function normalizeGender(string $value): string
    {
        $value = $this->normalizeTextKey($value);
        if (in_array($value, ['M', 'MASCHIO', 'MALE'], true)) {
            return 'M';
        }
        if (in_array($value, ['F', 'FEMMINA', 'FEMALE'], true)) {
            return 'F';
        }

        return '';
    }

    private function normalizeFiscalCode(string $value): string
    {
        return preg_replace('/[^A-Z0-9]/', '', strtoupper(trim($value))) ?? '';
    }

    private function normalizeTextKey(string $value): string
    {
        $value = preg_replace('/[^A-Z0-9]+/', ' ', $this->asciiUpper($value)) ?? '';
        return trim(preg_replace('/\s+/', ' ', $value) ?? '');
    }

    private function asciiUpper(string $value): string
    {
        if (function_exists('transliterator_transliterate')) {
            $transliterated = transliterator_transliterate('Any-Latin; Latin-ASCII; Upper()', $value);
            if (is_string($transliterated)) {
                return $transliterated;
            }
        }

        $transliterated = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        return strtoupper(is_string($transliterated) ? $transliterated : $value);
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string> $keys
     */
    private function firstValue(array $data, array $keys): string
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $data)) {
                continue;
            }
            $value = trim((string) $data[$key]);
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    /**
     * @param list<string> $errors
     * @return array{valid: bool, normalized: string, errors: list<string>, message: string}
     */
    private function result(string $code, array $errors): array
    {
        $errors = array_values(array_unique($errors));
        return [
            'valid' => $errors === [],
            'normalized' => $code,
            'errors' => $errors,
            'message' => $errors === []
                ? ($code === '' ? '' : 'Codice fiscale formalmente valido e coerente con i dati inseriti.')
                : 'Controllo codice fiscale non superato: ' . implode(' ', $errors),
        ];
    }
}
