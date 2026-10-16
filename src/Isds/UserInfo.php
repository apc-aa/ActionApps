<?php

declare(strict_types=1);

namespace AA\Isds;

/** The logged-in user of a data box (GetUserInfoFromLogin2) */
final readonly class UserInfo
{
    /**
     * @param int    $privileges sum of the bits: 1 and 2 read received messages, 4 send, 8 lists and
     *                           delivery info, 16 search boxes, 32 manage the box, 128 erase the vault
     *                           (WS_manipulace_s_datovymi_zpravami.pdf 1.4); 0 when ISDS gives none
     * @param string $type       PRIMARY_USER, ENTRUSTED_USER, ADMINISTRATOR, ...; '' when ISDS gives none
     */
    public function __construct(public int $privileges, public string $type) {}
}
