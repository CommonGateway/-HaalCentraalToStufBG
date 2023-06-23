<?php
/**
 * An haalCentraal to stuf BG service for mapping and sending.
 *
 * @author  Conduction.nl <info@conduction.nl>, Sarai Misidjan <sarai@conduction.nl>
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

namespace CommonGateway\HaalCentraalToStufBGBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

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
     * @var EntityManagerInterface
     */
    private EntityManagerInterface $entityManager;

    /**
     * The plugin logger.
     *
     * @var LoggerInterface
     */
    private LoggerInterface $logger;


    /**
     * @param EntityManagerInterface $entityManager The Entity Manager.
     * @param LoggerInterface        $pluginLogger  The plugin version of the logger interface.
     */
    public function __construct(
        EntityManagerInterface $entityManager,
        LoggerInterface $pluginLogger
    ) {
        $this->entityManager = $entityManager;
        $this->logger        = $pluginLogger;
        $this->configuration = [];
        $this->data          = [];

    }//end __construct()


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
        $this->data          = $data;
        $this->configuration = $configuration;

        $this->logger->debug("HaalCentraalToStufBGService -> HaalCentraalToStufBGHandler()");

        return ['response' => 'Hello. Your HaalCentraalToStufBGBundle works'];

    }//end petStoreHandler()


}//end class
