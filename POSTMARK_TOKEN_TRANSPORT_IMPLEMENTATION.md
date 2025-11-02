# Implementing TokenTransportInterface for Postmark

## Overview

This document explains how to enable batch email sending for Postmark in Mautic by implementing the `TokenTransportInterface`. This is **essential** for proper functioning with the `MultipleTransportBundle` when Postmark is used as a secondary transport.

---

## The Problem

### Current Issue
When Postmark is configured as a **secondary transport** (not the default), bulk emails only send to **one recipient** instead of the full list.

### Root Cause
1. `MailHelper` checks if the **default transport** supports tokenization during initialization
2. If default is SES (which supports `TokenTransportInterface`), tokenization is enabled
3. `MultipleTransportBundle` then routes the email to Postmark via `X-Custom-Transport-Id` header
4. Postmark **doesn't implement** `TokenTransportInterface`, so it can't process the batch metadata correctly
5. Result: Only one email is delivered

### Why It Matters
- **With Postmark as default**: Works fine (sends one-by-one)
- **With Postmark as secondary**: Breaks (only sends one email from batch)

---

## The Solution

Implement `TokenTransportInterface` in `PostmarkTransport` to enable proper batch sending using Postmark's Batch Email API.

---

## Postmark Batch Email API

### API Endpoint
```
POST https://api.postmarkapp.com/email/batch
```

### Capabilities
- Send up to **500 emails** per API call
- Each email can have different recipients, content, attachments, etc.
- Significantly faster than individual API calls
- Same features as single email endpoint

### Request Format
```json
[
  {
    "From": "sender@example.com",
    "To": "recipient1@example.com",
    "Subject": "Email Subject 1",
    "HtmlBody": "<p>Content 1</p>",
    "TextBody": "Content 1",
    "MessageStream": "outbound"
  },
  {
    "From": "sender@example.com",
    "To": "recipient2@example.com",
    "Subject": "Email Subject 2",
    "HtmlBody": "<p>Content 2</p>",
    "TextBody": "Content 2",
    "MessageStream": "outbound"
  }
]
```

### Response Format
```json
[
  {
    "To": "recipient1@example.com",
    "SubmittedAt": "2025-11-01T12:00:00Z",
    "MessageID": "xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx",
    "ErrorCode": 0,
    "Message": "OK"
  },
  {
    "To": "recipient2@example.com",
    "SubmittedAt": "2025-11-01T12:00:00Z",
    "MessageID": "xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx",
    "ErrorCode": 0,
    "Message": "OK"
  }
]
```

---

## Implementation Steps

### Step 1: Understand TokenTransportInterface

**Location**: `/app/bundles/EmailBundle/Mailer/Transport/TokenTransportInterface.php`

```php
interface TokenTransportInterface
{
    /**
     * Return the max number of to addresses allowed per batch.
     * If there is no limit, return 0.
     */
    public function getMaxBatchLimit(): int;

    /**
     * Get the count for the max number of recipients per batch.
     *
     * @param int    $toBeAdded Number of emails about to be added
     * @param string $type      Type of emails being added (to, cc, bcc)
     */
    public function getBatchRecipientCount(Email $message, int $toBeAdded = 1, string $type = 'to'): int;
}
```

**Purpose**:
- `getMaxBatchLimit()`: Tell Mautic the maximum batch size (500 for Postmark)
- `getBatchRecipientCount()`: Count recipients to prevent exceeding batch limit

### Step 2: Use TokenTransportTrait

**Location**: `/app/bundles/EmailBundle/Mailer/Transport/TokenTransportTrait.php`

```php
trait TokenTransportTrait
{
    public function getBatchRecipientCount(Email $message, int $toBeAdded = 1, string $type = 'to'): int
    {
        return count($message->getTo()) + count($message->getCc()) + count($message->getBcc()) + $toBeAdded;
    }
}
```

This trait provides a default implementation of `getBatchRecipientCount()`.

---

## Code Changes Required

### 1. Update PostmarkTransport.php Header

