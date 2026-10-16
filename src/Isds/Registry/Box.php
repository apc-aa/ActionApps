<?php

declare(strict_types=1);

namespace AA\Isds\Registry;

/**
 * One data box from the public list of data boxes (SdsList). Texts trimmed, '' when missing - raw
 * text of an outside file, escape it (safe()) when shown.
 */
final readonly class Box
{
    /** every id in the 2026 file matches; the id becomes a recipient address, so another shape is refused */
    public const ID = '/^[a-z0-9]{7}\z/';

    /**
     * @param string $ico      as in the file, leading zeros kept; '' e.g. for city districts and state
     *                         offices, which have only $idOvm
     * @param string $idOvm    the authority's id in the register of authorities; '' in the subordinate
     *                         boxes of subtype 13
     * @param string $name     the trade name, or "first last" of a person when there is none
     * @param int    $subtype  the ISDS box type: 10 an authority, 13 a box set up for a part of one,
     *                         15/16 a person or company acting as one (notary, school); 0 unknown
     * @param bool   $isMaster false for a box under another one - a city police, a branch office -
     *                         and when the file does not say
     * @param bool   $pdz      accepts commercial (paid) messages
     * @param string $city     the city of the box's address - the city of a district the name leaves
     *                         out ("Městský obvod Hrabová" is in Ostrava); Praha's districts have the
     *                         postal district ("Praha 9")
     * @throws \InvalidArgumentException an id of another shape, wherever the box comes from
     */
    public function __construct(
        public string $id,
        public string $ico,
        public string $idOvm,
        public string $name,
        public string $type,
        public int $subtype,
        public bool $isMaster,
        public bool $pdz,
        public string $city = '',
    ) {
        if (self::normalizeId($id) !== $id) {
            throw new \InvalidArgumentException('A data box id is 7 small letters or digits');
        }
    }

    /**
     * A box id as people type it - with spaces around, in capitals - as ISDS knows it
     * @throws \InvalidArgumentException not a box id even then
     */
    public static function normalizeId(string $id): string
    {
        $id = strtolower(trim($id));
        if (preg_match(self::ID, $id) !== 1) {
            throw new \InvalidArgumentException('A data box id is 7 small letters or digits');
        }
        return $id;
    }
}
