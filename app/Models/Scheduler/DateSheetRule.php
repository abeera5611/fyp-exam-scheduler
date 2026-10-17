<?php

namespace App\Models\Scheduler;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DateSheetRule extends Model
{
    protected $table = 'scheduler_datesheetrule';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'slots' => 'array',
        'slot_off_matrix' => 'array',
        'faculty_slot_matrix' => 'array',
        'weekend_days' => 'array',
        'schedule_same_course_code_together' => 'boolean',
        'allow_same_day_student_conflicts' => 'boolean',
        'same_day_student_conflict_limit' => 'integer',
        'balance_daily_strength' => 'boolean',
        'exclude_weekends' => 'boolean',
        'split_rooms' => 'boolean',
        'is_active' => 'boolean',
        'schedule_pg_courses_in_preferred_slot' => 'boolean',
        'exclude_pg_preferred_slot_for_bs' => 'boolean',
        'prevent_cross_building_for_same_class' => 'boolean',
        'merge_same_course_program_instructor' => 'boolean',
        'enforce_course_date_preferences' => 'boolean',
        'single_duty_per_faculty_per_day' => 'boolean',
    ];

    public function singleInvigilationDutyPerDayEnabled(): bool
    {
        return (bool) ($this->single_duty_per_faculty_per_day ?? false);
    }

    public function sameDayStudentConflictLimit(): int
    {
        $configuredLimit = max(0, (int) ($this->same_day_student_conflict_limit ?? 0));

        if ((bool) ($this->allow_same_day_student_conflicts ?? false) && $configuredLimit === 0) {
            return PHP_INT_MAX;
        }

        return $configuredLimit;
    }

    public function registrations(): HasMany
    {
        return $this->hasMany(CourseRegistration::class, 'rule_id');
    }

    public function facultyAssignments(): HasMany
    {
        return $this->hasMany(CourseFacultyAssignment::class, 'rule_id');
    }

    public function exams(): HasMany
    {
        return $this->hasMany(Exam::class, 'rule_id');
    }

    public function examDurationMinutes(): int
    {
        return strtoupper((string) $this->exam_type) === 'MID'
            ? (int) $this->midterm_default_minutes
            : (int) $this->terminal_default_minutes;
    }

    public function offDaysOverallList(): array
    {
        $lines = preg_split('/\r\n|\r|\n/', (string) $this->off_days_overall);

        return array_values(array_filter(array_map(fn ($v) => trim((string) $v), $lines)));
    }

    public function offDaysPerClassMap(): array
    {
        $map = [];
        $lines = preg_split('/\r\n|\r|\n/', (string) $this->off_days_per_class);
        foreach ($lines as $line) {
            $line = trim((string) $line);
            if ($line === '' || !str_contains($line, ',')) {
                continue;
            }
            [$class, $date] = array_map('trim', explode(',', $line, 2));
            if ($class !== '' && $date !== '') {
                $map[$class][] = $date;
            }
        }

        return $map;
    }

    public function courseDatePreferenceMap(): array
    {
        $map = [];
        $lines = preg_split('/\r\n|\r|\n/', (string) ($this->course_date_preferences ?? '')) ?: [];

        foreach ($lines as $line) {
            $line = trim((string) $line);
            if ($line === '') {
                continue;
            }

            [$courseCode, $programCode, $rawDates] = $this->parseCourseDatePreferenceLine($line);
            if ($courseCode === '' || $rawDates === '') {
                continue;
            }

            $dates = $this->parseCourseDatePreferenceDates($rawDates);
            if ($dates === []) {
                continue;
            }

            $programKey = $programCode !== '' ? $programCode : '*';
            $map[$courseCode][$programKey] = $map[$courseCode][$programKey] ?? [];

            foreach ($dates as $date) {
                $map[$courseCode][$programKey][$date] = true;
            }
        }

        foreach ($map as $courseCode => $programMap) {
            foreach ($programMap as $programCode => $dateSet) {
                $map[$courseCode][$programCode] = array_keys($dateSet);
                sort($map[$courseCode][$programCode]);
            }
        }

        return $map;
    }

    public function preferredDatesForOfferings(array $offerings): array
    {
        $datePreferences = $this->courseDatePreferenceMap();
        if ($datePreferences === []) {
            return [];
        }

        $dates = [];
        foreach ($offerings as $offering) {
            $courseCode = $this->normalizeCoursePreferenceCode((string) ($offering['course_code'] ?? ''));
            if ($courseCode === '' || !isset($datePreferences[$courseCode])) {
                continue;
            }

            foreach ($this->programPreferenceKeysForOffering($offering) as $programKey) {
                foreach ((array) ($datePreferences[$courseCode][$programKey] ?? []) as $date) {
                    $dates[$date] = true;
                }
            }

            foreach ((array) ($datePreferences[$courseCode]['*'] ?? []) as $date) {
                $dates[$date] = true;
            }
        }

        $dates = array_keys($dates);
        sort($dates);

        return $dates;
    }

    public function allCourseDatePreferenceDates(): array
    {
        $dates = [];
        foreach ($this->courseDatePreferenceMap() as $programMap) {
            foreach ((array) $programMap as $dateList) {
                foreach ((array) $dateList as $date) {
                    $dates[$date] = true;
                }
            }
        }

        $dates = array_keys($dates);
        sort($dates);

        return $dates;
    }

    public function enforceCourseDatePreferences(): bool
    {
        return (bool) ($this->enforce_course_date_preferences ?? true);
    }

    public function mergeStrengthThreshold(): int
    {
        return max(0, (int) ($this->merge_same_course_strength_threshold ?? 0));
    }

    private function parseCourseDatePreferenceLine(string $line): array
    {
        if (str_contains($line, ',')) {
            $parts = array_map('trim', explode(',', $line, 3));
        } else {
            $parts = preg_split('/\s*(?:=>|=|:)\s*/', $line, 3) ?: [];
            $parts = array_map('trim', $parts);
        }

        if (count($parts) >= 3) {
            return [
                $this->normalizeCoursePreferenceCode((string) $parts[0]),
                $this->normalizeProgramPreferenceCode((string) $parts[1]),
                (string) $parts[2],
            ];
        }

        if (count($parts) >= 2) {
            return [
                $this->normalizeCoursePreferenceCode((string) $parts[0]),
                '*',
                (string) $parts[1],
            ];
        }

        return ['', '', ''];
    }

    private function parseCourseDatePreferenceDates(string $rawDates): array
    {
        $dates = [];
        $tokens = preg_split('/\s*[;|]\s*/', trim($rawDates)) ?: [];

        foreach ($tokens as $token) {
            $date = $this->normalizePreferredDate((string) $token);
            if ($date !== null) {
                $dates[$date] = true;
            }
        }

        $dates = array_keys($dates);
        sort($dates);

        return $dates;
    }

    private function normalizePreferredDate(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        if (preg_match('/^(\d{4})[-\/](\d{1,2})[-\/](\d{1,2})$/', $value, $matches)) {
            return sprintf('%04d-%02d-%02d', (int) $matches[1], (int) $matches[2], (int) $matches[3]);
        }

        // Institute users usually type dates as 3-06-2026, so interpret
        // ambiguous numeric dates as DD-MM-YYYY, not US-style MM-DD-YYYY.
        if (preg_match('/^(\d{1,2})[-\/](\d{1,2})[-\/](\d{4})$/', $value, $matches)) {
            return sprintf('%04d-%02d-%02d', (int) $matches[3], (int) $matches[2], (int) $matches[1]);
        }

        return null;
    }

    private function programPreferenceKeysForOffering(array $offering): array
    {
        $keys = [];

        foreach ([(string) ($offering['program_code'] ?? ''), (string) ($offering['class_label'] ?? '')] as $value) {
            $programCode = $this->normalizeProgramPreferenceCode($value);
            if ($programCode !== '') {
                $keys[$programCode] = true;
            }
        }

        return array_keys($keys);
    }

    private function normalizeCoursePreferenceCode(string $courseCode): string
    {
        return strtoupper(trim((string) preg_replace('/[^A-Z0-9]/i', '', $courseCode)));
    }

    private function normalizeProgramPreferenceCode(string $programCode): string
    {
        $programCode = strtoupper(trim($programCode));
        if ($programCode === '*' || $programCode === 'ALL') {
            return '*';
        }

        if (preg_match('/^[A-Z]+/', $programCode, $matches)) {
            return $matches[0];
        }

        return strtoupper(trim((string) preg_replace('/[^A-Z0-9]/i', '', $programCode)));
    }


    public function courseSlotPreferenceMap(): array
    {
        $map = [];
        $lines = preg_split('/\r\n|\r|\n/', (string) ($this->course_slot_preferences ?? '')) ?: [];

        foreach ($lines as $line) {
            $line = trim((string) $line);
            if ($line === '') {
                continue;
            }

            [$courseCode, $programCode, $slot] = $this->parseCourseSlotPreferenceLine($line);
            if ($courseCode === '' || $slot === '') {
                continue;
            }

            $programKey = $programCode !== '' ? $programCode : '*';
            $map[$courseCode][$programKey] = $slot;
        }

        return $map;
    }

    public function preferredSlotPreferencesForOfferings(array $offerings): array
    {
        $slotPreferences = $this->courseSlotPreferenceMap();
        if ($slotPreferences === []) {
            return [];
        }

        $preferences = [];
        foreach ($offerings as $offering) {
            $courseCode = $this->normalizeCoursePreferenceCode((string) ($offering['course_code'] ?? ''));
            if ($courseCode === '' || !isset($slotPreferences[$courseCode])) {
                continue;
            }

            foreach ($this->programPreferenceKeysForOffering($offering) as $programKey) {
                $slot = trim((string) ($slotPreferences[$courseCode][$programKey] ?? ''));
                if ($slot !== '') {
                    $preferences[$slot] = true;
                }
            }

            $globalSlot = trim((string) ($slotPreferences[$courseCode]['*'] ?? ''));
            if ($globalSlot !== '') {
                $preferences[$globalSlot] = true;
            }
        }

        return array_keys($preferences);
    }

    private function parseCourseSlotPreferenceLine(string $line): array
    {
        if (str_contains($line, ',')) {
            $parts = array_map('trim', explode(',', $line, 3));
        } else {
            $parts = preg_split('/\s*(?:=>|=|:)\s*/', $line, 2) ?: [];
            $parts = array_map('trim', $parts);
        }

        if (count($parts) >= 3) {
            return [
                $this->normalizeCoursePreferenceCode((string) $parts[0]),
                $this->normalizeProgramPreferenceCode((string) $parts[1]),
                (string) $parts[2],
            ];
        }

        if (count($parts) >= 2) {
            return [
                $this->normalizeCoursePreferenceCode((string) $parts[0]),
                '*',
                (string) $parts[1],
            ];
        }

        return ['', '', ''];
    }

    public function sameCourseTogetherRules(): array
    {
        $rules = [
            'global_courses' => [],
            'course_programs' => [],
            'program_groups' => [],
        ];

        $raw = trim((string) ($this->same_course_together_codes ?? ''));
        if ($raw === '') {
            return $rules;
        }

        $seenProgramGroups = [];
        $lines = preg_split('/\r\n|\r|\n/', $raw) ?: [];

        foreach ($lines as $line) {
            $line = strtoupper(trim((string) $line));
            if ($line === '') {
                continue;
            }

            // Accept all of these forms:
            // CSC101
            // CSC101,BSE,BCS
            // CSC101: BSE, BCS
            // BSE,BCS
            $line = preg_replace('/\s*(?:=>|=|:)\s*/', ',', $line);
            $tokens = preg_split('/[\s,;|]+/', (string) $line) ?: [];

            $courseTokens = [];
            $programTokens = [];

            foreach ($tokens as $token) {
                $token = strtoupper(trim((string) preg_replace('/[^A-Z0-9*]/', '', (string) $token)));
                if ($token === '') {
                    continue;
                }

                if (preg_match('/\d/', $token)) {
                    $courseCode = $this->normalizeCoursePreferenceCode($token);
                    if ($courseCode !== '') {
                        $courseTokens[$courseCode] = true;
                    }
                    continue;
                }

                $programCode = $this->normalizeProgramPreferenceCode($token);
                if ($programCode !== '') {
                    $programTokens[$programCode] = true;
                }
            }

            $courses = array_keys($courseTokens);
            $programs = array_keys($programTokens);
            sort($courses);
            sort($programs);

            if ($courses !== []) {
                foreach ($courses as $courseCode) {
                    if ($programs === [] || in_array('*', $programs, true)) {
                        $rules['global_courses'][$courseCode] = true;
                        continue;
                    }

                    $rules['course_programs'][$courseCode] = $rules['course_programs'][$courseCode] ?? [];
                    foreach ($programs as $programCode) {
                        if ($programCode !== '*') {
                            $rules['course_programs'][$courseCode][$programCode] = true;
                        }
                    }
                }
                continue;
            }

            // Program-only lines mean: combine every same course code across
            // these programs, but do not include other programs.
            $programs = array_values(array_filter($programs, fn ($program) => $program !== '*'));
            if (count($programs) >= 2) {
                sort($programs);
                $groupKey = implode(',', $programs);
                if (!isset($seenProgramGroups[$groupKey])) {
                    $rules['program_groups'][] = $programs;
                    $seenProgramGroups[$groupKey] = true;
                }
            }
        }

        foreach ($rules['course_programs'] as $courseCode => $programSet) {
            $programs = array_keys($programSet);
            sort($programs);
            $rules['course_programs'][$courseCode] = array_fill_keys($programs, true);
        }

        return $rules;
    }

    public function manualCourseMergeGroups(): array
    {
        $groups = [];
        $lines = preg_split('/\r\n|\r|\n/', (string) ($this->manual_course_merge_groups ?? '')) ?: [];

        foreach ($lines as $index => $line) {
            $line = trim((string) $line);
            if ($line === '') {
                continue;
            }

            [$label, $membersText] = $this->parseManualCourseMergeLine($line, $index + 1);
            $members = $this->parseManualCourseMergeMembers($membersText);
            if (count($members) < 2) {
                continue;
            }

            $memberLabels = [];
            foreach ($members as $member) {
                $memberLabels[] = $this->manualCourseMergeMemberLabel($member);
            }

            $groups[] = [
                'key' => 'MANUAL_MERGE|' . count($groups),
                'label' => $label,
                'members' => $members,
                'description' => $label . ' (' . implode(', ', $memberLabels) . ')',
            ];
        }

        return $groups;
    }

    public function ignoredCourseOfferings(): array
    {
        $members = [];
        $lines = preg_split('/\r\n|\r|\n/', (string) ($this->ignored_course_offerings ?? '')) ?: [];

        foreach ($lines as $index => $line) {
            $line = trim((string) $line);
            if ($line === '') {
                continue;
            }

            [, $membersText] = $this->parseManualCourseMergeLine($line, $index + 1);
            foreach ($this->parseManualCourseMergeMembers($membersText) as $member) {
                $members[$this->manualCourseMergeMemberKey(
                    (string) ($member['course'] ?? ''),
                    (string) ($member['program'] ?? '*'),
                    $member['semester'] ?? null
                )] = $member;
            }
        }

        return array_values($members);
    }

    public function shouldIgnoreCourseOffering(array $offering): bool
    {
        foreach ($this->ignoredCourseOfferings() as $member) {
            if ($this->offeringMatchesCourseMember($offering, $member)) {
                return true;
            }
        }

        return false;
    }

    public function manualCourseMergeGroupingKeyForOffering(array $offering): ?string
    {
        foreach ($this->manualCourseMergeGroups() as $group) {
            foreach ((array) ($group['members'] ?? []) as $member) {
                if ($this->offeringMatchesCourseMember($offering, $member)) {
                    return (string) $group['key'];
                }
            }
        }

        return null;
    }

    private function offeringMatchesCourseMember(array $offering, array $member): bool
    {
        $courseCode = $this->normalizeCoursePreferenceCode((string) ($offering['course_code'] ?? ''));
        if ($courseCode === '' || ($member['course'] ?? '') !== $courseCode) {
            return false;
        }

        $programCode = $this->normalizedProgramForSameCourseRule($offering);
        $memberProgram = (string) ($member['program'] ?? '*');
        if ($memberProgram !== '*' && ($programCode === '' || $memberProgram !== $programCode)) {
            return false;
        }

        $memberSemester = $member['semester'] ?? null;
        if ($memberSemester !== null && !in_array((int) $memberSemester, $this->semesterCandidatesForManualMerge($offering), true)) {
            return false;
        }

        $memberClassLabel = $this->normalizeManualMergeText((string) ($member['class_label'] ?? ''));
        if ($memberClassLabel !== ''
            && $memberClassLabel !== $this->normalizeManualMergeText((string) ($offering['class_label'] ?? ''))
        ) {
            return false;
        }

        $memberInstructor = $this->normalizeManualMergeText((string) ($member['instructor_name'] ?? ''));
        if ($memberInstructor !== ''
            && $memberInstructor !== $this->normalizeManualMergeText((string) ($offering['instructor_name'] ?? $offering['instructor'] ?? ''))
        ) {
            return false;
        }

        return true;
    }

    public function manualCourseMergeGroupingKeyForExam($exam): ?string
    {
        return $this->manualCourseMergeGroupingKeyForOffering([
            'course_code' => (string) ($exam->course?->code ?? ''),
            'program_code' => (string) ($exam->program_code ?? ''),
            'class_label' => (string) ($exam->class_label ?? ''),
            'semester_no' => $exam->semester_no,
            'merged_class_labels' => (string) ($exam->merged_class_labels ?? ''),
        ]);
    }

    public function manualCourseMergeDescriptionForKey(?string $key): string
    {
        $key = trim((string) $key);
        if ($key === '') {
            return '';
        }

        foreach ($this->manualCourseMergeGroups() as $group) {
            if ((string) ($group['key'] ?? '') === $key) {
                return (string) ($group['description'] ?? $key);
            }
        }

        return $key;
    }

    public function sameCourseTogetherCodeSet(): array
    {
        $rules = $this->sameCourseTogetherRules();
        $codes = $rules['global_courses'] ?? [];

        foreach (array_keys((array) ($rules['course_programs'] ?? [])) as $courseCode) {
            $codes[$courseCode] = true;
        }

        return $codes;
    }

    public function shouldGroupCourseCodeTogether(?string $courseCode): bool
    {
        $normalized = $this->normalizeCoursePreferenceCode((string) $courseCode);
        if ($normalized === '') {
            return false;
        }

        $rules = $this->sameCourseTogetherRules();
        $hasScopedRules = $this->sameCourseTogetherRulesHaveSelection($rules);

        if (isset($rules['global_courses'][$normalized])) {
            return true;
        }

        if (isset($rules['course_programs'][$normalized])) {
            return true;
        }

        return !$hasScopedRules && (bool) $this->schedule_same_course_code_together;
    }

    public function sameCourseGroupingKeyForExam($exam): ?string
    {
        $manualKey = $this->manualCourseMergeGroupingKeyForExam($exam);
        if ($manualKey !== null) {
            return $manualKey;
        }

        return $this->sameCourseGroupingKeyForOffering([
            'course_code' => (string) ($exam->course?->code ?? ''),
            'course_title' => (string) ($exam->course?->title ?? ''),
            'program_code' => (string) ($exam->program_code ?? ''),
            'class_label' => (string) ($exam->class_label ?? ''),
            'semester_no' => $exam->semester_no,
            'instructor_name' => (string) ($exam->instructor ?? ''),
            'merged_class_labels' => (string) ($exam->merged_class_labels ?? ''),
        ]);
    }

    public function sameCourseGroupingKeyForOffering(array $offering): ?string
    {
        if ($this->isPostgraduateOffering($offering)) {
            $instructorName = $this->normalizeInstructorName((string) ($offering['instructor_name'] ?? $offering['instructor'] ?? ''));
            if ($instructorName !== '') {
                return 'PG_TEACHER|' . $instructorName;
            }
        }

        $courseCode = $this->normalizeCoursePreferenceCode((string) ($offering['course_code'] ?? ''));
        $programCode = $this->normalizedProgramForSameCourseRule($offering);
        if ($courseCode !== '') {
            $explicitSameCourseKey = $this->explicitSameCourseGroupingKey($courseCode, $programCode);
            if ($explicitSameCourseKey !== null) {
                return $explicitSameCourseKey;
            }
        }

        $manualKey = $this->manualCourseMergeGroupingKeyForOffering($offering);
        if ($manualKey !== null && $this->manualCourseMergeGroupHasMultipleCourseTitles($manualKey)) {
            return $manualKey;
        }

        $courseTitle = $this->normalizeCourseTitleForGrouping((string) ($offering['course_title'] ?? ''));
        $instructorName = $this->normalizeInstructorName((string) ($offering['instructor_name'] ?? $offering['instructor'] ?? ''));
        if ($courseTitle !== '' && $instructorName !== '') {
            return 'COURSE_TITLE_TEACHER|' . $courseTitle . '|' . $instructorName;
        }

        if ($courseTitle !== '') {
            return 'COURSE_TITLE|' . $courseTitle;
        }

        if ($manualKey !== null) {
            return $manualKey;
        }

        if ($courseCode === '') {
            return null;
        }

        $rules = $this->sameCourseTogetherRules();
        $hasScopedRules = $this->sameCourseTogetherRulesHaveSelection($rules);

        if (!$hasScopedRules && (bool) $this->schedule_same_course_code_together) {
            return 'COURSE|' . $courseCode;
        }

        return null;
    }

    private function manualCourseMergeGroupHasMultipleCourseTitles(string $key): bool
    {
        foreach ($this->manualCourseMergeGroups() as $group) {
            if ((string) ($group['key'] ?? '') !== $key) {
                continue;
            }

            $courseCodes = [];
            foreach ((array) ($group['members'] ?? []) as $member) {
                $courseCode = $this->normalizeCoursePreferenceCode((string) ($member['course'] ?? ''));
                if ($courseCode !== '') {
                    $courseCodes[$courseCode] = true;
                }
            }

            if (count($courseCodes) <= 1) {
                return false;
            }

            if (Course::getConnectionResolver() === null) {
                return true;
            }

            $titles = Course::query()
                ->whereIn('code', array_keys($courseCodes))
                ->pluck('title')
                ->map(fn ($title) => $this->normalizeCourseTitleForGrouping((string) $title))
                ->filter()
                ->unique()
                ->values();

            return $titles->count() > 1;
        }

        return false;
    }

    private function explicitSameCourseGroupingKey(string $courseCode, string $programCode): ?string
    {
        $rules = $this->sameCourseTogetherRules();

        if (isset($rules['global_courses'][$courseCode])) {
            return 'COURSE|' . $courseCode;
        }

        $coursePrograms = (array) ($rules['course_programs'][$courseCode] ?? []);
        if ($programCode !== '' && isset($coursePrograms[$programCode])) {
            $programs = array_keys($coursePrograms);
            sort($programs);
            return 'COURSE_PROGRAM|' . $courseCode . '|' . implode(',', $programs);
        }

        foreach ((array) ($rules['program_groups'] ?? []) as $programs) {
            $programs = array_values(array_filter(array_map('strval', (array) $programs)));
            sort($programs);
            if ($programCode !== '' && in_array($programCode, $programs, true)) {
                return 'PROGRAM_GROUP|' . $courseCode . '|' . implode(',', $programs);
            }
        }

        return null;
    }

    public function sameCourseGroupingDescriptionForKey(?string $key): string
    {
        $key = trim((string) $key);
        if ($key === '') {
            return '';
        }

        $parts = explode('|', $key);
        $type = strtoupper((string) ($parts[0] ?? ''));
        $courseCode = (string) ($parts[1] ?? '');
        $programs = array_filter(array_map('trim', explode(',', (string) ($parts[2] ?? ''))));
        $programText = $programs !== [] ? implode(', ', $programs) : '';

        if ($type === 'COURSE') {
            return $courseCode . ' across all programs';
        }

        if ($type === 'COURSE_PROGRAM') {
            return $courseCode . ' for selected program(s): ' . $programText;
        }

        if ($type === 'PROGRAM_GROUP') {
            return $courseCode . ' for configured program group: ' . $programText;
        }

        if ($type === 'COURSE_TITLE_TEACHER') {
            return 'same course title and teacher: ' . (string) ($parts[1] ?? '') . ' / ' . (string) ($parts[2] ?? '');
        }

        if ($type === 'COURSE_TITLE') {
            return 'same course title: ' . (string) ($parts[1] ?? '');
        }

        if ($type === 'PG_TEACHER') {
            return 'PG courses taught by ' . (string) ($parts[1] ?? '');
        }

        if ($type === 'MANUAL_MERGE') {
            return 'manual merge group: ' . $this->manualCourseMergeDescriptionForKey($key);
        }

        return $key;
    }

    private function sameCourseTogetherRulesHaveSelection(array $rules): bool
    {
        return !empty($rules['global_courses'])
            || !empty($rules['course_programs'])
            || !empty($rules['program_groups']);
    }

    private function normalizedProgramForSameCourseRule(array $offering): string
    {
        foreach ([(string) ($offering['program_code'] ?? ''), (string) ($offering['class_label'] ?? '')] as $candidate) {
            $programCode = $this->normalizeProgramPreferenceCode($candidate);
            if ($programCode !== '' && $programCode !== '*') {
                return $programCode;
            }
        }

        return '';
    }

    private function normalizeCourseTitleForGrouping(string $courseTitle): string
    {
        return strtoupper(trim((string) preg_replace('/\s+/', ' ', $courseTitle)));
    }

    private function parseManualCourseMergeLine(string $line, int $lineNumber): array
    {
        $label = 'Group ' . $lineNumber;
        $membersText = $line;

        if (preg_match('/^([^:=]+)\s*(?:=>|=|:)\s*(.+)$/', $line, $matches)) {
            $possibleLabel = trim((string) $matches[1]);
            $membersTextAfterLabel = trim((string) $matches[2]);
            if (!$this->looksLikeCourseCodeOnly($possibleLabel)
                || $this->looksLikeCourseCodeLabelForStructuredMembers($possibleLabel, $membersTextAfterLabel)
            ) {
                $label = $possibleLabel !== '' ? $possibleLabel : $label;
                $membersText = $membersTextAfterLabel;
            }
        }

        return [$label, $membersText];
    }

    private function looksLikeCourseCodeOnly(string $value): bool
    {
        $value = trim($value);
        if ($value === '') {
            return false;
        }

        return preg_match('/^[A-Z]+\d+[A-Z0-9-]*$/i', $value) === 1;
    }

    private function looksLikeCourseCodeLabelForStructuredMembers(string $label, string $membersText): bool
    {
        if (!str_contains($membersText, ',')) {
            return false;
        }

        $firstChunk = (string) ((preg_split('/\s*[;|]\s*/', $membersText) ?: [''])[0] ?? '');
        $firstFields = str_getcsv($firstChunk);

        return $this->normalizeCoursePreferenceCode((string) ($firstFields[0] ?? ''))
            === $this->normalizeCoursePreferenceCode($label);
    }

    private function parseManualCourseMergeMembers(string $membersText): array
    {
        $members = [];
        $chunks = preg_split('/\s*[;|]\s*/', $membersText) ?: [];

        if (count($chunks) > 1) {
            foreach ($chunks as $chunk) {
                [$course, $program, $semester, $classLabel, $instructorName] = $this->parseManualCourseMergeMember($chunk);
                if ($course !== '') {
                    $members[$this->manualCourseMergeMemberKey($course, $program, $semester, $classLabel, $instructorName)] = [
                        'course' => $course,
                        'program' => $program,
                        'semester' => $semester,
                        'class_label' => $classLabel,
                        'instructor_name' => $instructorName,
                    ];
                }
            }

            return array_values($members);
        }

        $tokens = preg_split('/[\s,]+/', strtoupper($membersText)) ?: [];
        $tokens = array_values(array_filter(array_map(
            fn ($token) => trim((string) preg_replace('/[^A-Z0-9*]/', '', (string) $token)),
            $tokens
        )));

        for ($index = 0; $index < count($tokens); $index++) {
            $token = $tokens[$index];
            if ($token === '' || !preg_match('/\d/', $token)) {
                continue;
            }

            $course = $this->normalizeCoursePreferenceCode($token);
            $program = '*';
            $semester = null;
            $next = (string) ($tokens[$index + 1] ?? '');
            if ($next !== '' && !preg_match('/\d/', $next)) {
                $program = $this->normalizeProgramPreferenceCode($next) ?: '*';
                $index++;
            }
            $next = (string) ($tokens[$index + 1] ?? '');
            if ($next !== '' && $this->looksLikeSemesterToken($next)) {
                $semester = $this->normalizeSemesterToken($next);
                $index++;
            }

            if ($course !== '') {
                $members[$this->manualCourseMergeMemberKey($course, $program, $semester)] = [
                    'course' => $course,
                    'program' => $program,
                    'semester' => $semester,
                    'class_label' => '',
                    'instructor_name' => '',
                ];
            }
        }

        return array_values($members);
    }

    private function parseManualCourseMergeMember(string $member): array
    {
        $member = strtoupper(trim($member));
        if ($member === '') {
            return ['', '*', null, '', ''];
        }

        if (str_contains($member, ',')) {
            $fields = array_map(
                fn ($field) => trim((string) $field),
                str_getcsv($member)
            );

            $course = $this->normalizeCoursePreferenceCode((string) ($fields[0] ?? ''));
            $program = $this->normalizeProgramPreferenceCode((string) ($fields[1] ?? '*')) ?: '*';
            $semester = null;
            $semesterText = trim((string) ($fields[2] ?? ''));
            if ($semesterText !== '' && $semesterText !== '*') {
                $semester = $this->normalizeSemesterToken($semesterText);
            }

            return [
                $course,
                $program,
                $semester,
                $this->normalizeManualMergeText((string) ($fields[3] ?? '')),
                $this->normalizeManualMergeText((string) ($fields[4] ?? '')),
            ];
        }

        $tokens = preg_split('/[\s,:]+/', $member) ?: [];
        $tokens = array_values(array_filter(array_map(
            fn ($token) => trim((string) preg_replace('/[^A-Z0-9*]/', '', (string) $token)),
            $tokens
        )));

        $course = '';
        $program = '*';
        $semester = null;

        foreach ($tokens as $token) {
            if ($course === '' && preg_match('/\d/', $token)) {
                $course = $this->normalizeCoursePreferenceCode($token);
                continue;
            }

            if ($course !== '' && $this->looksLikeSemesterToken($token)) {
                $semester = $this->normalizeSemesterToken($token);
                continue;
            }

            if ($course !== '' && !preg_match('/\d/', $token)) {
                $program = $this->normalizeProgramPreferenceCode($token) ?: '*';
                continue;
            }
        }

        return [$course, $program, $semester, '', ''];
    }

    private function manualCourseMergeMemberKey(
        string $course,
        string $program,
        ?int $semester,
        string $classLabel = '',
        string $instructorName = ''
    ): string
    {
        return implode(':', [
            $course,
            $program,
            $semester !== null ? (string) $semester : '*',
            $this->normalizeManualMergeText($classLabel),
            $this->normalizeManualMergeText($instructorName),
        ]);
    }

    private function manualCourseMergeMemberLabel(array $member): string
    {
        $label = (string) ($member['course'] ?? '');
        $program = (string) ($member['program'] ?? '*');
        if ($program !== '*') {
            $label .= ':' . $program;
        }

        if (($member['semester'] ?? null) !== null) {
            $label .= ':' . (int) $member['semester'];
        }

        $classLabel = (string) ($member['class_label'] ?? '');
        if ($classLabel !== '') {
            $label .= ':' . $classLabel;
        }

        $instructorName = (string) ($member['instructor_name'] ?? '');
        if ($instructorName !== '') {
            $label .= ':' . $instructorName;
        }

        return $label;
    }

    private function normalizeManualMergeText(string $value): string
    {
        $value = trim((string) preg_replace('/\s+/', ' ', $value));
        if ($value === '' || $value === '*') {
            return '';
        }

        return strtoupper($value);
    }

    private function normalizedSemesterForManualMerge(mixed $semesterNo, string $classLabel = ''): ?int
    {
        if ($semesterNo !== null && $semesterNo !== '') {
            $value = (int) $semesterNo;
            return $value > 0 ? $value : null;
        }

        if (preg_match('/(?:^|[-\s])([IVXLCDM]+|\d{1,2})$/i', trim($classLabel), $matches)) {
            return $this->normalizeSemesterToken((string) $matches[1]);
        }

        return null;
    }

    private function semesterCandidatesForManualMerge(array $offering): array
    {
        $semesters = [];
        $primary = $this->normalizedSemesterForManualMerge(
            $offering['semester_no'] ?? null,
            (string) ($offering['class_label'] ?? '')
        );
        if ($primary !== null) {
            $semesters[$primary] = true;
        }

        foreach ((array) ($offering['merged_class_labels'] ?? []) as $label) {
            $semester = $this->normalizedSemesterForManualMerge(null, (string) $label);
            if ($semester !== null) {
                $semesters[$semester] = true;
            }
        }

        $rawMergedClassLabels = is_array($offering['merged_class_labels'] ?? null)
            ? ''
            : (string) ($offering['merged_class_labels'] ?? '');
        if ($rawMergedClassLabels !== '') {
            foreach (preg_split('/[|;,]+/', $rawMergedClassLabels) ?: [] as $label) {
                $semester = $this->normalizedSemesterForManualMerge(null, (string) $label);
                if ($semester !== null) {
                    $semesters[$semester] = true;
                }
            }
        }

        $semesters = array_keys($semesters);
        sort($semesters);

        return array_map('intval', $semesters);
    }

    private function looksLikeSemesterToken(string $token): bool
    {
        return preg_match('/^(?:SEM(?:ESTER)?[-_ ]*)?(?:\d{1,2}|[IVXLCDM]+)$/i', trim($token)) === 1;
    }

    private function normalizeSemesterToken(string $token): ?int
    {
        $token = strtoupper(trim((string) preg_replace('/^SEM(?:ESTER)?[-_ ]*/i', '', $token)));
        if ($token === '' || $token === '*') {
            return null;
        }

        if (ctype_digit($token)) {
            $value = (int) $token;
            return $value > 0 ? $value : null;
        }

        $map = ['I' => 1, 'V' => 5, 'X' => 10, 'L' => 50, 'C' => 100, 'D' => 500, 'M' => 1000];
        $total = 0;
        $previous = 0;
        for ($index = strlen($token) - 1; $index >= 0; $index--) {
            $value = $map[$token[$index]] ?? 0;
            if ($value <= 0) {
                return null;
            }

            if ($value < $previous) {
                $total -= $value;
            } else {
                $total += $value;
                $previous = $value;
            }
        }

        return $total > 0 ? $total : null;
    }

    public function shouldMergeSameCourseProgramInstructor(): bool
    {
        return (bool) ($this->merge_same_course_program_instructor ?? false);
    }

    public function mergedOfferingKey(array $offering): string
    {
        $programCode = strtoupper(trim((string) ($offering['program_code'] ?? '')));
        if ($programCode === '') {
            $classLabel = strtoupper(trim((string) ($offering['class_label'] ?? '')));
            if (preg_match('/^[A-Z]+/', $classLabel, $matches)) {
                $programCode = $matches[0];
            }
        }

        return implode('|', [
            strtoupper(trim((string) ($offering['course_code'] ?? ''))),
            $programCode,
            $this->normalizeInstructorName((string) ($offering['instructor_name'] ?? '')),
        ]);
    }

    public function normalizeInstructorName(string $name): string
    {
        $normalized = preg_replace('/\s+/', ' ', trim($name));
        return strtoupper((string) $normalized);
    }

    public function postgraduateCourseCodeSet(): array
    {
        $raw = (string) ($this->pg_course_codes ?? '');
        if (trim($raw) === '') {
            return [];
        }

        $tokens = preg_split('/[\s,;|]+/', strtoupper($raw)) ?: [];
        $codes = [];
        foreach ($tokens as $token) {
            $code = trim((string) preg_replace('/[^A-Z0-9]/', '', (string) $token));
            if ($code !== '') {
                $codes[$code] = true;
            }
        }

        return $codes;
    }

    public function postgraduateProgramPrefixes(): array
    {
        $raw = trim((string) ($this->pg_program_prefixes ?? 'P,R'));
        if ($raw === '') {
            return [];
        }

        $tokens = preg_split('/[\s,;|]+/', strtoupper($raw)) ?: [];
        return array_values(array_filter(array_map(fn ($v) => trim((string) $v), $tokens)));
    }

    public function isPostgraduateOffering(array $offering): bool
    {
        $courseCode = strtoupper(trim((string) ($offering['course_code'] ?? '')));
        if ($courseCode !== '' && isset($this->postgraduateCourseCodeSet()[$courseCode])) {
            return true;
        }

        $programCode = strtoupper(trim((string) ($offering['program_code'] ?? '')));
        foreach ($this->postgraduateProgramPrefixes() as $prefix) {
            if ($prefix !== '' && str_starts_with($programCode, $prefix)) {
                return true;
            }
        }

        $minimumLevel = (int) ($this->pg_course_level_min ?? 700);
        if ($minimumLevel > 0 && preg_match('/(\d{3,4})/', $courseCode, $matches)) {
            return ((int) $matches[1]) >= $minimumLevel;
        }

        return false;
    }

    public function isUndergraduateOffering(array $offering): bool
    {
        if ($this->isPostgraduateOffering($offering)) {
            return false;
        }

        $programCode = strtoupper(trim((string) ($offering['program_code'] ?? '')));
        if ($programCode !== '') {
            return str_starts_with($programCode, 'B');
        }

        $classLabel = strtoupper(trim((string) ($offering['class_label'] ?? '')));

        return str_starts_with($classLabel, 'B');
    }
}