**File**: `/plugins/PostmarkBundle/Mailer/Transport/PostmarkTransport.php`

**Current** (lines 5-6):
```php
// use Mautic\EmailBundle\Mailer\Transport\TokenTransportInterface;
// use Mautic\EmailBundle\Mailer\Transport\TokenTransportTrait;
```

**Change to**:
```php
use Mautic\EmailBundle\Mailer\Transport\TokenTransportInterface;
use Mautic\EmailBundle\Mailer\Transport\TokenTransportTrait;
```

### 2. Update Class Declaration

**Current** (line 37):
```php
class PostmarkTransport extends AbstractTransport
```

**Change to**:
```php
class PostmarkTransport extends AbstractTransport implements TokenTransportInterface
{
    use TokenTransportTrait;
```

### 3. Add getMaxBatchLimit() Method

Add this method to the `PostmarkTransport` class:

```php
public function getMaxBatchLimit(): int
{
    // Postmark supports up to 500 emails per batch API call
    return 500;
}
```

**Placement**: Add after the `__toString()` method, around line 80.

### 4. Modify doSend() for Batch Support

**Current Behavior**: `doSend()` sends one email at a time

**New Behavior**: Check if message contains metadata (batch mode) and use batch API

#### Add Property for Batch Collection
```php
private array $batchMessages = [];
```

#### Update doSend() Method

**Current** `doSend()` sends immediately. **New version** should:

1. Check if message has metadata (indicates batch mode)
2. If yes, collect messages instead of sending
3. Implement a separate `sendBatch()` method to send all collected messages

**Pseudo-code**:
```php
protected function doSend(SentMessage $message): void
{
    $envelope = $message->getEnvelope();
    $email = MessageConverter::toEmail($message->getOriginalMessage());
    
    // Check if this is a batch send (metadata indicates multiple recipients)
    if ($email instanceof MauticMessage && !empty($email->getMetadata())) {
        // Batch mode: collect messages
        $this->collectBatchMessage($email, $envelope);
        return;
    }
    
    // Single send mode: send immediately (existing code)
    $this->sendSingleEmail($email, $envelope);
}
```

### 5. Implement Batch Collection

```php
private function collectBatchMessage(Email $email, Envelope $envelope): void
{
    // Get metadata for all recipients in this batch
    $metadata = $email->getMetadata();
    
    foreach ($metadata as $recipientEmail => $recipientData) {
        // Build individual email payload for each recipient
        $this->batchMessages[] = $this->buildEmailPayload(
            $email, 
            $envelope, 
            $recipientEmail, 
            $recipientData
        );
    }
}
```

### 6. Implement Batch Sending

