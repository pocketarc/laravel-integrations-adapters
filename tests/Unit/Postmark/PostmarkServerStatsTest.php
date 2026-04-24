<?php

declare(strict_types=1);

namespace Integrations\Adapters\Tests\Unit\Postmark;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Integrations\Adapters\Postmark\Data\PostmarkOutboundStatsData;
use Integrations\Adapters\Postmark\PostmarkClient;
use Integrations\Adapters\Postmark\PostmarkProvider;
use Integrations\Adapters\Tests\TestCase;
use Integrations\Testing\CreatesIntegration;
use InvalidArgumentException;
use Postmark\PostmarkClient as PostmarkSdkClient;

class PostmarkServerStatsTest extends TestCase
{
    use CreatesIntegration;

    public function test_overview_rejects_non_ymd_from_date(): void
    {
        $client = $this->createClient(new MockHandler([]));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('fromDate');

        $client->serverStats()->overview(fromDate: '04/23/2026');
    }

    public function test_overview_rejects_non_ymd_to_date(): void
    {
        $client = $this->createClient(new MockHandler([]));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('toDate');

        $client->serverStats()->overview(toDate: '23-04-2026');
    }

    public function test_overview_accepts_well_formed_ymd_dates(): void
    {
        $client = $this->createClient(new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], (string) json_encode([
                'Sent' => 100,
                'Bounced' => 2,
                'SMTPApiErrors' => 0,
                'BounceRate' => 2.0,
                'SpamComplaints' => 1,
                'SpamComplaintsRate' => 1.0,
                'Tracked' => 95,
                'Opens' => 40,
                'UniqueOpens' => 30,
                'TotalClicks' => 5,
                'UniqueLinksClicked' => 4,
                'WithClientRecorded' => 25,
                'WithPlatformRecorded' => 25,
                'WithReadTimeRecorded' => 20,
            ])),
        ]));

        $stats = $client->serverStats()->overview(fromDate: '2026-04-01', toDate: '2026-04-23');

        $this->assertInstanceOf(PostmarkOutboundStatsData::class, $stats);
        $this->assertSame(100, $stats->Sent);
        $this->assertSame(2.0, $stats->BounceRate);
    }

    public function test_overview_accepts_null_dates(): void
    {
        $client = $this->createClient(new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], (string) json_encode([
                'Sent' => 0, 'Bounced' => 0, 'SMTPApiErrors' => 0, 'BounceRate' => 0.0,
                'SpamComplaints' => 0, 'SpamComplaintsRate' => 0.0, 'Tracked' => 0,
                'Opens' => 0, 'UniqueOpens' => 0, 'TotalClicks' => 0,
                'UniqueLinksClicked' => 0, 'WithClientRecorded' => 0,
                'WithPlatformRecorded' => 0, 'WithReadTimeRecorded' => 0,
            ])),
        ]));

        $stats = $client->serverStats()->overview();

        $this->assertInstanceOf(PostmarkOutboundStatsData::class, $stats);
    }

    private function createClient(MockHandler $mockHandler): PostmarkClient
    {
        $integration = $this->createIntegration(
            providerKey: 'postmark',
            providerClass: PostmarkProvider::class,
            credentials: ['server_token' => 'srv-abc'],
        );

        $sdk = new PostmarkSdkClient('srv-abc');
        $sdk->setClient(new GuzzleClient(['handler' => HandlerStack::create($mockHandler)]));

        return new PostmarkClient($integration, $sdk);
    }
}
