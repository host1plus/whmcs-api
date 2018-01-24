<?php

namespace Controllers;

// REST
use \REST\Node;

// views
use \Views\{ApiResponse, Vps as VpsView, Jobs as JobsView, Order as OrderView};

// services
use \Host1Plus\Clients\{Vps as VpsClient, Jobs as JobsClient, Transport};

// utilities
use \Utilities\Header;

// enums
use \Host1Plus\Enums\{ServiceStates, Errors};

// exceptions
use \REST\Exceptions\{BadRequest, NotFound, InternalServerError};

final class Vps extends aController
{
    /* @var $vpsClient VpsClient */
    public static function getOne(Node $node)
    {
        $id      = (int)$node->getData(':id');
        $userId  = $node->getData('userId');
        $isAdmin = $node->getData('isAdmin');

        // prepare necessary objects
        list($hosting, $vpsId, $vpsClient) = self::_getHostingVpsIdClients($id, $userId, $isAdmin);

        // execute API request
        $vps = self::executeApiRequest($vpsClient, 'getOne', [$vpsId]);

        // prepare output
        return [ApiResponse::prepSuccess('', VpsView::prepArray($vps, $hosting)), Header::OK];
    }

    public static function update(Node $node, $calcOnly = false)
    {
        $id      = (int)$node->getData(':id');
        $userId  = $node->getData('userId');
        $isAdmin = $node->getData('isAdmin');
        $body    = $node->Body();

        // prepare necessary objects
        $hosting        = self::getHosting($id, $userId, 'h1pvps', $isAdmin, [ServiceStates::Active]);
        $userIdOwner    = ($isAdmin) ? $hosting->userid : $userId;
        $paymentGateway = self::selectPaymentGateway(isset($body['paymentGateway']) ? $body['paymentGateway'] : '', $hosting->paymentmethod, $userIdOwner);
        $configOpts     = self::getHostingConfigOptions($id);
        $params         = [];

        if (isset($body['hdd']))
            self::parseConfigOption($params, $body['hdd'], 'hdd', $configOpts, 'HDD', 10);

        if (isset($body['cpu']))
            self::parseConfigOption($params, $body['cpu'], 'cpu', $configOpts, 'CPU');

        if (isset($body['ram']))
            self::parseConfigOption($params, $body['ram'], 'ram', $configOpts, 'RAM', 256);

        if (isset($body['ip']))
            self::parseConfigOption($params, $body['ip'], 'ip', $configOpts, 'IP');

        if (isset($body['backups']))
            self::parseConfigOption($params, $body['backups'], 'backups', $configOpts, 'Backups');

        if (isset($body['bandwidth']))
            self::parseConfigOption($params, $body['bandwidth'], 'bandwidth', $configOpts, 'Bandwidth');

        if (isset($body['networkRate']))
            self::parseConfigOption($params, $body['networkRate'], 'networkRate', $configOpts, 'Network Rate');

        $paramCount = \count($params);
        if ($paramCount == 0)
            throw new BadRequest( sprintf(Errors::ParamsRequired, 1) );

        if (!$calcOnly)
            $_SESSION['h1pvps_upgradeParams'] = $params;

        $order = localAPI('UpgradeProduct', [
            'serviceid'     => $id,
            'type'          => 'configoptions',
            'paymentmethod' => $paymentGateway,
            'configoptions' => $params,
            'calconly'      => $calcOnly
        ]);

        if (!$calcOnly)
            unset($_SESSION['h1pvps_upgradeParams']);
        else
            $order['id'] = $hosting->id;

        if ($order['result'] == 'error')
            throw new InternalServerError($order['message']);

        // prepare individual option pricing
        $optionPricing = [];
        for ($i = 0; $i <= $paramCount; $i++)
        {
            $cname = "configname{$i}";
            $price = "price{$i}";
            if (!isset($order[$cname]))
                continue;

            $nsu = self::_getConfigOptNameStepUnit($order[$cname]);
            if ($nsu === false)
                continue;

            list($optionName, $step, $unit) = $nsu;

            $optionPricing[$optionName] = $order[$price]->toFull();
        }

        return [ApiResponse::prepSuccess('', OrderView::prepArray($order, $optionPricing, self::calcClientTax($userIdOwner, $order['subtotal']))), Header::OK];
    }

