<?php

declare(strict_types=1);

namespace MauticPlugin\PostmarkBundle\Mailer\Transport;

use Mautic\EmailBundle\Helper\MailHelper;
use Mautic\EmailBundle\Mailer\Message\MauticMessage;
// use Mautic\EmailBundle\Mailer\Transport\TokenTransportInterface;
// use Mautic\EmailBundle\Mailer\Transport\TokenTransportTrait;
use Mautic\EmailBundle\Model\TransportCallback;
use Mautic\LeadBundle\Entity\DoNotContact;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\HttpTransportException;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractApiTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\MessageConverter;
use Symfony\Component\Mime\Header\ParameterizedHeader;
use Symfony\Component\Mime\Header\UnstructuredHeader;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Component\Mailer\Transport\AbstractTransport;

class PostmarkTransport extends AbstractTransport
{
    public const MAUTIC_POSTMARK_API_SCHEME = 'mautic+postmark+api';

    public const POSTMARK_HOST = 'api.postmarkapp.com';

    private const STD_HEADER_KEYS = [
        'MIME-Version',
        'received',
        'dkim-signature',
        'Content-Type',
        'Content-Transfer-Encoding',
        'To',
        'From',
        'Subject',
        'Reply-To',
        'CC',
        'BCC',
    ];

    private ?string $messageStream = null;
    private ?HttpClientInterface $client = null;
    private LoggerInterface $logger;

    public function __construct(
        private string $apiKey,
        string $stream,
        private TransportCallback $callback,
        ?HttpClientInterface $client = null,
        ?EventDispatcherInterface $dispatcher = null,
        ?LoggerInterface $logger = null
    ) {
        parent::__construct($dispatcher, $logger);
        $this->host = self::POSTMARK_HOST;
        $this->messageStream = $stream;
        $this->client = $client;
        $this->logger = $logger;
    }

    public function __toString(): string
    {
        return sprintf(self::MAUTIC_POSTMARK_API_SCHEME.'://%s', $this->host).($this->messageStream ? '?messageStream='.$this->messageStream : '');
    }

    #[\Override]
    protected function doSend(SentMessage $message): void
    {
        try {
            
            $envelope = $message->getEnvelope();
            $email = MessageConverter::toEmail($message->getOriginalMessage());

            $response = $this->client->request('POST', 'https://'.$this->getEndpoint().'/email', [
                'headers' => [
                    'Accept' => 'application/json',
                    'X-Postmark-Server-Token' => $this->apiKey,
                ],
                'json' => $this->getPayload($email, $envelope),
            ]);

            $statusCode = $response->getStatusCode();
            $result = $response->toArray(false);

            if (200 !== $statusCode) {
                // Some delivery issues can be handled silently - route those through EventDispatcher
                if (null !== $this->dispatcher && self::CODE_INACTIVE_RECIPIENT === $result['ErrorCode']) {
                    $this->dispatcher->dispatch(new PostmarkDeliveryEvent($result['Message'], $result['ErrorCode'], $email->getHeaders()));
    
                    return;
                }
    
                throw new HttpTransportException('Unable to send an email: '.$result['Message'].\sprintf(' (code %d).', $result['ErrorCode']), $response);
            }
    
            $message->setMessageId($result['MessageID']);

        } catch (DecodingExceptionInterface) {
            throw new HttpTransportException('Unable to send an email: '.$response->getContent(false).\sprintf(' (code %d).', $statusCode), $response);
        } catch (TransportExceptionInterface $e) {
            throw new HttpTransportException('Could not reach the remote Postmark server.', $response, 0, $e);
        } 
    }

    private function getPayload(Email $email, Envelope $envelope): array
    {
        $payload = [
            'From' => $envelope->getSender()->toString(),
            'To' => implode(',', $this->stringifyAddresses($this->getRecipients($email, $envelope))),
            'Cc' => implode(',', $this->stringifyAddresses($email->getCc())),
            'Bcc' => implode(',', $this->stringifyAddresses($email->getBcc())),
            'ReplyTo' => implode(',', $this->stringifyAddresses($email->getReplyTo())),
            'Subject' => $email->getSubject(),
            'TextBody' => $email->getTextBody(),
            'HtmlBody' => $email->getHtmlBody(),
            'Attachments' => $this->getAttachments($email),
        ];

        $headersToBypass = ['from', 'to', 'cc', 'bcc', 'subject', 'content-type', 'sender', 'reply-to'];
        foreach ($email->getHeaders()->all() as $name => $header) {
            if (\in_array($name, $headersToBypass, true)) {
                continue;
            }

            if ($header instanceof TagHeader) {
                if (isset($payload['Tag'])) {
                    throw new TransportException('Postmark only allows a single tag per email.');
                }

                $payload['Tag'] = $header->getValue();

                continue;
            }

            if ($header instanceof MetadataHeader) {
                $payload['Metadata'][$header->getKey()] = $header->getValue();

                continue;
            }

            if ($header instanceof MessageStreamHeader) {
                $payload['MessageStream'] = $header->getValue();

                continue;
            }

            $payload['Headers'][] = [
                'Name' => $header->getName(),
                'Value' => $header->getBodyAsString(),
            ];
        }

        if (null !== $this->messageStream && !isset($payload['MessageStream'])) {
            $payload['MessageStream'] = $this->messageStream;
        }

        return $payload;
    }

    private function getRecipients(Email $email, Envelope $envelope): array
    {
        return array_filter($envelope->getRecipients(), fn (Address $address) => false === \in_array($address, array_merge($email->getCc(), $email->getBcc()), true));
    }

    private function getAttachments(Email $email): array
    {
        $attachments = [];
        foreach ($email->getAttachments() as $attachment) {
            $headers = $attachment->getPreparedHeaders();
            $filename = $headers->getHeaderParameter('Content-Disposition', 'filename');
            $disposition = $headers->getHeaderBody('Content-Disposition');

            $att = [
                'Name' => $filename,
                'Content' => $attachment->bodyToString(),
                'ContentType' => $headers->get('Content-Type')->getBody(),
            ];

            if ('inline' === $disposition) {
                $att['ContentID'] = 'cid:'.$filename;
            }

            $attachments[] = $att;
        }

        return $attachments;
    }

    private function getEndpoint(): ?string
    {
        return ($this->host ?: self::HOST);
    }

    // public function getMaxBatchLimit(): int
    // {
    //     return 500;
    // }
}
