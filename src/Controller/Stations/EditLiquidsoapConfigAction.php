<?php

declare(strict_types=1);

namespace App\Controller\Stations;

use App\Entity\Repository\SettingsRepository;
use App\Event\Radio\WriteLiquidsoapConfiguration;
use App\Exception\StationUnsupportedException;
use App\Http\Response;
use App\Http\ServerRequest;
use App\Radio\Backend\Liquidsoap;
use Doctrine\ORM\EntityManagerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ResponseInterface;

class EditLiquidsoapConfigAction
{
    public function __invoke(
        ServerRequest $request,
        Response $response,
        EntityManagerInterface $em,
        SettingsRepository $settingsRepo,
        EventDispatcherInterface $eventDispatcher,
    ): ResponseInterface {
        $station = $request->getStation();

        $backend = $request->getStationBackend();
        if (!($backend instanceof Liquidsoap)) {
            throw new StationUnsupportedException();
        }

        $configSections = Liquidsoap\ConfigWriter::getCustomConfigurationSections();
        $tokens = Liquidsoap\ConfigWriter::getDividerString();

        $event = new WriteLiquidsoapConfiguration($station, true, false);
        $eventDispatcher->dispatch($event);
        $config = $event->buildConfiguration();

        $areas = [];

        $tok = strtok($config, $tokens);
        while ($tok !== false) {
            $tok = trim($tok);
            if (in_array($tok, $configSections, true)) {
                $areas[] = [
                    'is_field' => true,
                    'field_name' => $tok,
                ];
            } else {
                $areas[] = [
                    'is_field' => false,
                    'markup'   => $tok,
                ];
            }

            $tok = strtok($tokens);
        }

        $router = $request->getRouter();
        return $request->getView()->renderVuePage(
            response: $response,
            component: 'Vue_StationsLiquidsoapConfig',
            id: 'station-liquidsoap-config',
            title: __('Edit Liquidsoap Configuration'),
            props: [
                'settingsUrl'      => (string)$router->fromHere('api:stations:liquidsoap-config'),
                'restartStatusUrl' => (string)$router->fromHere('api:stations:restart-status'),
                'config'           => $areas,
                'sections'         => $configSections,
            ],
        );
    }
}
