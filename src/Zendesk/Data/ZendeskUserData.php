<?php

declare(strict_types=1);

namespace Integrations\Adapters\Zendesk\Data;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Spatie\LaravelData\Data;

class ZendeskUserData extends Data
{
    /**
     * @param  array<int, string>|null  $tags
     * @param  array<string, mixed>|null  $user_fields
     * @param  array<string, mixed>|null  $original
     */
    public function __construct(
        public readonly int $id,
        public readonly string $url,
        public readonly string $name,
        public readonly string $email,
        public readonly ?string $external_id,
        public readonly bool $active,
        public readonly bool $suspended,
        public readonly bool $verified,
        public readonly string $role,
        public readonly ?int $role_type,
        public readonly ?int $custom_role_id,
        public readonly bool $moderator,
        public readonly ?string $ticket_restriction,
        public readonly bool $only_private_comments,
        public readonly bool $restricted_agent,
        public readonly ?int $organization_id,
        public readonly ?int $default_group_id,
        public readonly ?string $phone,
        public readonly ?bool $shared_phone_number,
        public readonly ?ZendeskPhotoData $photo,
        public readonly string $time_zone,
        public readonly string $iana_time_zone,
        public readonly int $locale_id,
        public readonly string $locale,
        public readonly Carbon $created_at,
        public readonly Carbon $updated_at,
        public readonly ?Carbon $last_login_at,
        public readonly ?bool $two_factor_auth_enabled,
        public readonly bool $shared,
        public readonly bool $shared_agent,
        public readonly bool $report_csv,
        public readonly ?string $alias = '',
        public readonly ?string $signature = '',
        public readonly ?string $details = '',
        public readonly ?string $notes = '',
        public readonly ?array $tags = [],
        public readonly ?array $user_fields = [],
        public readonly ?array $original = null,
    ) {}

    /**
     * @param  array<mixed>  $properties
     * @return array<mixed>
     */
    #[\Override]
    public static function prepareForPipeline(array $properties): array
    {
        $properties['original'] ??= $properties;

        $email = $properties['email'] ?? null;
        if (! is_string($email) || $email === '') {
            $id = $properties['id'] ?? 0;
            $properties['email'] = (is_int($id) ? $id : 0).'@zendesk.local';
        }

        if (array_key_exists('phone', $properties) && ! is_string($properties['phone'])) {
            Log::warning('ZendeskUserData: Non-string phone value received', [
                'user_id' => $properties['id'] ?? null,
                'phone_type' => get_debug_type($properties['phone']),
            ]);
            $properties['phone'] = null;
        }

        return $properties;
    }
}
