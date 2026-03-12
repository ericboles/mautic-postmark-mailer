<?php

declare(strict_types=1);

namespace MauticPlugin\PostmarkBundle\EventSubscriber;

use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Mautic\EmailBundle\EmailEvents;
use Mautic\EmailBundle\Event\TransportWebhookEvent;
use Mautic\EmailBundle\Model\TransportCallback;
use Mautic\EmailBundle\MonitoredEmail\Search\ContactFinder;
use Mautic\LeadBundle\Entity\DoNotContact as DNC;
use Mautic\LeadBundle\Model\DoNotContact;
use MauticPlugin\PostmarkBundle\Mailer\Transport\PostmarkTransport;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\Transport\Dsn;
use Psr\Log\LoggerInterface;

class CallbackSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private TransportCallback $transportCallback,
        private CoreParametersHelper $coreParametersHelper,
        private LoggerInterface $logger,
        private ContactFinder $finder,
        private DoNotContact $dncModel
    ) {
    }

    /**
     * @return array<string, string>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            EmailEvents::ON_TRANSPORT_WEBHOOK => 'processCallbackRequest',
        ];
    }

    public function processCallbackRequest(TransportWebhookEvent $event): void
    {
        $payload = null;
        $request = $event->getRequest();
        $contentType = $request->getContentType();
        
        // Log raw webhook for debugging
        $this->logger->info('Webhook received', [
            'content_type' => $contentType,
            'user_agent' => $request->headers->get('User-Agent'),
            'content_length' => $request->headers->get('Content-Length'),
            'raw_content_preview' => substr($request->getContent(), 0, 200)
        ]);
        
        // Parse the webhook payload first
        try {
            switch ($contentType) {
                case 'json':
                    $payload = $request->request->all();
                    break;
                default:
                    $payload = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
                    break;
            }
        } catch (\Exception $e) {
            $this->logger->error('Postmark webhook JSON decoding error', [
                'error' => $e->getMessage(),
                'content' => $request->getContent()
            ]);
            $event->setResponse(new Response('Invalid JSON', Response::HTTP_BAD_REQUEST));
            return;
        }
        
        // Check data
        if (!is_array($payload)) {
            $message = 'There is no data to process.';
            $this->logger->error('Postmark webhook - no data', [
                'content' => $request->getContent(),
                'payload_type' => gettype($payload)
            ]);
            $event->setResponse(new Response($message, Response::HTTP_BAD_REQUEST));
            return;
        }

        // Check if this is actually a Postmark webhook by looking at the payload structure
        // Postmark webhooks always have a RecordType field
        $messageType = $payload['RecordType'] ?? null;
        
        if (!$messageType) {
            // Not a Postmark webhook, ignore silently
            $this->logger->debug('Webhook does not have RecordType field - not a Postmark webhook', [
                'payload_keys' => array_keys($payload)
            ]);
            return;
        }

        // This is a Postmark webhook - check if Postmark transport is configured
        $dsn = Dsn::fromString($this->coreParametersHelper->get('mailer_dsn'));
        $isMainTransport = (PostmarkTransport::MAUTIC_POSTMARK_API_SCHEME === $dsn->getScheme());

        $this->logger->info('Postmark callback received', [
            'record_type' => $messageType,
            'is_main_transport' => $isMainTransport,
            'user_agent' => $request->headers->get('User-Agent'),
            'recipient' => $payload['Recipient'] ?? $payload['Email'] ?? 'unknown'
        ]);

        // For multi-transport setups: Process Postmark webhooks even if not main transport
        // We identify Postmark webhooks by the RecordType field and User-Agent header
        if (!$isMainTransport) {
            $this->logger->warning('Processing Postmark webhook even though Postmark is not the main transport', [
                'main_dsn_scheme' => $dsn->getScheme(),
                'record_type' => $messageType
            ]);
        }

        // Route to appropriate handler based on webhook type
        switch ($messageType) {
            case 'SubscriptionChange':
                $this->handleSubscriptionChange($payload, $event);
                break;
                
            case 'Bounce':
                $this->handleBounce($payload, $event);
                break;
                
            default:
                $this->logger->warning('Unsupported Postmark webhook type', [
                    'record_type' => $messageType
                ]);
                $event->setResponse(new Response("Unsupported webhook type: {$messageType}", Response::HTTP_BAD_REQUEST));
                break;
        }
    }

    /**
     * Handle Postmark Bounce webhook - provides detailed bounce information
     */
    private function handleBounce(array $payload, TransportWebhookEvent $event): void
    {
        $recipient = $payload['Email'] ?? null;
        $bounceType = $payload['Type'] ?? null;
        $metadata = $payload['Metadata'] ?? [];
        
        if (!$recipient) {
            $this->logger->error('Bounce webhook missing recipient email');
            $event->setResponse(new Response('Missing recipient email', Response::HTTP_BAD_REQUEST));
            return;
        }

        $this->logger->info('Processing Postmark bounce', [
            'recipient' => $recipient,
            'bounce_type' => $bounceType,
            'bounce_name' => $payload['Name'] ?? 'Unknown'
        ]);

        // Extract email ID from metadata for campaign statistics
        $emailId = null;
        if (!empty($metadata)) {
            $emailId = $metadata['email_id'] ?? $metadata['mautic_email_id'] ?? null;
            if ($emailId !== null) {
                $emailId = (int) $emailId;
            }
        }

        // Build detailed comment with full bounce information
        $commentParts = [];
        
        if (!empty($payload['Name'])) {
            $commentParts[] = "Type: {$payload['Name']}";
        }
        
        if (!empty($payload['Description'])) {
            $commentParts[] = "Description: {$payload['Description']}";
        }
        
        if (!empty($payload['Details'])) {
            $commentParts[] = "Details: {$payload['Details']}";
        }
        
        // Include truncated SMTP conversation if available
        if (!empty($payload['Content'])) {
            $smtpContent = substr($payload['Content'], 0, 500);
            if (strlen($payload['Content']) > 500) {
                $smtpContent .= '... (truncated)';
            }
            $commentParts[] = "SMTP: {$smtpContent}";
        }
        
        // Add bounce date
        if (!empty($payload['BouncedAt'])) {
            $commentParts[] = "Bounced: {$payload['BouncedAt']}";
        }
        
        $comment = implode("\n", $commentParts);
        if (empty($comment)) {
            $comment = 'Hard bounce (no details provided)';
        }

        // Process based on bounce type
        // HardBounce (TypeCode 1): permanent delivery failure
        // Transient (TypeCode 2): undeliverable (repeated soft bounces become permanent)
        // Blocked (TypeCode 100006): ISP or recipient server blocked delivery
        // AutoResponder (TypeCode 64): normally an out-of-office reply (ignore), BUT Postmark
        //   misclassifies some NDRs as AutoResponder when the bounce message body starts with
        //   "auto-re". In those cases the Details field contains a real 5xx SMTP error — detect
        //   and treat those as hard bounces.
        $isAutoresponderMisclassified = ($bounceType === 'AutoResponder')
            && $this->containsPermanentSmtpFailure($payload['Details'] ?? '');

        if (in_array($bounceType, ['HardBounce', 'Transient', 'Blocked'], true) || $isAutoresponderMisclassified) {
            if ($isAutoresponderMisclassified) {
                $comment = "[Postmark misclassified as AutoResponder — permanent SMTP failure detected]\n" . $comment;
            }

            $this->transportCallback->addFailureByAddress(
                $recipient,
                $comment,
                DNC::BOUNCED,
                $emailId
            );
            
            $this->logger->info('Bounce processed as DNC', [
                'recipient' => $recipient,
                'bounce_type' => $bounceType,
                'misclassified_autoresponder' => $isAutoresponderMisclassified,
                'email_id' => $emailId,
                'details_length' => strlen($comment)
            ]);
        } elseif ($bounceType === 'SpamComplaint') {
            // Spam complaints can also come through bounce webhook
            $this->transportCallback->addFailureByAddress(
                $recipient,
                $comment,
                DNC::UNSUBSCRIBED,
                $emailId
            );
            
            $this->logger->info('Spam complaint processed', [
                'recipient' => $recipient,
                'email_id' => $emailId
            ]);
        } elseif ($bounceType === 'AutoResponder') {
            // True out-of-office / vacation auto-reply — do not DNC
            $this->logger->info('AutoResponder (true out-of-office) received — ignoring', [
                'recipient' => $recipient,
            ]);
        } else {
            // Other bounce types (SoftBounce, etc.) - log but don't set DNC
            $this->logger->info('Non-permanent bounce received (not processing)', [
                'recipient' => $recipient,
                'bounce_type' => $bounceType
            ]);
        }

        $event->setResponse(new Response('Postmark Bounce webhook processed'));
    }

    /**
     * Detect SMTP 5xx permanent failure codes in a Postmark bounce Details string.
     *
     * Postmark occasionally misclassifies NDR messages as "AutoResponder" when the
     * bounce body begins with "auto-re". The Details field still contains the real
     * SMTP exchange, i.e. "550 5.1.1" or "550 5.4.1". A true out-of-office reply
     * will never contain a 5xx status code in its Details.
     *
     * Matches patterns like: 550 5.1.1 / 550-5.4.1 / 554 5.7.1 etc.
     */
    private function containsPermanentSmtpFailure(string $details): bool
    {
        // SMTP permanent failure: 5xx response code followed by enhanced status 5.x.x
        return (bool) preg_match('/\b5\d\d[\s\-]5\.\d+\.\d+/i', $details);
    }

    /**
     * Handle Postmark SubscriptionChange webhook - for suppressions and unsubscribes
     */
    private function handleSubscriptionChange(array $payload, TransportWebhookEvent $event): void
    {
        $reason = $payload['SuppressionReason'] ?? null;
        $suppressSending = filter_var($payload['SuppressSending'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $recipient = $payload['Recipient'] ?? null;

        if (!$recipient) {
            $this->logger->error('SubscriptionChange webhook missing recipient');
            $event->setResponse(new Response('Missing recipient', Response::HTTP_BAD_REQUEST));
            return;
        }

        // Handle reactivation (SuppressSending = false)
        if (!$suppressSending) {
            $this->logger->info('Removing dnc for recipient ' . $recipient);
            $this->removeFailureByAddress($recipient);
            $event->setResponse(new Response('Postmark Callback processed'));
            return;
        }

        $this->logger->info('Processing suppression for ' . $recipient . ' because of ' . $reason);

        // Extract additional tracking data from webhook
        $messageId = $payload['MessageID'] ?? null;
        $tag = $payload['Tag'] ?? null;
        $metadata = $payload['Metadata'] ?? [];
        $messageStream = $payload['MessageStream'] ?? null;

        // Extract email ID from metadata - this is critical for campaign statistics!
        $emailId = null;
        if (!empty($metadata)) {
            // Check for email_id in metadata (sent by PostmarkTransport)
            $emailId = $metadata['email_id'] ?? $metadata['mautic_email_id'] ?? null;
            
            // Convert to integer if it's a string
            if ($emailId !== null) {
                $emailId = (int) $emailId;
            }
        }

        // Log additional context for debugging
        $this->logger->info('Webhook context', [
            'messageId' => $messageId,
            'tag' => $tag,
            'messageStream' => $messageStream,
            'metadata' => $metadata,
            'emailId' => $emailId
        ]);

        // Process the suppression using TransportCallback
        // The 4th parameter (emailId) is CRITICAL - it enables campaign statistics!
        // Note: HardBounce and SpamComplaint may also be processed by Bounce webhook
        // TransportCallback handles duplicates gracefully
        switch($reason) {
            case 'ManualSuppression':
                // Contact manually unsubscribed
                $this->transportCallback->addFailureByAddress(
                    $recipient, 
                    'Manual unsubscribe via Postmark', 
                    DNC::UNSUBSCRIBED,
                    $emailId  // ← This enables campaign statistics!
                );
                break;
            case 'HardBounce':
                // Email bounced - Note: Bounce webhook provides more details
                $this->transportCallback->addFailureByAddress(
                    $recipient, 
                    'Hard bounce (from SubscriptionChange webhook)',
                    DNC::BOUNCED,
                    $emailId  // ← This enables campaign statistics!
                );
                break;
            case 'SpamComplaint':
                // Marked as spam - Note: Bounce webhook may provide more details
                $this->transportCallback->addFailureByAddress(
                    $recipient, 
                    'Spam complaint (from SubscriptionChange webhook)',
                    DNC::UNSUBSCRIBED,
                    $emailId  // ← This enables campaign statistics!
                );
                break;
            default:
                $this->logger->warning('Unknown suppression reason: ' . $reason);
                break;
        }

        $event->setResponse(new Response('Postmark Callback processed'));
    }

    private function removeFailureByAddress($address, $channelId = null): void
    {
        $result = $this->finder->findByAddress($address);

        if ($contacts = $result->getContacts()) {
            foreach ($contacts as $contact) {
                $channel = ($channelId) ? ['email' => $channelId] : 'email';
                $this->dncModel->removeDncForContact($contact->getId(), $channel);
            }
        }
    }
}
