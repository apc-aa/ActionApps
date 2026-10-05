<?php

declare(strict_types=1);

namespace AA\Import;

use AA\Http\ClientInterface;
use AA\IO\Grabber\AbstractGrabber;
use AA\IO\SourceRefused;
use AA\IO\SourceUnavailable;
use AA\Later\BatchPosition;
use AA_Content;
use AA_Value;
use InvalidArgumentException;
use LogicException;

/**
 * Wraps the grabber of one job for the Saver: adds the job's parameters to every source item and
 * keeps the place within the job, so a job longer than a step goes on from where the last step
 * stopped - if the source still starts with the same items. Otherwise it starts over: the
 * skipped items would no longer be the ones already stored.
 */
final class ParamsGrabber extends AbstractGrabber
{
    /** how many leading source items the fingerprint covers */
    public const FP_ITEMS = 20;

    /** where ItemContent::getSliceID() reads the slice of an item */
    private const SLICE_FIELDS = ['u_slice_id......', 'slice_id........'];

    /** @var list<AA_Content> read ahead for the fingerprint, not yet handed out */
    private array $buffer = [];
    private bool $exhausted = false;
    private int $taken = 0;
    private string $fp_now = '';
    private bool $restarted = false;
    private string $vmax = '';

    /**
     * @param array<string, string> $params        added to each item as fields; they win over source fields of the same
     *                                             name, so source data cannot redirect {index:{index:fld}}
     * @param int                   $offset        source items the job already handed out in earlier steps
     * @param string                $fp            fingerprint() of the step that reached $offset
     * @param string                $versionField  source field whose maximum is the version of the data; '' = none
     * @param bool                  $restartAllowed false = a changed fingerprint fails prepare() instead of starting
     *                                             over, before a single item is handed out (and stored)
     * @throws SourceRefused DEFINITION for a direct-fill grabber with parameters - its items are stored as they are,
     *                       so the parameters would land in the target item instead of serving the mapping
     */
    public function __construct(
        private readonly AbstractGrabber $inner,
        private readonly array $params,
        private readonly int $offset = 0,
        private readonly string $fp = '',
        private readonly string $versionField = '',
        private readonly bool $restartAllowed = true,
    ) {
        if (($offset < 0) || (($offset > 0) && ($fp === ''))) {
            // without the fingerprint nobody can tell the skipped items are still the stored ones
            throw new InvalidArgumentException('An offset needs the fingerprint of the run that reached it');
        }
        if (($params !== []) && $inner->isDirectfill()) {
            throw new SourceRefused('DEFINITION', 'Grabber ' . $inner::class . ' fills items directly, so it cannot take job parameters');
        }
    }

    /** @throws \Exception whatever the inner grabber throws, including while reading ahead
     *  @throws SourceUnavailable SOURCE_UNSTABLE when the job would start over, but may not */
    public function prepare(): void
    {
        // reset first: a failing inner prepare() must not leave the previous run's verdict behind
        $this->buffer = [];
        $this->exhausted = false;
        $this->taken = 0;
        $this->vmax = '';
        $this->fp_now = '';
        $this->restarted = false;
        $this->inner->prepare();
        while ((count($this->buffer) < self::FP_ITEMS) && ($item = $this->nextFromInner())) {
            $this->buffer[] = $item;
        }
        $fp = $this->fingerprintOf($this->buffer);
        if (($this->offset > 0) && ($this->fp !== $fp) && !$this->restartAllowed) {
            // no fingerprint either: the job did not start
            throw new SourceUnavailable('SOURCE_UNSTABLE', 'The source changed under the job once more');
        }
        $this->fp_now = $fp;
        $this->restarted = ($this->offset > 0) && ($this->fp !== $this->fp_now);
        if (!$this->restarted) {
            while (($this->taken < $this->offset) && $this->nextRaw()) {
                $this->taken++;
            }
        }
    }

    public function getItem(): ?AA_Content
    {
        if (!($raw = $this->nextRaw())) {
            return null;
        }
        $this->taken++;
        if ($this->versionField !== '') {
            foreach ($raw->getValuesArray($this->versionField) as $value) {
                $this->vmax = self::laterVersion($this->vmax, (string) $value);
            }
        }
        // a second prepare() reads the same objects again; with the parameters in them its fingerprint would differ
        $item = clone $raw;
        // the expressions of the mapping would apply the aliases of the slice these name - a source
        // must not choose whose aliases run over its own values
        unset($item[self::SLICE_FIELDS[0]], $item[self::SLICE_FIELDS[1]]);
        foreach ($this->params as $name => $value) {
            $item->setAaValue($name, new AA_Value($value));
        }
        return $item;
    }

