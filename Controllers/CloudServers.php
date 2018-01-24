<?php

namespace Controllers;

// REST
use \REST\Node;

// views
use \Views\{ApiResponse, CloudServers as CloudView, Jobs as JobView, Volumes as VolumeView, Order as OrderView};

// services
use \WHMCS\Database\Capsule;
use \Host1Plus\Clients\{CloudServers as CloudClient, Jobs as JobsClient, Transport};

// utilities
use \Utilities\Header;

// enums
use \Host1Plus\Enums\{ServiceStates, Errors};

// exceptions
use \REST\Exceptions\{BadRequest, NotFound, InternalServerError, NotImplemented};
use \Illuminate\Database\QueryException;

final class CloudServers extends aController
{
    /* @var $cloudClient CloudClient */

    public static function getOne(Node $node)
    {
        $id      = (int)$node->getData(':id');
        $userId  = $node->getData('userId');
        $isAdmin = $node->getData('isAdmin');

        // prepare necessary objects
        list($hosting, $serverId, $cloudClient) = self::_getHostingCloudIdClients($id, $userId, $isAdmin);

        // execute API request
        $cloudServer = self::executeApiRequest($cloudClient, 'getOne', [$serverId]);

        return [ApiResponse::prepSuccess('', CloudView::prepArray($cloudServer, $hosting)), Header::OK];
    }

    public static function update(Node $node, $calcOnly = false)
    {
        $id      = (int)$node->getData(':id');
        $userId  = $node->getData('userId');
        $isAdmin = $node->getData('isAdmin');
        $body    = $node->Body();

        // prepare necessary objects
        $hosting        = self::getHosting($id, $userId, 'h1pcloud', $isAdmin, [ServiceStates::Active]);
        $userIdOwner    = ($isAdmin) ? $hosting->userid : $userId;
        $paymentGateway = self::selectPaymentGateway(isset($body['paymentGateway']) ? $body['paymentGateway'] : '', $hosting->paymentmethod, $userIdOwner);
        $configOpts     = self::getHostingConfigOptions($id);
        $params         = [];

        // validate provided config options
        // check if no downgrade is being performed
        if (isset($body['cpu']))
            self::parseConfigOption($params, $body['cpu'], 'cpu', $configOpts, 'CPU');

        if (isset($body['ram']))
            self::parseConfigOption($params, $body['ram'], 'ram', $configOpts, 'RAM', 512);

        if (isset($body['hdd']))
            self::parseConfigOption($params, $body['hdd'], 'hdd', $configOpts, 'HDD', 10);

        if (isset($body['bandwidth']))
            self::parseConfigOption($params, $body['bandwidth'], 'bandwidth', $configOpts, 'Bandwidth');

        if (isset($body['ip']))
            self::parseConfigOption($params, $body['ip'], 'ip', $configOpts, 'IP');

        if (isset($body['backups']))
            self::parseConfigOption($params, $body['backups'], 'backups', $configOpts, 'Backups');

        if (isset($body['additionalDisks']))
        {
            if (!\is_array($body['additionalDisks']))
                throw new BadRequest( \sprintf(Errors::InvalidParameter, 'additionalDisks', 'array', \gettype($body['additionalDisks'])) );

            foreach ($body['additionalDisks'] as $disk)
            {
                self::_validateAdditionalDisk($disk);

                $confKey = "Additional Disk {$disk['key']}";
                $value   = $disk['value'] / 10;

                if ($configOpts[$confKey]->min > $value || $configOpts[$confKey]->max < $value)
                {
                    $min = $configOpts[$confKey]->min * 10;
                    $max = $configOpts[$confKey]->max * 10;
                    throw new BadRequest( \sprintf(Errors::InvalidParameter, $confKey, "min: {$min}, max: {$max}", $disk['value']) );
                }

                if ($value == 0 || $value > $configOpts[$confKey]->qty)
                    $params[$configOpts[$confKey]->id] = $value;
            }
        }

        $paramCount = \count($params);
        if ($paramCount == 0)
            throw new BadRequest( sprintf(Errors::ParamsRequired, 1) );

        if (!$calcOnly)
            $_SESSION['h1pcloud_upgradeParams'] = $params;

        $order = localAPI('UpgradeProduct', [
            'serviceid'     => $id,
            'type'          => 'configoptions',
            'paymentmethod' => $paymentGateway,
            'configoptions' => $params,
            'calconly'      => $calcOnly
        ]);

        // prepare individual option pricing
        $optionPricing = [];
        for ($i = 1; $i <= $paramCount; $i++)
        {
            $cname = "configname{$i}";
            $price = "price{$i}";
            if (!isset($order[$cname]))
                continue;

            $nsu = self::_getConfigOptNameStepUnit($order[$cname]);
            if ($nsu === false)
                continue;

            list($optionName, $step, $unit) = $nsu;
            if ($optionName == 'additionalDisks')
            {
                $optionPricing[$optionName][] = ['key' => (int)\substr($order[$cname], -1), 'value' => $order[$price]->toFull()];
                continue;
            }

            $optionPricing[$optionName] = $order[$price]->toFull();
        }

        if (!$calcOnly)
            unset($_SESSION['h1pcloud_upgradeParams']);
        else
            $order['id'] = $hosting->id;

        if ($order['result'] == 'error')
            throw new InternalServerError($order['message']);

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

        $configOpts = self::getProductConfigOptions($productId, 'h1pcloud');

        $c = \count($configOpts);
        if ($c == 0)
            throw new NotFound( \sprintf(Errors::NotFound, 'product', 'id', $productId) );

        $data = [];
        for ($i = 0; $i < $c; $i++)
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

            if ($optionName == 'additionalDisks')
                $data[$optionName][] = $configOpts[$i];
            else
                $data[$optionName] = $configOpts[$i];
        }

