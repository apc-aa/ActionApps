<?php

declare(strict_types=1);

namespace AA\Security;

use AA_Log;
use AA_Object;
use AA_Property;

/**
 * Which protections are switched off on this install - one AA_Object owned by the install (AA_ID),
 * written only by admin/aa_security.php. Missing object or missing id = the protection is on, so a
 * new install and an install updated to a new protection are protected without a step.
 */
final class Settings extends AA_Object
{
    /** log.type of the switches - selector the protection, params on|off; the user column says who */
    public const EVENT = 'SECURITY';

    /** @var string[] Protection values switched off */
    public $off = [];

    public static function getClassProperties(): array
    {
        //                               id     name                    type      multi persist validator required
        return [
            'off' => new AA_Property('off', _m('Switched off'), 'string', true, true, 'string', false),
        ];
    }

    public static function isOn(Protection $protection): bool
    {
        return !in_array($protection->value, (array) self::current()->off, true);
    }

    /**
     * Stores which protections are off - the rest is on; every change is logged.
     * @param Protection[] $off
     * @return Protection[] the protections whose state changed
     */
    public static function store(array $off): array
    {
        $settings = self::current();
        $changed  = [];
        foreach (Protection::cases() as $protection) {
            if (in_array($protection, $off, true) === self::isOn($protection)) {
                $changed[] = $protection;
            }
        }
        if (!$changed) {
            return [];
        }
        $settings->setNew(self::id(), constant('AA_ID'));
        $settings->off = array_values(array_unique(array_map(static fn(Protection $p) => $p->value, $off)));
        $settings->save();
        foreach ($changed as $protection) {
            AA_Log::write(self::EVENT, $protection->value, self::isOn($protection) ? 'on' : 'off');
        }
        return $changed;
    }

    /** the last switch of the protection: ['time' => unixtime, 'user' => uid, 'params' => on|off], [] if never switched */
    public static function lastSwitch(Protection $protection): array
    {
        return AA_Log::getLastLogEvents(self::EVENT, $protection->value);
    }

    private static function current(): self
    {
        $settings = AA_Object::load(self::id(), self::class);
        return ($settings instanceof self) ? $settings : new self();
    }

    /** one object per install; derived from AA_ID so it cannot meet a random object id */
    private static function id(): string
    {
        return md5('aa-security-settings:' . constant('AA_ID'));
    }
}
