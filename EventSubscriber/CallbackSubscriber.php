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

class CallbackSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private TransportCallback $transportCallback,
        private CoreParametersHelper $coreParametersHelper
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

        $payload = $event->getRequest()->request->all();

        foreach ($payload as $postmarkPayload){
            $messageType = $postmarkPayload['RecordType'] ?? null;

            if ($messageType !== "SubscriptionChange") {
                continue;
            }

            $reason = $postmarkPayload['SuppressionReason'];
            $suppressSending = filter_var($postmarkPayload['SuppressSending'], FILTER_VALIDATE_BOOLEAN);
            

            $recipient = $postmarkPayload['Recipient'] ?? null;

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
            $this->transportCallback->processCallbackByEmailAddress($hashId, $rawReason);
        }

        $event->setResponse(new Response('Callback processed'));
    }


}
