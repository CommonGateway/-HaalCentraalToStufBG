<?php
/**
 * An haalCentraal to stuf BG service for mapping and sending.
 *
 * @author  Conduction.nl <info@conduction.nl>, Sarai Misidjan <sarai@conduction.nl>
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

namespace CommonGateway\HaalCentraalToStufBGBundle\Service;

use App\Entity\Gateway as Source;
use App\Entity\Mapping;
use Psr\Log\LoggerInterface;
use CommonGateway\CoreBundle\Service\GatewayResourceService;
use CommonGateway\CoreBundle\Service\CallService;
use CommonGateway\CoreBundle\Service\MappingService;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Serializer\Encoder\XmlEncoder;

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
     * Gets BSN from xml body.
     *
     * @return string|null $bsn.
     */
    private function getBsnFromBody(): ?string
    {
        return $this->data['body']['SOAP-ENV:Body']['BG:npsLv01-prs-GezinssituatieOpAdresAanvrager']['BG:gelijk']['BG:inp.bsn'] ?? null;

    }//end getBsnFromBody()


    /**
     * Finds source by reference.
     *
     * @return Source|null The resulting source.
     */
    public function getSource(): ?Source
    {
        $source = $this->gatewayResourceService->getSource('https://commongateway.nl/source/stuf.haalcentraal.source.json', 'haalcentraal-to-stufbg-bundle');
        if ($source instanceof Source === false) {
            $this->logger->error("No source found with reference: https://commongateway.nl/source/stuf.haalcentraal.source.json");

            return null;
        }

        return $source;

    }//end getSource()


    /**
     * Finds mapping by reference.
     *
     * @return Mapping|null The resulting mapping.
     */
    public function getMapping(): ?Mapping
    {
        $mapping = $this->gatewayResourceService->getMapping('https://commongateway.nl/source/stuf.haalCentraalToLa01.source.json', 'haalcentraal-to-stufbg-bundle');
        if ($mapping instanceof Mapping === false) {
            $this->logger->error("No mapping found with reference: https://commongateway.nl/source/stuf.haalCentraalToLa01.source.json");

            return null;
        }

        return $mapping;

    }//end getMapping()


    /**
     * Fetches a person with given source and endpoint.
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
            // Logging might be dangerous here?
            // $this->logger->error('Error when fetching ingeschrevenpersoon: ' . $e->getMessage());
            return null;
        }

    }//end fetchPerson()


    /**
     * Fetches all relatives of the ingeschreven persoon.
     *
     * @param Source $source
     * @param array  $ingeschrevenPersoon
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
                // var_dump("ingeschrevenPersoon['_links'][$type] true");
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
     * @param array $data          The data array
     * @param array $configuration The configuration array
     *
     * @return array A handler must ALWAYS return an array
     */
    public function haalCentraalToStufBGHandler(array $data, array $configuration): array
    {
        $this->logger->debug("HaalCentraalToStufBGService -> HaalCentraalToStufBGHandler()");
        $this->data          = $data;
        $this->configuration = $configuration;

        // 0. Validate some configuration.
        $mapping = $this->getMapping();
        if ($mapping === null) {
            return [];
        }

        $source = $this->getSource();
        if ($source === null) {
            return [];
        }

        // 1. Get bsn from body.
        $this->logger->info('Getting BSN from request body..');
        $bsn = $this->getBsnFromBody();
        if ($bsn === null) {
            $this->logger->error('BSN not found in xml body.');

            return [];
        }

        // 2. Get ingeschrevenpersoon from source.
        $ingeschrevenPersoon = $this->fetchPerson($source, "/$bsn");

        // 3. Check partners, parents and children. Fetch those.
        $allRelatives = $this->getAllRelatives($source, $ingeschrevenPersoon);

        // 4. Map them together into a stuf response.
        $allRelatives       = array_merge($allRelatives, $ingeschrevenPersoon);
        $mappedAllRelatives = $this->mappingService->mapping($mapping, $allRelatives);

        // 5. Create response
        $xmlEncoder = new XmlEncoder(['xml_root_node_name' => 'SOAP-ENV:Envelope']);
        $xmlString  = $xmlEncoder->encode($mappedAllRelatives, 'xml', ['xml_encoding' => 'utf-8', 'remove_empty_tags' => true]);

        return ['response' => new Response($xmlString, 200, ['Content-Type' => 'application/xml'])];

    }//end haalCentraalToStufBGHandler()


}//end class
