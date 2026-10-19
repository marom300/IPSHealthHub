<?php

declare(strict_types=1);

require_once __DIR__ . '/libs/DayClock.php';
require_once __DIR__ . '/libs/ArchiveWriter.php';
require_once __DIR__ . '/libs/HcIngest.php';
require_once __DIR__ . '/libs/HookAuth.php';

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
 * Phase 2: Health Connect ingest WebHook (HC Webhook app), token auth,
 *          raw payload logging, de-duplication and daily aggregation.
 */
class HealthHub extends IPSModule
{
    private const GUID_ARCHIVE = '{43192F0B-135B-4CE7-A0A7-1475603F3060}';

    private const STATUS_INVALID_TZ = 201;
    private const STATUS_NO_ARCHIVE = 202;

    private const HOOK_INGEST = '/hook/healthhub/ingest';
    /** Raw payload files kept in <kernel dir>/HealthHub/raw */
    private const RAW_KEEP_FILES = 300;

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

        // --- Health Connect ingest ---
        $this->RegisterPropertyString('IngestToken', '');            // generated on first ApplyChanges
        $this->RegisterPropertyString('BodySources', '');            // preferred data_origin(s), comma separated
        $this->RegisterPropertyString('ActivitySources', '');
        $this->RegisterPropertyString('MeasureRule', HHHcIngest::RULE_SESSION);
        $this->RegisterAttributeBoolean('RuleMigrated', false);     // v0.6: first -> session once
        $this->RegisterPropertyInteger('IngestKeepDays', 14);
        $this->RegisterAttributeString('IngestState', '{"days":{}}');
        $this->RegisterAttributeString('SeenOrigins', '{}');         // type => origin => [count, last]

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

        // Fitdays does not export body water to Health Connect: keep the variable hidden
        // until a value has ever been written (writeDaily shows it again)
        $waterId = $this->GetIDForIdent('BodyWater');
        if (IPS_GetVariable($waterId)['VariableUpdated'] === 0 && !IPS_GetObject($waterId)['ObjectIsHidden']) {
            IPS_SetHidden($waterId, true);
        }

        $this->RegisterVariableInteger('LastIngest', $this->Translate('Last Health Connect ingest'), '~UnixTimestamp', 90);
        $this->RegisterVariableInteger('LastFddbImport', $this->Translate('Last FDDB import'), '~UnixTimestamp', 91);
        $this->RegisterVariableString('Status', $this->Translate('Status'), '', 92);

        // v0.6: the robust session median became the default - switch the old default once
        if (!$this->ReadAttributeBoolean('RuleMigrated')) {
            $this->WriteAttributeBoolean('RuleMigrated', true);
            if ($this->ReadPropertyString('MeasureRule') === HHHcIngest::RULE_FIRST) {
                IPS_SetProperty($this->InstanceID, 'MeasureRule', HHHcIngest::RULE_SESSION);
                IPS_ApplyChanges($this->InstanceID);
                return;
            }
        }

