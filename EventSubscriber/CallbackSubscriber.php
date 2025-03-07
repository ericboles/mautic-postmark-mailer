<?php

declare(strict_types=1);

namespace MauticPlugin\PostmarkBundle\EventSubscriber;

use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Mautic\EmailBundle\EmailEvents;
use Mautic\EmailBundle\Event\TransportWebhookEvent;
use Mautic\EmailBundle\Model\TransportCallback;
use Mautic\LeadBundle\Entity\DoNotContact;
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
        switch ($contentType) {
            case 'json':
                $payload = $request->request->all();
                break;
            default:
                $payload = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
                break;
        }

        // Check data
        if (!is_array($payload)) {
            $message = 'There is no data to process.';
            $this->logger->error($message . $event->getRequest()->getContent());
            $event->setResponse(new Response($message, Response::HTTP_BAD_REQUEST));
            return;
        }

        //$payload = $event->getRequest()->request->all();

        $this->logger->info('Postmark callback received', $payload);

        foreach ($payload as $postmarkPayload){
            $messageType = $postmarkPayload['RecordType'] ?? null;

            if ($messageType !== "SubscriptionChange") {
                continue;
            }

            $reason = $postmarkPayload['SuppressionReason'];
            $suppressSending = filter_var($postmarkPayload['SuppressSending'], FILTER_VALIDATE_BOOLEAN);
            

            $recipient = $postmarkPayload['Recipient'] ?? null;

            $logger->info('Unsubscribing ' . $recipient . ' because of ' . $reason);

            switch($reason){
                case 'ManualSuppression':
                    $this->transportCallback->addFailureByAddress($recipient, 'unsubscribed', DoNotContact::UNSUBSCRIBED);
                    break;
                case 'HardBounce':
                    $this->transportCallback->addFailureByAddress($recipient, 'hard_bounce');
                    break;
                case 'SpamComplaint':
                    $this->transportCallback->addFailureByAddress($recipient, 'spam_complaint', DoNotContact::UNSUBSCRIBED);
                    break;
                default:
                    break;
            }
        }

        $event->setResponse(new Response('Postmark Callback processed'));
    }


}
