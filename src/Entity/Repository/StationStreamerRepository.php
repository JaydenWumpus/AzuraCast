<?php

declare(strict_types=1);

namespace App\Entity\Repository;

use App\Doctrine\ReloadableEntityManagerInterface;
use App\Doctrine\Repository;
use App\Entity;
use App\Environment;
use App\Radio\AutoDJ\Scheduler;
use Psr\Log\LoggerInterface;
use Symfony\Component\Serializer\Serializer;

/**
 * @extends Repository<Entity\StationStreamer>
 */
class StationStreamerRepository extends Repository
{
    protected Scheduler $scheduler;

    protected StationStreamerBroadcastRepository $broadcastRepo;

    public function __construct(
        ReloadableEntityManagerInterface $em,
        Serializer $serializer,
        Environment $environment,
        LoggerInterface $logger,
        Scheduler $scheduler,
        StationStreamerBroadcastRepository $broadcastRepo
    ) {
        parent::__construct($em, $serializer, $environment, $logger);

        $this->scheduler = $scheduler;
        $this->broadcastRepo = $broadcastRepo;
    }

    /**
     * Attempt to authenticate a streamer.
     *
     * @param Entity\Station $station
     * @param string $username
     * @param string $password
     */
    public function authenticate(
        Entity\Station $station,
        string $username = '',
        string $password = ''
    ): bool {
        // Extra safety check for the station's streamer status.
        if (!$station->getEnableStreamers()) {
            return false;
        }

        $streamer = $this->getStreamer($station, $username);
        if (!($streamer instanceof Entity\StationStreamer)) {
            return false;
        }

        return $streamer->authenticate($password) && $this->scheduler->canStreamerStreamNow($streamer);
    }

    /**
     * @param Entity\Station $station
     * @param string $username
     *
     */
    public function onConnect(Entity\Station $station, string $username = ''): string|bool
    {
        // End all current streamer sessions.
        $this->broadcastRepo->endAllActiveBroadcasts($station);

        $streamer = $this->getStreamer($station, $username);
        if (!($streamer instanceof Entity\StationStreamer)) {
            return false;
        }

        $station->setIsStreamerLive(true);
        $station->setCurrentStreamer($streamer);
        $this->em->persist($station);

        $record = new Entity\StationStreamerBroadcast($streamer);
        $this->em->persist($record);
        $this->em->flush();

        return true;
    }

    public function onDisconnect(Entity\Station $station): bool
    {
        foreach ($this->broadcastRepo->getActiveBroadcasts($station) as $broadcast) {
            $broadcast->setTimestampEnd(time());
            $this->em->persist($broadcast);
        }

        $station->setIsStreamerLive(false);
        $station->setCurrentStreamer();
        $this->em->persist($station);
        $this->em->flush();

        return true;
    }

    public function getStreamer(Entity\Station $station, string $username = ''): ?Entity\StationStreamer
    {
        /** @var Entity\StationStreamer|null $streamer */
        $streamer = $this->repository->findOneBy(
            [
                'station' => $station,
                'streamer_username' => $username,
                'is_active' => 1,
            ]
        );

        return $streamer;
    }

    /**
     * Fetch all streamers who are deactivated and have a reactivate at timestamp set
     *
     * @param int|null $reactivate_at
     *
     * @return Entity\StationStreamer[]
     */
    public function getStreamersDueForReactivation(int $reactivate_at = null): array
    {
        $reactivate_at = $reactivate_at ?? time();

        return $this->em->createQueryBuilder()
            ->select('s')
            ->from($this->entityClass, 's')
            ->where('s.is_active = 0')
            ->andWhere('s.reactivate_at <= :reactivate_at')
            ->setParameter('reactivate_at', $reactivate_at)
            ->getQuery()
            ->execute();
    }
}
