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
        $dsn = Dsn::fromString($this->coreParametersHelper->get('mailer_dsn'));

        if (PostmarkTransport::MAUTIC_POSTMARK_API_SCHEME !== $dsn->getScheme()) {
            return;
        }

        $payload = null;
        $request = $event->getRequest();
        $contentType = $request->getContentType();
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
            $this->logger->error('JSON decoding error: ' . $e->getMessage());
            $event->setResponse(new Response('Invalid JSON', Response::HTTP_BAD_REQUEST));
            return;
        }
        

        // Check data
        if (!is_array($payload)) {
            $message = 'There is no data to process.';
            $this->logger->error($message . $event->getRequest()->getContent());
            $event->setResponse(new Response($message, Response::HTTP_BAD_REQUEST));
            return;
        }


        $this->logger->info('Postmark callback received');

        $messageType = $payload['RecordType'] ?? null;

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
        if ($bounceType === 'HardBounce') {
            $this->transportCallback->addFailureByAddress(
                $recipient,
                $comment,
                DNC::BOUNCED,
                $emailId
            );
            
            $this->logger->info('Hard bounce processed with detailed information', [
                'recipient' => $recipient,
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
        } else {
            // Soft bounces, transient issues, etc.
            $this->logger->info('Non-permanent bounce received (not processing)', [
                'recipient' => $recipient,
                'bounce_type' => $bounceType
            ]);
        }

        $event->setResponse(new Response('Postmark Bounce webhook processed'));
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