    public static function calcUpdatePricing(Node $node)
    {
        return self::update($node, true);
    }

    public static function getOptions(Node $node)
    {
        $productId = $node->getQueryParam('productId');
        if (\is_null($productId))
            throw new BadRequest( \sprintf(Errors::NotProvidedParam, 'productId') );

        $configOpts = self::getProductConfigOptions($productId, 'h1pvps');

        $c = \count($configOpts);
        if ($c == 0)
            throw new NotFound( \sprintf(Errors::NotFound, 'product', 'id', $productId) );

        $print = [];
        for($i = 0; $i < $c; $i++)
        {
            $nsu = self::_getConfigOptNameStepUnit($configOpts[$i]->name);
            if ($nsu === false)
                continue;

            list($optionName, $step, $unit) = $nsu;

            $configOpts[$i]->step = $step;
            $configOpts[$i]->unit = $unit;
            $configOpts[$i]->min *= $step;
            $configOpts[$i]->max *= $step;
            $configOpts[$i]->unlimited = ($configOpts[$i]->max == 0) ? true : false;

            $print[$optionName] = $configOpts[$i];
        }

        return [ApiResponse::prepSuccess('', $print), Header::OK];
    }

    public static function start(Node $node)
    {
        $id      = (int)$node->getData(':id');
        $userId  = $node->getData('userId');
        $isAdmin = $node->getData('isAdmin');

        // prepare necessary objects
        list($hosting, $vpsId, $vpsClient) = self::_getHostingVpsIdClients($id, $userId, $isAdmin, [ServiceStates::Active]);

        // execute API request
        $job = self::executeApiRequest($vpsClient, 'start', [$vpsId]);

        // prepare output
        return [ApiResponse::prepSuccess('', JobsView::prepArray($job, $hosting)), Header::OK];
    }

    public static function stop(Node $node)
    {
        $id      = (int)$node->getData(':id');
        $userId  = $node->getData('userId');
        $isAdmin = $node->getData('isAdmin');
        $body    = $node->Body();

        // prepare necessary objects
        list($hosting, $vpsId, $vpsClient) = self::_getHostingVpsIdClients($id, $userId, $isAdmin, [ServiceStates::Active]);

        // parse body
        $kill    = (isset($body['kill']) && \is_bool($body['kill']))       ? $body['kill']    : false;
        $noForce = (isset($body['noForce']) && \is_bool($body['noForce'])) ? $body['noForce'] : false;

        // execute API request
        $job = self::executeApiRequest($vpsClient, 'stop', [$vpsId, $kill, $noForce]);

        // prepare output
        return [ApiResponse::prepSuccess('', JobsView::prepArray($job, $hosting)), Header::OK];
    }

    public static function restart(Node $node)
    {
        $id      = (int)$node->getData(':id');
        $userId  = $node->getData('userId');
        $isAdmin = $node->getData('isAdmin');

        // prepare necessary objects
        list($hosting, $vpsId, $vpsClient) = self::_getHostingVpsIdClients($id, $userId, $isAdmin, [ServiceStates::Active]);

        // execute API request
        $job = self::executeApiRequest($vpsClient, 'restart', [$vpsId]);

        // prepare output
        return [ApiResponse::prepSuccess('', JobsView::prepArray($job, $hosting)), Header::OK];
    }

