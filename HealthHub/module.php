<?php

declare(strict_types=1);

require_once __DIR__ . '/libs/DayClock.php';
require_once __DIR__ . '/libs/ArchiveWriter.php';

/**
 * HealthHub - IP-Symcon module
 * ----------------------------
 * Collects daily health and nutrition values into archived variables
 * (one logged value per day, 12:00 local time) and - in later phases -
 * receives Health Connect data via WebHook, imports the FDDB diary and
 * exposes everything read-only to Claude via an integrated MCP server.
 *
 * Phase 1: variables, profiles, archiving, idempotent ArchiveWriter,
 *          waist input, nightly consolidation, archive self test.
 */
class HealthHub extends IPSModule
{
    private const GUID_ARCHIVE = '{43192F0B-135B-4CE7-A0A7-1475603F3060}';

    private const STATUS_INVALID_TZ = 201;
    private const STATUS_NO_ARCHIVE = 202;

    private const WAIST_MIN = 30.0;
    private const WAIST_MAX = 250.0;

    /** Consolidation timer: daily at 00:10 local, covers the last N finished days. */
    private const CONSOLIDATE_TIME = '00:10:00';
    private const CONSOLIDATE_DAYS = 3;

    /** Housekeeping timer interval (s) while something is pending. */
    private const PENDING_INTERVAL = 20;
    /** Initial-value purge: search window around logging start (s), and how long to keep looking (s). */
    private const PURGE_WINDOW = 120;
    private const PURGE_KEEP = 180;

    /**
     * Daily variables: ident => [type, name, profile, position]
     * type: 1 = integer, 2 = float. All of them are archived (aggregation "Standard").
     */
    private const DAILY_VARS = [
        // nutrition (FDDB)
        'KcalIn'       => [2, 'Calories eaten', 'HH.kcal', 10],
        'Protein'      => [2, 'Protein', 'HH.gram', 11],
        'Fat'          => [2, 'Fat', 'HH.gram', 12],
        'Carbs'        => [2, 'Carbohydrates', 'HH.gram', 13],
        'Sugar'        => [2, 'Sugar', 'HH.gram', 14],
        'Fiber'        => [2, 'Fiber', 'HH.gram', 15],
        // body (Fitdays via Health Connect, waist manual)
        'Weight'       => [2, 'Weight', 'HH.kg', 20],
        'BodyFat'      => [2, 'Body fat', 'HH.percent', 21],
        'LeanMass'     => [2, 'Lean body mass', 'HH.kg', 22],
        'BodyWater'    => [2, 'Body water', 'HH.kg', 23],
        'BoneMass'     => [2, 'Bone mass', 'HH.kg', 24],
        'Waist'        => [2, 'Waist circumference', 'HH.cm', 25],
        // activity & sleep (Pixel Watch via Health Connect)
        'Steps'        => [1, 'Steps', 'HH.steps', 30],
        'ActiveKcal'   => [2, 'Active calories (watch)', 'HH.kcal', 31],
        'SleepMinutes' => [1, 'Sleep duration', 'HH.minutes', 32],
        'RestingHR'    => [1, 'Resting heart rate', 'HH.bpm', 33],
    ];

