<?php

declare(strict_types=1);

namespace MauticPlugin\PostmarkBundle\Tests\Unit\Mailer\Factory;

use Mautic\EmailBundle\Model\TransportCallback;
use MauticPlugin\PostmarkBundle\Mailer\Factory\PostmarkTransportFactory;
use MauticPlugin\PostmarkBundle\Mailer\Transport\PostmarkTransport;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Mailer\Exception\InvalidArgumentException;
use Symfony\Component\Mailer\Exception\UnsupportedSchemeException;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class PostmarkTransportFactoryTest extends TestCase
{
    private PostmarkTransportFactory $postmarkTransportFactory;

    private TranslatorInterface|MockObject $translatorMock;

    protected function setUp(): void
    {
        $eventDispatcherMock   = $this->createMock(EventDispatcherInterface::class);
        $this->translatorMock  = $this->createMock(TranslatorInterface::class);
        $transportCallbackMock = $this->createMock(TransportCallback::class);
        $httpClientMock        = $this->createMock(HttpClientInterface::class);
        $loggerMock            = $this->createMock(LoggerInterface::class);

        $this->postmarkTransportFactory = new PostmarkTransportFactory(
            $transportCallbackMock,
            $this->translatorMock,
            $eventDispatcherMock,
            $httpClientMock,
            $loggerMock
        );
    }

    public function testCreateTransport(): void
    {
        $dsn = new Dsn(
            PostmarkTransport::MAUTIC_POSTMARK_API_SCHEME,
            'host',
            null,
            'postmark_api_key',
            null,
            ['region' => 'us']
        );
        $postmarkTransport = $this->postmarkTransportFactory->create($dsn);
        Assert::assertInstanceOf(PostmarkTransport::class, $postmarkTransport);
    }

    public function testUnsupportedScheme(): void
    {
        $this->expectException(UnsupportedSchemeException::class);
        $this->expectExceptionMessage('The "some+unsupported+scheme" scheme is not supported; supported schemes for mailer "postmark" are: "mautic+postmark+api".');
        $dsn = new Dsn(
            'some+unsupported+scheme',
            'host',
            null,
            'postmark_api_key',
            null,
            ['region' => 'us']
        );
        $this->postmarkTransportFactory->create($dsn);
    }

    public function testEmptyPostmarkRegion(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Postmark region is empty. Add 'region' as a option.");

        $this->translatorMock->expects(self::once())
            ->method('trans')
            ->with('mautic.postmark.plugin.region.empty', [], 'validators')
            ->willReturn("Postmark region is empty. Add 'region' as a option.");

        $dsn = new Dsn(
            'mautic+postmark+api',
            'host',
            null,
            'postmark_api_key',
            null,
        );
        $this->postmarkTransportFactory->create($dsn);
    }

    public function testInvalidPostmarkRegion(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Postmark region is invalid. Add 'us' or 'eu' as a suitable region.");

        $this->translatorMock->expects(self::once())
            ->method('trans')
            ->with('mautic.postmark.plugin.region.invalid', [], 'validators')
            ->willReturn("Postmark region is invalid. Add 'us' or 'eu' as a suitable region.");

        $dsn = new Dsn(
            'mautic+postmark+api',
            'host',
            null,
            'postmark_api_key',
            null,
            ['region' => 'some_invalid_region']
        );
        $this->postmarkTransportFactory->create($dsn);
    }
}
