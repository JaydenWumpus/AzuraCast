<?php

declare(strict_types=1);

namespace App\Controller\Api\Internal;

use App\Enums\StationPermissions;
use App\Http\Response;
use App\Http\ServerRequest;
use App\Radio\Backend\Liquidsoap\Command\AbstractCommand;
use App\Radio\Enums\LiquidsoapCommands;
use InvalidArgumentException;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

class LiquidsoapAction
{
    public function __invoke(
        ServerRequest $request,
        Response $response,
        ContainerInterface $di,
        LoggerInterface $logger,
        string $action
    ): ResponseInterface {
        $station = $request->getStation();
        $asAutoDj = $request->hasHeader('X-Liquidsoap-Api-Key');
        $payload = (array)$request->getParsedBody();

        try {
            $acl = $request->getAcl();
            if (!$acl->isAllowed(StationPermissions::View, $station->getIdRequired())) {
                $authKey = $request->getHeaderLine('X-Liquidsoap-Api-Key');
                if (!$station->validateAdapterApiKey($authKey)) {
                    throw new RuntimeException('Invalid API key.');
                }
            }

            $command = LiquidsoapCommands::tryFrom($action);
            if (null === $command || !$di->has($command->getClass())) {
                throw new InvalidArgumentException('Command not found.');
            }

            /** @var AbstractCommand $commandObj */
            $commandObj = $di->get($command->getClass());

            $result = $commandObj->run($station, $asAutoDj, $payload);
            $response->getBody()->write($result);
        } catch (Throwable $e) {
            $logger->error(
                sprintf(
                    'Liquidsoap command "%s" error: %s',
                    $action,
                    $e->getMessage()
                ),
                [
                    'station' => (string)$station,
                    'payload' => $payload,
                    'as-autodj' => $asAutoDj,
                ]
            );

            return $response->withStatus(400)
                ->write('false');
        }

        return $response;
    }
}
