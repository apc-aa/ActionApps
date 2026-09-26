<?php

declare(strict_types=1);

namespace AA\Central;

use Closure;
use InvalidArgumentException;
use UnexpectedValueException;

/**
 * Where "Copy an app between AAs" sends the admin: the install page of the target AA, which pulls the app
 * from the source. For this AA it is the local page (with the source row id); another AA gets only a link
 * to its own page - a central_conf id of ours means nothing there, so the admin picks the source there.
 */
final class TransferLink
{
    /** the source or target is this AA, not a central_conf row */
    public const THIS_AA = 0;

    /**
     * @param string            $url    installapp.php of this AA (relative to central/), or the absolute one of the target
     * @param array<string,int> $params the query for installapp.php of this AA
     * @param bool              $remote the target is another AA
     * @param string            $target the name of the target AA; '' for this AA
     * @param string            $source the name of the source the admin chooses on the target; '' when the
     *                                  source is that AA itself
     */
    private function __construct(
        public readonly string $url,
        public readonly array $params,
        public readonly bool $remote,
        public readonly string $target = '',
        public readonly string $source = '',
    ) {}

    /** THIS_AA or a central_conf id from a request - digits only
     *  @throws InvalidArgumentException */
    public static function id(string $value): int
    {
        return preg_match('/^\d{1,10}$/', $value) ? (int) $value : throw new InvalidArgumentException('no AA id');
    }

    /**
     * @param Closure(int): ?Actionapps $find a central_conf row by its id
     * @param string                    $here the name of this AA, for the note on another AA
     * @throws InvalidArgumentException no such row
     * @throws UnexpectedValueException the target row has no valid http(s) address
     */
    public static function resolve(int $source, int $target, Closure $find, string $here): self
    {
        $from = self::row($source, $find);
        $to   = self::row($target, $find);
        if (!$to) {
            return new self('installapp.php', $from ? ['aa' => $source] : [], false);
        }
        $url = $to->getPageUrl('central/installapp.php') ?? throw new UnexpectedValueException("the AA $target has no valid http(s) address");
        $source_name = match (true) {
            $source === $target => '',
            $from === null      => $here,
            default             => $from->getName(),
        };
        return new self($url, [], true, $to->getName(), $source_name);
    }

    /** @param Closure(int): ?Actionapps $find @throws InvalidArgumentException */
    private static function row(int $id, Closure $find): ?Actionapps
    {
        if ($id === self::THIS_AA) {
            return null;
        }
        return (($id > 0) ? $find($id) : null) ?? throw new InvalidArgumentException("no AA $id in the Central list");
    }
}
