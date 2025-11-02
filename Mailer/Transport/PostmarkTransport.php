<?php

declare(strict_types=1);

namespace MauticPlugin\PostmarkBundle\Mailer\Transport;

use Mautic\EmailBundle\Helper\MailHelper;
use Mautic\EmailBundle\Mailer\Message\MauticMessage;
use Mautic\EmailBundle\Mailer\Transport\TokenTransportInterface;
use Mautic\EmailBundle\Mailer\Transport\TokenTransportTrait;
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

class PostmarkTransport extends AbstractTransport implements TokenTransportInterface
{
    use TokenTransportTrait;

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

    /**
     * Return the max number of to addresses allowed per batch.
     * 
     * Phase 1 Implementation: Return 1 to signal tokenization support while maintaining
     * one-by-one sending behavior (same as when Postmark is default transport).
     * This fixes the MultipleTransportBundle issue where only the first recipient receives
     * the email when Postmark is configured as a secondary transport.
     * 
     * Future Phase 2: Can be increased to 500 to enable Postmark's batch API for performance.
     */
    public function getMaxBatchLimit(): int
    {
        return 1; // Phase 1: Disable batching, enable tokenization support
    }

    #[\Override]
    protected function doSend(SentMessage $message): void
    {
        $envelope = $message->getEnvelope();
        $email = MessageConverter::toEmail($message->getOriginalMessage());

        // Check if this is a MauticMessage with metadata (batch mode)
        if ($email instanceof MauticMessage) {
            $metadata = $email->getMetadata();
            
            if (!empty($metadata)) {
                // This is a batch send - metadata contains all recipients
                $this->logger->info('Postmark batch mode detected', [
                    'recipient_count' => count($metadata),
                    'recipients' => array_keys($metadata)
                ]);
                
                // Send to each recipient individually
                $this->sendToMultipleRecipients($email, $envelope, $metadata);
                return;
            }
        }

        // Single recipient send (original behavior)
        $this->sendSingleEmail($email, $envelope, $message);
    }

    /**
     * Send email to multiple recipients (batch mode with metadata)
     */
    private function sendToMultipleRecipients(MauticMessage $email, Envelope $envelope, array $metadata): void
    {
        $this->logger->info('Sending to multiple recipients via Postmark', [
            'count' => count($metadata),
            'recipients' => array_keys($metadata)
        ]);

        foreach ($metadata as $recipientAddress => $recipientData) {
            try {
                $this->logger->debug('Processing recipient', [
                    'recipient' => $recipientAddress,
                    'has_tokens' => !empty($recipientData['tokens'])
                ]);
                
                // Create a new message for this specific recipient
                $recipientEmail = clone $email;
                
                // Clear all recipients and set only this one
                $recipientEmail->to($recipientAddress);
                $recipientEmail->cc();
                $recipientEmail->bcc();
                
                // Apply tokens for this recipient if provided
                if (!empty($recipientData['tokens'])) {
                    $subject = $email->getSubject();
                    $htmlBody = $email->getHtmlBody();
                    $textBody = $email->getTextBody();
                    
                    foreach ($recipientData['tokens'] as $token => $value) {
                        $subject = str_replace($token, $value, $subject);
                        $htmlBody = str_replace($token, $value, $htmlBody);
                        $textBody = str_replace($token, $value, $textBody);
                    }
                    
                    $recipientEmail->subject($subject);
                    $recipientEmail->html($htmlBody);
                    $recipientEmail->text($textBody);
                }
                
                // Create a new envelope with ONLY this recipient
                $newEnvelope = new Envelope(
                    $envelope->getSender(),
                    [new Address($recipientAddress)]
                );
                
                // Create a SentMessage wrapper for this recipient
                $sentMessage = new SentMessage($recipientEmail, $newEnvelope);
                
                // Send to this recipient
                $this->sendSingleEmail($recipientEmail, $newEnvelope, $sentMessage);
                
                $this->logger->info('Email sent successfully', [
                    'recipient' => $recipientAddress
                ]);
                
            } catch (\Exception $e) {
                // Log but don't stop - continue to other recipients
                $this->logger->error('Failed to send to recipient', [
                    'recipient' => $recipientAddress,
                    'error' => $e->getMessage()
                ]);
            }
        }
    }

    /**
     * Send a single email (original doSend logic)
     */
    private function sendSingleEmail(Email $email, Envelope $envelope, SentMessage $message): void
    {
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
        // Initialize metadata array to collect data
        $metadata = [];

        // Extract Mautic tracking information from email headers
        $mauticHeaders = [];
        foreach ($email->getHeaders()->all() as $name => $header) {
            $headerValue = $header->getBodyAsString();
            
            // Special handling for X-EMAIL-ID - this is critical for campaign stats!
            if (strtoupper($name) === 'X-EMAIL-ID') {
                $metadata['email_id'] = $headerValue;
                $this->logger?->info('Added email_id to Postmark metadata', [
                    'email_id' => $headerValue
                ]);
            }
            
            // Capture all Mautic-related headers
            if (str_starts_with($name, 'X-Mautic-') || str_starts_with($name, 'X-Email-')) {
                $mauticHeaders[$name] = $headerValue;
            }
        }

        // Add other Mautic tracking data to metadata
        if (!empty($mauticHeaders)) {
            // Add relevant Mautic tracking info
            foreach ($mauticHeaders as $headerName => $headerValue) {
                $metadataKey = str_replace(['X-Mautic-', 'X-Email-', 'X-'], '', $headerName);
                $metadata['mautic_' . strtolower($metadataKey)] = $headerValue;
            }

            $this->logger?->debug('Added Mautic tracking metadata to Postmark', [
                'metadata' => $metadata
            ]);
        }

        // Only add Metadata to payload if we have actual data
        // Postmark API rejects empty Metadata objects (error 403)
        if (!empty($metadata)) {
            $payload['Metadata'] = $metadata;
        }
    }

    // public function getMaxBatchLimit(): int
    // {
    //     return 500;
    // }
}
