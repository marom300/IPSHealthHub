<?php

declare(strict_types=1);

/**
 * In-memory archive. setVariableValue() mimics IPS: a changed value is
 * logged with the current time (synchronously here).
 */
class FakeArchiveBackend implements HHArchiveBackend
{
    /** @var array<int, array> varId => rows */
    public $rows = [];
    /** @var array<int, mixed> */
    public $values = [];
    /** @var callable */
    private $now;
    /** @var array call log */
    public $calls = [];

    public function __construct(callable $now)
    {
        $this->now = $now;
    }

    public function getLoggedValues(int $varId, int $start, int $end): array
    {
        $out = array_values(array_filter($this->rows[$varId] ?? [], static function ($r) use ($start, $end) {
            return $r['TimeStamp'] >= $start && $r['TimeStamp'] <= $end;
        }));
        usort($out, static function ($a, $b) {
            return $a['TimeStamp'] <=> $b['TimeStamp'];
        });
        return $out;
    }

    public function deleteVariableData(int $varId, int $start, int $end): void
    {
        $this->calls[] = 'delete';
        $this->rows[$varId] = array_values(array_filter($this->rows[$varId] ?? [], static function ($r) use ($start, $end) {
            return $r['TimeStamp'] < $start || $r['TimeStamp'] > $end;
        }));
    }

    public function addLoggedValues(int $varId, array $values): void
    {
        $this->calls[] = 'add';
        foreach ($values as $v) {
            $this->rows[$varId][] = ['TimeStamp' => (int) $v['TimeStamp'], 'Value' => $v['Value']];
        }
    }

    public function reAggregateVariable(int $varId): void
    {
        $this->calls[] = 'reaggregate';
    }

    public function getVariableValue(int $varId)
    {
        return $this->values[$varId] ?? 0;
    }

    public function setVariableValue(int $varId, $value): void
    {
        $this->calls[] = 'set';
        $old = $this->values[$varId] ?? 0;
        $this->values[$varId] = $value;
        if ($old != $value) {
            $this->rows[$varId][] = ['TimeStamp' => (int) ($this->now)(), 'Value' => $value];
        }
    }
}
