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
        
        error_log("Validating...");

        // Check data
        if (!is_array($payload)) {
            $message = 'There is no data to process.';
            $this->logger->error($message . $event->getRequest()->getContent());
            $event->setResponse(new Response($message, Response::HTTP_BAD_REQUEST));
            return;
        }


        $this->logger->info('Postmark callback received');

        $messageType = $payload['RecordType'] ?? null;

        error_log('Message type: ' . $messageType);



        if ($messageType !== "SubscriptionChange") {
            $event->setResponse(new Response("This callback only supports 'SubscriptionChange' events", Response::HTTP_BAD_REQUEST));
            return;
        }

        $reason = $payload['SuppressionReason'];
        $suppressSending = filter_var($payload['SuppressSending'], FILTER_VALIDATE_BOOLEAN);
        $recipient = $payload['Recipient'] ?? null;

        error_log('SuprressSending: ' . $suppressSending);

        if(!$suppressSending){
            error_log('SuppressSending is false, ignoring');
            $this->logger->info('Removing dnc for recipient ' . $recipient);
            $this->removeFailureByAddress($recipient);
            $event->setResponse(new Response('Postmark Callback processed'));
            return;
        }

        $this->logger->info('Unsubscribing ' . $recipient . ' because of ' . $reason);

        switch($reason) {
            case 'ManualSuppression':
                $this->transportCallback->addFailureByAddress($recipient, 'unsubscribed', DNC::UNSUBSCRIBED);
                break;
            case 'HardBounce':
                $this->transportCallback->addFailureByAddress($recipient, 'hard_bounce');
                break;
            case 'SpamComplaint':
                $this->transportCallback->addFailureByAddress($recipient, 'spam_complaint', DNC::UNSUBSCRIBED);
                break;
            default:
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