    public static function reinstall(Node $node)
    {
        $id      = (int)$node->getData(':id');
        $userId  = $node->getData('userId');
        $isAdmin = $node->getData('isAdmin');
        $body    = $node->Body();

        // prepare necessary objects
        list($hosting, $vpsId, $vpsClient) = self::_getHostingVpsIdClients($id, $userId, $isAdmin, [ServiceStates::Active]);

        // parse body
        if (!isset($body['osTemplate']))
            throw new BadRequest( \sprintf(Errors::NotProvidedParam, 'osTemplate') );

        $osTemplate = $body['osTemplate'];
        $rootPass   = (isset($body['rootpass']) && \is_string($body['rootpass'])) ? $body['rootpass'] : '';

        // execute API request
        $job = self::executeApiRequest($vpsClient, 'reinstall', [$vpsId, $osTemplate, $rootPass]);

        // prepare output
        return [ApiResponse::prepSuccess('', JobsView::prepArray($job, $hosting)), Header::OK];
    }

    public static function getTemplates(Node $node)
    {
        $id     = (int)$node->getData(':id');
        $userId = $node->getData('userId');

        list($hosting, $vpsId, $vpsClient) = self::_getHostingVpsIdClients($id, $userId);

        // execute API request
        $osTemplates = self::executeApiRequest($vpsClient, 'getOsTemplates');

        // prepare output
        return [ApiResponse::prepSuccess('', $osTemplates), Header::OK];
    }

    public static function changeHostname(Node $node)
    {
        $id      = (int)$node->getData(':id');
        $userId  = $node->getData('userId');
        $isAdmin = $node->getData('isAdmin');
        $body    = $node->Body();

        // prepare necessary objects
        list($hosting, $vpsId, $vpsClient) = self::_getHostingVpsIdClients($id, $userId, $isAdmin, [ServiceStates::Active]);

        // parse body
        if (!isset($body['hostname']))
            throw new BadRequest( \sprintf(Errors::NotProvidedParam, 'hostname') );

        // execute API request
        $job = self::executeApiRequest($vpsClient, 'changeHostname', [$vpsId, $body['hostname']]);

        // prepare output
        return [ApiResponse::prepSuccess('', JobsView::prepArray($job, $hosting)), Header::OK];
    }

    public static function resetPassword(Node $node)
    {
        $id      = (int)$node->getData(':id');
        $userId  = $node->getData('userId');
        $isAdmin = $node->getData('isAdmin');

        // prepare necessary objects
        list($hosting, $vpsId, $vpsClient) = self::_getHostingVpsIdClients($id, $userId, $isAdmin, [ServiceStates::Active]);

        // execute API request
        $job = self::executeApiRequest($vpsClient, 'resetPassword', [$vpsId]);

        // prepare output
        return [ApiResponse::prepSuccess('', JobsView::prepArray($job, $hosting)), Header::OK];
    }

    public static function getVnc(Node $node)
    {
        $id     = (int)$node->getData(':id');
        $userId = $node->getData('userId');

        // prepare necessary objects
        list($hosting, $vpsId, $vpsClient) = self::_getHostingVpsIdClients($id, $userId, false, [ServiceStates::Active]);

        // execute API request
        $vnc = self::executeApiRequest($vpsClient, 'getVnc', [$vpsId]);

        // prepare output
        return [ApiResponse::prepSuccess('', $vnc), Header::OK];
    }

    public static function startVnc(Node $node)
    {
        $id     = (int)$node->getData(':id');
        $userId = $node->getData('userId');

        // prepare necessary objects
        list($hosting, $vpsId, $vpsClient) = self::_getHostingVpsIdClients($id, $userId, false, [ServiceStates::Active]);

        // execute API request
        $job = self::executeApiRequest($vpsClient, 'startVnc', [$vpsId]);

        // prepare output
        return [ApiResponse::prepSuccess('', JobsView::prepArray($job, $hosting)), Header::OK];
    }

    public static function stopVnc(node $node)
    {
        $id     = (int)$node->getData(':id');
        $userId = $node->getData('userId');

        // prepare necessary objects
        list($hosting, $vpsId, $vpsClient) = self::_getHostingVpsIdClients($id, $userId, false, [ServiceStates::Active]);

        // execute API request
        $job = self::executeApiRequest($vpsClient, 'stopVnc', [$vpsId]);

        // prepare output
        return [ApiResponse::prepSuccess('', JobsView::prepArray($job, $hosting)), Header::OK];
    }