    /** source items handed out so far, those skipped by the offset included - where the next step goes on */
    public function offset(): int
    {
        return $this->taken;
    }

    /** sha1 of the first FP_ITEMS source items (as a list, so their count counts too); '' before prepare() */
    public function fingerprint(): string
    {
        return $this->fp_now;
    }

    /** the source changed under an offset, so the job started over from its first item */
    public function restarted(): bool
    {
        return $this->restarted;
    }

    /** maximum of the version field over the items handed out since the last prepare(); '' without the field */
    public function versionMax(): string
    {
        return $this->vmax;
    }

    /**
     * The later of two versions: numbers by value (999 < 1000), anything else as text (2019-12 < 2020-01);
     * '' is none. Mixed numbers and text order only by text, so keep one format of values per stream.
     * Equal numbers written differently (2021, 2021.0) fall back to text, so the order of calls does not matter.
     */
    public static function laterVersion(string $a, string $b): string
    {
        $a = trim($a);
        $b = trim($b);
        if (($a === '') || ($b === '')) {
            return ($a === '') ? $b : $a;
        }
        $cmp = (is_numeric($a) && is_numeric($b)) ? ($b <=> $a) : 0;
        return ((($cmp === 0) ? strcmp($b, $a) : $cmp) > 0) ? $b : $a;
    }

    public function finish(): void
    {
        $this->inner->finish();
    }

    public function isDirectfill(): bool
    {
        return $this->inner->isDirectfill();
    }

    public function getIdMode(): string
    {
        return $this->inner->getIdMode();
    }

    public function getStoreMode(): string
    {
        return $this->inner->getStoreMode();
    }

    public function getCharset(): string
    {
        return $this->inner->getCharset();
    }

    public function getSources(): array
    {
        return $this->inner->getSources();
    }

    public function setHttp(?ClientInterface $http): static
    {
        $this->inner->setHttp($http);
        return $this;
    }

    public function fetchesViaLoadData(): bool
    {
        return $this->inner->fetchesViaLoadData();
    }

    public function setLoadFrequency(float $load_frequency): static
    {
        $this->inner->setLoadFrequency($load_frequency);
        return $this;
    }

    /** the inner grabber's messages - report() of the Saver shows them */
    public function reportRows(int $verbosity = 1): array
    {
        return $this->inner->reportRows($verbosity);
    }

    public function clear_report(): void
    {
        $this->inner->clear_report();
    }

    private function nextRaw(): ?AA_Content
    {
        return array_shift($this->buffer) ?? $this->nextFromInner();
    }

    /** the inner grabber is not asked again once it said there is no more */
    private function nextFromInner(): ?AA_Content
    {
        if ($this->exhausted) {
            return null;
        }
        $item = $this->inner->getItem();
        $this->exhausted = is_null($item);
        return $item;
    }

    /** @param list<AA_Content> $items */
    private function fingerprintOf(array $items): string
    {
        $fields = array_map(fn(AA_Content $item): array => $this->normalizeValues($item->getAllAsArray()), $items);
        // canonical: field order and "5" vs 5 do not matter, so equal data gives the same print in every step
        return sha1(BatchPosition::canonical($fields));
    }

    /**
     * Source data as canonical() takes it, the same in every step: a float as its exact bits (its
     * decimal form follows the ini precision), bytes that are not UTF-8 as hex (json_encode refuses them).
     * @throws LogicException on a value that has no stable form - naming the grabber, never the data
     */
    private function normalizeValues(array $values): array
    {
        $ret = [];
        foreach ($values as $key => $value) {
            if (is_string($key) && !mb_check_encoding($key, 'UTF-8')) {
                $key = 'bin:' . bin2hex($key);
            }
            if ($value instanceof \Stringable) {
                $value = (string) $value;
            }
            $ret[$key] = match (true) {
                is_array($value)                                           => $this->normalizeValues($value),
                is_float($value)                                           => ['float' => bin2hex(pack('E', $value))],
                is_string($value) && !mb_check_encoding($value, 'UTF-8')   => ['bin' => bin2hex($value)],
                is_scalar($value), is_null($value)                         => $value,
                default => throw new LogicException('Grabber ' . $this->inner::class . ' gave a ' . get_debug_type($value) . ' value, which cannot be fingerprinted'),
            };
        }
        return $ret;
    }
}
