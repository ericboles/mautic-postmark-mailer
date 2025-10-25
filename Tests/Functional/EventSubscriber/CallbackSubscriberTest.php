<?php

declare(strict_types=1);

namespace MauticPlugin\PostmarkBundle\Tests\Functional\EventSubscriber;

use Mautic\CoreBundle\Helper\DateTimeHelper;
use Mautic\CoreBundle\Test\MauticMysqlTestCase;
use Mautic\EmailBundle\Entity\Stat;
use Mautic\LeadBundle\Entity\DoNotContact;
use Mautic\LeadBundle\Entity\Lead;
use PHPUnit\Framework\Assert;
use Symfony\Component\HttpFoundation\Request;

class CallbackSubscriberTest extends MauticMysqlTestCase
{
    protected function setUp(): void
    {
        if ('testPostmarkTransportNotConfigured' !== $this->getName()) {
            $this->configParams['mailer_dsn'] = 'mautic+postmark+api://:some_api@some_host:25?messageStream=my_broadcast';
        }

        parent::setUp();
    }

    public function testPostmarkTransportNotConfigured(): void
    {
        $this->client->request(Request::METHOD_POST, '/mailer/callback');
        $response = $this->client->getResponse();
        Assert::assertSame('No email transport that could process this callback was found', $response->getContent());
        Assert::assertSame(404, $response->getStatusCode());
    }

    /**
     * @dataProvider provideMessageEventType
     */
    public function testPostmarkCallbackProcessByAddress(string $bounceType): void
    {
        $parameters = $this->getParameters($bounceType);

        $contact      = $this->createContact('bounced-address@wildbit.com');
        $now          = new \DateTime();
        $stat         = $this->createStat($contact, '65763254757234', 'bounced-address@wildbit.com', $now);
        $this->em->flush();

        $this->client->request(Request::METHOD_POST, '/mailer/callback', $parameters);
        $response = $this->client->getResponse();
        Assert::assertSame('Callback processed', $response->getContent());
        Assert::assertSame(200, $response->getStatusCode());

        // Only parse hard bounces
        if ('HardBounce' == $type) {
            $result = $this->getCommentAndReason($type);

            $openDetails = $stat->getOpenDetails();
            $bounces     = $openDetails['bounces'][0];
            Assert::assertSame($now->format(DateTimeHelper::FORMAT_DB), $bounces['datetime']);
            Assert::assertSame($result['comments'], $bounces['reason']);

            $dnc = $contact->getDoNotContact()->current();
            Assert::assertSame('email', $dnc->getChannel());
            Assert::assertSame($result['comments'], $dnc->getComments());
            Assert::assertSame($now->format(DateTimeHelper::FORMAT_DB), $dnc->getDateAdded()->format(DateTimeHelper::FORMAT_DB));
            Assert::assertSame($contact, $dnc->getLead());
            Assert::assertSame($result['reason'], $dnc->getReason());
        }
    }

    /**
     * @dataProvider provideMessageEventType
     */
    public function testPostmarkCallbackProcessByEmailAddress(string $type, string $bounceType): void
    {
        $parameters = $this->getParameters($type, $bounceType);

        $contact = $this->createContact('recipient@example.com');
        $this->em->flush();

        $now          = new \DateTime();
        $nowFormatted = $now->format(DateTimeHelper::FORMAT_DB);

        $this->client->request(Request::METHOD_POST, '/mailer/callback', $parameters);
        $response = $this->client->getResponse();
        Assert::assertSame('Callback processed', $response->getContent());
        Assert::assertSame(200, $response->getStatusCode());

        // Only parse hard bounces
        if ('bounce' !== $type && '25' !== $bounceType) {
            $result = $this->getCommentAndReason($type);

            $dnc = $contact->getDoNotContact()->current();
            Assert::assertSame('email', $dnc->getChannel());
            Assert::assertSame($result['comments'], $dnc->getComments());
            Assert::assertSame($nowFormatted, $dnc->getDateAdded()->format(DateTimeHelper::FORMAT_DB));
            Assert::assertSame($contact, $dnc->getLead());
            Assert::assertSame($result['reason'], $dnc->getReason());
        }
    }

    /**
     * @return array<mixed>
     */
    public function provideMessageEventType(): iterable
    {
        yield [ "ManualSuppression" ];
        yield [ "HardBounce"];
        yield [ "SpamComplaint"];
    }

