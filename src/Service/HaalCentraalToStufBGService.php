<?php

namespace CommonGateway\HaalCentraalToStufBGBundle\Service;

use App\Entity\Gateway as Source;
use App\Entity\Mapping;
use Psr\Log\LoggerInterface;
use CommonGateway\CoreBundle\Service\GatewayResourceService;
use CommonGateway\CoreBundle\Service\CallService;
use CommonGateway\CoreBundle\Service\MappingService;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Serializer\Encoder\XmlEncoder;

/**
 * An haalCentraal to stuf BG service for mapping and sending.
 *
 * Fetches a ingeschreven persoon with given bsn, fetches its relatives and maps them together to a StUF xml response.
 *
 * @author   Conduction.nl <info@conduction.nl>, Sarai Misidjan <sarai@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @category Service
 */
class HaalCentraalToStufBGService
{

    /**
     * @var array
     */
    private array $configuration;

    /**
     * @var array
     */
    private array $data;

    /**
     * @var CallService
     */
    private CallService $callService;

    /**
     * @var MappingService
     */
    private MappingService $mappingService;

    /**
     * @var GatewayResourceService
     */
    private GatewayResourceService $gatewayResourceService;

    /**
     * The plugin logger.
     *
     * @var LoggerInterface
     */
    private LoggerInterface $logger;


    /**
     * @param CallService            $callService            The CallService.
     * @param MappingService         $mappingService         The MappingService.
     * @param GatewayResourceService $gatewayResourceService The GatewayResourceService.
     * @param LoggerInterface        $pluginLogger           The plugin version of the logger interface.
     */
    public function __construct(
        CallService $callService,
        MappingService $mappingService,
        GatewayResourceService $gatewayResourceService,
        LoggerInterface $pluginLogger
    ) {
        $this->callService            = $callService;
        $this->mappingService         = $mappingService;
        $this->gatewayResourceService = $gatewayResourceService;
        $this->logger                 = $pluginLogger;
        $this->configuration          = [];
        $this->data                   = [];

    }//end __construct()


    /**
     * Fetches a person with given source and endpoint.
     *
     * @param Source $source   Source for haalcentraal api.
     * @param string $endpoint Endpoint to fetch person from.
     *
     * @return array|null The fetched person
     */
    public function fetchPerson(Source $source, string $endpoint): ?array
    {
        $this->logger->info('Fetching ingeschrevenpersoon..');
        try {
            $response = $this->callService->call($source, $endpoint, 'GET');
            return $this->callService->decodeResponse($source, $response, 'application/json');
        } catch (\Exception $e) {
            $this->logger->error('Error when fetching ingeschrevenpersoon: '.$e->getMessage());

            return null;
        }

    }//end fetchPerson()


    /**
     * Fetches all relatives of the ingeschreven persoon.
     *
     * @param Source $source              Source for haalcentraal api.
     * @param array  $ingeschrevenPersoon Already fetched ingeschrevenPersoon to fetch relatives from.
     *
     * @return array|null The relatives of a ingeschreven persoon.
     */
    public function getAllRelatives(Source $source, array $ingeschrevenPersoon): ?array
    {
        $fetchedPeople = [
            'partners' => [],
            'ouders'   => [],
            'kinderen' => [],
        ];
        foreach ($fetchedPeople as $type => $people) {
            if (isset($ingeschrevenPersoon['_links'][$type]) === true) {
                foreach ($ingeschrevenPersoon['_links'][$type] as $link) {
                    // Remove domain etc from link so we have a endpoint.
                    $endpoint               = str_replace(str_replace('https', 'http', $source->getLocation()), '', $link['href']);
                    $fetchedPeople[$type][] = $this->fetchPerson($source, $endpoint);
                }
            }
        }

        return [
            'enrichedPartners' => $fetchedPeople['partners'],
            'enrichedParents'  => $fetchedPeople['ouders'],
            'enrichedChildren' => $fetchedPeople['kinderen'],
        ];

    }//end getAllRelatives()


    /**
     * An haalCentraal to stuf BG handler that is triggered by an action.
     *
     * @param array $data          The data array.
     * @param array $configuration The configuration array.
     *
     * @return array A handler must ALWAYS return an array.
     */
    public function haalCentraalToStufBGHandler(array $data, array $configuration): array
    {
        $this->logger->debug("HaalCentraalToStufBGService -> HaalCentraalToStufBGHandler()");
        $this->data          = $data;
        $this->configuration = $configuration;

        // 0. Validate some configuration.
        $mapping = $this->gatewayResourceService->getMapping('https://commongateway.nl/mapping/stuf.haalCentraalToLa01.mapping.json', 'haalcentraal-to-stufbg-bundle');
        if ($mapping instanceof Mapping === false) {
            return $this->data;
        }

        $source = $this->gatewayResourceService->getSource('https://commongateway.nl/source/stuf.haalcentraal.source.json', 'haalcentraal-to-stufbg-bundle');
        if ($source instanceof Source === false) {
            return $this->data;
        }

        // 1. Get bsn from body.
        $this->logger->info('Getting BSN from request body..');
        $bsn              = $this->data['body']['SOAP-ENV:Body']['BG:npsLv01-prs-GezinssituatieOpAdresAanvrager']['BG:gelijk']['BG:inp.bsn'] ?? null;
        $referentienummer = $this->data['body']['SOAP-ENV:Body']['BG:npsLv01-prs-GezinssituatieOpAdresAanvrager']['BG:stuurgegevens']['StUF:referentienummer'] ?? null;

        if ($bsn === null) {
            $this->logger->error('BSN not found in xml body.');

            return $this->data;
        }

        // 2. Get ingeschrevenpersoon from source.
        $ingeschrevenPersoon = $this->fetchPerson($source, "/$bsn");
        if ($ingeschrevenPersoon === null || empty($ingeschrevenPersoon) === true) {
            $this->logger->error('IngeschrevenPersoon could not be found/fetched from source.');

            return $this->data;
        }

        // 3. Check partners, parents and children. Fetch those.
        $allRelatives = $this->getAllRelatives($source, $ingeschrevenPersoon);

        // 4. Map them together into a stuf response.
        $allRelatives       = array_merge($allRelatives, $ingeschrevenPersoon, ['referentienummer' => $referentienummer]);
        $mappedAllRelatives = $this->mappingService->mapping($mapping, $allRelatives);

        // 5. Create response
        $xmlEncoder = new XmlEncoder(['xml_root_node_name' => 'SOAP-ENV:Envelope']);
        $xmlString  = $xmlEncoder->encode($mappedAllRelatives, 'xml', ['xml_encoding' => 'utf-8', 'remove_empty_tags' => true]);

        $this->data['response'] = new Response($xmlString, 200, ['Content-Type' => 'application/xml', 'accept' => 'xml']);

        return $this->data;

    }//end haalCentraalToStufBGHandler()


}//end class
