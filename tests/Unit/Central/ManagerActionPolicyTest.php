<?php

declare(strict_types=1);

namespace Tests\Unit\Central;

use AA\Central\ManagerActionPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ManagerActionPolicyTest extends TestCase
{
    /** [server, has row button, may perform]
     *  @return array<string, array{array<string, string>, bool, bool}> */
    public static function requests(): array
    {
        $host  = ['HTTP_HOST' => 'central.ecn.cz'];
        $post  = $host + ['REQUEST_METHOD' => 'POST'];
        $get   = $host + ['REQUEST_METHOD' => 'GET'];
        return [
            'form POST, same origin'          => [$post + ['HTTP_SEC_FETCH_SITE' => 'same-origin'], false, true],
            'form POST, old browser'          => [$post + ['HTTP_ORIGIN' => 'https://central.ecn.cz'], false, true],
            'form POST, no headers'           => [$post, false, true],
            'POST from another site'          => [$post + ['HTTP_SEC_FETCH_SITE' => 'cross-site'], false, false],
            'POST from a sibling subdomain'   => [$post + ['HTTP_ORIGIN' => 'https://evil.ecn.cz'], false, false],
            'bulk action by GET link'         => [$get + ['HTTP_SEC_FETCH_SITE' => 'same-origin'], false, false],
            'bulk action by typed URL'        => [$get + ['HTTP_SEC_FETCH_SITE' => 'none'], false, false],
            'bulk action, no method'          => [$host, false, false],
            'row button, same origin'         => [$get + ['HTTP_SEC_FETCH_SITE' => 'same-origin'], true, true],
            'row button link from other site' => [$get + ['HTTP_SEC_FETCH_SITE' => 'cross-site'], true, false],
            'row button link, same site'      => [$get + ['HTTP_SEC_FETCH_SITE' => 'same-site'], true, false],
            'row button, POST'                => [$post, true, true],
            // the Empty trash switch (DeleteTrash) goes as a row button
            'empty trash link, same origin'   => [$get + ['HTTP_SEC_FETCH_SITE' => 'same-origin'], true, true],
            'empty trash link from other site' => [$get + ['HTTP_SEC_FETCH_SITE' => 'cross-site'], true, false],
            'empty trash, sibling origin'     => [$get + ['HTTP_ORIGIN' => 'https://evil.ecn.cz'], true, false],
        ];
    }

    #[DataProvider('requests')]
    public function test_mayPerform_matches_table(array $server, bool $row_button, bool $expected): void
    {
        $this->assertSame($expected, ManagerActionPolicy::mayPerform($server, $row_button));
    }
}