    public static function getBackups(Node $node)
    {
        $id          = (int)$node->getData(':id');
        $userId      = $node->getData('userId');
        $queryParams = $node->QueryParams();

        // prepare necessary objects
        list($hosting, $vpsId, $vpsClient) = self::_getHostingVpsIdClients($id, $userId, false, [ServiceStates::Active]);

        // parse query parameters
        $type  = (isset($queryParams['type']) && \is_string($queryParams['type']))   ? $queryParams['type']  : '';
        $state = (isset($queryParams['state']) && \is_string($queryParams['state'])) ? $queryParams['state'] : '';

        // execute API request
        $backups = self::executeApiRequest($vpsClient, 'getBackups', [$vpsId, $type, $state]);

        // prepare output - replace serviceId with $hosting->id
        $count = \count($backups);
        for ($i = 0; $i < $count; $i++)
        {
            $backups[$i]['serviceId'] = $hosting->id;
        }

        return [ApiResponse::prepSuccess('', $backups), Header::OK];
    }

    public static function getBackup(Node $node)
    {
        $id       = (int)$node->getData(':id');
        $backupId = $node->getData(':backupId');
        $userId   = $node->getData('userId');

        // prepare necessary objects
        list($hosting, $vpsId, $vpsClient) = self::_getHostingVpsIdClients($id, $userId, false, [ServiceStates::Active]);

        // execute API request
        $backup = self::executeApiRequest($vpsClient, 'getBackup', [$vpsId, $backupId]);

        // prepare output - replace serviceId with $hosting->id
        $backup['serviceId'] = $hosting->id;
        return [ApiResponse::prepSuccess('', $backup), Header::OK];
    }

    public static function createBackup(Node $node)
    {
        $id     = (int)$node->getData(':id');
        $userId = $node->getData('userId');
        $body   = $node->Body();

        // prepare necessary objects
        list($hosting, $vpsId, $vpsClient) = self::_getHostingVpsIdClients($id, $userId, false, [ServiceStates::Active]);

        // parse body parameters
        if (!isset($body['name']))
            throw new BadRequest( \sprintf(Errors::NotProvidedParam, 'name') );
        elseif (!\is_string($body['name']))
            throw new BadRequest( \sprintf(Errors::InvalidParam, 'name', 'string', $body['name']) );

        $type        = (isset($body['type']) && \is_string($body['type']))               ? $body['type']        : '';
        $description = (isset($body['description']) && \is_string($body['description'])) ? $body['description'] : '';

        // execute API request
        $job = self::executeApiRequest($vpsClient, 'createBackup', [$vpsId, $body['name'], $type, $description]);

        // prepare output
        return [ApiResponse::prepSuccess('', JobsView::prepArray($job, $hosting)), Header::OK];
    }

    public static function restoreBackup(Node $node)
    {
        $id       = (int)$node->getData(':id');
        $backupId = $node->getData(':backupId');
        $userId   = $node->getData('userId');

        // prepare necessary objects
        list($hosting, $vpsId, $vpsClient) = self::_getHostingVpsIdClients($id, $userId, false, [ServiceStates::Active]);

        // execute API request
        $job = self::executeApiRequest($vpsClient, 'restoreBackup', [$vpsId, $backupId]);

        // prepare output
        return [ApiResponse::prepSuccess('', JobsView::prepArray($job, $hosting)), Header::OK];
    }

    public static function deleteBackup(Node $node)
    {
        $id       = (int)$node->getData(':id');
        $backupId = $node->getData(':backupId');
        $userId   = $node->getData('userId');

        // prepare necessary objects
        list($hosting, $vpsId, $vpsClient) = self::_getHostingVpsIdClients($id, $userId, false, [ServiceStates::Active]);

        // execute API request
        $job = self::executeApiRequest($vpsClient, 'deleteBackup', [$vpsId, $backupId]);

        // prepare output
        return [ApiResponse::prepSuccess('', JobsView::prepArray($job, $hosting)), Header::OK];
    }

