<?php

declare(strict_types=1);

namespace Tests\Unit\Isds\Fixtures;

use AA\Isds\Registry\Box;
use AA\Isds\Registry\RegisterIndex;
use AA\Isds\Registry\UnusableRegisterException;

/**
 * The register of data boxes given here instead of a file and the database. lookup() answers as
 * RegisterIndex's does: a state that is not usable throws.
 */
final class FakeRegisterIndex extends RegisterIndex
{
    /** the official name of every box of permissive() */
    public const NAME = 'Obec Lhota';

    /** @var list<list<string>> the boxes of each lookup() */
    public array $lookups = [];

    /**
     * @param string $given the state()
     * @param ?array<string, array{0: string, 1: string, 2?: int, 3?: bool}> $boxes box id => its IČO as lookup() gives
     *        it ('' none, BAD_ICO), its official name, subtype and master flag - an authority's own box when left out;
     *        null = every box asked for, such a box without IČO, named NAME
     * @param bool $breaks lookup() finds the index replaced and cannot make it again - the state turns UNREADABLE
     */
    public function __construct(private string $given = self::OK, private ?array $boxes = [], private bool $breaks = false)
    {
        parent::__construct('/nonexistent/register.xml');
    }

    /** a register that has every box, a municipality's own, none with an IČO - for what is not about the register */
    public static function permissive(): self
    {
        return new self(self::OK, null);
    }

    public function state(): string
    {
        return $this->given;
    }

    public function lookup(array $boxes): array
    {
        $this->lookups[] = $boxes;
        if ($this->breaks) {
            $this->given = self::UNREADABLE;
        }
        if (!$this->usable()) {
            throw new UnusableRegisterException("The register of data boxes is $this->given");
        }
        $found = [];
        foreach ($boxes as $box) {
            [$ico, $name, $subtype, $master] = (is_null($this->boxes) ? ['', self::NAME] : ($this->boxes[$box] ?? [null, null])) + [2 => Box::AUTHORITY, 3 => true];
            if (!is_null($ico)) {
                $found[$box] = ['id' => $box, 'ico' => $ico, 'subtype' => $subtype, 'master' => $master, 'name' => (string) $name];
            }
        }

        return $found;
    }
}
