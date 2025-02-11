<?php

declare(strict_types=1);

namespace MauticPlugin\PostmarkBundle\Mailer\Factory;

use Mautic\EmailBundle\Model\TransportCallback;
use MauticPlugin\PostmarkBundle\Mailer\Transport\PostmarkTransport;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Mailer\Exception\InvalidArgumentException;
use Symfony\Component\Mailer\Exception\UnsupportedSchemeException;
use Symfony\Component\Mailer\Transport\AbstractTransportFactory;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

class PostmarkTransportFactory extends AbstractTransportFactory
{
    public function __construct(
        private TransportCallback $transportCallback,
        private TranslatorInterface $translator,
        EventDispatcherInterface $eventDispatcher,
        HttpClientInterface $client = null,
        LoggerInterface $logger = null
    ) {
        parent::__construct($eventDispatcher, $client, $logger);
    }

    /**
     * @return string[]
     */
    protected function getSupportedSchemes(): array
    {
        return [PostmarkTransport::MAUTIC_POSTMARK_API_SCHEME];
    }

    public function create(Dsn $dsn): TransportInterface
    {
        if (PostmarkTransport::MAUTIC_POSTMARK_API_SCHEME === $dsn->getScheme()) {
            if (!$region = $dsn->getOption('messageStream')) {
                throw new InvalidArgumentException($this->translator->trans('mautic.postmark.plugin.stream.empty', [], 'validators'));
            }

            // if (!array_key_exists($region, PostmarkTransport::POSTMARK_HOSTS)) {
            //     throw new InvalidArgumentException($this->translator->trans('mautic.postmark.plugin.region.invalid', [], 'validators'));
            // }

            return new PostmarkTransport(
                $this->getPassword($dsn),
                $region,
                $this->transportCallback,
                $this->client,
                $this->dispatcher,
                $this->logger
            );
        }

        throw new UnsupportedSchemeException($dsn, 'postmark', $this->getSupportedSchemes());
    }
}
