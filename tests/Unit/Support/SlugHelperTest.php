<?php

namespace Ssntpl\Neev\Tests\Unit\Support;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Ssntpl\Neev\Database\Factories\TeamFactory;
use Ssntpl\Neev\Support\SlugHelper;
use Ssntpl\Neev\Tests\TestCase;
use Ssntpl\Neev\Tests\Traits\WithNeevConfig;

class SlugHelperTest extends TestCase
{
    use RefreshDatabase;
    use WithNeevConfig;

    // =================================================================
    // normalize()
    // =================================================================

    public function test_normalize_lowercases_text(): void
    {
        $this->assertSame('hello-world', SlugHelper::normalize('Hello-World'));
    }

    public function test_normalize_removes_special_characters(): void
    {
        $this->assertSame('helloworld', SlugHelper::normalize('hello@world!'));
    }

    public function test_normalize_keeps_hyphens(): void
    {
        $this->assertSame('hello-world', SlugHelper::normalize('hello-world'));
    }

    public function test_normalize_keeps_numbers(): void
    {
        $this->assertSame('team123', SlugHelper::normalize('Team123'));
    }

    public function test_normalize_collapses_consecutive_hyphens(): void
    {
        $this->assertSame('hello-world', SlugHelper::normalize('hello---world'));
    }

    public function test_normalize_trims_leading_hyphens(): void
    {
        $this->assertSame('hello', SlugHelper::normalize('--hello'));
    }

    public function test_normalize_trims_trailing_hyphens(): void
    {
        $this->assertSame('hello', SlugHelper::normalize('hello--'));
    }

    public function test_normalize_trims_leading_and_trailing_hyphens(): void
    {
        $this->assertSame('hello', SlugHelper::normalize('--hello--'));
    }

    public function test_normalize_returns_team_for_empty_string(): void
    {
        $this->assertSame('team', SlugHelper::normalize(''));
    }

    public function test_normalize_returns_team_for_all_special_characters(): void
    {
        $this->assertSame('team', SlugHelper::normalize('!@#$%^'));
    }

    public function test_normalize_trims_whitespace(): void
    {
        $this->assertSame('hello', SlugHelper::normalize('  hello  '));
    }

    public function test_normalize_removes_spaces(): void
    {
        // Spaces are not alphanumeric or hyphens, so they get removed
        $this->assertSame('helloworld', SlugHelper::normalize('hello world'));
    }

    // =================================================================
    // generate()
    // =================================================================

    public function test_generate_creates_url_safe_slug(): void
    {
        $slug = SlugHelper::generate('My Test Team');

        $this->assertMatchesRegularExpression('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/', $slug);
    }

    public function test_generate_appends_counter_for_reserved_slugs(): void
    {
        // 'www' is reserved by default
        $slug = SlugHelper::generate('www');

        $this->assertSame('www-1', $slug);
    }

    public function test_generate_appends_counter_for_other_reserved_slugs(): void
    {
        $this->assertSame('api-1', SlugHelper::generate('api'));
        $this->assertSame('admin-1', SlugHelper::generate('admin'));
    }

    public function test_generate_appends_counter_for_existing_slugs(): void
    {
        TeamFactory::new()->create(['slug' => 'acme']);

        $slug = SlugHelper::generate('acme');

        $this->assertSame('acme-1', $slug);
    }

    public function test_generate_increments_counter_for_multiple_existing_slugs(): void
    {
        TeamFactory::new()->create(['slug' => 'acme']);
        TeamFactory::new()->create(['slug' => 'acme-1']);

        $slug = SlugHelper::generate('acme');

        $this->assertSame('acme-2', $slug);
    }

    public function test_generate_respects_exclude_id(): void
    {
        $team = TeamFactory::new()->create(['slug' => 'acme']);

        // When updating the same team, its own slug should not conflict
        $slug = SlugHelper::generate('acme', $team->id);

        $this->assertSame('acme', $slug);
    }

    public function test_generate_pads_short_slugs_to_min_length(): void
    {
        // Default min_length is 2
        $slug = SlugHelper::generate('a');

        $this->assertGreaterThanOrEqual(2, strlen($slug));
    }

    public function test_generate_truncates_long_slugs(): void
    {
        $longName = str_repeat('a', 100);

        $slug = SlugHelper::generate($longName);

        // Default max_length is 63, slug is truncated to max-5=58
        $this->assertLessThanOrEqual(63, strlen($slug));
    }

    public function test_generate_produces_unique_slug_for_non_conflicting_text(): void
    {
        $slug = SlugHelper::generate('unique-team-name');

        $this->assertSame('unique-team-name', $slug);
    }

    // =================================================================
    // isValid()
    // =================================================================

    public function test_is_valid_returns_true_for_valid_slug(): void
    {
        $this->assertTrue(SlugHelper::isValid('my-team'));
    }

    public function test_is_valid_returns_true_for_slug_with_numbers(): void
    {
        $this->assertTrue(SlugHelper::isValid('team123'));
    }

    public function test_is_valid_returns_true_for_single_character_with_min_length_1(): void
    {
        // Default min_length is 2
        config(['neev.slug.min_length' => 1]);
        $this->assertTrue(SlugHelper::isValid('a'));
    }

    public function test_is_valid_returns_true_for_minimum_length_slug(): void
    {
        // Default min_length is 2
        $this->assertTrue(SlugHelper::isValid('ab'));
    }

    public function test_is_valid_returns_false_for_too_short_slug(): void
    {
        // Default min_length is 2
        $this->assertFalse(SlugHelper::isValid('a'));
    }

    public function test_is_valid_returns_false_for_too_long_slug(): void
    {
        // Default max_length is 63
        $longSlug = str_repeat('a', 64);

        $this->assertFalse(SlugHelper::isValid($longSlug));
    }

    public function test_is_valid_returns_false_for_uppercase_characters(): void
    {
        $this->assertFalse(SlugHelper::isValid('MyTeam'));
    }

    public function test_is_valid_returns_false_for_leading_hyphen(): void
    {
        $this->assertFalse(SlugHelper::isValid('-team'));
    }

    public function test_is_valid_returns_false_for_trailing_hyphen(): void
    {
        $this->assertFalse(SlugHelper::isValid('team-'));
    }

    public function test_is_valid_returns_false_for_special_characters(): void
    {
        $this->assertFalse(SlugHelper::isValid('team@name'));
    }

    public function test_is_valid_returns_false_for_spaces(): void
    {
        $this->assertFalse(SlugHelper::isValid('my team'));
    }

    public function test_is_valid_returns_false_for_empty_string(): void
    {
        $this->assertFalse(SlugHelper::isValid(''));
    }

    public function test_is_valid_returns_true_for_max_length_slug(): void
    {
        // Default max_length is 63
        $slug = str_repeat('a', 63);

        $this->assertTrue(SlugHelper::isValid($slug));
    }

    public function test_is_valid_returns_true_for_hyphens_in_middle(): void
    {
        $this->assertTrue(SlugHelper::isValid('my-great-team'));
    }
}
