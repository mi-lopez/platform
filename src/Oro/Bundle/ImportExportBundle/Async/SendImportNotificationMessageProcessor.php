<?php

namespace Oro\Bundle\ImportExportBundle\Async;

use Doctrine\Persistence\ManagerRegistry;
use Oro\Bundle\EmailBundle\Exception\NotSupportedException;
use Oro\Bundle\ImportExportBundle\Processor\ProcessorRegistry;
use Oro\Bundle\MessageQueueBundle\Entity\Job;
use Oro\Bundle\NotificationBundle\Async\Topic\SendEmailNotificationTemplateTopic;
use Oro\Bundle\NotificationBundle\Model\NotificationSettings;
use Oro\Bundle\UserBundle\Entity\User;
use Oro\Component\MessageQueue\Client\MessageProducerInterface;
use Oro\Component\MessageQueue\Client\TopicSubscriberInterface;
use Oro\Component\MessageQueue\Consumption\MessageProcessorInterface;
use Oro\Component\MessageQueue\Transport\MessageInterface;
use Oro\Component\MessageQueue\Transport\SessionInterface;
use Oro\Component\MessageQueue\Util\JSON;
use Psr\Log\LoggerInterface;

/**
 * Responsible for sending different types of notifications related to import process such as import result and
 * validation result notifications.
 */
class SendImportNotificationMessageProcessor implements MessageProcessorInterface, TopicSubscriberInterface
{
    /**
     * @var MessageProducerInterface
     */
    private $producer;

    /**
     *  @var LoggerInterface
     */
    private $logger;

    /**
     *  @var ImportExportResultSummarizer
     */
    private $importJobSummaryResultService;

    /**
     * @var NotificationSettings
     */
    private $notificationSettings;

    /**
     * @var ManagerRegistry
     */
    private $doctrine;

    public function __construct(
        MessageProducerInterface $producer,
        LoggerInterface $logger,
        ImportExportResultSummarizer $importJobSummaryResultService,
        NotificationSettings $notificationSettings,
        ManagerRegistry $doctrine
    ) {
        $this->producer = $producer;
        $this->logger = $logger;
        $this->importJobSummaryResultService = $importJobSummaryResultService;
        $this->notificationSettings = $notificationSettings;
        $this->doctrine = $doctrine;
    }

    /**
     * {@inheritdoc}
     */
    public function process(MessageInterface $message, SessionInterface $session)
    {
        $body = JSON::decode($message->getBody());

        if (! isset($body['rootImportJobId'], $body['process'], $body['userId'])) {
            $this->logger->critical('Invalid message');

            return self::REJECT;
        }

        if (! ($job = $this->doctrine->getRepository(Job::class)->findJobById((int)$body['rootImportJobId']))) {
            $this->logger->critical('Job not found');

            return self::REJECT;
        }

        $user = $this->doctrine->getRepository(User::class)->find($body['userId']);
        if (! $user instanceof User) {
            $this->logger->error(
                sprintf('User not found. Id: %s', $body['userId'])
            );

            return self::REJECT;
        }

        switch ($body['process']) {
            case ProcessorRegistry::TYPE_IMPORT_VALIDATION:
                $template = ImportExportResultSummarizer::TEMPLATE_IMPORT_VALIDATION_RESULT;
                break;
            case ProcessorRegistry::TYPE_IMPORT:
                $template = ImportExportResultSummarizer::TEMPLATE_IMPORT_RESULT;
                break;
            default:
                throw new NotSupportedException(
                    sprintf('Not found template for "%s" process of Import', $body['process'])
                );
        }

        $summary = $this->importJobSummaryResultService->getSummaryResultForNotification($job, $body['originFileName']);

        $this->sendNotification($user->getId(), $template, $summary);

        return self::ACK;
    }

    private function sendNotification(int $userId, string $template, array $templateParams): void
    {
        $message = [
            'from' => $this->notificationSettings->getSender()->toString(),
            'recipientUserId' => $userId,
            'template' => $template,
            'templateParams' => $templateParams,
        ];

        $this->producer->send(SendEmailNotificationTemplateTopic::getName(), $message);

        $this->logger->info('Sent notification message.');
    }

    /**
     * {@inheritdoc}
     */
    public static function getSubscribedTopics()
    {
        return [Topics::SEND_IMPORT_NOTIFICATION];
    }
}
