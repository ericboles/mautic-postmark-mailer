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
use Symfony\Component\Mailer\Header\TagHeader;
use MauticPlugin\PostmarkBundle\Mailer\Transport\MessageStreamHeader;
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

    // Postmark API Error Codes
    private const CODE_INACTIVE_RECIPIENT = 406;  // Recipient on suppression list

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
                // Handle suppressed recipients (on Postmark suppression list)
                if (self::CODE_INACTIVE_RECIPIENT === $result['ErrorCode']) {
                    // Get recipient email address
                    $recipients = $envelope->getRecipients();
                    $recipientEmail = !empty($recipients) ? $recipients[0]->getAddress() : 'unknown';
                    
                    // Extract email ID for proper stat tracking
                    $emailId = null;
                    foreach ($email->getHeaders()->all() as $name => $header) {
                        if (strtoupper($name) === 'X-EMAIL-ID') {
                            $emailId = (int) $header->getBodyAsString();
                            break;
                        }
                    }
                    
                    // Re-sync to Mautic DNC - Postmark suppression list is source of truth
                    // The 4th parameter (emailId) ensures campaign stats are updated
                    $this->callback->addFailureByAddress(
                        $recipientEmail,
                        'Postmark Suppression: ' . ($result['Message'] ?? 'Recipient on suppression list'),
                        DoNotContact::BOUNCED,
                        $emailId
                    );
                    
                    // Log the sync action for admin awareness
                    $this->logger->warning('Contact reactivated in Mautic but suppressed in Postmark - re-synced to DNC', [
                        'recipient' => $recipientEmail,
                        'email_id' => $emailId,
                        'postmark_error' => $result['Message'] ?? 'Unknown',
                        'postmark_error_code' => $result['ErrorCode'],
                        'action' => 'Re-added to Mautic DNC list',
                        'note' => 'Remove from Postmark suppression list if reactivation was intentional'
                    ]);
                    
                    // Return without throwing exception - allows other emails to continue
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

        // Add Mautic tracking information to metadata for better webhook processing
        $this->addMauticTrackingMetadata($payload, $email);

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

            if (str_starts_with($name, 'X-PM-Metadata-')) {
                $metadataKey = substr($name, 14); // Remove 'X-PM-Metadata-' prefix
                $payload['Metadata'][$metadataKey] = $header->getBodyAsString();

                continue;
            }

            if ($header instanceof MessageStreamHeader || strcasecmp($name, 'X-PM-Message-Stream') === 0) {
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
        return ($this->host ?: self::POSTMARK_HOST);
    }

    /**
     * Add Mautic-specific tracking metadata to Postmark payload
     * This is CRITICAL for campaign statistics - the email_id enables bounce tracking!
     */
    private function addMauticTrackingMetadata(array &$payload, Email $email): void
    {
        if (!isset($payload['Metadata'])) {
            $payload['Metadata'] = [];
        }

        // Extract Mautic tracking information from email headers
        $mauticHeaders = [];
        foreach ($email->getHeaders()->all() as $name => $header) {
            $headerValue = $header->getBodyAsString();
            
            // Special handling for X-EMAIL-ID - this is critical for campaign stats!
            if (strtoupper($name) === 'X-EMAIL-ID') {
                $payload['Metadata']['email_id'] = $headerValue;
                $this->logger?->info('Added email_id to Postmark metadata', [
                    'email_id' => $headerValue
                ]);
            }
            
            // Capture all Mautic-related headers
            if (str_starts_with($name, 'X-Mautic-') || str_starts_with($name, 'X-Email-')) {
                $mauticHeaders[$name] = $headerValue;
            }
        }

        // Add other Mautic tracking data to Postmark metadata
        if (!empty($mauticHeaders)) {
            // Add relevant Mautic tracking info
            foreach ($mauticHeaders as $headerName => $headerValue) {
                $metadataKey = str_replace(['X-Mautic-', 'X-Email-', 'X-'], '', $headerName);
                $payload['Metadata']['mautic_' . strtolower($metadataKey)] = $headerValue;
            }

            $this->logger?->debug('Added Mautic tracking metadata to Postmark', [
                'metadata' => $payload['Metadata']
            ]);
        }
    }

    // public function getMaxBatchLimit(): int
    // {
    //     return 500;
    // }
}
