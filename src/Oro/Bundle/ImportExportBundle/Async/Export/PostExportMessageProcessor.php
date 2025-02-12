<?php

namespace Oro\Bundle\ImportExportBundle\Async\Export;

use Oro\Bundle\EntityBundle\ORM\DoctrineHelper;
use Oro\Bundle\ImportExportBundle\Async\ImportExportResultSummarizer;
use Oro\Bundle\ImportExportBundle\Async\Topics;
use Oro\Bundle\ImportExportBundle\Exception\RuntimeException;
use Oro\Bundle\ImportExportBundle\Handler\ExportHandler;
use Oro\Bundle\MessageQueueBundle\Entity\Job;
use Oro\Bundle\MessageQueueBundle\Entity\Repository\JobRepository;
use Oro\Bundle\NotificationBundle\Async\Topic\SendEmailNotificationTemplateTopic;
use Oro\Bundle\NotificationBundle\Model\NotificationSettings;
use Oro\Component\MessageQueue\Client\MessageProducerInterface;
use Oro\Component\MessageQueue\Client\TopicSubscriberInterface;
use Oro\Component\MessageQueue\Consumption\MessageProcessorInterface;
use Oro\Component\MessageQueue\Job\JobManagerInterface;
use Oro\Component\MessageQueue\Transport\MessageInterface;
use Oro\Component\MessageQueue\Transport\SessionInterface;
use Oro\Component\MessageQueue\Util\JSON;
use Psr\Log\LoggerInterface;

/**
 * This processor serves to finalize batched export process and send email notification upon its completion.
 */
class PostExportMessageProcessor implements MessageProcessorInterface, TopicSubscriberInterface
{
    /**
     * @var ExportHandler
     */
    private $exportHandler;

    /**
     * @var MessageProducerInterface
     */
    private $producer;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var DoctrineHelper
     */
    private $doctrineHelper;

    /**
     * @var JobManagerInterface
     */
    private $jobManager;

    /**
     * @var ImportExportResultSummarizer
     */
    private $importExportResultSummarizer;

    /**
     * @var NotificationSettings
     */
    private $notificationSettings;

    public function __construct(
        ExportHandler $exportHandler,
        MessageProducerInterface $producer,
        LoggerInterface $logger,
        DoctrineHelper $doctrineHelper,
        JobManagerInterface $jobManager,
        ImportExportResultSummarizer $importExportResultSummarizer,
        NotificationSettings $notificationSettings
    ) {
        $this->exportHandler = $exportHandler;
        $this->producer = $producer;
        $this->logger = $logger;
        $this->doctrineHelper = $doctrineHelper;
        $this->jobManager = $jobManager;
        $this->importExportResultSummarizer = $importExportResultSummarizer;
        $this->notificationSettings = $notificationSettings;
    }

    /**
     * {@inheritdoc}
     */
    public function process(MessageInterface $message, SessionInterface $session)
    {
        $body = JSON::decode($message->getBody());

        if (! isset(
            $body['jobId'],
            $body['jobName'],
            $body['exportType'],
            $body['outputFormat'],
            $body['recipientUserId'],
            $body['entity']
        )) {
            $this->logger->critical('Invalid message');

            return self::REJECT;
        }

        if (!($job = $this->getJobRepository()->findJobById((int)$body['jobId']))) {
            $this->logger->critical('Job not found');

            return self::REJECT;
        }

        $job = $job->isRoot() ? $job : $job->getRootJob();
        $files = [];

        foreach ($job->getChildJobs() as $childJob) {
            if (! empty($childJob->getData()) && ($file = $childJob->getData()['file'])) {
                $files[] = $file;
            }
        }

        $fileName = null;
        try {
            $fileName = $this->exportHandler->exportResultFileMerge(
                $body['jobName'],
                $body['exportType'],
                $body['outputFormat'],
                $files
            );
        } catch (RuntimeException $e) {
            $this->logger->critical(
                sprintf('Error occurred during export merge: %s', $e->getMessage()),
                ['exception' => $e]
            );
        }

        if ($fileName !== null) {
            $job->setData(array_merge($job->getData(), ['file' => $fileName]));
            $this->jobManager->saveJob($job);

            $summary = $this->importExportResultSummarizer->processSummaryExportResultForNotification($job, $fileName);

            $this->sendEmailNotification($body['recipientUserId'], $summary, $body['notificationTemplate'] ?? '');

            $this->producer->send(
                Topics::SAVE_IMPORT_EXPORT_RESULT,
                [
                    'jobId' => $job->getId(),
                    'type' => $body['exportType'],
                    'entity' => $body['entity'],
                ]
            );
        }

        return self::ACK;
    }

    /**
     * {@inheritdoc}
     */
    public static function getSubscribedTopics()
    {
        return [Topics::POST_EXPORT];
    }

    private function sendEmailNotification(int $recipientUserId, array $summary, string $templateName = ''): void
    {
        $message = [
            'from' => $this->notificationSettings->getSender()->toString(),
            'recipientUserId' => $recipientUserId,
            'template' => $templateName ?: ImportExportResultSummarizer::TEMPLATE_EXPORT_RESULT,
            'templateParams' => $summary,
        ];

        $this->producer->send(SendEmailNotificationTemplateTopic::getName(), $message);

        $this->logger->info('Sent notification email.');
    }

    private function getJobRepository(): JobRepository
    {
        return $this->doctrineHelper->getEntityRepository(Job::class);
    }
}
