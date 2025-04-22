<?php
namespace PBA\HealthCheck\Cron;

use Magento\Cron\Model\ResourceModel\Schedule\CollectionFactory as ScheduleCollectionFactory;
use Psr\Log\LoggerInterface;

class HealthCheck
{
    protected $scheduleCollectionFactory;
    protected $logger;

    public function __construct(
        ScheduleCollectionFactory $scheduleCollectionFactory,
        LoggerInterface $logger
    ) {
        $this->scheduleCollectionFactory = $scheduleCollectionFactory;
        $this->logger = $logger;
    }

    public function execute()
    {
        $collection = $this->scheduleCollectionFactory->create();
        $collection->addFieldToFilter('status', ['neq' => 'success']);
        $collection->addFieldToFilter('scheduled_at', ['lt' => date('Y-m-d H:i:s', strtotime('-5 minutes'))]);

        if ($collection->count() > 0) {
            $this->logger->error('Health Check: Há crons com problemas!', ['cron_count' => $collection->count()]);
        } else {
            $this->logger->info('Health Check: Todos os crons estão funcionando corretamente.');
        }
    }
}