```php
private function buildEmailPayload(Email $email, Envelope $envelope, string $recipientEmail, array $metadata): array
{
    // Create individual payload for one recipient
    $payload = [
        'From' => $envelope->getSender()->toString(),
        'To' => $recipientEmail,
        'Subject' => $email->getSubject(),
        'TextBody' => $email->getTextBody(),
        'HtmlBody' => $email->getHtmlBody(),
    ];
    
    // Add optional fields
    if (!empty($email->getCc())) {
        $payload['Cc'] = implode(',', $this->stringifyAddresses($email->getCc()));
    }
    
    if (!empty($email->getBcc())) {
        $payload['Bcc'] = implode(',', $this->stringifyAddresses($email->getBcc()));
    }
    
    if (!empty($email->getReplyTo())) {
        $payload['ReplyTo'] = implode(',', $this->stringifyAddresses($email->getReplyTo()));
    }
    
    // Add message stream if configured
    if (null !== $this->messageStream) {
        $payload['MessageStream'] = $this->messageStream;
    }
    
    // Add headers
    $payload['Headers'] = $this->buildHeaders($email);
    
    // Add attachments if any
    if (!empty($email->getAttachments())) {
        $payload['Attachments'] = $this->getAttachments($email);
    }
    
    // Add Mautic tracking metadata
    if (!empty($metadata)) {
        $payload['Metadata'] = $this->buildMetadata($metadata);
    }
    
    return $payload;
}

public function sendBatch(): void
{
    if (empty($this->batchMessages)) {
        return;
    }
    
    try {
        $response = $this->client->request('POST', 'https://'.$this->getEndpoint().'/email/batch', [
            'headers' => [
                'Accept' => 'application/json',
                'X-Postmark-Server-Token' => $this->apiKey,
            ],
            'json' => $this->batchMessages,
        ]);
        
        $statusCode = $response->getStatusCode();
        $results = $response->toArray(false);
        
        if (200 !== $statusCode) {
            throw new HttpTransportException('Batch send failed: '.$response->getContent(false), $response);
        }
        
        // Process results for each email
        foreach ($results as $result) {
            if (0 !== $result['ErrorCode']) {
                // Handle individual email failures
                $this->handleBatchFailure($result);
            }
        }
        
    } catch (TransportExceptionInterface $e) {
        throw new HttpTransportException('Could not reach Postmark server for batch send.', null, 0, $e);
    } finally {
        // Clear batch messages
        $this->batchMessages = [];
    }
}

private function handleBatchFailure(array $result): void
{
    // Handle suppressed recipients (on Postmark suppression list)
    if (self::CODE_INACTIVE_RECIPIENT === $result['ErrorCode']) {
        $recipientEmail = $result['To'] ?? 'unknown';
        
        // Re-sync to Mautic DNC
        $this->callback->addFailureByAddress(
            $recipientEmail,
            'Postmark Suppression: ' . ($result['Message'] ?? 'Recipient on suppression list'),
            DoNotContact::BOUNCED
        );
        
        $this->logger->warning('Batch email suppressed by Postmark', [
            'recipient' => $recipientEmail,
            'error' => $result['Message'] ?? 'Unknown',
        ]);
    } else {
        // Log other errors
        $this->logger->error('Postmark batch email failed', [
            'recipient' => $result['To'] ?? 'unknown',
            'error_code' => $result['ErrorCode'],
            'message' => $result['Message'] ?? 'Unknown error',
        ]);
    }
}
```

---

## How Mautic Uses Token Transport

### 1. Email Preparation Phase
```php
// In SendEmailToContact or EmailModel
$mailer->enableQueue(); // Enable batch mode
$mailer->setEmail($email);

foreach ($contacts as $contact) {
    $mailer->setLead($contact);
    $mailer->addTo($contact['email']);
    $mailer->queue(); // Queues without sending
}
```

### 2. Queue Building (MailHelper.php:440-467)
When `tokenizationEnabled = true`:
```php
public function queue($dispatchSendEvent = false, $returnMode = self::QUEUE_RESET_TO)
{
    if ($this->tokenizationEnabled) {
        // Build metadata for each recipient
        foreach ($this->queuedRecipients as $email => $name) {
            $tokens = $this->getTokens();
            $this->metadata[$fromAddress]['contacts'][$email] = $this->buildMetadata($name, $tokens);
        }
        return true; // Don't send yet
    }
    // ... non-batch mode sends immediately
}
```

### 3. Batch Flush (MailHelper.php:512-588)
```php
public function flushQueue($resetEmailTypes = ['To', 'Cc', 'Bcc'])
{
    if ($this->tokenizationEnabled && count($this->metadata)) {
        foreach ($this->metadata as $metadatum) {
            // Set metadata on message
            foreach ($metadatum['contacts'] as $email => $contact) {
                $this->message->addMetadata($email, $contact);
                $this->message->to(new Address($email, $contact['name'] ?? ''));
            }
            
            // Send batch (calls doSend() on transport)
            $this->send(false, true);
            
            // Clear for next batch
            $this->message->clearMetadata();
        }
    }
}
```

### 4. Actual Sending
```php
// MailHelper.php:395
$this->mailer->send($this->message); // Symfony MailerInterface

// Goes through:
// → Symfony Mailer
// → Messenger (creates SendEmailMessage)
// → SendEmailMessageHandler (MultipleTransportBundle)
// → Routes to correct transport via X-Custom-Transport-Id
// → PostmarkTransport.doSend()
```

