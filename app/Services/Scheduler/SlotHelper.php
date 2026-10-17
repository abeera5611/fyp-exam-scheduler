<?php

namespace App\Services\Scheduler;

use App\Models\Scheduler\DateSheetRule;
use App\Models\Scheduler\Room;
use Carbon\Carbon;

class SlotHelper
{
    public static function normalizeSlotKey(?string $value): string
    {
        return strtolower((string) preg_replace('/[^0-9A-Za-z]+/', '', (string) $value));
    }

    public static function normalizePersonName(?string $value): string
    {
        $text = trim((string) $value);
        if ($text === '') {
            return '';
        }

        $text = preg_replace('/\b(?:dr|prof|mr|mrs|ms|miss|engr|eng|sir|madam)\.?\b/i', '', $text);

        return strtolower((string) preg_replace('/[^0-9A-Za-z]+/', '', (string) $text));
    }

    public static function normalizeRoomKey(?string $value): string
    {
        return strtolower((string) preg_replace('/[^0-9A-Za-z]+/', '', (string) $value));
    }

    public static function physicalRoomLabel(Room|string|null $roomOrId): string
    {
        $roomId = $roomOrId instanceof Room ? (string) $roomOrId->room_id : (string) $roomOrId;
        $label = trim((string) preg_replace('/\s+/', ' ', $roomId));
        if ($label === '') {
            return '';
        }

        // Row-group suffixes such as CR2 A / CR2 B are not separate
        // physical rooms for duty purposes. Strip a final single-letter
        // suffix only when the base contains a room number.
        if (preg_match('/^(.+?\d)\s*[-_ ]?\s*[A-Z]$/i', $label, $matches)) {
            return trim((string) $matches[1]);
        }

        return $label;
    }

    public static function physicalRoomKey(Room|string|null $roomOrId, ?string $building = null, ?string $floor = null): string
    {
        if ($roomOrId instanceof Room) {
            $building = $building ?? (string) ($roomOrId->building ?? '');
            $floor = $floor ?? (string) ($roomOrId->floor ?? '');
        }

        return implode('|', [
            self::normalizeRoomKey((string) $building),
            self::normalizeRoomKey((string) $floor),
            self::normalizeRoomKey(self::physicalRoomLabel($roomOrId)),
        ]);
    }

    public static function isDutyEligibleRoom(Room|string|null $roomOrId): bool
    {
        if ($roomOrId instanceof Room && $roomOrId->looksLikeWaitingRoom()) {
            return false;
        }

        $roomId = $roomOrId instanceof Room ? $roomOrId->room_id : (string) $roomOrId;
        $key = self::normalizeRoomKey($roomId);

        return !in_array($key, [
            'waitingroom',
            'tbd',
            'tba',
            'tobedecided',
            'tobeannounced',
            'unknown',
            'unassigned',
            'notassigned',
            'notallotted',
        ], true);
    }

    public static function buildSlotMaps(array $slots): array
    {
        $lookup = [];
        $order = [];

        foreach (array_values($slots) as $index => $slot) {
            $slotId = $slot['id'] ?? null;
            $label = $slot['label'] ?? $slotId;
            $canonical = $slotId ?: $label;

            if ($canonical === null || $canonical === '') {
                continue;
            }

            $order[$canonical] = $index;

            if ($slotId) {
                $lookup[self::normalizeSlotKey((string) $slotId)] = $canonical;
            }

            if ($label) {
                $lookup[self::normalizeSlotKey((string) $label)] = $canonical;
            }
        }

        return [$lookup, $order];
    }

