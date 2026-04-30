<?php

declare(strict_types=1);

namespace Integrations\Adapters\Tests\Unit\Stripe;

use Integrations\Adapters\Stripe\StripeClient;
use Integrations\Adapters\Stripe\StripeProvider;
use Integrations\Adapters\Stripe\StripeResource;
use Integrations\Adapters\Tests\TestCase;
use Integrations\RequestContext;
use Integrations\Testing\CreatesIntegration;
use RuntimeException;
use Stripe\ApiResponse;
use Stripe\Charge;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\InvalidRequestException;
use Stripe\StripeClient as StripeSdkClient;
use Stripe\StripeObject;
use Stripe\Util\CaseInsensitiveArray;

class StripeResourceTest extends TestCase
{
    use CreatesIntegration;

    public function test_call_stripe_records_request_id_from_returned_resource(): void
    {
        $resource = $this->makeResource();
        $ctx = new RequestContext;

        $charge = new Charge;
        $charge->setLastResponse(new ApiResponse(
            '',
            200,
            new CaseInsensitiveArray(['Request-Id' => 'req_success_123']),
            [],
        ));

        $result = $resource->callStripeForTest($ctx, fn (): Charge => $charge);

        $this->assertSame($charge, $result);
        $this->assertSame('req_success_123', $ctx->providerRequestId());
    }

    public function test_call_stripe_records_request_id_from_api_error_and_rethrows(): void
    {
        $resource = $this->makeResource();
        $ctx = new RequestContext;

        $error = new InvalidRequestException('bad request');
        $error->setRequestId('req_error_456');

        $caught = null;
        try {
            $resource->callStripeForTest($ctx, fn (): StripeObject => throw $error);
        } catch (ApiErrorException $e) {
            $caught = $e;
        }

        $this->assertSame($error, $caught);
        $this->assertSame('req_error_456', $ctx->providerRequestId());
    }

    public function test_call_stripe_lets_non_api_exceptions_propagate_unmodified(): void
    {
        $resource = $this->makeResource();
        $ctx = new RequestContext;

        $caught = null;
        try {
            $resource->callStripeForTest(
                $ctx,
                fn (): StripeObject => throw new RuntimeException('not a Stripe error'),
            );
        } catch (RuntimeException $e) {
            $caught = $e;
        }

        $this->assertInstanceOf(RuntimeException::class, $caught);
        $this->assertNull($ctx->providerRequestId());
    }

    public function test_stripe_options_omits_idempotency_key_when_null(): void
    {
        $resource = $this->makeResource();

        $opts = $resource->stripeOptionsForTest(new RequestContext);

        $this->assertSame([], $opts);
    }

    public function test_stripe_options_includes_idempotency_key_when_set(): void
    {
        $resource = $this->makeResource();

        $opts = $resource->stripeOptionsForTest(new RequestContext('order-42'));

        $this->assertSame(['idempotency_key' => 'order-42'], $opts);
    }

    private function makeResource(): TestableStripeResource
    {
        $integration = $this->createIntegration(
            providerKey: 'stripe',
            providerClass: StripeProvider::class,
            credentials: [
                'api_key' => 'sk_test_unit',
                'webhook_secret' => 'whsec_zzz',
            ],
        );

        return new TestableStripeResource(
            $integration,
            new StripeClient($integration, new StripeSdkClient('sk_test_unit')),
        );
    }
}

/**
 * Concrete subclass that exposes the protected helpers under test. Lives
 * here rather than in a Fixtures/ directory because it's coupled tightly
 * to the assertions in this single file.
 */
class TestableStripeResource extends StripeResource
{
    public function callStripeForTest(RequestContext $ctx, callable $sdkCall): StripeObject
    {
        return $this->callStripe($ctx, $sdkCall);
    }

    /**
     * @return array{idempotency_key?: string}
     */
    public function stripeOptionsForTest(RequestContext $ctx): array
    {
        return $this->stripeOptions($ctx);
    }
}