    public static function getIPv4(Node $node)
    {
        $id     = (int)$node->getData(':id');
        $userId = $node->getData('userId');

        // prepare necessary objects
        list($hosting, $vpsId, $vpsClient) = self::_getHostingVpsIdClients($id, $userId, false, [ServiceStates::Active]);

        // execute API request
        $ips = self::executeApiRequest($vpsClient, 'getIPv4', [$vpsId]);

        // prepare output
        return [ApiResponse::prepSuccess('', $ips), Header::OK];
    }

    public static function getIPv6(Node $node)
    {
        $id     = (int)$node->getData(':id');
        $userId = $node->getData('userId');

        // prepare necessary objects
        list($hosting, $vpsId, $vpsClient) = self::_getHostingVpsIdClients($id, $userId, false, [ServiceStates::Active]);

        // execute API request
        $ips = self::executeApiRequest($vpsClient, 'getIPv6', [$vpsId]);

        // prepare output
        return [ApiResponse::prepSuccess('', $ips), Header::OK];
    }

    public static function addIPv6(Node $node)
    {
        $id     = (int)$node->getData(':id');
        $userId = $node->getData('userId');
        $body   = $node->Body();

        // prepare necessary objects
        list($hosting, $vpsId, $vpsClient) = self::_getHostingVpsIdClients($id, $userId, false, [ServiceStates::Active]);

        // parse request body
        $amount = (isset($body['amount']) && \is_int($body['amount'])) ? $body['amount'] : 1;

        // execute API request
        $job = self::executeApiRequest($vpsClient, 'addIPv6', [$vpsId, $amount]);

        // prepare output
        return [ApiResponse::prepSuccess('', JobsView::prepArray($job, $hosting)), Header::OK];
    }

    public static function deleteIPv6(Node $node)
    {
        $id     = (int)$node->getData(':id');
        $userId = $node->getData('userId');
        $body   = $node->Body();

        // prepare necessary objects
        list($hosting, $vpsId, $vpsClient) = self::_getHostingVpsIdClients($id, $userId, false, [ServiceStates::Active]);

        // parse request body
        if (!isset($body['notations']))
            throw new BadRequest( \sprintf(Errors::NotProvidedParam, 'notations') );
        elseif (!\is_array($body['notations']))
            throw new BadRequest( \sprintf(Errors::InvalidParam, 'notations', 'array', $body['notations']) );

        // execute API request
        $job = self::executeApiRequest($vpsClient, 'deleteIPv6', [$vpsId, $body['notations']]);

        // prepare output
        return [ApiResponse::prepSuccess('', JobsView::prepArray($job, $hosting)), Header::OK];
    }

    public static function changePrimaryIp(Node $node)
    {
        $id     = (int)$node->getData(':id');
        $userId = $node->getData('userId');
        $body   = $node->Body();

        // prepare necessary objects
        list($hosting, $vpsId, $vpsClient) = self::_getHostingVpsIdClients($id, $userId, false, [ServiceStates::Active]);

        // parse request body
        if (!isset($body['ip']))
            throw new BadRequest( \sprintf(Errors::NotProvidedParam, 'ip') );
        elseif (!\is_string($body['ip']))
            throw new BadRequest( \sprintf(Errors::InvalidParam, 'ip', 'string', $body['ip']) );

        // execute API request
        $job = self::executeApiRequest($vpsClient, 'changePrimaryIp', [$vpsId, $body['ip']]);

        // prepare output
        return [ApiResponse::prepSuccess('', JobsView::prepArray($job, $hosting)), Header::OK];
    }

    public static function getStatistics(Node $node)
    {
        $id          = (int)$node->getData(':id');
        $userId      = $node->getData('userId');
        $queryParams = $node->QueryParams();

        // prepare necessary objects
        list($hosting, $vpsId, $vpsClient) = self::_getHostingVpsIdClients($id, $userId, false, [ServiceStates::Active]);

        $from      = ( isset($queryParams['from']) )      ? (int)$queryParams['from'] : -1;
        $to        = ( isset($queryParams['to']) )        ? (int)$queryParams['to']   : -1;
        $retention = ( isset($queryParams['retention']) ) ? $queryParams['retention'] : '';

        // execute API request
        $statistics = self::executeApiRequest($vpsClient, 'getStatistics', [$vpsId, $from, $to, $retention]);

        // prepare output
        return [ApiResponse::prepSuccess('', $statistics), Header::OK];
    }