        if ($this->ReadPropertyString('IngestToken') === '') {
            IPS_SetProperty($this->InstanceID, 'IngestToken', HHHookAuth::newToken());
            IPS_ApplyChanges($this->InstanceID);
            return;
        }

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
            $this->registerHook(self::HOOK_INGEST);
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
            $this->registerHook(self::HOOK_INGEST);
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
     * WebHook entry point (WebHook Control calls this for registered hooks).
     */
    public function ProcessHookData()
    {
        $hook = (string) ($_SERVER['HOOK'] ?? parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH));
        if (rtrim($hook, '/') !== self::HOOK_INGEST) {
            $this->respond(404, ['ok' => false, 'error' => 'unknown hook']);
            return;
        }
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            header('Allow: POST');
            $this->respond(405, ['ok' => false, 'error' => 'POST only']);
            return;
        }
        if (!HHHookAuth::isAuthorized($_SERVER, $this->ReadPropertyString('IngestToken'))) {
            $this->SendDebug('Ingest', 'rejected: invalid or missing token from ' . ($_SERVER['REMOTE_ADDR'] ?? '?'), 0);
            header('WWW-Authenticate: Bearer');
            $this->respond(401, ['ok' => false, 'error' => 'unauthorized']);
            return;
        }
        $result = $this->ingest((string) file_get_contents('php://input'));
        $this->respond($result['ok'] ? 200 : 400, $result);
    }

    /**
     * Processes a payload like the WebHook would - for tests without the phone,
     * e.g. with a raw file from <kernel dir>/HealthHub/raw.
     * HH_IngestJson(int $InstanceID, string $Json)
     */
    public function IngestJson(string $Json): string
    {
        return json_encode($this->ingest($Json), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    /** Creates a new ingest token (old one stops working immediately). */
    public function RegenerateIngestToken(): void
    {
        IPS_SetProperty($this->InstanceID, 'IngestToken', HHHookAuth::newToken());
        IPS_ApplyChanges($this->InstanceID);
        $this->ReloadForm();
    }

    /**
     * Deletes the archived Health Connect values (body, activity, sleep - not FDDB, not waist)
     * for a date range and drops these days from the record buffer.
     * HH_DeleteHealthConnectData(int $InstanceID, string $From, string $To)
     */
    public function DeleteHealthConnectData(string $From, string $To): string
    {
        $clock = $this->clock();
        if (!HHDayClock::isValidDay($From) || !HHDayClock::isValidDay($To) || $From > $To) {
            return $this->Translate('Invalid date range, expected YYYY-MM-DD');
        }
        $days = $clock->daysBetween($From, $To);
        if (count($days) > 400) {
            return $this->Translate('Date range too long (max. 400 days)');
        }
        $archiveId = $this->archiveId();
        if ($archiveId === 0) {
            return $this->Translate('No archive instance found.');
        }

        $writer = new HHArchiveWriter($this->backend($archiveId), $clock);
        $deleted = 0;
        foreach (HHHcIngest::TYPES as $def) {
            $stats = $writer->writeDays($this->GetIDForIdent($def['ident']), array_fill_keys($days, null));
            $deleted += $stats['deleted'];
        }

        if (IPS_SemaphoreEnter('HH_Ingest_' . $this->InstanceID, 30000)) {
            try {
                $state = json_decode($this->ReadAttributeString('IngestState'), true) ?: ['days' => []];
                foreach ($days as $day) {
                    unset($state['days'][$day]);
                }
                $this->WriteAttributeString('IngestState', json_encode($state));
            } finally {
                IPS_SemaphoreLeave('HH_Ingest_' . $this->InstanceID);
            }
        }

        $msg = sprintf($this->Translate('%d day value(s) deleted (%s to %s)'), $deleted, $From, $To);
        $this->SendDebug('Delete', $msg, 0);
        return $msg;
    }

    /** Forgets the buffered Health Connect records (archive data stays untouched). */
    public function ResetIngestState(): void
    {
        $this->WriteAttributeString('IngestState', '{"days":{}}');
        $this->WriteAttributeString('SeenOrigins', '{}');
        $this->ReloadForm();
    }

    public function GetConfigurationForm()
    {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);

        // sources seen so far -> helps to fill in the preferred sources
        $rows = [];
        foreach (json_decode($this->ReadAttributeString('SeenOrigins'), true) ?: [] as $type => $origins) {
            foreach ($origins as $origin => $info) {
                $rows[] = [
                    'Type'   => $type,
                    'Origin' => $origin,
                    'Count'  => (int) $info[0],
                    'Last'   => date('d.m.Y H:i', (int) $info[1]),
                ];
            }
        }
        $url = 'http://<IPS-IP>:3777' . self::HOOK_INGEST;
        $patches = [
            'IngestUrl'   => ['caption' => sprintf($this->Translate('Webhook URL: %s - header: Authorization: Bearer <token>'), $url)],
            'SeenOrigins' => ['values' => $rows],
        ];
        $this->patchForm($form['elements'], $patches);
        $this->patchForm($form['actions'], $patches);
        return json_encode($form);
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
        $varId = $this->GetIDForIdent($ident);
        if ($ident === 'BodyWater' && IPS_GetObject($varId)['ObjectIsHidden'] && count(array_filter($values, 'is_numeric')) > 0) {
            IPS_SetHidden($varId, false); // a source finally delivers body water
        }
        $stats = $writer->writeDays($varId, $values);
        $this->SendDebug('Write ' . $ident, json_encode($values) . ' -> ' . json_encode($stats), 0);
        return $stats;
    }

    /**
     * Parses a HC Webhook payload, merges it into the buffered state and writes
     * every affected day of every affected variable to the archive.
     */
    private function ingest(string $raw): array
    {
        $this->logRaw($raw);
        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            $this->updateStatusText('Ingest: invalid JSON');
            return ['ok' => false, 'error' => 'invalid JSON'];
        }
        if ($this->archiveId() === 0) {
            return ['ok' => false, 'error' => 'no archive instance'];
        }

        $clock = $this->clock();
        $parser = new HHHcIngest(
            $clock,
            HHHcIngest::parseSourceList($this->ReadPropertyString('BodySources')),
            HHHcIngest::parseSourceList($this->ReadPropertyString('ActivitySources')),
            $this->ReadPropertyString('MeasureRule'),
            $this->ReadPropertyInteger('IngestKeepDays')
        );

        if (!IPS_SemaphoreEnter('HH_Ingest_' . $this->InstanceID, 30000)) {
            return ['ok' => false, 'error' => 'busy'];
        }
        try {
            $state = json_decode($this->ReadAttributeString('IngestState'), true) ?: ['days' => []];
            $result = $parser->ingest($payload, $state);
            $this->WriteAttributeString('IngestState', json_encode($result['state']));

            $written = [];
            foreach ($result['touched'] as $ident => $days) {
                // null = no (longer any) value for that day -> the writer deletes the day.
                // Only possible for derived values (lean mass, resting heart rate): days of
                // received types always have records.
                $values = [];
                foreach ($days as $day) {
                    $values[$day] = $parser->computeDay($result['state'], $day, $ident);
                }
                if (count($values) > 0) {
                    $this->writeDaily($ident, $values);
                    $written[$ident] = $values;
                }
            }
        } finally {
            IPS_SemaphoreLeave('HH_Ingest_' . $this->InstanceID);
        }

        $stats = $result['stats'];
        if ($stats['test']) {
            $this->updateStatusText('Ingest ' . date('d.m. H:i') . ': test payload from the app received - OK, not stored');
            $this->SendDebug('Ingest', 'test payload ignored', 0);
            return ['ok' => true, 'test' => true, 'accepted' => 0, 'note' => 'test payload, not stored'];
        }
        $this->rememberOrigins($stats['origins']);
        $this->SetValue('LastIngest', time());
        $this->updateStatusText(sprintf(
            'Ingest %s: %d new, %d duplicate, %d ignored, %d too old, %d partial day, %d HR points',
            date('d.m. H:i'),
            $stats['accepted'],
            $stats['duplicates'],
            $stats['ignored'],
            $stats['too_old'],
            $stats['partial'],
            $stats['hr_points']
        ));
        $this->SendDebug('Ingest', json_encode(['stats' => $stats, 'written' => $written]), 0);

        return [
            'ok'           => true,
            'accepted'     => $stats['accepted'],
            'duplicates'   => $stats['duplicates'],
            'ignored'      => $stats['ignored'],
            'too_old'      => $stats['too_old'],
            'partial'      => $stats['partial'],
            'hr_points'    => $stats['hr_points'],
            'unused_types' => $stats['unused_types'],
            'written'      => $written,
        ];
    }

    /** Debug mode: raw payload into the debug window and a file. */
    private function logRaw(string $raw): void
    {
        if (!$this->ReadPropertyBoolean('DebugMode')) {
            return;
        }
        $this->SendDebug('Ingest raw', $raw, 0);
        $dir = rtrim(IPS_GetKernelDir(), '/\\') . DIRECTORY_SEPARATOR . 'HealthHub' . DIRECTORY_SEPARATOR . 'raw';
        if (!is_dir($dir) && !@mkdir($dir, 0777, true)) {
            $this->SendDebug('Ingest raw', 'cannot create ' . $dir, 0);
            return;
        }
        $file = $dir . DIRECTORY_SEPARATOR . 'hc_' . date('Ymd_His') . '_' . substr(uniqid(), -5) . '.json';
        @file_put_contents($file, $raw);
        $files = glob($dir . DIRECTORY_SEPARATOR . 'hc_*.json') ?: [];
        sort($files);
        while (count($files) > self::RAW_KEEP_FILES) {
            @unlink(array_shift($files));
        }
    }

    private function rememberOrigins(array $origins): void
    {
        if (count($origins) === 0) {
            return;
        }
        $seen = json_decode($this->ReadAttributeString('SeenOrigins'), true) ?: [];
        foreach ($origins as $type => $list) {
            foreach ($list as $origin => $count) {
                $seen[$type][$origin] = [($seen[$type][$origin][0] ?? 0) + $count, time()];
            }
        }
        $this->WriteAttributeString('SeenOrigins', json_encode($seen));
    }

    private function updateStatusText(string $text): void
    {
        $this->SetValue('Status', $text);
    }

    private function respond(int $code, array $body): void
    {
        $reasons = [200 => 'OK', 400 => 'Bad Request', 401 => 'Unauthorized', 404 => 'Not Found', 405 => 'Method Not Allowed'];
        header('HTTP/1.1 ' . $code . ' ' . ($reasons[$code] ?? ''));
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($body, JSON_UNESCAPED_SLASHES);
    }

    /** Recursively sets properties of form elements found by name. */
    private function patchForm(array &$elements, array $patches): void
    {
        foreach ($elements as &$el) {
            if (isset($el['name'], $patches[$el['name']])) {
                $el = array_merge($el, $patches[$el['name']]);
            }
            if (isset($el['items']) && is_array($el['items'])) {
                $this->patchForm($el['items'], $patches);
            }
        }
    }

    private function registerHook(string $hook): void
    {
        $ids = IPS_GetInstanceListByModuleID('{015A6EB8-D6E5-4B93-B496-0D3F77AE9FE1}'); // WebHook Control
        if (count($ids) === 0) {
            return;
        }
        $hookId = $ids[0];
        $hooks = json_decode(IPS_GetProperty($hookId, 'Hooks'), true) ?: [];
        foreach ($hooks as $h) {
            if (($h['Hook'] ?? '') === $hook && (int) ($h['TargetID'] ?? 0) === $this->InstanceID) {
                return; // already registered
            }
        }
        $hooks = array_values(array_filter($hooks, static function ($h) use ($hook) {
            return ($h['Hook'] ?? '') !== $hook;
        }));
        $hooks[] = ['Hook' => $hook, 'TargetID' => $this->InstanceID];
        IPS_SetProperty($hookId, 'Hooks', json_encode($hooks));
        IPS_ApplyChanges($hookId);
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
