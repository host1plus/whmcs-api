<?php

// workaround for WHMCS not allowing REQUEST_METHOD that is not GET or POST
$originalRM = $_SERVER['REQUEST_METHOD'];
$_SERVER['REQUEST_METHOD'] = 'GET';

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/vendor/autoload.php';

$_SERVER['REQUEST_METHOD'] = $originalRM;
unset($originalRM);

// router
use \REST\Router;

// views
use \Views\ApiResponse;

// utilities
use \REST\Utilities\Url;
use \Utilities\Header;

// enums
use \REST\Enums\AccessLevels as ACL;

// exceptions
use \REST\Exceptions\{BadRequest, Unauthorized, NotFound, NotImplemented, Conflict};

try
{
    $router = new Router();

    $aclUser      = [ACL::User];
    $aclAdmin     = [ACL::Admin];
    $aclUserAdmin = [ACL::User, ACL::Admin];
    $aclPublic    = [ACL::Pub, ACL::User, ACL::Admin];

    // cloud servers
    // GET
    $router->GET('/cloudservers/:id', [\Controllers\CloudServers::class, 'getOne'], $aclUserAdmin);
    $router->GET('/cloudservers/:id/isos', [\Controllers\CloudServers::class, 'getIsos'], $aclUser);
    $router->GET('/cloudservers/:id/isos/:isoId', [\Controllers\CloudServers::class, 'getIso'], $aclUser);
    $router->GET('/cloudservers/:id/limits', [\Controllers\CloudServers::class, 'getLimits'], $aclUser);
    $router->GET('/cloudservers/:id/osTypes', [\Controllers\CloudServers::class, 'getOsTypes'], $aclUser);
    $router->GET('/cloudservers/:id/osTypes/:osTypeId', [\Controllers\CloudServers::class, 'getOsType'], $aclUser);
    $router->GET('/cloudservers/:id/osCategories', [\Controllers\CloudServers::class, 'getOsCategories'], $aclUser);
    $router->GET('/cloudservers/:id/osCategories/:osCategoryId', [\Controllers\CloudServers::class, 'getOsCategory'], $aclUser);
    $router->GET('/cloudservers/:id/templates', [\Controllers\CloudServers::class, 'getTemplatesAvailable'], $aclUserAdmin);
    $router->GET('/cloudservers/:id/templates/:templateId', [\Controllers\CloudServers::class, 'getTemplate'], $aclUserAdmin);
    $router->GET('/cloudservers/:id/subnets/v4', [\Controllers\CloudServers::class, 'getIPv4'], $aclUser);
    $router->GET('/cloudservers/:id/subnets/v6', [\Controllers\CloudServers::class, 'getIPv6'], $aclUser);
    $router->GET('/cloudservers/:id/volumes', [\Controllers\CloudServers::class, 'getVolumes'], $aclUser);
    $router->GET('/cloudservers/:id/volumes/:volumeId', [\Controllers\CloudServers::class, 'getVolume'], $aclUser);
    $router->GET('/cloudservers/:id/backups', [\Controllers\CloudServers::class, 'getBackups'], $aclUser);
    $router->GET('/cloudservers/:id/backups/:backupId', [\Controllers\CloudServers::class, 'getBackup'], $aclUser);
    $router->GET('/cloudservers/:id/volumes/:volumeId/backupSchedules', [\Controllers\CloudServers::class, 'getSchedules'], $aclUser);
    $router->GET('/cloudservers/:id/jobs', [\Controllers\CloudServers::class, 'getJobs'], $aclUserAdmin);
    $router->GET('/cloudservers/:id/jobs/:jobId', [\Controllers\CloudServers::class, 'getJob'], $aclUserAdmin);
    $router->GET('/cloudservers/:id/statistics', [\Controllers\CloudServers::class, 'getStatistics'], $aclUser);
    $router->GET('/cloudservers/:id/statistics/summary', [\Controllers\CloudServers::class, 'getStatisticsSummary'], $aclUser);
    $router->GET('/cloudservers/options', [\Controllers\CloudServers::class, 'getOptions'], $aclPublic);

    // POST
    $router->POST('/cloudservers/:id/start', [\Controllers\CloudServers::class, 'start'], $aclUserAdmin);
    $router->POST('/cloudservers/:id/stop', [\Controllers\CloudServers::class, 'stop'], $aclUserAdmin);
    $router->POST('/cloudservers/:id/reboot', [\Controllers\CloudServers::class, 'reboot'], $aclUserAdmin);
    $router->POST('/cloudservers/:id/reinstall', [\Controllers\CloudServers::class, 'reinstall'], $aclUserAdmin);
    $router->POST('/cloudservers/:id/resetRootPass', [\Controllers\CloudServers::class, 'resetRootPass'], $aclUserAdmin);
    $router->POST('/cloudservers/:id/rescue', [\Controllers\CloudServers::class, 'rescue'], $aclUser);
    $router->POST('/cloudservers/:id/unrescue', [\Controllers\CloudServers::class, 'unrescue'], $aclUser);
    $router->POST('/cloudservers/:id/vnc', [\Controllers\CloudServers::class, 'createVnc'], $aclUser);
    $router->POST('/cloudservers/:id/isos', [\Controllers\CloudServers::class, 'uploadIso'], $aclUser);
    $router->POST('/cloudservers/:id/attachIso', [\Controllers\CloudServers::class, 'attachIso'], $aclUser);
    $router->POST('/cloudservers/:id/detachIso', [\Controllers\CloudServers::class, 'detachIso'], $aclUser);
    $router->POST('/cloudservers/:id/installIso', [\Controllers\CloudServers::class, 'installIso'], $aclUser);
    $router->POST('/cloudservers/:id/volumes/:volumeId/backup', [\Controllers\CloudServers::class, 'createBackup'], $aclUser);
    $router->POST('/cloudservers/:id/restore', [\Controllers\CloudServers::class, 'restore'], $aclUser);
    $router->POST('/cloudservers/:id/volumes/:volumeId/backupSchedules', [\Controllers\CloudServers::class, 'createSchedule'], $aclUser);
    $router->POST('/cloudservers/:id/calcUpdatePricing', [\Controllers\CloudServers::class, 'calcUpdatePricing'], $aclUser);

    // PATCH
    $router->PATCH('/cloudservers/:id/names', [\Controllers\CloudServers::class, 'changeNames'], $aclUserAdmin);
    $router->PATCH('/cloudservers/:id', [\Controllers\CloudServers::class, 'update'], $aclUserAdmin);

    // DELETE
    $router->DELETE('/cloudservers/:id/isos/:isoId', [\Controllers\CloudServers::class, 'deleteIso'], $aclUser);
    $router->DELETE('/cloudservers/:id/backups/:backupId', [\Controllers\CloudServers::class, 'deleteBackup'], $aclUser);
    $router->DELETE('/cloudservers/:id/volumes/:volumeId/backupSchedules/:scheduleId', [\Controllers\CloudServers::class, 'deleteSchedule'], $aclUser);


    // vps
    // GET
    $router->GET('/vps/:id', [\Controllers\Vps::class, 'getOne'], $aclUserAdmin);
    $router->GET('/vps/:id/templates', [\Controllers\Vps::class, 'getTemplates'], $aclUserAdmin);
    $router->GET('/vps/:id/vnc', [\Controllers\Vps::class, 'getVnc'], $aclUser);
    $router->GET('/vps/:id/backups', [\Controllers\Vps::class, 'getBackups'], $aclUser);
    $router->GET('/vps/:id/backups/:backupId', [\Controllers\Vps::class, 'getBackup'], $aclUser);
    $router->GET('/vps/:id/ipv4', [\Controllers\Vps::class, 'getIPv4'], $aclUser);
    $router->GET('/vps/:id/ipv6', [\Controllers\Vps::class, 'getIPv6'], $aclUser);
    $router->GET('/vps/:id/statistics', [\Controllers\Vps::class, 'getStatistics'], $aclUser);
    $router->GET('/vps/:id/schedules', [\Controllers\Vps::class, 'getSchedules'], $aclUser);
    $router->GET('/vps/:id/schedules/:scheduleId', [\Controllers\Vps::class, 'getSchedule'], $aclUser);
    $router->GET('/vps/:id/jobs', [\Controllers\Vps::class, 'getJobs'], $aclUserAdmin);
    $router->GET('/vps/:id/jobs/:jobId', [\Controllers\Vps::class, 'getJob'], $aclUserAdmin);
    $router->GET('/vps/options', [\Controllers\Vps::class, 'getOptions'], $aclPublic);

    // POST
    $router->POST('/vps/:id/start', [\Controllers\Vps::class, 'start'], $aclUserAdmin);
    $router->POST('/vps/:id/stop', [\Controllers\Vps::class, 'stop'], $aclUserAdmin);
    $router->POST('/vps/:id/restart', [\Controllers\Vps::class, 'restart'], $aclUserAdmin);
    $router->POST('/vps/:id/reinstall', [\Controllers\Vps::class, 'reinstall'], $aclUserAdmin);
    $router->POST('/vps/:id/resetPassword', [\Controllers\Vps::class, 'resetPassword'], $aclUserAdmin);
    $router->POST('/vps/:id/vnc/start', [\Controllers\Vps::class, 'startVnc'], $aclUser);
    $router->POST('/vps/:id/vnc/stop', [\Controllers\Vps::class, 'stopVnc'], $aclUser);
    $router->POST('/vps/:id/backups', [\Controllers\Vps::class, 'createBackup'], $aclUser);
    $router->POST('/vps/:id/backups/:backupId/restore', [\Controllers\Vps::class, 'restoreBackup'], $aclUser);
    $router->POST('/vps/:id/ipv6', [\Controllers\Vps::class, 'addIPv6'], $aclUser);
    $router->POST('/vps/:id/changePrimaryIp', [\Controllers\Vps::class, 'changePrimaryIp'], $aclUser);
    $router->POST('/vps/:id/schedules', [\Controllers\Vps::class, 'createSchedule'], $aclUser);
    $router->POST('/vps/:id/calcUpdatePricing', [\Controllers\Vps::class, 'calcUpdatePricing'], $aclUser);

    // PATCH
    $router->PATCH('/vps/:id/hostname', [\Controllers\Vps::class, 'changeHostname'], $aclUserAdmin);
    $router->PATCH('/vps/:id/schedules/:scheduleId', [\Controllers\Vps::class, 'updateSchedule'], $aclUser);
    $router->PATCH('/vps/:id', [\Controllers\Vps::class, 'update'], $aclUserAdmin);

    // DELETE
    $router->DELETE('/vps/:id/backups/:backupId', [\Controllers\Vps::class, 'deleteBackup'], $aclUser);
    $router->DELETE('/vps/:id/ipv6', [\Controllers\Vps::class, 'deleteIPv6'], $aclUser);
    $router->DELETE('/vps/:id/schedules/:scheduleId', [\Controllers\Vps::class, 'deleteSchedule'], $aclUser);

    // prepare request node
    $node = $router->findNodeByUrl(Url::parseFromServer());

    // validate request access level
    if (isset($_SESSION['uid']))
    {
        $requestACL = ACL::User;
        $node->addData('userId', $_SESSION['uid']);
        $node->addData('isAdmin', false);
    }
    elseif (isset($_SESSION['adminid']))
    {
        $requestACL = ACL::Admin;
        $node->addData('userId', $_SESSION['adminid']);
        $node->addData('isAdmin', true);
    }
    else
        $requestACL = ACL::Pub;

    if (!$node->isValidAccessLevel($requestACL))
        throw new Unauthorized;

    // setup input body
    $node->parseBodyFromInputJson();

    // execute handler
    $response = $node->executeHandler();
    $respData = \GuzzleHttp\json_encode($response['data']);
    Header::setByCode($response['headerCode']);
    echo $respData;
}
catch (BadRequest $ex)
{
    Header::setBadRequest();
    echo \GuzzleHttp\json_encode(ApiResponse::prepFailure($ex->getMessage()));
}
catch (Unauthorized $ex)
{
    Header::setUnauthorized();
    echo \GuzzleHttp\json_encode(ApiResponse::prepFailure($ex->getMessage()));
}
catch (NotFound $ex)
{
    Header::setNotFound();
    echo \GuzzleHttp\json_encode(ApiResponse::prepFailure($ex->getMessage()));
}
catch (Conflict $ex)
{
    Header::setConflict();
    echo \GuzzleHttp\json_encode(ApiResponse::prepFailure($ex->getMessage()));
}
catch (NotImplemented $ex)
{
    Header::setNotImplemented();
    echo \GuzzleHttp\json_encode(ApiResponse::prepFailure($ex->getMessage()));
}
catch (Exception $ex)
{
    error_log($ex);

    Header::setInternalServerError();
    echo \GuzzleHttp\json_encode(ApiResponse::prepFailure($ex->getMessage()));
}