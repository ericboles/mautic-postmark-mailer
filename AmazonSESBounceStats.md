
# How Mautic Email Plugins Track Bounces/Unsubscribes and Update Campaign Statistics

This document explains how the Amazon SES plugin handles bounce/unsubscribe webhooks and updates both contact DNC status and campaign statistics. This flow should be replicated in other email transport plugins like Postmark.

## Overview

The system maintains two separate but related tracking mechanisms:
1. **Campaign Statistics** - Track bounces/opens/clicks per campaign via the `email_stats` table
2. **Contact DNC (Do Not Contact)** - Prevent future sends to contacts who bounced/unsubscribed

## 1. Email Sending Phase - Tracking Hash Creation

When an email is sent through any transport plugin:

- Each email send creates a `Stat` record in the `email_stats` table
- Key fields in the `Stat` entity:
  ```php
  private $trackingHash;    // Unique identifier for this specific send
  private $email;           // The Mautic Email entity (template/broadcast)
  private $lead;            // The contact who received it
  private $source;          // Source type (e.g., "campaign.event")
  private $sourceId;        // Specific campaign event ID
  private $isFailed;        // Bounce status (initially false)
  private $emailAddress;    // Recipient email address
  private $openDetails;     // Array storing bounce/open details
Critical Link: The source and sourceId fields connect email stats to campaigns:
source = "campaign.event" for campaign emails
sourceId = campaign_events.id (which links to campaigns table)
2. Webhook Reception and Processing
Amazon SES Implementation
File: /plugins/AmazonSesBundle/EventSubscriber/CallbackSubscriber.php

public function processCallbackRequest(TransportWebhookEvent $event): void
{
    $dsn = Dsn::fromString($this->coreParametersHelper->get('mailer_dsn'));
    
    // Only process if this transport is active
    if (AmazonSesTransport::MAUTIC_AMAZONSES_API_SCHEME !== $dsn->getScheme()) {
        return;
    }
    
    $payload = json_decode($event->getRequest()->getContent(), true);
    $this->processJsonPayload($payload, $payload['Type']);
}

private function processJsonPayload(array $payload, $type): array
{
    switch ($type) {
        case 'Bounce':
            if ('Permanent' == $payload['bounce']['bounceType']) {
                $emailId = $this->getEmailHeader($payload);  // Gets X-EMAIL-ID header
                $bouncedRecipients = $payload['bounce']['bouncedRecipients'];
                
                foreach ($bouncedRecipients as $bouncedRecipient) {
                    $this->transportCallback->addFailureByAddress(
                        $this->cleanupEmailAddress($bouncedRecipient['emailAddress']), 
                        $bounceCode, 
                        DoNotContact::BOUNCED, 
                        $emailId
                    );
                }
            }
            break;
            
        case 'Complaint':
            $emailId = $this->getEmailHeader($payload);
            $complaintRecipients = $payload['complaint']['complainedRecipients'];
            
            foreach ($complaintRecipients as $complaintRecipient) {
                $this->transportCallback->addFailureByAddress(
                    $this->cleanupEmailAddress($complaintRecipient['emailAddress']), 
                    $complianceCode, 
                    DoNotContact::UNSUBSCRIBED, 
                    $emailId
                );
            }
            break;
    }
}

private function getEmailHeader($payload)
{
    if (!isset($payload['mail']['headers'])) {
        return null;
    }
    
    foreach ($payload['mail']['headers'] as $header) {
        if ('X-EMAIL-ID' === strtoupper($header['name'])) {
            return $header['value'];
        }
    }
}
3. TransportCallback Processing
File: /app/bundles/EmailBundle/Model/TransportCallback.php

The TransportCallback class handles the core logic for processing bounces/complaints:

A. Find the Contact and Stat Record
public function addFailureByAddress($address, $comments, $dncReason = DNC::BOUNCED, $channelId = null): void
{
    $result = $this->finder->findByAddress($address);
    
    if ($contacts = $result->getContacts()) {
        foreach ($contacts as $contact) {
            $channel = ($channelId) ? ['email' => $channelId] : 'email';
            $this->dncModel->addDncForContact($contact->getId(), $channel, $dncReason, $comments);
        }
    }
}

public function addFailureByHashId($hashId, $comments, $dncReason = DNC::BOUNCED): void
{
    $result = $this->finder->findByHash($hashId);
    
    if ($contacts = $result->getContacts()) {
        $stat = $result->getStat();
        $this->updateStatDetails($stat, $comments, $dncReason);  // ← Updates campaign stats
        
        $email = $stat->getEmail();
        $channel = ($email) ? ['email' => $email->getId()] : 'email';
        
        foreach ($contacts as $contact) {
            $this->dncModel->addDncForContact($contact->getId(), $channel, $dncReason, $comments);
        }
    }
}
B. Update Stat Record (Campaign Statistics)
private function updateStatDetails(Stat $stat, $comments, $dncReason): void
{
    if (DNC::BOUNCED === $dncReason) {
        $stat->setIsFailed(true);  // ← This updates campaign bounce count!
    }
    
    // Store detailed bounce information
    $openDetails = $stat->getOpenDetails();
    if (!isset($openDetails['bounces'])) {
        $openDetails['bounces'] = [];
    }
    
    $dtHelper = new DateTimeHelper();
    $openDetails['bounces'][] = [
        'datetime' => $dtHelper->toUtcString(),
        'reason'   => $comments,
    ];
    
    $stat->setOpenDetails($openDetails);
    $this->emailStatModel->saveEntity($stat);
}
C. Add Contact to DNC
// In DoNotContact model
public function addDncForContact($contactId, $channel, $reason, $comments): void
{
    // Prevents future emails to this contact
    // Creates record in lead_donotcontact table
}
4. ContactFinder Logic
File: /app/bundles/EmailBundle/MonitoredEmail/Search/ContactFinder.php

public function findByHash($hash): Result
{
    $result = new Result();
    
    /** @var Stat $stat */
    $stat = $this->statRepository->findOneBy(['trackingHash' => $hash]);
    
    if ($stat && $stat->getLead()) {
        $result->setStat($stat);  // ← This connects to campaign stats
    }
    
    return $result;
}

public function findByAddress($address): Result
{
    $result = new Result();
    
    if ($contacts = $this->leadRepository->getContactsByEmail($address)) {
        $result->setContacts($contacts);  // ← This enables DNC updates
    }
    
    return $result;
}
5. Campaign Statistics Calculation
File: /app/bundles/EmailBundle/Entity/StatRepository.php

Campaign statistics are calculated by querying the email_stats table:

public function getFailedCount($emailIds = null, $listId = null, ChartQuery $chartQuery = null, $combined = false)
{
    return $this->getStatusCount('is_failed', $emailIds, $listId, $chartQuery, $combined);
}

public function getStatusCount($column, $emailIds = null, $listId = null, ChartQuery $chartQuery = null, $combined = false)
{
    $q = $this->_em->getConnection()->createQueryBuilder();
    
    $q->select('count(s.id) as count')
      ->from(MAUTIC_TABLE_PREFIX.'email_stats', 's');
      
    // Join to campaigns when needed
    $q->leftJoin('s', MAUTIC_TABLE_PREFIX.'campaign_events', 'ce', 's.source = "campaign.event" and s.source_id = ce.id')
      ->leftJoin('ce', MAUTIC_TABLE_PREFIX.'campaigns', 'campaign', 'ce.campaign_id = campaign.id');
      
    $q->andWhere("s.$column = 1");  // e.g., is_failed = 1 for bounces
    
    if ($campaignId) {
        $q->andWhere('ce.campaign_id = :campaignId')
          ->setParameter('campaignId', $campaignId);
    }
    
    return $q->execute()->fetchColumn();
}
6. Database Schema
email_stats table
CREATE TABLE email_stats (
    id INT PRIMARY KEY,
    email_id INT,           -- Links to emails table (template/broadcast)
    lead_id INT,            -- Links to leads table (contact)
    email_address VARCHAR,  -- Recipient email
    list_id INT,           -- Segment ID if sent to segment
    tracking_hash VARCHAR,  -- Unique hash for this send
    source VARCHAR,         -- "campaign.event" for campaigns
    source_id INT,         -- campaign_events.id
    date_sent DATETIME,
    is_read BOOLEAN,       -- Opened
    is_failed BOOLEAN,     -- Bounced
    date_read DATETIME,    -- When opened
    retry_count INT,
    open_count INT,
    open_details JSON      -- Stores bounce details, open times, etc.
);
Key Relationships
-- Campaign bounce count
SELECT COUNT(*) FROM email_stats s
JOIN campaign_events ce ON (s.source = 'campaign.event' AND s.source_id = ce.id)
WHERE ce.campaign_id = ? AND s.is_failed = 1;

-- Contact DNC status
SELECT * FROM lead_donotcontact 
WHERE lead_id = ? AND channel = 'email' AND reason = 1; -- 1 = BOUNCED
7. Implementation Flow for Other Plugins
To implement similar functionality in the Postmark plugin:

Step 1: Create Event Subscriber
class CallbackSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private TransportCallback $transportCallback,
        private CoreParametersHelper $coreParametersHelper,
        private LoggerInterface $logger
    ) {}
    
    public static function getSubscribedEvents(): array
    {
        return [
            EmailEvents::ON_TRANSPORT_WEBHOOK => ['processCallbackRequest', 0],
        ];
    }
    
    public function processCallbackRequest(TransportWebhookEvent $event): void
    {
        $dsn = Dsn::fromString($this->coreParametersHelper->get('mailer_dsn'));
        
        // Only process if Postmark is active
        if ('mautic+postmark+api' !== $dsn->getScheme()) {
            return;
        }
        
        $payload = json_decode($event->getRequest()->getContent(), true);
        $this->processPostmarkWebhook($payload);
    }
    
    private function processPostmarkWebhook(array $payload): void
    {
        switch ($payload['RecordType']) {
            case 'Bounce':
                if ('HardBounce' === $payload['Type']) {
                    $this->transportCallback->addFailureByAddress(
                        $payload['Email'],
                        $payload['Description'] ?? 'Hard bounce',
                        DoNotContact::BOUNCED,
                        $this->extractEmailId($payload)
                    );
                }
                break;
                
            case 'SpamComplaint':
                $this->transportCallback->addFailureByAddress(
                    $payload['Email'],
                    'Spam complaint',
                    DoNotContact::UNSUBSCRIBED,
                    $this->extractEmailId($payload)
                );
                break;
        }
    }
    
    private function extractEmailId(array $payload): ?int
    {
        // Extract from Postmark's Metadata or Tag fields
        return $payload['Metadata']['email_id'] ?? null;
    }
}
Step 2: Register the Subscriber
In your plugin's Config/config.php:

'services' => [
    'events' => [
        'mautic.postmark.callback_subscriber' => [
            'class' => 'MauticPlugin\\PostmarkBundle\\EventSubscriber\\CallbackSubscriber',
            'arguments' => [
                'mautic.email.transport_callback',
                'mautic.helper.core_parameters',
                'monolog.logger.mautic'
            ]
        ]
    ]
]
Step 3: Ensure Email ID is Included in Sends
In your transport class, make sure to include the email ID in headers/metadata:

// In PostmarkTransport.php
private function getPayload(Email $email, Envelope $envelope): array
{
    $payload = [
        'From' => $envelope->getSender()->toString(),
        'To' => implode(',', $this->stringifyAddresses($this->getRecipients($email, $envelope))),
        'Subject' => $email->getSubject(),
        'HtmlBody' => $email->getHtmlBody(),
    ];
    
    // Add email ID for webhook tracking
    if ($email instanceof MauticMessage) {
        $metadata = $email->getMetadata();
        if (!empty($metadata)) {
            foreach ($metadata as $recipient => $mailData) {
                if (isset($mailData['emailId'])) {
                    $payload['Metadata'] = ['email_id' => $mailData['emailId']];
                    break;
                }
            }
        }
    }
    
    return $payload;
}
8. Complete Data Flow Summary
1. Email Send
   ├─→ Create Stat record with source="campaign.event", source_id=campaign_event.id
   └─→ Include email_id in transport headers/metadata

2. Webhook Received
   ├─→ Check if transport is active (DSN scheme)
   ├─→ Parse webhook payload
   └─→ Extract email address + email_id

3. TransportCallback.addFailureByAddress()
   ├─→ Find contacts by email address
   ├─→ Find Stat record by tracking hash (if available)
   ├─→ Update Stat.is_failed = true (for campaign stats)
   ├─→ Store bounce details in Stat.open_details
   └─→ Add contact to DNC table

4. Campaign Statistics
   └─→ Query email_stats WHERE is_failed=1 AND source_id=campaign_event.id
Key Points for Implementation
Two Separate Systems: Campaign stats (via email_stats) and contact DNC are updated independently
Critical Fields: source and source_id in email_stats link emails to campaigns
TransportCallback: Use the existing TransportCallback service - don't reimplement the logic
Email ID Tracking: Include email ID in transport metadata so webhooks can link back to campaigns
DSN Check: Only process webhooks when your transport is the active one
Error Handling: Log webhook processing errors but don't fail the HTTP response
This system ensures that both campaign managers see accurate bounce statistics and contacts are properly protected from future sends.