    public static function buildSlotsFromRule(DateSheetRule $rule): array
    {
        if (is_array($rule->slots) && $rule->slots !== []) {
            $slots = array_values(array_map(function ($slot) {
                return [
                    'id' => (string) ($slot['id'] ?? $slot['label'] ?? ''),
                    'label' => (string) ($slot['label'] ?? $slot['id'] ?? ''),
                    'start' => (string) ($slot['start'] ?? ''),
                    'end' => (string) ($slot['end'] ?? ''),
                    'type' => strtoupper((string) ($slot['type'] ?? '')),
                ];
            }, $rule->slots));

            $limit = max(0, (int) ($rule->slots_per_day ?? 0));
            if ($limit > 0) {
                $slots = array_slice($slots, 0, $limit);
            }

            return self::hydrateSlotTypes($slots);
        }

        $duration = $rule->examDurationMinutes();
        $gap = (int) $rule->slot_break_minutes;
        $limit = max(0, (int) ($rule->slots_per_day ?? 0));

        $start = Carbon::createFromFormat('H:i:s', (string) $rule->day_start_time);
        $endBoundary = Carbon::createFromFormat('H:i:s', (string) $rule->day_end_time);

        $slots = [];
        $cursor = $start->copy();

        while ($cursor->copy()->addMinutes($duration)->lessThanOrEqualTo($endBoundary)) {
            if ($limit > 0 && count($slots) >= $limit) {
                break;
            }

            $slotEnd = $cursor->copy()->addMinutes($duration);
            $label = $cursor->format('Hi') . '-' . $slotEnd->format('Hi');
            $slots[] = [
                'id' => str_replace('-', '', $label),
                'label' => $label,
                'start' => $cursor->format('H:i'),
                'end' => $slotEnd->format('H:i'),
                'type' => '',
            ];
            $cursor = $slotEnd->copy()->addMinutes($gap);
        }

        return self::hydrateSlotTypes($slots);
    }

    private static function hydrateSlotTypes(array $slots): array
    {
        $lastIndex = count($slots) - 1;

        foreach ($slots as $index => &$slot) {
            $type = strtoupper(trim((string) ($slot['type'] ?? '')));
            if (!in_array($type, ['MORNING', 'AFTERNOON', 'EVENING'], true)) {
                if ($lastIndex > 0 && $index === $lastIndex) {
                    $type = 'EVENING';
                } elseif ($index === 0) {
                    $type = 'MORNING';
                } else {
                    $type = 'AFTERNOON';
                }
            }
            $slot['type'] = $type;
        }
        unset($slot);

        return $slots;
    }

    public static function slotSortKey(string $slot, array $slotLookup, array $slotOrder): array
    {
        $normalized = self::normalizeSlotKey($slot);
        $canonical = $slotLookup[$normalized] ?? null;

        if ($canonical !== null && array_key_exists($canonical, $slotOrder)) {
            return [0, $slotOrder[$canonical]];
        }

        if (preg_match('/(\d{1,2}:\d{2})/', $slot, $matches)) {
            try {
                $minutes = Carbon::createFromFormat('H:i', $matches[1])->hour * 60
                    + Carbon::createFromFormat('H:i', $matches[1])->minute;

                return [1, $minutes];
            } catch (\Throwable) {
            }
        }

        return [2, $slot];
    }

    public static function splitExamInstructors(?string $value): array
    {
        $text = trim((string) $value);
        if ($text === '') {
            return [];
        }

        $text = str_replace("\r", "\n", $text);
        $parts = preg_split(
            '/\s*(?:\n+|\/|;|\||\band\b|,(?=\s*(?:dr|prof|mr|mrs|ms|miss|engr|eng)\b))\s*/i',
            $text
        ) ?: [];

        $seen = [];
        $out = [];

        foreach ($parts as $part) {
            $cleaned = trim((string) preg_replace('/\s+/', ' ', $part), " ,;/|-");
            if ($cleaned === '') {
                continue;
            }

            $marker = self::normalizePersonName($cleaned) ?: strtolower($cleaned);
            if (isset($seen[$marker])) {
                continue;
            }

            $seen[$marker] = true;
            $out[] = $cleaned;
        }

        if ($out !== []) {
            return $out;
        }

        $cleaned = trim((string) preg_replace('/\s+/', ' ', $text), " ,;/|-");

        return $cleaned === '' ? [] : [$cleaned];
    }

