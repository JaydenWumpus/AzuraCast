<?php

declare(strict_types=1);

namespace App\Sync\Task;

use App\Doctrine\ReloadableEntityManagerInterface;
use App\Entity;
use Psr\Log\LoggerInterface;

class CleanupHistoryTask extends AbstractTask
{
    public function __construct(
        protected Entity\Repository\SettingsRepository $settingsRepo,
        protected Entity\Repository\SongHistoryRepository $historyRepo,
        protected Entity\Repository\StationQueueRepository $queueRepo,
        protected Entity\Repository\ListenerRepository $listenerRepo,
        ReloadableEntityManagerInterface $em,
        LoggerInterface $logger
    ) {
        parent::__construct($em, $logger);
    }

    public static function getSchedulePattern(): string
    {
        return '17 * * * *';
    }

    public function run(bool $force = false): void
    {
        // Clear station queue independent of history settings.
        $this->queueRepo->cleanup(Entity\StationQueue::DAYS_TO_KEEP);

        // Clean up history and listeners according to user settings.
        $daysToKeep = $this->settingsRepo->readSettings()->getHistoryKeepDays();
        if (0 !== $daysToKeep) {
            $this->historyRepo->cleanup($daysToKeep);
            $this->listenerRepo->cleanup($daysToKeep);
        }
    }
}