    public function Create()
    {
        parent::Create();

        // --- general ---
        $this->RegisterPropertyInteger('ArchiveID', 0);              // 0 = first Archive Control
        $this->RegisterPropertyString('TimeZone', 'Europe/Vienna');
        $this->RegisterPropertyBoolean('DebugMode', false);

        // --- goals / profile (served by the MCP tool get_profile in phase 4) ---
        $this->RegisterPropertyInteger('KcalTarget', 1800);
        $this->RegisterPropertyInteger('ProteinTarget', 130);
        $this->RegisterPropertyInteger('FatTarget', 60);
        $this->RegisterPropertyFloat('PaceMin', -0.5);               // kg/week, faster end
        $this->RegisterPropertyFloat('PaceMax', -0.4);               // kg/week, slower end
        $this->RegisterPropertyString('StartDate', '2026-09-01');
        $this->RegisterPropertyFloat('StartWeight', 74.2);
        $this->RegisterPropertyFloat('StartBodyFat', 21.4);
        $this->RegisterPropertyFloat('StartLeanMass', 58.2);

        // --- internal queues (archive housekeeping) ---
        $this->RegisterAttributeString('PendingReaggregate', '[]');  // [varId, ...]
        $this->RegisterAttributeString('PendingPurge', '[]');        // [{id, ts}, ...]

        $this->RegisterTimer('Consolidate', 0, 'HH_Consolidate($_IPS[\'TARGET\']);');
        $this->RegisterTimer('Pending', 0, 'HH_ProcessPending($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $this->maintainProfiles();

        foreach (self::DAILY_VARS as $ident => [$type, $name, $profile, $pos]) {
            if ($type === 1) {
                $this->RegisterVariableInteger($ident, $this->Translate($name), $profile, $pos);
            } else {
                $this->RegisterVariableFloat($ident, $this->Translate($name), $profile, $pos);
            }
        }
        $this->EnableAction('Waist');

        $this->RegisterVariableInteger('LastIngest', $this->Translate('Last Health Connect ingest'), '~UnixTimestamp', 90);
        $this->RegisterVariableInteger('LastFddbImport', $this->Translate('Last FDDB import'), '~UnixTimestamp', 91);
        $this->RegisterVariableString('Status', $this->Translate('Status'), '', 92);

        if (!$this->isValidTimeZone($this->ReadPropertyString('TimeZone'))) {
            $this->SetTimerInterval('Consolidate', 0);
            $this->SetStatus(self::STATUS_INVALID_TZ);
            return;
        }
        if ($this->archiveId() === 0) {
            $this->SetTimerInterval('Consolidate', 0);
            $this->SetStatus(self::STATUS_NO_ARCHIVE);
            return;
        }

        // the archive may not be ready during kernel start-up
        if (IPS_GetKernelRunlevel() == KR_READY) {
            $this->enableArchiving();
        } else {
            $this->RegisterMessage(0, IPS_KERNELSTARTED);
        }
        $this->scheduleConsolidation();
        $this->updatePendingTimer();
        $this->SetStatus(IS_ACTIVE);
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        if ($Message == IPS_KERNELSTARTED && $this->archiveId() > 0) {
            $this->enableArchiving();
        }
    }

    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case 'Waist':
                $this->SetWaist((float) $Value, '');
                break;
            default:
                throw new Exception('Invalid ident: ' . $Ident);
        }
    }

    /**
     * Stores a waist measurement (cm) for a day. $date = '' means today.
     * HH_SetWaist(int $InstanceID, float $cm, string $date = '')
     */
    public function SetWaist(float $cm, string $date = ''): bool
    {
        $clock = $this->clock();
        $day = $date === '' ? $clock->today() : $date;
        if (!HHDayClock::isValidDay($day)) {
            echo $this->Translate('Invalid date, expected YYYY-MM-DD') . ': ' . $day;
            return false;
        }
        if ($day > $clock->today()) {
            echo $this->Translate('Date must not be in the future') . ': ' . $day;
            return false;
        }
        if ($cm < self::WAIST_MIN || $cm > self::WAIST_MAX) {
            echo sprintf($this->Translate('Waist must be between %d and %d cm'), (int) self::WAIST_MIN, (int) self::WAIST_MAX);
            return false;
        }
        $stats = $this->writeDaily('Waist', [$day => round($cm, 1)]);
        return count($stats['skipped']) === 0;
    }

    /**
     * Timer target: normalises the last finished days of all daily variables
     * to exactly one archive entry at 12:00 local time.
     */
    public function Consolidate(): void
    {
        $this->scheduleConsolidation();
        $archiveId = $this->archiveId();
        if ($archiveId === 0) {
            return;
        }
        $clock = $this->clock();
        $backend = $this->backend($archiveId);
        $writer = new HHArchiveWriter($backend, $clock);
        $changed = 0;
        foreach (array_keys(self::DAILY_VARS) as $ident) {
            $varId = $this->GetIDForIdent($ident);
            $varChanged = false;
            for ($i = 1; $i <= self::CONSOLIDATE_DAYS; $i++) {
                if ($writer->consolidateDay($varId, $clock->addDays($clock->today(), -$i), false)) {
                    $varChanged = true;
                    $changed++;
                }
            }
            if ($varChanged) {
                $backend->reAggregateVariable($varId);
            }
        }
        $this->SendDebug('Consolidate', $changed . ' day(s) normalised', 0);
    }

    /**
     * Timer target: archive housekeeping that cannot be done immediately.
     *  - removes the 0 entry IPS logs when logging is switched on for a variable
     *    (it would otherwise look like a real "0 kcal" day)
     *  - retries re-aggregations that failed because another aggregation was running
     */
    public function ProcessPending(): void
    {
        $archiveId = $this->archiveId();
        if ($archiveId === 0) {
            $this->SetTimerInterval('Pending', 0);
            return;
        }
        $backend = $this->backend($archiveId);
        $now = time();

        $keep = [];
        foreach (json_decode($this->ReadAttributeString('PendingPurge'), true) ?: [] as $item) {
            $varId = (int) $item['id'];
            $ts = (int) $item['ts'];
            if (!IPS_VariableExists($varId)) {
                continue;
            }
            $removed = 0;
            foreach ($backend->getLoggedValues($varId, $ts - self::PURGE_WINDOW, $ts + self::PURGE_WINDOW) as $row) {
                if ((float) $row['Value'] == 0.0) {
                    $backend->deleteVariableData($varId, (int) $row['TimeStamp'], (int) $row['TimeStamp']);
                    $removed++;
                }
            }
            if ($removed > 0) {
                $this->SendDebug('Purge', IPS_GetName($varId) . ': ' . $removed . ' initial entry/entries removed', 0);
                $this->queueReaggregate($varId);
            }
            if ($now < $ts + self::PURGE_KEEP) {
                $keep[] = $item; // the archive may log the initial value with a delay
            }
        }
        $this->WriteAttributeString('PendingPurge', json_encode($keep));

        $ids = json_decode($this->ReadAttributeString('PendingReaggregate'), true) ?: [];
        $this->WriteAttributeString('PendingReaggregate', '[]');
        foreach ($ids as $varId) {
            if (IPS_VariableExists((int) $varId)) {
                $backend->reAggregateVariable((int) $varId); // re-queues itself on failure
            }
        }
        $this->updatePendingTimer();
    }

    /**
     * Verifies the archive write strategy on this IPS installation with a
     * temporary variable. Returns a human readable report (form button).
     * HH_SelfTestArchive(int $InstanceID)
     */
    public function SelfTestArchive(): string
    {
        $archiveId = $this->archiveId();
        if ($archiveId === 0) {
            return $this->Translate('No archive instance found.');
        }
        $clock = $this->clock();
        $ident = 'ArchiveSelfTest';
        $this->RegisterVariableFloat($ident, 'Archive self test', '', 999);
        $varId = $this->GetIDForIdent($ident);
        IPS_SetHidden($varId, true);
        $enabledAt = time();
        AC_SetLoggingStatus($archiveId, $varId, true);
        AC_SetAggregationType($archiveId, $varId, 0);
        IPS_ApplyChanges($archiveId);

        $backend = new HHIpsArchiveBackend($archiveId); // no retry queue for the temporary variable
        $writer = new HHArchiveWriter($backend, $clock);
        $today = $clock->today();
        $past = $clock->addDays($today, -2);
        [$pastStart, $pastEnd] = $clock->dayRange($past);
        [$todayStart, $todayEnd] = $clock->dayRange($today);
        $settle = function () {
            IPS_Sleep(1500); // archive logs SetValue asynchronously
        };
        $describe = function (array $rows) use ($clock): string {
            return count($rows) . ' [' . implode(', ', array_map(static function ($r) use ($clock) {
                $t = (new DateTimeImmutable('@' . (int) $r['TimeStamp']))->setTimezone($clock->timeZone());
                return $t->format('H:i:s') . '=' . $r['Value'];
            }, $rows)) . ']';
        };
        $lines = [];
        $ok = true;
        $check = function (string $label, array $rows, int $count, float $value) use (&$lines, &$ok, $describe) {
            $pass = count($rows) === $count && ($count === 0 || abs((float) $rows[count($rows) - 1]['Value'] - $value) < 1e-6);
            $ok = $ok && $pass;
            $lines[] = ($pass ? 'OK   ' : 'FAIL ') . $label . ': ' . $describe($rows);
        };

        try {
            // 0) IPS logs the current value when logging is switched on
            $settle();
            $initial = $backend->getLoggedValues($varId, $enabledAt - 10, time() + 10);
            $lines[] = 'INFO entries logged when logging was switched on: ' . $describe($initial)
                . (count($initial) > 0 ? ' -> removed automatically for daily variables' : '');
            $backend->deleteVariableData($varId, 0, time() + 86400);

            // 1) raw IPS behaviour: SetValue + AC_AddLoggedValues on the same day
            //    (timestamp before the SetValue entry - IPS rejects newer than unwritten data)
            SetValue($varId, 42.0);
            $settle();
            $backend->addLoggedValues($varId, [['TimeStamp' => $todayStart + 60, 'Value' => 42.0]]);
            $raw = $backend->getLoggedValues($varId, $todayStart, $todayEnd);
            $lines[] = 'INFO raw SetValue + AC_AddLoggedValues today: ' . $describe($raw)
                . (count($raw) > 1 ? ' -> duplicate, as expected (why the writer avoids it)' : '');
            $backend->deleteVariableData($varId, $todayStart, $todayEnd);

            // 2) past day: write, rewrite same, rewrite changed
            $writer->writeDay($varId, $past, 1.0);
            $writer->writeDay($varId, $past, 1.0);
            $writer->writeDay($varId, $past, 2.0);
            $rows = $backend->getLoggedValues($varId, $pastStart, $pastEnd);
            $check('past day rewritten 3x', $rows, 1, 2.0);
            $noonOk = count($rows) === 1 && (int) $rows[0]['TimeStamp'] === $clock->noon($past);
            $ok = $ok && $noonOk;
            $lines[] = ($noonOk ? 'OK   ' : 'FAIL ') . 'past day timestamp is 12:00 local';

            // 3) today: write, repeat same value, change value
            $writer->writeDay($varId, $today, 10.0);
            $settle();
            $writer->writeDay($varId, $today, 10.0);
            $writer->writeDay($varId, $today, 11.0);
            $settle();
            $check('today written 3x', $backend->getLoggedValues($varId, $todayStart, $todayEnd), 1, 11.0);

            // 4) null deletes the day
            $writer->writeDay($varId, $past, null);
            $check('past day deleted via null', $backend->getLoggedValues($varId, $pastStart, $pastEnd), 0, 0.0);
        } catch (Throwable $e) {
            $ok = false;
            $lines[] = 'FAIL exception: ' . $e->getMessage();
        } finally {
            AC_DeleteVariableData($archiveId, $varId, 0, time() + 86400);
            AC_SetLoggingStatus($archiveId, $varId, false);
            IPS_ApplyChanges($archiveId);
            $this->UnregisterVariable($ident);
        }

        $report = ($ok ? 'ARCHIVE SELF TEST PASSED' : 'ARCHIVE SELF TEST FAILED') . "\n" . implode("\n", $lines);
        $this->SendDebug('SelfTestArchive', $report, 0);
        return $report;
    }

    // ------------------------------------------------------------------
    // internals
    // ------------------------------------------------------------------

    /**
     * Writes daily values of one of the module variables, casting to the variable type.
     * @param array $values ['YYYY-MM-DD' => value|null]
     */
    private function writeDaily(string $ident, array $values): array
    {
        $archiveId = $this->archiveId();
        if ($archiveId === 0) {
            throw new RuntimeException('No archive instance found');
        }
        $type = self::DAILY_VARS[$ident][0];
        foreach ($values as $day => $v) {
            if ($v !== null) {
                $values[$day] = $type === 1 ? (int) round((float) $v) : (float) $v;
            }
        }
        $writer = new HHArchiveWriter($this->backend($archiveId), $this->clock());
        $stats = $writer->writeDays($this->GetIDForIdent($ident), $values);
        $this->SendDebug('Write ' . $ident, json_encode($values) . ' -> ' . json_encode($stats), 0);
        return $stats;
    }

    private function backend(int $archiveId): HHIpsArchiveBackend
    {
        return new HHIpsArchiveBackend($archiveId, function (int $varId) {
            $this->queueReaggregate($varId);
        });
    }

    private function queueReaggregate(int $varId): void
    {
        $ids = json_decode($this->ReadAttributeString('PendingReaggregate'), true) ?: [];
        if (!in_array($varId, $ids, true)) {
            $ids[] = $varId;
            $this->WriteAttributeString('PendingReaggregate', json_encode($ids));
        }
        $this->updatePendingTimer();
    }

    private function updatePendingTimer(): void
    {
        $pending = count(json_decode($this->ReadAttributeString('PendingReaggregate'), true) ?: [])
            + count(json_decode($this->ReadAttributeString('PendingPurge'), true) ?: []);
        $this->SetTimerInterval('Pending', $pending > 0 ? self::PENDING_INTERVAL * 1000 : 0);
    }

    private function clock(): HHDayClock
    {
        $tz = $this->ReadPropertyString('TimeZone');
        return new HHDayClock($this->isValidTimeZone($tz) ? $tz : 'Europe/Vienna');
    }

    private function isValidTimeZone(string $tz): bool
    {
        return in_array($tz, DateTimeZone::listIdentifiers(), true);
    }

    private function archiveId(): int
    {
        $id = $this->ReadPropertyInteger('ArchiveID');
        if ($id > 0 && IPS_InstanceExists($id) && IPS_GetInstance($id)['ModuleInfo']['ModuleID'] === self::GUID_ARCHIVE) {
            return $id;
        }
        $list = IPS_GetInstanceListByModuleID(self::GUID_ARCHIVE);
        return count($list) > 0 ? $list[0] : 0;
    }

    /**
     * Enables logging (aggregation "Standard") for all daily variables.
     * Already logged variables are left untouched; the archive is only
     * re-applied (= restarted) if something actually changed.
     */
    private function enableArchiving(): void
    {
        $archiveId = $this->archiveId();
        $enabled = [];
        foreach (array_keys(self::DAILY_VARS) as $ident) {
            $varId = $this->GetIDForIdent($ident);
            if (!AC_GetLoggingStatus($archiveId, $varId)) {
                AC_SetLoggingStatus($archiveId, $varId, true);
                AC_SetAggregationType($archiveId, $varId, 0);
                $enabled[] = $varId;
            }
        }
        if (count($enabled) > 0) {
            IPS_ApplyChanges($archiveId);
            // IPS logs the current value (0) when logging starts -> remove it shortly after
            $purge = json_decode($this->ReadAttributeString('PendingPurge'), true) ?: [];
            foreach ($enabled as $varId) {
                $purge[] = ['id' => $varId, 'ts' => time()];
            }
            $this->WriteAttributeString('PendingPurge', json_encode($purge));
            $this->updatePendingTimer();
            $this->SendDebug('Archive', 'Logging enabled for ' . count($enabled) . ' daily variable(s)', 0);
        }
    }

    /** Sets the consolidation timer to the next CONSOLIDATE_TIME (DST safe). */
    private function scheduleConsolidation(): void
    {
        $tz = $this->clock()->timeZone();
        $now = new DateTimeImmutable('now', $tz);
        $next = new DateTimeImmutable($now->format('Y-m-d') . ' ' . self::CONSOLIDATE_TIME, $tz);
        if ($next <= $now) {
            $next = $next->modify('+1 day');
        }
        $this->SetTimerInterval('Consolidate', max(1, $next->getTimestamp() - $now->getTimestamp()) * 1000);
    }

    private function maintainProfiles(): void
    {
        $this->ensureProfile('HH.kcal', VARIABLETYPE_FLOAT, ' kcal', 0);
        $this->ensureProfile('HH.gram', VARIABLETYPE_FLOAT, ' g', 1);
        $this->ensureProfile('HH.kg', VARIABLETYPE_FLOAT, ' kg', 2);
        $this->ensureProfile('HH.percent', VARIABLETYPE_FLOAT, ' %', 1);
        $this->ensureProfile('HH.cm', VARIABLETYPE_FLOAT, ' cm', 1);
        IPS_SetVariableProfileValues('HH.cm', self::WAIST_MIN, self::WAIST_MAX, 0.5);
        $this->ensureProfile('HH.steps', VARIABLETYPE_INTEGER, '', 0);
        $this->ensureProfile('HH.minutes', VARIABLETYPE_INTEGER, ' min', 0);
        $this->ensureProfile('HH.bpm', VARIABLETYPE_INTEGER, ' bpm', 0);
    }

    private function ensureProfile(string $name, int $type, string $suffix, int $digits): void
    {
        if (!IPS_VariableProfileExists($name)) {
            IPS_CreateVariableProfile($name, $type);
        }
        IPS_SetVariableProfileText($name, '', $suffix);
        if ($type === VARIABLETYPE_FLOAT) {
            IPS_SetVariableProfileDigits($name, $digits);
        }
    }
}