---

## Integration Pattern

### Check How SES Does It

**AmazonSesTransport** provides a good reference:

```php
class AmazonSesTransport extends AbstractTransport implements TokenTransportInterface
{
    use TokenTransportTrait;
    
    public function getMaxBatchLimit(): int
    {
        return (int) ($this->settings['maxSendRate'] ?? 14);    
    }
    
    protected function doSend(SentMessage $message): void
    {
        // Check if message is MauticMessage with metadata
        if ($email instanceof MauticMessage && !empty($email->getMetadata())) {
            // Batch mode
            $this->sendBatchEmails($email);
        } else {
            // Single mode
            $this->sendSingleEmail($email);
        }
    }
}
```

---

## Testing Strategy

### 1. Unit Tests
Create `PostmarkTransportTest.php` similar to `AmazonSesTransportTest.php`:

```php
public function testImplementsTokenTransportInterface(): void
{
    $transport = $this->createTransport();
    $this->assertInstanceOf(TokenTransportInterface::class, $transport);
}

public function testGetMaxBatchLimit(): void
{
    $transport = $this->createTransport();
    $this->assertEquals(500, $transport->getMaxBatchLimit());
}

public function testBatchRecipientCount(): void
{
    $transport = $this->createTransport();
    $message = new Email();
    $message->to('test1@example.com');
    $message->cc('test2@example.com');
    
    $count = $transport->getBatchRecipientCount($message, 1);
    $this->assertEquals(3, $count); // 1 to + 1 cc + 1 to-be-added
}
```

### 2. Integration Tests

**Test Scenario 1**: Postmark as Default Transport
```php
// Should work (already does)
$email = createSegmentEmail();
$model->sendEmail($email, $contacts); // All emails sent ✓
```

**Test Scenario 2**: Postmark as Secondary Transport
```php
// Configure SES as default, Postmark for specific email
$email = createSegmentEmail();
$email->setTransport($postmarkTransport); // Via MultipleTransportBundle

$model->sendEmail($email, $contacts); // Should send all emails ✓
```

**Test Scenario 3**: Batch Limit Enforcement
```php
// Send 501 emails (exceeds 500 limit)
$contacts = createContacts(501);
$model->sendEmail($email, $contacts);

// Should make 2 API calls: 500 + 1
assertPostmarkApiCallCount(2);
```

### 3. Manual Testing

1. Configure Postmark as secondary transport
2. Create segment with 100 contacts
3. Create segment email assigned to Postmark transport
4. Send email via UI
5. **Verify**: All 100 contacts receive email (not just 1)

---

## Benefits

### Performance
- **Before**: 100 API calls for 100 emails (1 per recipient)
- **After**: 1 API call for 100 emails (or 2 calls for 501 emails)
- **Improvement**: ~99% reduction in API calls

### Reliability
- Fixes bulk email bug with MultipleTransportBundle
- Consistent behavior regardless of default transport
- Proper integration with Mautic's batching system

### Scalability
- Can send to 50,000 contacts with 100 API calls (vs 50,000 calls)
- Reduces API rate limit concerns
- Faster overall send time

---

## Potential Challenges

### 1. Token Replacement in Batch Mode
**Issue**: Each recipient needs personalized content (tokens like `{firstname}`)

**Solution**: The metadata system handles this:
```php
$metadata[$email] = [
    'name' => $contact['name'],
    'tokens' => [
        '{firstname}' => $contact['firstname'],
        '{lastname}' => $contact['lastname'],
        // ... other tokens
    ],
    'leadId' => $contact['id'],
];
```

Mautic replaces tokens before building the batch payload.

### 2. Attachments
**Issue**: Batch emails with attachments increase payload size

**Solution**: 
- Postmark supports attachments in batch mode
- Monitor payload size (stay under API limits)
- Consider sending attachment emails one-by-one if needed:
```php
public function getMaxBatchLimit(): int
{
    // Check if current email has attachments
    if ($this->hasAttachments) {
        return 1; // Force one-by-one for emails with attachments
    }
    return 500;
}
```