    public static function getSchedules(Node $node)
    {
        $id     = (int)$node->getData(':id');
        $userId = $node->getData('userId');

        // prepare necessary objects
        list($hosting, $vpsId, $vpsClient) = self::_getHostingVpsIdClients($id, $userId, false, [ServiceStates::Active]);

        // execute API request
        $schedules = self::executeApiRequest($vpsClient, 'getSchedules', [$vpsId, $node->QueryParams()]);

        // prepare output
        // replace $schedules->serviceId with $hosting->id
        $count = \count($schedules);
        for ($i = 0; $i < $count; $i++)
        {
            $schedules[$i]['serviceId'] = $hosting->id;
        }

        return [ApiResponse::prepSuccess('', $schedules), Header::OK];
    }

    public static function getSchedule(Node $node)
    {
        $id         = (int)$node->getData(':id');
        $scheduleId = $node->getData(':scheduleId');
        $userId     = $node->getData('userId');

        // prepare necessary objects
        list($hosting, $vpsId, $vpsClient) = self::_getHostingVpsIdClients($id, $userId, false, [ServiceStates::Active]);

        // execute API request
        $schedule = self::executeApiRequest($vpsClient, 'getSchedule', [$vpsId, $scheduleId]);

        // prepare output
        // replace $schedules->serviceId with $hosting->id
        $schedule['serviceId'] = $hosting->id;
        return [ApiResponse::prepSuccess('', $schedule), Header::OK];
    }

    public static function createSchedule(Node $node)
    {
        $id     = (int)$node->getData(':id');
        $userId = $node->getData('userId');
        $body   = $node->Body();

        // prepare necessary objects
        list($hosting, $vpsId, $vpsClient) = self::_getHostingVpsIdClients($id, $userId, false, [ServiceStates::Active]);

        if (!isset($body['name']))
            throw new BadRequest( \sprintf(Errors::NotProvidedParam, 'name') );
        elseif (!\is_string($body['name']))
            throw new BadRequest( \sprintf(Errors::InvalidParam, 'name', 'string', $body['name']) );

        if (!isset($body['interval']))
            throw new BadRequest( \sprintf(Errors::NotProvidedParam, 'interval') );
        elseif (!\is_string($body['interval']))
            throw new BadRequest( \sprintf(Errors::InvalidParam, 'interval', 'string', $body['interval']) );

        if (!isset($body['executeAfter']))
            throw new BadRequest( \sprintf(Errors::NotProvidedParam, 'executeAfter') );
        elseif (!\is_integer($body['executeAfter']))
            throw new BadRequest( \sprintf(Errors::InvalidParam, 'executeAfter', 'integer', $body['executeAfter']) );

        $copyAmount = (isset($body['copyAmount']) && \is_int($body['copyAmount'])) ? $body['copyAmount'] : 1;
        $disabled   = (isset($body['disabled']) && \is_bool($body['disabled']))    ? $body['copyAmount'] : false;

        // execute API request
        $schedule = self::executeApiRequest($vpsClient, 'createSchedule', [$vpsId, $body['name'], $body['interval'], $body['executeAfter'], $copyAmount, $disabled]);

        // prepare output
        // replace $schedule->serviceId with $hosting->id
        $schedule['serviceId'] = $hosting->id;
        return [ApiResponse::prepSuccess('', $schedule), Header::OK];
    }

