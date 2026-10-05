<?php

declare(strict_types=1);

namespace AA\Import;

use InvalidArgumentException;

/**
 * One part of a source the run goes through (an indicator, a table). The key is stored in the
 * run's cursor and state, so it is restricted to plain characters; ':' is left out because it
 * separates the stream key in job keys.
 */
final class Stream
{
    public const KEY = '/^[A-Za-z0-9_.-]{1,64}$/D';

    public const PARAM = '/^[a-z][a-z0-9_]{0,31}$/D';

    /** @var array<string, string> */
    public readonly array $params;

    /**
     * @param string $label from the source, untrusted: escaped on output
     * @param array<string, string|int> $params values used to build the request; they go into a URL
     *                                          only through http_build_query(…, PHP_QUERY_RFC3986) or
     *                                          rawurlencode(), never into its path
     * @param string $catalogKey   the catalog entry the stream comes from
     * @param string $versionField source field whose maximum is the data version; '' = probe() gives it.
     *                             With it set, any version of the stream - $known of jobs() and probe(),
     *                             a version probe() gives - is on the scale of that field
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        array $params,
        public readonly string $catalogKey = '',
        public readonly string $versionField = '',
    ) {
        if (preg_match(self::KEY, $key) !== 1) {
            throw new InvalidArgumentException('Stream key must match ' . self::KEY);
        }
        $this->params = self::strings($params);
    }

    /**
     * Parameter values as strings: they end up in a URL or a field, and a stored and a fresh
     * value must compare equal. Null, bool and float have no single string form (a float prints
     * by the ini precision), so they are refused.
     * @param array<mixed> $params
     * @return array<string, string>
     */
    public static function strings(array $params): array
    {
        $out = [];
        foreach ($params as $name => $value) {
            if (!is_string($name) || preg_match(self::PARAM, $name) !== 1) {
                throw new InvalidArgumentException('Parameter name must match ' . self::PARAM);
            }
            if (!is_string($value) && !is_int($value)) {
                throw new InvalidArgumentException("Parameter $name must be a string or an integer");
            }
            $out[$name] = (string) $value;
        }
        return $out;
    }
}
