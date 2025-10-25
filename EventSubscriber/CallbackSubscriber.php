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

        if ($messageType !== "SubscriptionChange") {
            $event->setResponse(new Response("This callback only supports 'SubscriptionChange' events", Response::HTTP_BAD_REQUEST));
            return;
        }

        $reason = $payload['SuppressionReason'];
        $suppressSending = filter_var($payload['SuppressSending'], FILTER_VALIDATE_BOOLEAN);
        $recipient = $payload['Recipient'] ?? null;


        if(!$suppressSending){
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
        switch($reason) {
            case 'ManualSuppression':
                // Contact manually unsubscribed
                $this->transportCallback->addFailureByAddress(
                    $recipient, 
                    'unsubscribed', 
                    DNC::UNSUBSCRIBED,
                    $emailId  // ← This enables campaign statistics!
                );
                break;
            case 'HardBounce':
                // Email bounced - this will set isFailed=true on the Stat record
                $this->transportCallback->addFailureByAddress(
                    $recipient, 
                    'hard_bounce',
                    DNC::BOUNCED,
                    $emailId  // ← This enables campaign statistics!
                );
                break;
            case 'SpamComplaint':
                // Marked as spam
                $this->transportCallback->addFailureByAddress(
                    $recipient, 
                    'spam_complaint',
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
