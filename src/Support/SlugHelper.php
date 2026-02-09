<?php

namespace Ssntpl\Neev\Support;

use Ssntpl\Neev\Models\Team;

class SlugHelper
{
    /**
     * Normalize text to a URL-safe slug.
     *
     * Rules:
     * - Lowercase
     * - Only alphanumeric and hyphens
     * - No consecutive hyphens
     * - No leading/trailing hyphens
     */
    public static function normalize(string $text): string
    {
        $slug = strtolower(trim($text));
        $slug = preg_replace('/[^a-z0-9-]/', '', $slug);
        $slug = preg_replace('/-+/', '-', $slug);
        $slug = trim($slug, '-');

        return $slug ?: 'team';
    }

    /**
     * Generate a unique slug for a team.
     *
     * @param string $text The text to generate slug from
     * @param int|null $excludeId Team ID to exclude from uniqueness check (for updates)
     * @return string The unique slug
     */
    public static function generate(string $text, ?int $excludeId = null): string
    {
        $config = config('neev.slug', []);
        $minLength = $config['min_length'] ?? 2;
        $maxLength = $config['max_length'] ?? 63;
        $reserved = $config['reserved'] ?? ['www', 'api', 'admin', 'app', 'mail', 'ftp', 'cdn'];

        $slug = static::normalize($text);

        // Ensure minimum length
        if (strlen($slug) < $minLength) {
            $slug = str_pad($slug, $minLength, '0');
        }

        // Ensure maximum length (leave room for counter suffix)
        if (strlen($slug) > $maxLength - 5) {
            $slug = substr($slug, 0, $maxLength - 5);
            $slug = rtrim($slug, '-');
        }

        $originalSlug = $slug;
        $counter = 1;

        // Check reserved slugs and uniqueness
        while (static::isReserved($slug, $reserved) || static::slugExists($slug, $excludeId)) {
            $slug = $originalSlug . '-' . $counter;
            $counter++;

            // Prevent infinite loop
            if ($counter > 1000) {
                $slug = $originalSlug . '-' . uniqid();
                break;
            }
        }

        return $slug;
    }

    /**
     * Check if a slug is valid.
     *
     * @param string $slug The slug to validate
     * @return bool True if valid
     */
    public static function isValid(string $slug): bool
    {
        $config = config('neev.slug', []);
        $minLength = $config['min_length'] ?? 2;
        $maxLength = $config['max_length'] ?? 63;

        if (strlen($slug) < $minLength || strlen($slug) > $maxLength) {
            return false;
        }

        // Must start and end with alphanumeric, only contain alphanumeric and hyphens
        return (bool) preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/', $slug);
    }

    /**
     * Check if a slug is reserved.
     *
     * @param string $slug The slug to check
     * @param array $reserved List of reserved slugs
     * @return bool True if reserved
     */
    protected static function isReserved(string $slug, array $reserved): bool
    {
        return in_array($slug, $reserved, true);
    }

    /**
     * Check if a slug already exists.
     *
     * @param string $slug The slug to check
     * @param int|null $excludeId Team ID to exclude from check
     * @return bool True if exists
     */
    protected static function slugExists(string $slug, ?int $excludeId = null): bool
    {
        $query = Team::model()->where('slug', $slug);

        if ($excludeId) {
            $query->where('id', '!=', $excludeId);
        }

        return $query->exists();
    }
}