        return [ApiResponse::prepSuccess('', $data), Header::OK];
    }

    public static function start(Node $node)
    {
        $id      = (int)$node->getData(':id');
        $userId  = $node->getData('userId');
        $isAdmin = $node->getData('isAdmin');

        // prepare necessary objects
        list($hosting, $serverId, $cloudClient) = self::_getHostingCloudIdClients($id, $userId, $isAdmin, [ServiceStates::Active]);

        // execute API request
        $job = self::executeApiRequest($cloudClient, 'start', [$serverId]);

        return [ApiResponse::prepSuccess('', JobView::prepArray($job, $hosting)), Header::OK];
    }

    public static function stop(Node $node)
    {
        $id      = (int)$node->getData(':id');
        $userId  = $node->getData('userId');
        $isAdmin = $node->getData('isAdmin');
        $body    = $node->Body();

        // prepare necessary objects
        list($hosting, $serverId, $cloudClient) = self::_getHostingCloudIdClients($id, $userId, $isAdmin, [ServiceStates::Active]);

        // parse body
        $forced = (isset($body['forced']) && \is_bool($body['forced'])) ? $body['forced'] : false;

        // execute API request
        $job = self::executeApiRequest($cloudClient, 'stop', [$serverId, $forced]);

        return [ApiResponse::prepSuccess('', JobView::prepArray($job, $hosting)), Header::OK];
    }

    public static function reboot(Node $node)
    {
        $id      = (int)$node->getData(':id');
        $userId  = $node->getData('userId');
        $isAdmin = $node->getData('isAdmin');
        $body    = $node->Body();

        // prepare necessary objects
        list($hosting, $serverId, $cloudClient) = self::_getHostingCloudIdClients($id, $userId, $isAdmin, [ServiceStates::Active]);

        // parse body
        $forced = (isset($body['forced']) && \is_bool($body['forced'])) ? $body['forced'] : false;

        // execute API request
        $job = self::executeApiRequest($cloudClient, 'reboot', [$serverId, $forced]);

        return [ApiResponse::prepSuccess('', JobView::prepArray($job, $hosting)), Header::OK];
    }

    public static function reinstall(Node $node)
    {
        $id      = (int)$node->getData(':id');
        $userId  = $node->getData('userId');
        $isAdmin = $node->getData('isAdmin');
        $body    = $node->Body();

        // prepare necessary objects
        list($hosting, $serverId, $cloudClient) = self::_getHostingCloudIdClients($id, $userId, $isAdmin, [ServiceStates::Active]);

        // parse body
        if (!isset($body['templateId']))
            throw new BadRequest( \sprintf(Errors::NotProvidedParam, 'templateId') );

        // execute API request
        $job = self::executeApiRequest($cloudClient, 'reinstall', [$serverId, $body['templateId']]);

        return [ApiResponse::prepSuccess('', JobView::prepArray($job, $hosting)), Header::OK];
    }

    public static function resetRootPass(Node $node)
    {
        $id      = (int)$node->getData(':id');
        $userId  = $node->getData('userId');
        $isAdmin = $node->getData('isAdmin');

        // prepare necessary objects
        list($hosting, $serverId, $cloudClient) = self::_getHostingCloudIdClients($id, $userId, $isAdmin, [ServiceStates::Active]);

        // execute API request
        $job = self::executeApiRequest($cloudClient, 'resetRootPass', [$serverId]);

        return [ApiResponse::prepSuccess('', JobView::prepArray($job, $hosting)), Header::OK];
    }

    public static function changeNames(Node $node)
    {
        $id      = (int)$node->getData(':id');
        $userId  = $node->getData('userId');
        $isAdmin = $node->getData('isAdmin');

        // prepare necessary objects
        list($hosting, $serverId, $cloudClient) = self::_getHostingCloudIdClients($id, $userId, $isAdmin, [ServiceStates::Active]);

        // execute API request
        $cloudServer = self::executeApiRequest($cloudClient, 'changeNames', [$serverId, $node->Body()]);

        return [ApiResponse::prepSuccess('', CloudView::prepArray($cloudServer, $hosting)), Header::OK];
    }

    public static function restore(Node $node)
    {
        $id     = (int)$node->getData(':id');
        $userId = $node->getData('userId');
        $body   = $node->Body();

        // prepare necessary objects
        list($hosting, $serverId, $cloudClient) = self::_getHostingCloudIdClients($id, $userId, false, [ServiceStates::Active]);

        // parse body
        if (!isset($body['backupId']))
            throw new BadRequest( \sprintf(Errors::NotProvidedParam, 'backupId') );
        elseif (!\is_string($body['backupId']))
            throw new BadRequest( \sprintf(Errors::InvalidParam, 'backupId', 'string', $body['backupId']) );

        if (!isset($body['volumeId']))
            throw new BadRequest( \sprintf(Errors::NotProvidedParam, 'volumeId') );
        elseif (!\is_string($body['volumeId']))
            throw new BadRequest( \sprintf(Errors::InvalidParam, 'volumeId', 'string', $body['volumeId']) );

        // execute API request
        $job = self::executeApiRequest($cloudClient, 'restore', [$serverId, $body['backupId'], $body['volumeId']]);

        return [ApiResponse::prepSuccess('', JobView::prepArray($job, $hosting)), Header::OK];
    }

    public static function rescue(Node $node)
    {
        $id     = (int)$node->getData(':id');
        $userId = $node->getData('userId');
        $body   = $node->Body();

        // prepare necessary objects
        list($hosting, $serverId, $cloudClient) = self::_getHostingCloudIdClients($id, $userId, false, [ServiceStates::Active]);

        // parse body
        $reboot = (isset($body['reboot']) && \is_bool($body['reboot'])) ? $body['reboot'] : false;
        $forced = (isset($body['forced']) && \is_bool($body['forced'])) ? $body['forced'] : false;

        // execute API request
        $job = self::executeApiRequest($cloudClient, 'rescue', [$serverId, $reboot, $forced]);

        return [ApiResponse::prepSuccess('', JobView::prepArray($job, $hosting)), Header::OK];
    }

    public static function unrescue(Node $node)
    {
        $id     = (int)$node->getData(':id');
        $userId = $node->getData('userId');
        $body   = $node->Body();

        // prepare necessary objects
        list($hosting, $serverId, $cloudClient) = self::_getHostingCloudIdClients($id, $userId, false, [ServiceStates::Active]);

        // parse body
        $reboot = (isset($body['reboot']) && \is_bool($body['reboot'])) ? $body['reboot'] : false;
        $forced = (isset($body['forced']) && \is_bool($body['forced'])) ? $body['forced'] : false;

        // execute API request
        $job = self::executeApiRequest($cloudClient, 'unrescue', [$serverId, $reboot, $forced]);

        return [ApiResponse::prepSuccess('', JobView::prepArray($job, $hosting)), Header::OK];
    }

    public static function createVnc(Node $node)
    {
        $id     = (int)$node->getData(':id');
        $userId = $node->getData('userId');

        // prepare necessary objects
        list($hosting, $serverId, $cloudClient) = self::_getHostingCloudIdClients($id, $userId, false, [ServiceStates::Active]);

        // execute API request
        $vnc = self::executeApiRequest($cloudClient, 'createVnc', [$serverId]);

        return [ApiResponse::prepSuccess('', $vnc), Header::OK];
    }

    public static function addSshKey(Node $node)
    {

    }

    public static function getIsos(Node $node)
    {
        $id     = (int)$node->getData(':id');
        $userId = $node->getData('userId');

        // prepare necessary objects
        list($hosting, $serverId, $cloudClient) = self::_getHostingCloudIdClients($id, $userId, false, [ServiceStates::Active]);

        // execute API request
        $isos = self::executeApiRequest($cloudClient, 'getIsos', [$serverId, [['key' => 'userId', 'value' => (string)$userId]]]);

        return [ApiResponse::prepSuccess('', $isos), Header::OK];
    }

    public static function getIso(Node $node)
    {
        $id     = (int)$node->getData(':id');
        $userId = $node->getData('userId');
        $isoId  = $node->getData(':isoId');

        // prepare necessary objects
        list($hosting, $serverId, $cloudClient) = self::_getHostingCloudIdClients($id, $userId, false, [ServiceStates::Active]);

        // confirm that tag marking this iso as local users iso exists
        $tags = self::executeApiRequest($cloudClient, 'getTags', [['key' => 'userId', 'value' => (string)$userId, 'resourceId' => $isoId, 'resourceType' => 'ISO']]);
        if (\count($tags) != 1)
            throw new NotFound( \sprintf(Errors::NotFound, 'iso', 'id', $isoId) );

        // execute API request
        $iso = self::executeApiRequest($cloudClient, 'getIso', [$serverId, $isoId]);

        return [ApiResponse::prepSuccess('', $iso), Header::OK];
    }

    public static function uploadIso(Node $node)
    {
        $id     = (int)$node->getData(':id');
        $userId = $node->getData('userId');
        $body   = $node->Body();

        // prepare necessary objects
        list($hosting, $serverId, $cloudClient) = self::_getHostingCloudIdClients($id, $userId, false, [ServiceStates::Active]);

        // parse body
        if (!isset($body['name']))
            throw new BadRequest( \sprintf(Errors::NotProvidedParam, 'name') );
        elseif (!\is_string($body['name']))
            throw new BadRequest( \sprintf(Errors::InvalidParam, 'name', 'string', $body['name']) );

        if (!isset($body['url']))
            throw new BadRequest( \sprintf(Errors::NotProvidedParam, 'url') );
        elseif (!\is_string($body['url']))
            throw new BadRequest( \sprintf(Errors::InvalidParam, 'url', 'string', $body['url']) );

        $bootable = (isset($body['bootable']) && \is_bool($body['bootable']))   ? $body['bootable'] : false;
        $osTypeId = (isset($body['osTypeId']) && \is_string($body['osTypeId'])) ? $body['osTypeId'] : '';

        // check that current iso upload limit is not over capacity
        $isoUsage    = self::_calcIsoUsage($cloudClient, $serverId, $userId);
        $uploadLimit = self::_getUserIsoUploadLimti($userId);
        $urlSize     = self::_getUrlSize($body['url']);

        if ($uploadLimit <= ($isoUsage + $urlSize))
            throw new BadRequest( \sprintf(Errors::Limit, 'Cloud ISO Upload', $uploadLimit) );

        // execute API request
        $iso = self::executeApiRequest($cloudClient, 'uploadIso', [$serverId, $body['name'], $body['url'], $bootable, $osTypeId]);

        // tag created iso with local userId
        self::executeApiRequest($cloudClient, 'createTags', [$iso['id'], 'ISO', [['key' => 'userId', 'value' => $userId]]]);

        return [ApiResponse::prepSuccess('', $iso), Header::OK];
    }

    public static function attachIso(Node $node)
    {
        $id     = (int)$node->getData(':id');
        $userId = $node->getData('userId');
        $body   = $node->Body();

        // prepare necessary objects
        list($hosting, $serverId, $cloudClient) = self::_getHostingCloudIdClients($id, $userId, false, [ServiceStates::Active]);

        // parse body
        if (!isset($body['isoId']))
            throw new BadRequest( \sprintf(Errors::NotProvidedParam, 'isoId') );
        elseif (!\is_string($body['isoId']))
            throw new BadRequest( \sprintf(Errors::InvalidParam, 'isoId', 'string', $body['isoId']) );

        $reboot = (isset($body['reboot']) && \is_bool($body['reboot'])) ? $body['reboot'] : false;
        $forced = (isset($body['forced']) && \is_bool($body['forced'])) ? $body['forced'] : false;

        // confirm that tag marking this iso as local users iso exists
        $tags = self::executeApiRequest($cloudClient, 'getTags', [['key' => 'userId', 'value' => (string)$userId, 'resourceId' => $body['isoId'], 'resourceType' => 'ISO']]);
        if (\count($tags) != 1)
            throw new NotFound( \sprintf(Errors::NotFound, 'iso', 'id', $body['isoId']) );

        // execute API request
        $job = self::executeApiRequest($cloudClient, 'attachIso', [$serverId, $body['isoId'], $reboot, $forced]);

        return [ApiResponse::prepSuccess('', JobView::prepArray($job, $hosting)), Header::OK];
    }

    public static function detachIso(Node $node)
    {
        $id     = (int)$node->getData(':id');
        $userId = $node->getData('userId');
        $body   = $node->Body();

        // prepare necessary objects
        list($hosting, $serverId, $cloudClient) = self::_getHostingCloudIdClients($id, $userId, false, [ServiceStates::Active]);

        // parse body
        $reboot = (isset($body['reboot']) && \is_bool($body['reboot'])) ? $body['reboot'] : false;
        $forced = (isset($body['forced']) && \is_bool($body['forced'])) ? $body['forced'] : false;

        // execute API request
        $job = self::executeApiRequest($cloudClient, 'detachIso', [$serverId, $reboot, $forced]);

        return [ApiResponse::prepSuccess('', JobView::prepArray($job, $hosting)), Header::OK];
    }

    public static function installIso(Node $node)
    {
        $id     = (int)$node->getData(':id');
        $userId = $node->getData('userId');
        $body   = $node->Body();

        // prepare necessary objects
        list($hosting, $serverId, $cloudClient) = self::_getHostingCloudIdClients($id, $userId, false, [ServiceStates::Active]);

        // parse body
        if (!isset($body['isoId']))
            throw new BadRequest( \sprintf(Errors::NotProvidedParam, 'isoId') );
        elseif (!\is_string($body['isoId']))
            throw new BadRequest( \sprintf(Errors::InvalidParam, 'isoId', 'string', $body['isoId']) );

        // confirm that tag marking this iso as local users iso exists
        $tags = self::executeApiRequest($cloudClient, 'getTags', [['key' => 'userId', 'value' => (string)$userId, 'resourceId' => $body['isoId'], 'resourceType' => 'ISO']]);
        if (\count($tags) != 1)
            throw new NotFound( \sprintf(Errors::NotFound, 'iso', 'id', $body['isoId']) );

        // execute API request
        $job = self::executeApiRequest($cloudClient, 'installIso', [$serverId, $body['isoId']]);

        return [ApiResponse::prepSuccess('', JobView::prepArray($job, $hosting)), Header::OK];
    }

    public static function deleteIso(Node $node)
    {
        $id     = (int)$node->getData(':id');
        $userId = $node->getData('userId');
        $isoId  = $node->getData(':isoId');

        // prepare necessary objects
        list($hosting, $serverId, $cloudClient) = self::_getHostingCloudIdClients($id, $userId, false, [ServiceStates::Active]);

        // confirm that tag marking this iso as local users iso exists
        $tags = self::executeApiRequest($cloudClient, 'getTags', [['key' => 'userId', 'value' => (string)$userId, 'resourceId' => $isoId, 'resourceType' => 'ISO']]);
        if (\count($tags) != 1)
            throw new NotFound( \sprintf(Errors::NotFound, 'iso', 'id', $isoId) );

        // execute API request
        $job = self::executeApiRequest($cloudClient, 'deleteIso', [$serverId, $isoId]);

        return [ApiResponse::prepSuccess('', JobView::prepArray($job, $hosting)), Header::OK];
    }

    public static function getLimits(Node $node)
    {
        $id     = (int)$node->getData(':id');
        $userId = $node->getData('userId');

        // prepare necessary objects
        list($hosting, $serverId, $cloudClient) = self::_getHostingCloudIdClients($id, $userId, false, [ServiceStates::Active]);

        // execute API request
        $limits = self::executeApiRequest($cloudClient, 'getLimits', [$serverId]);

        return [ApiResponse::prepSuccess('', $limits), Header::OK];
    }

    public static function getOsTypes(Node $node)
    {
        $id     = (int)$node->getData(':id');
        $userId = $node->getData('userId');

        // prepare necessary objects
        list($hosting, $serverId, $cloudClient) = self::_getHostingCloudIdClients($id, $userId, false, [ServiceStates::Active]);

        // execute API request
        $osTypes = self::executeApiRequest($cloudClient, 'getOsTypes', [$serverId, $node->QueryParams()]);

        return [ApiResponse::prepSuccess('', $osTypes), Header::OK];
    }

    public static function getOsType(Node $node)
    {
        $id       = (int)$node->getData(':id');
        $userId   = $node->getData('userId');
        $osTypeId = $node->getData(':osTypeId');

        // prepare necessary objects
        list($hosting, $serverId, $cloudClient) = self::_getHostingCloudIdClients($id, $userId, false, [ServiceStates::Active]);

        // execute API request
        $osType = self::executeApiRequest($cloudClient, 'getOsType', [$serverId, $osTypeId]);

        return [ApiResponse::prepSuccess('', $osType), Header::OK];
    }

    public static function getOsCategories(Node $node)
    {
        $id     = (int)$node->getData(':id');
        $userId = $node->getData('userId');

        // prepare necessary objects
        list($hosting, $serverId, $cloudClient) = self::_getHostingCloudIdClients($id, $userId, false, [ServiceStates::Active]);

        // execute API request
        $osCategories = self::executeApiRequest($cloudClient, 'getOsCategories', [$serverId]);

        return [ApiResponse::prepSuccess('', $osCategories), Header::OK];
    }

    public static function getOsCategory(Node $node)
    {
        $id           = (int)$node->getData(':id');
        $userId       = $node->getData('userId');
        $osCategoryId = $node->getData(':osCategoryId');

        // prepare necessary objects
        list($hosting, $serverId, $cloudClient) = self::_getHostingCloudIdClients($id, $userId, false, [ServiceStates::Active]);

        // execute API request
        $osCategory = self::executeApiRequest($cloudClient, 'getOsCategory', [$serverId, $osCategoryId]);

        return [ApiResponse::prepSuccess('', $osCategory), Header::OK];
    }

    public static function getTemplatesAvailable(Node $node)
    {
        $id     = (int)$node->getData(':id');
        $userId = $node->getData('userId');

        // prepare necessary objects
        list($hosting, $serverId, $cloudClient) = self::_getHostingCloudIdClients($id, $userId, false, [ServiceStates::Active]);

        // execute API request
        $templates = self::executeApiRequest($cloudClient, 'getTemplatesAvailable', [$serverId]);

        return [ApiResponse::prepSuccess('', $templates), Header::OK];
    }

    public static function getTemplate(Node $node)
    {
        $id         = (int)$node->getData(':id');
        $userId     = $node->getData('userId');
        $templateId = $node->getData(':templateId');

        // prepare necessary objects
        list($hosting, $serverId, $cloudClient) = self::_getHostingCloudIdClients($id, $userId, false, [ServiceStates::Active]);

        // execute API request
        $template = self::executeApiRequest($cloudClient, 'getTemplate', [$serverId, $templateId]);

        return [ApiResponse::prepSuccess('', $template), Header::OK];
    }

    public static function getIPv4(Node $node)
    {
        $id     = (int)$node->getData(':id');
        $userId = $node->getData('userId');

        // prepare necessary objects
        list($hosting, $serverId, $cloudClient) = self::_getHostingCloudIdClients($id, $userId, false, [ServiceStates::Active]);

        // execute API request
        $ipv4 = self::executeApiRequest($cloudClient, 'getIPv4', [$serverId]);

        return [ApiResponse::prepSuccess('', $ipv4), Header::OK];
    }

    public static function getIPv6(Node $node)
    {
        $id     = (int)$node->getData(':id');
        $userId = $node->getData('userId');

        // prepare necessary objects
        list($hosting, $serverId, $cloudClient) = self::_getHostingCloudIdClients($id, $userId, false, [ServiceStates::Active]);

        // execute API request
        $ipv6 = self::executeApiRequest($cloudClient, 'getIPv6', [$serverId]);

        return [ApiResponse::prepSuccess('', $ipv6), Header::OK];
    }

    public static function getVolumes(Node $node)
    {
        $id     = (int)$node->getData(':id');
        $userId = $node->getData('userId');

        // prepare necessary objects
        list($hosting, $serverId, $cloudClient) = self::_getHostingCloudIdClients($id, $userId, false, [ServiceStates::Active]);

        // execute API request
        $volumes = self::executeApiRequest($cloudClient, 'getVolumes', [$serverId]);

        // prepare output
        $print = [];
        foreach ($volumes as $volume)
        {
            $print[] = VolumeView::prepArray($volume, $hosting);
        }

        return [ApiResponse::prepSuccess('', $print), Header::OK];
    }

    public static function getVolume(Node $node)
    {
        $id       = (int)$node->getData(':id');
        $userId   = $node->getData('userId');
        $volumeId = $node->getData(':volumeId');

        // prepare necessary objects
        list($hosting, $serverId, $cloudClient) = self::_getHostingCloudIdClients($id, $userId, false, [ServiceStates::Active]);

        // execute API request
        $volume = self::executeApiRequest($cloudClient, 'getVolume', [$serverId, $volumeId]);

        return [ApiResponse::prepSuccess('', VolumeView::prepArray($volume, $hosting)), Header::OK];
    }

    public static function getBackups(Node $node)
    {
        $id     = (int)$node->getData(':id');
        $userId = $node->getData('userId');

        // prepare necessary objects
        list($hosting, $serverId, $cloudClient) = self::_getHostingCloudIdClients($id, $userId, false, [ServiceStates::Active]);

        // execute API request
        $backups = self::executeApiRequest($cloudClient, 'getBackups', [$serverId]);

        // prepare output
        $print = [];
        foreach ($backups as $backup)
        {
            $print[] = CloudView::prepBackupArray($backup, $hosting);
        }

        return [ApiResponse::prepSuccess('', $print), Header::OK];
    }

    public static function getBackup(Node $node)
    {
        $id       = (int)$node->getData(':id');
        $userId   = $node->getData('userId');
        $backupId = $node->getData(':backupId');

        // prepare necessary objects
        list($hosting, $serverId, $cloudClient) = self::_getHostingCloudIdClients($id, $userId, false, [ServiceStates::Active]);

        // execute API request
        $backup = self::executeApiRequest($cloudClient, 'getBackup', [$serverId, $backupId]);

        return [ApiResponse::prepSuccess('', CloudView::prepBackupArray($backup, $hosting)), Header::OK];
    }

    public static function createBackup(Node $node)
    {
        $id       = (int)$node->getData(':id');
        $userId   = $node->getData('userId');
        $volumeId = $node->getData(':volumeId');

        // prepare necessary objects
        list($hosting, $serverId, $cloudClient) = self::_getHostingCloudIdClients($id, $userId, false, [ServiceStates::Active]);

        // execute API request
        $job = self::executeApiRequest($cloudClient, 'createBackup', [$serverId, $volumeId, $node->Body()]);

        return [ApiResponse::prepSuccess('', JobView::prepArray($job, $hosting)), Header::OK];
    }

    public static function deleteBackup(Node $node)
    {
        $id       = (int)$node->getData(':id');
        $userId   = $node->getData('userId');
        $backupId = $node->getData(':backupId');

        // prepare necessary objects
        list($hosting, $serverId, $cloudClient) = self::_getHostingCloudIdClients($id, $userId, false, [ServiceStates::Active]);

        // execute API request
        $job = self::executeApiRequest($cloudClient, 'deleteBackup', [$serverId, $backupId]);

        return [ApiResponse::prepSuccess('', JobView::prepArray($job, $hosting)), Header::OK];
    }

    public static function getSchedules(Node $node)
    {
        $id       = (int)$node->getData(':id');
        $userId   = $node->getData('userId');
        $volumeId = $node->getData(':volumeId');

        // prepare necessary objects
        list($hosting, $serverId, $cloudClient) = self::_getHostingCloudIdClients($id, $userId, false, [ServiceStates::Active]);

        // execute API request
        $schedules = self::executeApiRequest($cloudClient, 'getSchedules', [$serverId, $volumeId]);

        return [ApiResponse::prepSuccess('', $schedules), Header::OK];
    }

    public static function createSchedule(Node $node)
    {
        $id       = (int)$node->getData(':id');
        $userId   = $node->getData('userId');
        $volumeId = $node->getData(':volumeId');

        // prepare necessary objects
        list($hosting, $serverId, $cloudClient) = self::_getHostingCloudIdClients($id, $userId, false, [ServiceStates::Active]);

        // execute API request
        $schedule = self::executeApiRequest($cloudClient, 'createSchedule', [$serverId, $volumeId, $node->Body()]);

        return [ApiResponse::prepSuccess('', $schedule), Header::OK];
    }

    public static function deleteSchedule(Node $node)
    {
        $id         = (int)$node->getData(':id');
        $userId     = $node->getData('userId');
        $volumeId   = $node->getData(':volumeId');
        $scheduleId = $node->getData(':scheduleId');

        // prepare necessary objects
        list($hosting, $serverId, $cloudClient) = self::_getHostingCloudIdClients($id, $userId, false, [ServiceStates::Active]);

        // execute API request
        self::executeApiRequest($cloudClient, 'deleteSchedule', [$serverId, $volumeId, $scheduleId]);

        return [null, Header::NoContent];
    }

    public static function getJobs(Node $node)
    {
        $id          = (int)$node->getData(':id');
        $userId      = $node->getData('userId');
        $isAdmin     = $node->getData('isAdmin');
        $queryParams = $node->QueryParams();

        // prepare necessary objects
        list($hosting, $apiUrl, $apiKey, $serverId) = self::getHostingApiUrlKeyRemoteId($id, $userId, 'h1pcloud', $isAdmin, [ServiceStates::Active]);

        // parse query parameters
        // override certain parameters
        $queryParams['serviceId'] = $serverId;
        $queryParams['module']    = 'cloudstack';
        $queryParams['instance']  = 'vm,snapshot,iso';

        // prpeare clients
        $jobsClient = new JobsClient(new Transport($apiUrl, $apiKey));

        // execute API request
        $jobs = self::executeApiRequest($jobsClient, 'get', [$queryParams]);

        // prepare output
        $print = [];
        foreach ($jobs as $job)
        {
            $print[] = JobView::prepArray($job, $hosting);
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
        list($hosting, $apiUrl, $apiKey, $serverId) = self::getHostingApiUrlKeyRemoteId($id, $userId, 'h1pcloud', $isAdmin);

        // prepare filtering parameters
        $params = ['serviceId' => $serverId, 'module' => 'cloudstack', 'instance' => 'vm,snapshot,iso'];

        // prpeare clients
        $jobsClient = new JobsClient(new Transport($apiUrl, $apiKey));

        // execute API request
        $job = self::executeApiRequest($jobsClient, 'getOne', [$jobId, $params]);

        // prepare output
        return [ApiResponse::prepSuccess('', JobView::prepArray($job, $hosting)), Header::OK];
    }

    public static function getStatistics(Node $node)
    {
        $id          = (int)$node->getData(':id');
        $userId      = $node->getData('userId');
        $queryParams = $node->QueryParams();

        // prepare necessary objects
        list($hosting, $serverId, $cloudClient) = self::_getHostingCloudIdClients($id, $userId, false, [ServiceStates::Active]);

        $type = ( isset($queryParams['type']) ) ? $queryParams['type']      : '';
        $from = ( isset($queryParams['from']) ) ? (int)$queryParams['from'] : -1;
        $to   = ( isset($queryParams['to']) )   ? (int)$queryParams['to']   : -1;
        $continuous = ( isset($queryParams['continuous']) ) ? (bool)$queryParams['continuous'] : false;

        $statistics = self::executeApiRequest($cloudClient, 'getStatistics', [$serverId, $type, $from, $to, $continuous]);

        return [ApiResponse::prepSuccess('', $statistics), Header::OK];
    }

    public static function getStatisticsSummary(Node $node)
    {
        $id          = (int)$node->getData(':id');
        $userId      = $node->getData('userId');
        $queryParams = $node->QueryParams();

        // prepare necessary objects
        list($hosting, $serverId, $cloudClient) = self::_getHostingCloudIdClients($id, $userId, false, [ServiceStates::Active]);

        $type = ( isset($queryParams['type']) ) ? $queryParams['type']      : '';
        $from = ( isset($queryParams['from']) ) ? (int)$queryParams['from'] : -1;
        $to   = ( isset($queryParams['to']) )   ? (int)$queryParams['to']   : -1;
        $continuous = ( isset($queryParams['continuous']) ) ? (bool)$queryParams['continuous'] : false;

        $statistics = self::executeApiRequest($cloudClient, 'getStatisticsSummary', [$serverId, $type, $from, $to, $continuous]);

        return [ApiResponse::prepSuccess('', $statistics), Header::OK];
    }

    // private functions
    private static function _getHostingCloudIdClients(int $id, int $userId, bool $isAdmin = false, array $states = [])
    {
        $data = self::getHostingApiUrlKeyRemoteId($id, $userId, 'h1pcloud', $isAdmin, $states);

        return [$data[0], $data[3], new CloudClient(new Transport($data[1], $data[2]))];
    }

    private static function _validateAdditionalDisk(array $disk)
    {
        if (!isset($disk['value']))
            throw new BadRequest( \sprintf(Errors::NotProvidedParam, 'additional disk value') );

        if (!\is_int($disk['value']) || ($disk['value'] % 10) != 0)
            throw new BadRequest( \sprintf(Errors::InvalidParameter, 'additional disk value', 'integer divisible by 10', $disk['value']) );

        if (!isset($disk['key']))
            throw new BadRequest( \sprintf(Errors::NotProvidedParam, 'additional disk key') );

        if (!\is_int($disk['key']) || $disk['key'] < 1 || $disk['key'] > 8)
            throw new BadRequest( \sprintf(Errors::InvalidParameter, 'additional disk key', '1..8', $disk['key']) );
     }

     private static function _getConfigOptNameStepUnit($name)
     {
         switch ($name)
         {
            case 'CPU':
                return ['cpu', 1, 'core'];
            case 'RAM':
                return ['ram', 512, 'MB'];
            case 'HDD':
                return ['hdd', 10, 'GB'];
            case 'Bandwidth':
                return ['bandwidth', 1, 'TB'];
            case 'IP':
                return ['ip', 1, 'IP'];
            case 'Backups':
                return ['backups', 1, 'backup'];
            case \strpos($name, 'Additional Disk') !== false:
                return ['additionalDisks', 10, 'GB'];
            default:
                return false;
         }
     }

    private static function _calcIsoUsage(CloudClient $cloudClient, int $serverId, int $userId)
    {
        $isos  = self::executeApiRequest($cloudClient, 'getIsos', [$serverId, [['key' => 'userId', 'value' => (string)$userId]]]);
        $usage = 0;
        foreach ($isos as $iso)
        {
            $usage += $iso['size'];
        }
        return $usage;
    }

    private static function _getUserIsoUploadLimti(int $userId)
    {
        $isoLimit = Capsule::table('tblcustomfieldsvalues')
                ->select('tblcustomfieldsvalues.value')
                ->join('tblcustomfields', 'tblcustomfields.id', '=', 'tblcustomfieldsvalues.fieldid')
                ->where([
                    ['tblcustomfields.type', 'client'],
                    ['tblcustomfields.fieldname', 'Cloud ISO Upload Limit'],
                    ['tblcustomfieldsvalues.relid', $userId]
                ])->get();

        if (\count($isoLimit) != 1)
            throw new NotImplemented( \sprintf(Errors::NotImplemented, 'Cloud ISO Upload Limit', 'client settings not found') );

        // parse iso limit
        $limit = (\is_numeric($isoLimit[0]->value)) ? (int)$isoLimit[0]->value : self::_getDefaultIsoUploadLimit();

        // convert gigabytes to bytes and return
        return $limit * 1024 * 1024 * 1024;
    }

    private static function _getUrlSize($url)
    {
        $ch = \curl_init($url);

        \curl_setopt($ch, \CURLOPT_NOBODY, true);
        \curl_setopt($ch, \CURLOPT_HEADER, true);
        \curl_setopt($ch, \CURLOPT_RETURNTRANSFER, true);
        \curl_setopt($ch, \CURLOPT_FOLLOWLOCATION, true);
        \curl_exec($ch);

        if (\curl_getinfo($ch, \CURLINFO_RESPONSE_CODE) !== 200)
        {
            \curl_close($ch);
            throw new BadRequest( \sprintf(Errors::InvalidParameter, 'url', 'link to a valid resource to be downloaded', $url) );
        }

        $downloadLength = \curl_getinfo($ch, \CURLINFO_CONTENT_LENGTH_DOWNLOAD);
        \curl_close($ch);
        return $downloadLength;
    }

    private static function _getDefaultIsoUploadLimit()
    {
        try
        {
            $addonSettings = Capsule::table('tbladdonmodules')->where('module', 'h1papi')->where('setting', 'option3')->pluck('value');
            if (\count($addonSettings) != 1)
                throw new NotImplemented( \sprintf(Errors::NotImplemented, 'Cloud ISO Upload Limit', 'module not configured') );

            return (\is_numeric($addonSettings[0])) ? (int)$addonSettings[0] : 0;
        }
        catch (QueryException $ex)
        {
            throw new InternalServerError('', 0, $ex);
        }
    }
}