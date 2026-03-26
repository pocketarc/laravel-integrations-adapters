<?php

declare(strict_types=1);

namespace Integrations\Adapters\Zendesk\Data;

use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;

class ZendeskUserData extends Data
{
    /**
     * @param  array<int, string>  $tags
     * @param  array<string, mixed>  $user_fields
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
        public readonly string|null|Optional $alias = '',
        public readonly string|null|Optional $signature = '',
        public readonly string|null|Optional $details = '',
        public readonly string|null|Optional $notes = '',
        public readonly array|Optional $tags = [],
        public readonly array|Optional $user_fields = [],
    ) {}

    /**
     * Create from Zendesk API response.
     * Handles email fallback for users without email addresses.
     *
     * Note: Method is named "create*" not "from*" to avoid Laravel Data's
     * custom creator detection which would cause infinite recursion.
     *
     * @param  array<string, mixed>|object  $response
     */
    public static function createFromZendeskResponse(object|array $response): self
    {
        if (is_object($response)) {
            $encoded = json_encode($response);
            $response = is_string($encoded) ? json_decode($encoded, true) : [];
            if (! is_array($response)) {
                $response = [];
            }
        }

        $email = $response['email'] ?? null;
        if ($email === null || $email === '') {
            $id = $response['id'] ?? 0;
            $response['email'] = (is_int($id) ? $id : 0).'@zendesk.local';
        }

        if (isset($response['phone']) && ! is_string($response['phone'])) {
            Log::warning('ZendeskUserData: Non-string phone value received', [
                'user_id' => $response['id'] ?? null,
                'phone_type' => get_debug_type($response['phone']),
            ]);
            $response['phone'] = null;
        }

        return self::from($response);
    }
}
