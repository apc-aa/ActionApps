<?php

declare(strict_types=1);

namespace Tests\Unit\Isds\Fixtures;

use AA\Isds\Schema;
use AA\Isds\SliceRules;
use AA_Field;
use AA_Slice;
use Tests\Unit\Isds\Registry\Fixtures\PropertySlice;
use Tests\Unit\Legacy\Fixtures\FakeFields;

/**
 * SliceRules over the sites, slices and relations set here instead of the database. ready() sets up
 * a site using a campaign and a message slice that pass every rule; a test then breaks one thing.
 * Needs store_fixtures.php (FakeFields) and Registry/fixtures/PropertySlice.php loaded.
 */
final class FakeSliceRules extends SliceRules
{
    /** @var list<string> site modules there are */
    public array $sites = [];

    /** @var array<string, AA_Slice> slices there are, out of the trash */
    public array $slices = [];

    /** @var list<string> modules in the trash */
    public array $trash = [];

    /** @var array<string, list<string>> site => the modules it uses */
    public array $related = [];

    /** @var array<string, string> module id => name */
    public array $names = [];

    /** @var array<string, list<string>> slice id => its outlets there are (SliceRules::OUTLETS) */
    public array $outlets = [];

    /** @var list<string> ids PropertySlice registered process-wide */
    private static array $registered = [];

    public static function ready(string $site, string $campaign, string $message): self
    {
        $rules          = new self();
        $rules->sites   = [$site];
        $rules->related = [$site => [$campaign, $message]];
        $rules->slices  = [$campaign => self::sliceOf($campaign, true), $message => self::sliceOf($message, false)];
        $rules->names   = [$site => 'Terminal', $campaign => 'ISDS campaigns', $message => 'ISDS messages'];

        return $rules;
    }

    /**
     * A slice with the fields of Schema, closed by a reading password, unless the arguments say else.
     * @param array<string, mixed> $props   slice settings over the closed ones
     * @param list<string>         $without fields left out
     * @param list<string>         $asText  number fields stored as text
     */
    public static function sliceOf(string $id, bool $campaign, array $props = [], array $without = [], array $asText = [], string $stateDefault = 'txt:draft'): PropertySlice
    {
        $fields = [];
        foreach (array_diff($campaign ? Schema::CAMPAIGN_FIELDS : Schema::MESSAGE_FIELDS, $without) as $field) {
            $number   = in_array($field, $campaign ? Schema::CAMPAIGN_NUMBER_FIELDS : Schema::MESSAGE_NUMBER_FIELDS, true) && !in_array($field, $asText, true);
            $fields[] = new AA_Field([
                'id'                => $field,
                'name'              => $field,
                'slice_id'          => '',
                'in_item_tbl'       => '',
                'text_stored'       => $number ? 0 : 1,
                'input_default'     => ($campaign && ($field === Schema::CAMPAIGN_STATE)) ? $stateDefault : 'txt:',
                'input_show_func'   => 'fld',
                'input_insert_func' => 'qte',
                'content_edit'      => 0,
                'html_default'      => 0,
                'multiple'          => 0,
            ]);
        }
        self::$registered[] = $id;
        // AA_Slice gives the reading password as its md5
        $closed = array_fill_keys(SliceRules::CLOSED, '0') + ['export_to_all' => '0', 'reading_password' => md5('read')];

        return new PropertySlice($id, new FakeFields($id, $fields), $props + $closed);
    }

    /** the module registry is process-wide */
    public static function forget(): void
    {
        foreach (self::$registered as $id) {
            PropertySlice::forget($id);
        }
        self::$registered = [];
    }

    public function name(string $id): string
    {
        return $this->names[$id] ?? $id;
    }

    protected function slice(string $id): ?AA_Slice
    {
        return $this->slices[$id] ?? null;
    }

    protected function isSite(string $id): bool
    {
        return in_array($id, $this->sites, true);
    }

    protected function inTrash(string $id): bool
    {
        return in_array($id, $this->trash, true);
    }

    protected function relatedModules(string $site): array
    {
        return $this->related[$site] ?? [];
    }

    protected function outlets(string $slice): array
    {
        $there = $this->outlets[$slice] ?? [];

        return array_combine(SliceRules::OUTLETS, array_map(static fn(string $outlet): bool => in_array($outlet, $there, true), SliceRules::OUTLETS));
    }
}