    /**
     * Test the new Bounce webhook with detailed bounce information
     */
    public function testPostmarkBounceWebhookWithDetails(): void
    {
        $parameters = [
            'RecordType' => 'Bounce',
            'MessageStream' => 'outbound',
            'ID' => 42,
            'Type' => 'HardBounce',
            'TypeCode' => 1,
            'Name' => 'Hard bounce',
            'Tag' => 'my-tag',
            'MessageID' => '883953f4-6105-42a2-a16a-77a8eac79483',
            'ServerID' => 123456,
            'Description' => 'The server was unable to deliver your message (ex: unknown user, mailbox not found).',
            'Details' => 'Test bounce details',
            'Email' => 'bounce-test@example.com',
            'From' => 'sender@example.com',
            'BouncedAt' => '2023-10-01T12:00:00Z',
            'DumpAvailable' => true,
            'Inactive' => true,
            'CanActivate' => false,
            'Subject' => 'Test Email',
            'Content' => 'SMTP conversation: 550 5.1.1 User unknown',
            'Metadata' => [
                'email_id' => '123',
                'custom_field' => 'value',
            ],
        ];

        $contact = $this->createContact('bounce-test@example.com');
        $this->em->flush();

        $this->client->request(Request::METHOD_POST, '/mailer/callback', $parameters);
        $response = $this->client->getResponse();
        Assert::assertSame('Postmark Bounce webhook processed', $response->getContent());
        Assert::assertSame(200, $response->getStatusCode());

        // Verify DNC was created with detailed bounce information
        $dnc = $contact->getDoNotContact()->current();
        Assert::assertNotNull($dnc);
        Assert::assertSame('email', $dnc->getChannel());
        Assert::assertSame(DoNotContact::BOUNCED, $dnc->getReason());
        
        // Verify detailed bounce information is in the comment
        $comments = $dnc->getComments();
        Assert::assertStringContainsString('Type: Hard bounce', $comments);
        Assert::assertStringContainsString('Description: The server was unable to deliver', $comments);
        Assert::assertStringContainsString('Details: Test bounce details', $comments);
        Assert::assertStringContainsString('SMTP: 550 5.1.1 User unknown', $comments);
        Assert::assertStringContainsString('Bounced: 2023-10-01T12:00:00Z', $comments);
    }

    /**
     * Test Bounce webhook with SpamComplaint type
     */
    public function testPostmarkBounceWebhookSpamComplaint(): void
    {
        $parameters = [
            'RecordType' => 'Bounce',
            'MessageStream' => 'outbound',
            'ID' => 43,
            'Type' => 'SpamComplaint',
            'TypeCode' => 512,
            'Name' => 'Spam complaint',
            'MessageID' => '883953f4-6105-42a2-a16a-77a8eac79484',
            'ServerID' => 123456,
            'Description' => 'The subscriber marked this message as spam.',
            'Email' => 'spam-test@example.com',
            'From' => 'sender@example.com',
            'BouncedAt' => '2023-10-01T12:00:00Z',
            'Metadata' => [],
        ];

        $contact = $this->createContact('spam-test@example.com');
        $this->em->flush();

        $this->client->request(Request::METHOD_POST, '/mailer/callback', $parameters);
        $response = $this->client->getResponse();
        Assert::assertSame('Postmark Bounce webhook processed', $response->getContent());
        Assert::assertSame(200, $response->getStatusCode());

        // Verify DNC was created with spam complaint reason
        $dnc = $contact->getDoNotContact()->current();
        Assert::assertNotNull($dnc);
        Assert::assertSame('email', $dnc->getChannel());
        Assert::assertSame(DoNotContact::UNSUBSCRIBED, $dnc->getReason());
        
        // Verify spam complaint info in comment
        $comments = $dnc->getComments();
        Assert::assertStringContainsString('Type: Spam complaint', $comments);
        Assert::assertStringContainsString('Description: The subscriber marked this message as spam', $comments);
    }

    /**
     * @return array<mixed>
     */
    private function getParameters(string $supressionReason): array
    {

        return [
            [
                'RecordType'        => 'SubscriptionChange',
                'MessageID'         => '883953f4-6105-42a2-a16a-77a8eac79483',
                'ServerID'          => 123456,
                'MessageStream'     => 'outbound',
                'ChangedAt'         => '2020-02-01T10:53:34.416071Z',
                'Recipient'         => 'bounced-address@wildbit.com',
                'Origin'            => 'Recipient',
                'SuppressSending'   => true,
                'SuppressionReason' => $type,
                'Tag'               => 'my-tag',
                'Metadata'          => [
                    'example'   => 'value',
                    'example_2' => 'value',
                ],
            ],
        ];
    }

    private function createContact(string $email): Lead
    {
        $lead = new Lead();
        $lead->setEmail($email);

        $this->em->persist($lead);

        return $lead;
    }

    private function createStat(Lead $contact, string $trackingHash, string $emailAddress, \DateTime $dateSent): Stat
    {
        $stat = new Stat();
        $stat->setLead($contact);
        $stat->setTrackingHash($trackingHash);
        $stat->setEmailAddress($emailAddress);
        $stat->setDateSent($dateSent);

        $this->em->persist($stat);

        return $stat;
    }

    /**
     * @return array<mixed>
     */
    private function getCommentAndReason(string $type): array
    {
        return match ($type) {
            'bounce' => [
                'comments' => 'hard_bounce',
                'reason'   => DoNotContact::BOUNCED,
            ],
            'spam_complaint'                            => [
                'comments' => '',
                'reason'   => DoNotContact::UNSUBSCRIBED,
            ],
            'list_unsubscribe', 'link_unsubscribe'      => [
                'comments' => 'unsubscribed',
                'reason'   => DoNotContact::UNSUBSCRIBED,
            ],
            default                                     => [
                'comments' => '',
                'reason'   => '',
            ],
        };
    }
}
