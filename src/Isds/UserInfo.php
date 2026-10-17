<?php

declare(strict_types=1);

namespace AA\Isds;

/** The logged-in user of a data box (GetUserInfoFromLogin2) */
final readonly class UserInfo
{
    // the rights of a user, bits of $privileges (WS_manipulace_s_datovymi_zpravami.pdf 1.4)
    /** read received messages */
    public const READ_NON_PERSONAL = 1;
    /** read received messages to own hands too */
    public const READ_ALL = 2;
    /** send messages, read the sent ones */
    public const CREATE_DM = 4;
    /** lists of messages, delivery info */
    public const VIEW_INFO = 8;
    /** search for data boxes */
    public const SEARCH_DB = 16;
    /** manage the box: users and their rights */
    public const OWNER_ADM = 32;
    /** erase messages in the vault (64, reading the vault, was dropped in 2012) */
    public const ERASE_VAULT = 128;

    /**
     * @param ?int   $privileges sum of the rights above; null when ISDS gives none (nil or left out)
     * @param string $type       PRIMARY_USER, ENTRUSTED_USER, ADMINISTRATOR, ...; '' when ISDS gives none
     */
    public function __construct(public ?int $privileges, public string $type) {}

    public function has(int $right): bool
    {
        return !is_null($this->privileges) && (($this->privileges & $right) === $right);
    }
}
