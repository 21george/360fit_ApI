<?php

declare(strict_types=1);

namespace Tests\Unit\Helpers;

use App\Helpers\Diff;
use PHPUnit\Framework\TestCase;

final class DiffTest extends TestCase
{
    public function test_changed_fields_returns_empty_when_before_is_null(): void
    {
        $body = ['name' => 'Alice'];
        $this->assertSame([], Diff::changedFields($body, null, ['name']));
    }

    public function test_changed_fields_detects_simple_string_change(): void
    {
        $before = (object) ['name' => 'Alice'];
        $body   = ['name' => 'Bob'];

        $this->assertSame(['name'], Diff::changedFields($body, $before, ['name']));
    }

    public function test_changed_fields_ignores_fields_not_in_body(): void
    {
        $before = (object) ['name' => 'Alice', 'age' => 30];
        $body   = ['name' => 'Alice'];

        $this->assertSame([], Diff::changedFields($body, $before, ['name', 'age']));
    }

    public function test_changed_fields_treats_null_and_empty_string_as_distinct(): void
    {
        $before = (object) ['bio' => 'Hello'];

        $this->assertSame(['bio'], Diff::changedFields(['bio' => ''], $before, ['bio']));
        $this->assertSame(['bio'], Diff::changedFields(['bio' => null], $before, ['bio']));

        $beforeEmpty = (object) ['bio' => ''];
        $this->assertSame(['bio'], Diff::changedFields(['bio' => null], $beforeEmpty, ['bio']));
    }

    public function test_changed_fields_treats_numeric_string_and_int_as_equal(): void
    {
        $before = (object) ['count' => 100];

        $this->assertSame([], Diff::changedFields(['count' => '100'], $before, ['count']));
        $this->assertSame([], Diff::changedFields(['count' => 100], $before, ['count']));
        $this->assertSame([], Diff::changedFields(['count' => '1e2'], $before, ['count']));
        $this->assertSame([], Diff::changedFields(['count' => 100.0], $before, ['count']));
    }

    public function test_changed_fields_detects_non_numeric_string_vs_int(): void
    {
        $before = (object) ['count' => 100];

        $this->assertSame(['count'], Diff::changedFields(['count' => '100foo'], $before, ['count']));
    }

    public function test_changed_fields_never_coerces_bool(): void
    {
        $before = (object) ['active' => true];

        $this->assertSame(['active'], Diff::changedFields(['active' => '1'], $before, ['active']));
        $this->assertSame(['active'], Diff::changedFields(['active' => 1], $before, ['active']));
        $this->assertSame(['active'], Diff::changedFields(['active' => ''], $before, ['active']));
        $this->assertSame([], Diff::changedFields(['active' => true], $before, ['active']));
    }

    public function test_changed_fields_detects_array_change(): void
    {
        $before = (object) ['social' => ['twitter' => '@alice', 'insta' => '@al']];

        // Order-independent equality for associative arrays
        $reordered = ['insta' => '@al', 'twitter' => '@alice'];
        $this->assertSame([], Diff::changedFields(['social' => $reordered], $before, ['social']));

        // Actual content change
        $this->assertSame(
            ['social'],
            Diff::changedFields(['social' => ['twitter' => '@bob']], $before, ['social'])
        );
    }

    public function test_values_equal_fast_path_same_instance(): void
    {
        $obj = new \stdClass();
        $this->assertTrue(Diff::valuesEqual($obj, $obj));
    }
}
