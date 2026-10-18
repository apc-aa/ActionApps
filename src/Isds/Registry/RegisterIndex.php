<?php

declare(strict_types=1);

namespace AA\Isds\Registry;

/**
 * The record of the downloaded register (Sync::registerFile()): the sha256, size and time of the
 * download, kept by RegisterRecord in the install's database. The register lies in the upload
 * directory, which the web server serves and other PHP users of the server may write into: only bytes
 * that hash to the recorded sha256 are the register this AA downloaded (Sync::registerBoxes()).
 */
class RegisterIndex
{
    /** info(): the register this AA downloaded, as far as a look at the file tells */
    public const RECORDED = 'recorded';

    /** info(): a register AA has no record of downloading - an older AA downloaded it, or it was put
     *  there by hand */
    public const UNRECORDED = 'unrecorded';

    /** info(): the register this AA downloaded is gone, or another file lies in its place - which only
     *  reading it tells (Sync::registerBoxes()) */
    public const REPLACED = 'replaced';

    /** RegisterRecord, '' = unset: sha256, size and time of the downloaded register */
    public const RECORD = ['xml' => '', 'size' => '', 'time' => ''];

    /** null = the install has no upload directory */
    private readonly ?string $register;

    private string $problem = '';

    /** @param ?string $register the register; Sync::registerFile() when null */
    public function __construct(?string $register = null)
    {
        try {
            $register ??= Sync::registerFile();
        } catch (\RuntimeException $e) {
            $this->problem = $e->getMessage();
        }
        $this->register = $register;
    }

    /**
     * The register as the record vouches for it, from a look at the file and a read of the record - not
     * of the register's bytes, which Sync::registerBoxes() checks. null when nothing was downloaded.
     * @return ?array{state: string, time: int, size: int, sha256: string} state RECORDED, UNRECORDED or
     *         REPLACED; time, size and sha256 of the recorded download, 0 and '' when UNRECORDED
     * @throws \RuntimeException no upload directory, or one PHP cannot look into - the message names it
     */
    public function info(): ?array
    {
        if ($this->register === null) {
            throw new \RuntimeException($this->problem);
        }
        $exists   = Sync::registerExists($this->register);
        $record   = $this->recorded();
        $recorded = ($record['xml'] !== '');
        if (!$exists && !$recorded) {
            return null;
        }
        return [
            'state'  => $recorded ? ($exists ? self::RECORDED : self::REPLACED) : self::UNRECORDED,
            'time'   => (int) $record['time'],
            'size'   => (int) $record['size'],
            'sha256' => $record['xml'],
        ];
    }

    /** @return array<string, string> RECORD as stored now */
    public function recorded(): array
    {
        return $this->readRecord() + self::RECORD;
    }

    /**
     * Records the register Sync::download() has just put in place
     * @throws UnrecordedException the record cannot be written
     */
    public function downloaded(string $sha256, int $size): void
    {
        try {
            $this->writeRecord(['xml' => $sha256, 'size' => $size, 'time' => time()]);
        } catch (\RuntimeException $e) {
            throw new UnrecordedException('The register of data boxes is downloaded, but its record cannot be written: ' . $e->getMessage(), 0, $e);
        }
    }

    // ------------------------------------------------------------ what tests replace

    /** @return array<string, string> RegisterRecord::read() */
    protected function readRecord(): array
    {
        return RegisterRecord::read();
    }

    /** @param array<string, string|int> $values */
    protected function writeRecord(array $values): void
    {
        RegisterRecord::write($values);
    }
}
