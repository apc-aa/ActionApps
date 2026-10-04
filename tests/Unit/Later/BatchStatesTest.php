<?php

declare(strict_types=1);

namespace Tests\Unit\Later;

use AA\Later\BatchStates;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Users of the batch core name its four roles their own way (import: running) and may
 * add states of their own (Central: preview), so transitions are asked of BatchStates.
 */
final class BatchStatesTest extends TestCase
{
    private static function central(): BatchStates
    {
        return new BatchStates('active', 'error', 'done', 'ended', ['preview' => ['active']]);
    }

    public function test_all_lists_roles_then_extra_states(): void
    {
        $this->assertSame(['active', 'error', 'done', 'ended', 'preview'], self::central()->all());
        $this->assertSame(['running', 'error', 'done', 'ended'], (new BatchStates('running', 'error', 'done', 'ended'))->all());
    }

    /** @return array<string, array{string, string, bool}> */
    public static function roleTransitions(): array
    {
        return [
            'step fails'           => ['running', 'error', true],
            'last step'            => ['running', 'done', true],
            'end a running run'    => ['running', 'ended', true],
            'resume'               => ['error', 'running', true],
            'end a failed run'     => ['error', 'ended', true],
            'done is final'        => ['done', 'running', false],
            'ended is final'       => ['ended', 'running', false],
            'error is not success' => ['error', 'done', false],
            'no self transition'   => ['running', 'running', false],
        ];
    }

    #[DataProvider('roleTransitions')]
    public function test_mayGo_role_transitions(string $from, string $to, bool $may): void
    {
        $this->assertSame($may, (new BatchStates('running', 'error', 'done', 'ended'))->mayGo($from, $to));
    }

    public function test_mayGo_extra_state_transitions(): void
    {
        $states = self::central();

        $this->assertTrue($states->mayGo('preview', 'active'), 'listed in extra');
        $this->assertTrue($states->mayGo('preview', 'ended'), 'a run in an own state can always be ended');
        $this->assertFalse($states->mayGo('preview', 'done'), 'not listed');
        $this->assertFalse($states->mayGo('active', 'preview'), 'roles never go to an own state');
    }

    public function test_mayGo_unknown_state_refused(): void
    {
        $states = self::central();

        $this->assertFalse($states->mayGo('nowhere', 'ended'));
        $this->assertFalse($states->mayGo('active', 'nowhere'));
        $this->assertFalse($states->mayGo('', ''));
    }

    public function test_construct_rejects_duplicate_state(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new BatchStates('active', 'error', 'done', 'ended', ['done' => ['active']]);
    }

    public function test_construct_rejects_unknown_target(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new BatchStates('active', 'error', 'done', 'ended', ['preview' => ['approved']]);
    }

    public function test_construct_rejects_extra_state_looping_to_itself(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new BatchStates('active', 'error', 'done', 'ended', ['preview' => ['preview']]);
    }
}
