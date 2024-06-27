<?php

namespace CommonGateway\HaalCentraalToStufBGBundle\Service;

use App\Entity\Gateway as Source;
use App\Entity\Mapping;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Criteria;
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
    public function fetchPerson(Source $source, ?string $endpoint='', ?array $query=[]): ?array
    {
        $this->logger->info('Fetching ingeschrevenpersoon..');
        try {
            $response = $this->callService->call($source, $endpoint, 'GET', ['query' => $query]);
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
            if (isset($ingeschrevenPersoon['_embedded'][$type]) === true) {
                foreach ($ingeschrevenPersoon['_embedded'][$type] as $link) {
                    if (isset($link['_links']['ingeschrevenPersoon']) === true) {
                        // Remove domain etc from link so we have a endpoint.
                        $endpoint = \Safe\parse_url($link['_links']['ingeschrevenPersoon']['href'],  PHP_URL_PATH);
                        $bsn      = ltrim(explode('/haal-centraal-brp-bevragen/api/v1.3/ingeschrevenpersonen', $endpoint)[1], '/');
                        $bsns[]   = $bsn;
                    }
                }

                $query = [
                    'burgerservicenummer' => implode(',', $bsns),
                ];

                $people               = $this->fetchPerson($source, '', $query);

                $foundPeople          = new ArrayCollection($people['_embedded']['ingeschrevenpersonen']);
                $fetchedPeople[$type] = $foundPeople->filter(function($person) use ($ingeschrevenPersoon) {
                    $comp = true;
                    if(isset($ingeschrevenPersoon['verblijfplaats']['postcode']) === true && isset($person['verblijfplaats']['postcode']) === true) {
                        $comp = $comp && $ingeschrevenPersoon['verblijfplaats']['postcode'] === $person['verblijfplaats']['postcode'];
                    }

                    if(isset($ingeschrevenPersoon['verblijfplaats']['huisnummer']) === true && isset($person['verblijfplaats']['huisnummer']) === true) {
                        $comp = $comp && $ingeschrevenPersoon['verblijfplaats']['huisnummer'] === $person['verblijfplaats']['huisnummer'];
                    }

                    if(isset($ingeschrevenPersoon['verblijfplaats']['huisletter']) === true && isset($person['verblijfplaats']['huisletter']) === true) {
                        $comp = $comp && $ingeschrevenPersoon['verblijfplaats']['huisnummer'] === $person['verblijfplaats']['huisnummer'];
                    }

                    if(isset($ingeschrevenPersoon['verblijfplaats']['huisnummertoevoeging']) === true && isset($person['verblijfplaats']['huisnummertoevoeging']) === true) {
                        $comp = $comp && $ingeschrevenPersoon['verblijfplaats']['huisnummertoevoeging'] === $person['verblijfplaats']['huisnummertoevoeging'];
                    }

                    if ((isset($ingeschrevenPersoon['verblijfplaats']['postcode']) !== isset($person['verblijfplaats']['postcode']))
                        || (isset($ingeschrevenPersoon['verblijfplaats']['huisnummer']) !==  isset($person['verblijfplaats']['huisnummer']))
                        || (isset($ingeschrevenPersoon['verblijfplaats']['huisletter']) !== isset($person['verblijfplaats']['huisletter']))
                        || (isset($ingeschrevenPersoon['verblijfplaats']['huisnummertoevoeging']) !== isset($person['verblijfplaats']['huisnummertoevoeging']))
                    ) {
                        return false;
                    }

                    return $comp;
                })->toArray();
                $bsns                 = [];
            }//end if
        }//end foreach

        return [
            'enrichedPartners' => $fetchedPeople['partners'],
            'enrichedParents'  => $fetchedPeople['ouders'],
            'enrichedChildren' => $fetchedPeople['kinderen'],
        ];

    }//end getAllRelatives()


    /**
     * Checks if the nationality of the ingeschreven persoon is Dutch.
     *
     * @param array $ingeschrevenPersoon Already fetched ingeschrevenPersoon to check the nationality.
     *
     * @return array|null If the nationality of a ingeschreven persoon is Dutch.
     */
    public function checkDutchNationality(array $ingeschrevenPersoon): ?array
    {
        // Set the nationality default on false.
        $dutchNationality = "false";
        foreach ($ingeschrevenPersoon['nationaliteiten'] as $nationality) {
            // If the omschrijving is 'Nederlandse' or the code is '0001' then set the bool to true.
            if ($nationality['nationaliteit']['omschrijving'] === 'Nederlandse'
                || $nationality['nationaliteit']['code'] === '0001'
            ) {
                $dutchNationality = "true";
            }
        }

        return ['nederlandseNationaliteit' => $dutchNationality];

    }//end checkDutchNationality()


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
        $ingeschrevenPersoon = $this->fetchPerson($source, "/$bsn", ['expand' => 'ouders,partners,kinderen']);
        if ($ingeschrevenPersoon === null || empty($ingeschrevenPersoon) === true) {
            $ingeschrevenPersoon = $this->fetchPerson($source, "/$bsn");
        }


        if ($ingeschrevenPersoon === null || empty($ingeschrevenPersoon) === true) {
            $this->logger->error('IngeschrevenPersoon could not be found/fetched from source.');

            return $this->data;
        }

        // 3. Check partners, parents and children. Fetch those.
        $allRelatives = $this->getAllRelatives($source, $ingeschrevenPersoon);

        // 4. Check if the nationality of the ingeschrevenpersoon is Dutch.
        $nationality = $this->checkDutchNationality($ingeschrevenPersoon);

        // 5. Map them together into a stuf response.
        $allRelatives       = array_merge($nationality, $allRelatives, $ingeschrevenPersoon, ['referentienummer' => $referentienummer]);
        $mappedAllRelatives = $this->mappingService->mapping($mapping, $allRelatives);

        // 6. Create response
        $xmlEncoder = new XmlEncoder(['xml_root_node_name' => 'SOAP-ENV:Envelope']);
        $xmlString  = $xmlEncoder->encode($mappedAllRelatives, 'xml', ['xml_encoding' => 'utf-8', 'remove_empty_tags' => true]);

        $this->data['response'] = new Response($xmlString, 200, ['Content-Type' => 'application/xml', 'accept' => 'xml']);

        return $this->data;

    }//end haalCentraalToStufBGHandler()


}//end class