    public static function updateSchedule(Node $node)
    {
        $id         = (int)$node->getData(':id');
        $scheduleId = $node->getData(':scheduleId');
        $userId     = $node->getData('userId');

        // prepare necessary objects
        list($hosting, $vpsId, $vpsClient) = self::_getHostingVpsIdClients($id, $userId, false, [ServiceStates::Active]);

        // execute API request
        $schedule = self::executeApiRequest($vpsClient, 'updateSchedule', [$vpsId, $scheduleId, $node->Body()]);

        // prepare output
        // replace $schedule->serviceId with $hosting->id
        $schedule['serviceId'] = $hosting->id;
        return [ApiResponse::prepSuccess('', $schedule), Header::OK];
    }

    public static function deleteSchedule(Node $node)
    {
        $id         = (int)$node->getData(':id');
        $scheduleId = $node->getData(':scheduleId');
        $userId     = $node->getData('userId');

        // prepare necessary objects
        list($hosting, $vpsId, $vpsClient) = self::_getHostingVpsIdClients($id, $userId, false, [ServiceStates::Active]);

        // execute API request
        self::executeApiRequest($vpsClient, 'deleteSchedule', [$vpsId, $scheduleId]);

        return [null, Header::NoContent];
    }

    public static function getJobs(Node $node)
    {
        $id          = (int)$node->getData(':id');
        $userId      = $node->getData('userId');
        $isAdmin     = $node->getData('isAdmin');
        $queryParams = $node->QueryParams();

        // prepare necessary objects
        list($hosting, $apiUrl, $apiKey, $vpsId) = self::getHostingApiUrlKeyRemoteId($id, $userId, 'h1pvps', $isAdmin, [ServiceStates::Active]);

        // parse query parameters
        // override certain parameters
        $queryParams['serviceId'] = $vpsId;
        $queryParams['module']    = 'vzrogueone';
        $queryParams['instance']  = 'instance';

        // prpeare clients
        $jobsClient = new JobsClient(new Transport($apiUrl, $apiKey));

        // execute API request
        $jobs = self::executeApiRequest($jobsClient, 'get', [$queryParams]);

        // prepare output
        $print = [];
        foreach ($jobs as $job)
        {
            $print[] = JobsView::prepArray($job, $hosting);
        }

        return [ApiResponse::prepSuccess('', $print), Header::OK];
    }

    public static function getJob(Node $node)
    {
        $id      = (int)$node->getData(':id');
        $jobId   = (int)$node->getData(':jobId');
        $userId  = $node->getData('userId');
        $isAdmin = $node->getData('isAdmin');

        // prepare necessary objects
        list($hosting, $apiUrl, $apiKey, $vpsId) = self::getHostingApiUrlKeyRemoteId($id, $userId, 'h1pvps', $isAdmin);

        // prepare filtering parameters
        $params = ['serviceId' => $vpsId, 'module' => 'vzrogueone', 'instance' => 'instance'];

        // prpeare clients
        $jobsClient = new JobsClient(new Transport($apiUrl, $apiKey));

        // execute API request
        $job = self::executeApiRequest($jobsClient, 'getOne', [$jobId, $params]);

        // prepare output
        return [ApiResponse::prepSuccess('', JobsView::prepArray($job, $hosting)), Header::OK];
    }

    // private functions
    private static function _getHostingVpsIdClients(int $id, int $userId, bool $isAdmin = false, array $states = [])
    {
        $data = self::getHostingApiUrlKeyRemoteId($id, $userId, 'h1pvps', $isAdmin, $states);

        return [$data[0], $data[3], new VpsClient(new Transport($data[1], $data[2]))];
    }

    private static function _getConfigOptNameStepUnit($name)
    {
        switch ($name)
        {
            case 'CPU':
                return ['cpu', 1, 'core'];
            case 'RAM':
                return ['ram', 256, 'MB'];
            case 'HDD':
                return ['hdd', 10, 'GB'];
            case 'Bandwidth':
                return ['bandwidth', 1, 'TB'];
            case 'IP':
                return ['ip', 1, 'IP'];
            case 'Network Rate':
                return ['networkRate', 1, 'mbps'];
            case 'Backups':
                return ['backups', 1, 'backup'];
            case 'Tun':
                return ['tun', 1, 'enabled'];
            default:
                return false;
        }
    }
}