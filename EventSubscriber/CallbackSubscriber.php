<?php

declare(strict_types=1);

namespace MauticPlugin\PostmarkBundle\EventSubscriber;

use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Mautic\EmailBundle\EmailEvents;
use Mautic\EmailBundle\Event\TransportWebhookEvent;
use Mautic\EmailBundle\Model\TransportCallback;
use Mautic\EmailBundle\MonitoredEmail\Search\ContactFinder;
use Mautic\EmailBundle\Entity\Stat;
use Mautic\LeadBundle\Entity\DoNotContact as DNC;
use Mautic\LeadBundle\Model\DoNotContact;
use MauticPlugin\PostmarkBundle\Mailer\Transport\PostmarkTransport;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\Transport\Dsn;
use Psr\Log\LoggerInterface;
use Doctrine\ORM\EntityManagerInterface;

class CallbackSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private TransportCallback $transportCallback,
        private CoreParametersHelper $coreParametersHelper,
        private LoggerInterface $logger,
        private ContactFinder $finder,
        private DoNotContact $dncModel,
        private EntityManagerInterface $entityManager
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

        // Log additional context for debugging
        $this->logger->info('Webhook context', [
            'messageId' => $messageId,
            'tag' => $tag,
            'messageStream' => $messageStream,
            'metadata' => $metadata
        ]);

        // Try to find specific email stat by MessageID for more detailed tracking
        $emailStat = null;
        if ($messageId) {
            $emailStat = $this->findEmailStatByMessageId($messageId, $recipient);
        }

        switch($reason) {
            case 'ManualSuppression':
                $this->transportCallback->addFailureByAddress($recipient, 'unsubscribed', DNC::UNSUBSCRIBED);
                if ($emailStat) {
                    $this->addBounceToEmailStat($emailStat, 'unsubscribed', $reason);
                }
                break;
            case 'HardBounce':
                $this->transportCallback->addFailureByAddress($recipient, 'hard_bounce');
                if ($emailStat) {
                    $this->addBounceToEmailStat($emailStat, 'hard_bounce', $reason);
                }
                break;
            case 'SpamComplaint':
                $this->transportCallback->addFailureByAddress($recipient, 'spam_complaint', DNC::UNSUBSCRIBED);
                if ($emailStat) {
                    $this->addBounceToEmailStat($emailStat, 'spam_complaint', $reason);
                }
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

    /**
     * Find email stat by Postmark MessageID and recipient email
     */
    private function findEmailStatByMessageId(string $messageId, string $recipient): ?Stat
    {
        try {
            $repository = $this->entityManager->getRepository(Stat::class);
            
            // Try to find by MessageID (stored as tracking hash or in email details)
            $stat = $repository->createQueryBuilder('s')
                ->where('s.trackingHash = :messageId')
                ->orWhere('s.emailAddress = :recipient AND s.openDetails LIKE :messageIdPattern')
                ->setParameter('messageId', $messageId)
                ->setParameter('recipient', $recipient)
                ->setParameter('messageIdPattern', '%' . $messageId . '%')
                ->orderBy('s.dateSent', 'DESC')
                ->setMaxResults(1)
                ->getQuery()
                ->getOneOrNullResult();

            if ($stat) {
                $this->logger->info('Found email stat for MessageID', [
                    'messageId' => $messageId,
                    'recipient' => $recipient,
                    'statId' => $stat->getId()
                ]);
            } else {
                $this->logger->warning('No email stat found for MessageID', [
                    'messageId' => $messageId,
                    'recipient' => $recipient
                ]);
            }

            return $stat;
        } catch (\Exception $e) {
            $this->logger->error('Error finding email stat by MessageID', [
                'messageId' => $messageId,
                'recipient' => $recipient,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Add bounce information to specific email stat
     */
    private function addBounceToEmailStat(Stat $emailStat, string $bounceType, string $reason): void
    {
        try {
            $openDetails = $emailStat->getOpenDetails() ?: [];
            
            // Add bounce information
            if (!isset($openDetails['bounces'])) {
                $openDetails['bounces'] = [];
            }

            $bounceData = [
                'datetime' => (new \DateTime())->format('Y-m-d H:i:s'),
                'type' => $bounceType,
                'reason' => $reason,
                'source' => 'postmark_webhook'
            ];

            $openDetails['bounces'][] = $bounceData;
            
            // Update the email stat
            $emailStat->setOpenDetails($openDetails);
            $this->entityManager->persist($emailStat);
            $this->entityManager->flush();

            $this->logger->info('Added bounce to email stat', [
                'statId' => $emailStat->getId(),
                'bounceType' => $bounceType,
                'reason' => $reason
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Error adding bounce to email stat', [
                'statId' => $emailStat->getId(),
                'bounceType' => $bounceType,
                'error' => $e->getMessage()
            ]);
        }
    }
}
