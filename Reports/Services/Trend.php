<?php

namespace Modules\Reports\Services;

/**
 * A value alongside the same value in the preceding period.
 *
 * Kept as a tiny value object rather than an array so the views cannot
 * silently show a raw number where a trend was intended.
 */
class Trend
{
    public $current;
    public $previous;

    /** True when a smaller number is the better outcome (e.g. response time). */
    public $lowerIsBetter = false;

    public static function of($current, $previous, $lowerIsBetter = false)
    {
        $t = new self();
        $t->current       = $current;
        $t->previous      = $previous;
        $t->lowerIsBetter = $lowerIsBetter;

        return $t;
    }

    /**
     * Percentage change, or null when there is no meaningful baseline.
     *
     * Growth from zero is deliberately null rather than "infinite" or an
     * arbitrary 100% - the honest statement is "no comparison available",
     * and the view renders it as such.
     */
    public function change()
    {
        if (!$this->previous) {
            return null;
        }

        if ($this->current === null) {
            return null;
        }

        return round((($this->current - $this->previous) / $this->previous) * 100, 1);
    }

    public function direction()
    {
        $change = $this->change();

        if ($change === null || abs($change) < 0.05) {
            return 'flat';
        }

        return $change > 0 ? 'up' : 'down';
    }

    /**
     * Whether this movement should read as good, bad, or neutral.
     *
     * Volume rising is not inherently bad and resolution time rising is not
     * inherently good, so the caller states which way round it is.
     */
    public function sentiment()
    {
        $dir = $this->direction();

        if ($dir === 'flat') {
            return 'neutral';
        }

        $good = $this->lowerIsBetter ? ($dir === 'down') : ($dir === 'up');

        return $good ? 'good' : 'bad';
    }

    public function hasComparison()
    {
        return $this->change() !== null;
    }

    /** Signed, human-readable change, e.g. "+12.4%". */
    public function changeLabel()
    {
        $change = $this->change();

        if ($change === null) {
            return null;
        }

        return ($change > 0 ? '+' : '').$change.'%';
    }
}
