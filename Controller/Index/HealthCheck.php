<?php
/**
 * Copyright © PBA All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace PBA\HealthCheck\Controller\Index;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\CacheInterface;
use Magento\Cron\Model\ResourceModel\Schedule\CollectionFactory as ScheduleCollectionFactory;
use Psr\Log\LoggerInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;

class HealthCheck extends Action
{
    protected $scheduleCollectionFactory;
    protected $cache;
    protected $logger;
    protected $scopeConfig;

    const XML_PATH_ENABLED = 'healthcheck/general/enabled';
    const XML_PATH_CACHE_LIFETIME = 'healthcheck/general/cache_lifetime';
    const XML_PATH_ENABLE_IP_RESTRICTION = 'healthcheck/general/enable_ip_restriction';
    const XML_PATH_ALLOWED_IPS = 'healthcheck/general/allowed_ips';

    const CACHE_KEY = 'healthcheck_status';

    public function __construct(
        Context $context,
        ScheduleCollectionFactory $scheduleCollectionFactory,
        CacheInterface $cache,
        LoggerInterface $logger,
        ScopeConfigInterface $scopeConfig
    ) {
        $this->scheduleCollectionFactory = $scheduleCollectionFactory;
        $this->cache = $cache;
        $this->logger = $logger;
        $this->scopeConfig = $scopeConfig;
        parent::__construct($context);
    }

    public function execute()
    {
        try {
            // Verificar se o módulo está habilitado
            $isEnabled = $this->scopeConfig->isSetFlag(self::XML_PATH_ENABLED, \Magento\Store\Model\ScopeInterface::SCOPE_STORE);
            if (!$isEnabled) {
                $this->getResponse()->setHttpResponseCode(503); // HTTP 503: Serviço Indisponível
                $this->getResponse()->setBody(json_encode([
                    'status' => 'error',
                    'message' => 'O módulo Health Check está desativado.',
                ]));
                return;
            }

            // Verificar se a restrição por IP está habilitada
            $isIpRestrictionEnabled = $this->scopeConfig->isSetFlag(self::XML_PATH_ENABLE_IP_RESTRICTION, \Magento\Store\Model\ScopeInterface::SCOPE_STORE);
            if ($isIpRestrictionEnabled) {
                $allowedIps = $this->scopeConfig->getValue(self::XML_PATH_ALLOWED_IPS, \Magento\Store\Model\ScopeInterface::SCOPE_STORE);
                $allowedIpsArray = array_map('trim', explode(',', $allowedIps));
                $clientIp = $this->getRequest()->getServer('REMOTE_ADDR');

                if (!in_array($clientIp, $allowedIpsArray)) {
                    $this->getResponse()->setHttpResponseCode(403); // HTTP 403: Proibido
                    $this->getResponse()->setBody(json_encode([
                        'status' => 'error',
                        'message' => 'Acesso negado. Seu IP não está na lista de IPs permitidos.',
                    ]));
                    return;
                }
            }

            // Obter o tempo de vida do cache das configurações
            $cacheLifetime = (int) $this->scopeConfig->getValue(self::XML_PATH_CACHE_LIFETIME, \Magento\Store\Model\ScopeInterface::SCOPE_STORE);

            // Verificar se o resultado está armazenado no cache
            $cachedResponse = $this->cache->load(self::CACHE_KEY);
            if ($cachedResponse) {
                $cachedData = json_decode($cachedResponse, true);
                $this->getResponse()->setHttpResponseCode($cachedData['http_code']);
                $this->getResponse()->representJson(json_encode($cachedData['response']));
                return;
            }

            // Caso não haja cache, realizar a verificação
            $collection = $this->scheduleCollectionFactory->create();
            $collection->addFieldToFilter('status', ['neq' => 'success']); // Status diferente de 'success'
            $collection->addFieldToFilter('scheduled_at', ['lt' => date('Y-m-d H:i:s', strtotime('-5 minutes'))]);

            $problematicCrons = [];
            if ($collection->count() > 0) {
                foreach ($collection as $cron) {
                    $problematicCrons[] = [
                        'job_code' => $cron->getJobCode(),
                        'status' => $cron->getStatus(),
                        'scheduled_at' => $cron->getScheduledAt(),
                        'executed_at' => $cron->getExecutedAt(),
                        'finished_at' => $cron->getFinishedAt(),
                    ];

                    // Registrar cada cron problemático no log
                    $this->logger->error('Cron Scheduler Problem Detected', [
                        'job_code' => $cron->getJobCode(),
                        'status' => $cron->getStatus(),
                        'scheduled_at' => $cron->getScheduledAt(),
                        'executed_at' => $cron->getExecutedAt(),
                        'finished_at' => $cron->getFinishedAt(),
                    ]);
                }

                // Construir a resposta de erro
                $result = [
                    'status' => 'error',
                    'message' => 'Crons problemáticos encontrados.',
                    'cron_details' => $problematicCrons,
                ];

                // Salvar o erro com o código HTTP no cache
                $responseJson = json_encode([
                    'response' => $result,
                    'http_code' => 500
                ]);
                $this->cache->save($responseJson, self::CACHE_KEY, [], $cacheLifetime);

                // Retornar código HTTP 500
                $this->getResponse()->setHttpResponseCode(500);
                $this->getResponse()->representJson(json_encode($result));
                return;
            }

            // Caso tudo esteja OK
            $result = [
                'status' => 'success',
                'message' => 'Todos os crons estão funcionando corretamente.',
            ];

            // Salvar no cache o resultado de sucesso com o código HTTP
            $responseJson = json_encode([
                'response' => $result,
                'http_code' => 200
            ]);
            $this->cache->save($responseJson, self::CACHE_KEY, [], $cacheLifetime);

            // Retornar código HTTP 200
            $this->getResponse()->setHttpResponseCode(200);
            $this->getResponse()->representJson(json_encode($result));
        } catch (\Exception $e) {
            // Registrar a exceção no log
            $this->logger->critical('Exception in Health Check', ['exception' => $e->getMessage()]);
            $this->getResponse()->setHttpResponseCode(500);
            $this->getResponse()->representJson(json_encode([
                'status' => 'error',
                'message' => 'Erro interno. Verifique os logs para mais detalhes.',
            ]));
        }
    }
}