    public static function parseRoomTokens(?string $value): array
    {
        $parts = [];
        $rawParts = $value ? preg_split('/,/', $value) : [];

        foreach ($rawParts ?: [] as $raw) {
            $raw = trim((string) $raw);
            if ($raw === '') {
                continue;
            }

            if (preg_match('/([A-Za-z]+)[-\s]*([0-9]+)\/([0-9]+)/', $raw, $matches)) {
                $parts[] = $matches[1] . $matches[2];
                $parts[] = $matches[1] . $matches[3];
                continue;
            }

            if (preg_match('/([A-Za-z]+)[-\s]*([0-9]+)$/', $raw, $matches)) {
                $parts[] = $matches[1] . $matches[2];
                continue;
            }

            $cleaned = str_replace(' ', '', $raw);
            if ($cleaned !== '') {
                $parts[] = $cleaned;
            }
        }

        return array_values(array_unique($parts));
    }

    public static function parseRegistrationSession(string $registrationId): string
    {
        preg_match('/(FA|SP|SU)\s*\d{2}/i', $registrationId, $termMatches);
        $term = isset($termMatches[0]) ? strtoupper(str_replace(' ', '', $termMatches[0])) : '';

        return $term;
    }

    public static function parseProgramFromRegistration(string $registrationId): string
    {
        preg_match('/-(BAF|BAI|BBA|BCE|BCS|BDA|BEE|BEN|BMD|BSE|BSM|PCS|PEE|PMS|PMT|RCE|RCS|REE|RMS|RMT)-/i', $registrationId, $matches);

        return isset($matches[1]) ? strtoupper($matches[1]) : '';
    }

    public static function parseRegistrationParts(string $registrationId): array
    {
        if (!preg_match('/^(?:CIIT|COMSATS)\/([A-Z]{2}\d{2})-([A-Z]+)-\d+\/([A-Z]+)$/i', trim($registrationId), $matches)) {
            return [
                'intake_code' => self::parseRegistrationSession($registrationId),
                'program_code' => self::parseProgramFromRegistration($registrationId),
                'campus_code' => '',
            ];
        }

        return [
            'intake_code' => strtoupper($matches[1]),
            'program_code' => strtoupper($matches[2]),
            'campus_code' => strtoupper($matches[3]),
        ];
    }

    public static function termToIndex(string $sessionCode): ?int
    {
        $sessionCode = strtoupper(trim($sessionCode));
        if (!preg_match('/^(SP|FA|SU)(\d{2})$/', $sessionCode, $matches)) {
            return null;
        }

        $term = $matches[1];
        $year = 2000 + (int) $matches[2];
        $termIndex = $term === 'SP' ? 0 : 1;

        return ($year * 2) + $termIndex;
    }

    public static function semesterNumber(string $intakeCode, string $sessionCode): ?int
    {
        $startIndex = self::termToIndex($intakeCode);
        $sessionIndex = self::termToIndex($sessionCode);

        if ($startIndex === null || $sessionIndex === null || $sessionIndex < $startIndex) {
            return null;
        }

        return $sessionIndex - $startIndex + 1;
    }

    public static function toRoman(int $value): string
    {
        $map = [
            1000 => 'M', 900 => 'CM', 500 => 'D', 400 => 'CD', 100 => 'C', 90 => 'XC',
            50 => 'L', 40 => 'XL', 10 => 'X', 9 => 'IX', 5 => 'V', 4 => 'IV', 1 => 'I',
        ];

        $result = '';
        foreach ($map as $number => $roman) {
            while ($value >= $number) {
                $result .= $roman;
                $value -= $number;
            }
        }

        return $result;
    }

    public static function classLabel(string $programCode, ?int $semesterNo): string
    {
        $programCode = strtoupper(trim($programCode));
        if ($programCode === '') {
            return '';
        }

        if ($semesterNo === null || $semesterNo <= 0) {
            return $programCode;
        }

        return $programCode . '-' . self::toRoman($semesterNo);
    }
}