### 3. Error Handling
**Issue**: One failed email shouldn't block entire batch

**Solution**: Postmark's batch API returns individual results:
```php
foreach ($results as $result) {
    if (0 !== $result['ErrorCode']) {
        // Handle this specific email failure
        // Don't throw exception (let others succeed)
    }
}
```

### 4. Message ID Tracking
**Issue**: Need to store MessageID for each sent email

**Solution**: Postmark batch response includes MessageID for each email:
```php
foreach ($results as $index => $result) {
    if (0 === $result['ErrorCode']) {
        // Store MessageID for bounce tracking
        $recipientEmail = $this->batchMessages[$index]['To'];
        // Associate $result['MessageID'] with $recipientEmail
    }
}
```

---

## Alternative: Phased Implementation

If full batch support is complex, consider a phased approach:

### Phase 1: Basic Token Support (Minimal Change)
```php
class PostmarkTransport extends AbstractTransport implements TokenTransportInterface
{
    use TokenTransportTrait;
    
    public function getMaxBatchLimit(): int
    {
        return 1; // Disable batching but signal tokenization support
    }
}
```

**Result**: Fixes MultipleTransportBundle bug by preventing tokenization mismatch, but still sends one-by-one.

### Phase 2: Limited Batching
```php
public function getMaxBatchLimit(): int
{
    return 50; // Conservative batch size for testing
}
```

### Phase 3: Full Batching
```php
public function getMaxBatchLimit(): int
{
    return 500; // Postmark's actual limit
}
```

---

## Configuration Considerations

### Allow Batch Size Configuration

Add to `PostmarkTransportFactory`:
```php
public function create(Dsn $dsn): TransportInterface
{
    $batchSize = (int) $dsn->getOption('batch_size', 500);
    
    return new PostmarkTransport(
        $apiKey,
        $messageStream,
        $callback,
        $client,
        $dispatcher,
        $logger,
        $batchSize // Pass to constructor
    );
}
```

Usage in DSN:
```
mautic+postmark+api://SERVER_TOKEN@default?messageStream=outbound&batch_size=100
```

---

## Summary

### Files to Modify
1. **PostmarkTransport.php**: Main implementation
   - Uncomment TokenTransportInterface imports
   - Add `implements TokenTransportInterface` + `use TokenTransportTrait`
   - Add `getMaxBatchLimit()` method
   - Update `doSend()` to support batch mode
   - Add batch collection and sending methods

### Minimum Viable Change
```php
// Uncomment lines 5-6
use Mautic\EmailBundle\Mailer\Transport\TokenTransportInterface;
use Mautic\EmailBundle\Mailer\Transport\TokenTransportTrait;

// Update line 37
class PostmarkTransport extends AbstractTransport implements TokenTransportInterface
{
    use TokenTransportTrait;
    
    // Add after __toString()
    public function getMaxBatchLimit(): int
    {
        return 500;
    }
    
    // Then update doSend() to handle metadata...
}
```

### Expected Outcome
- ✅ Postmark works correctly as secondary transport
- ✅ Bulk emails send to all recipients (not just one)
- ✅ Better performance (fewer API calls)
- ✅ Consistent behavior across all transport configurations

---

## References

- **Postmark Batch Email API**: https://postmarkapp.com/developer/api/email-api#batch-emails
- **Mautic TokenTransportInterface**: `/app/bundles/EmailBundle/Mailer/Transport/TokenTransportInterface.php`
- **Amazon SES Implementation**: `/plugins/AmazonSesBundle/Mailer/Transport/AmazonSesTransport.php`
- **MailHelper Queue Logic**: `/app/bundles/EmailBundle/Helper/MailHelper.php` (lines 440-588)
- **MultipleTransportBundle Handler**: `/plugins/MauticMultipleTransportBundle/Messenger/Handler/SendEmailMessageHandler.php`

---

**Last Updated**: November 1, 2025
