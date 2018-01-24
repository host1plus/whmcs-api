<?php

// clients
use \WHMCS\Database\Capsule;

use \Host1Plus\Clients\{Transport, CloudServers as CloudClient, Vps as VpsClient};

// utilities
use \Host1Plus\Utilities\Cache;

// enums
use \Host1Plus\Enums\Errors;

function h1papi_orderAddOsTemplateList(array $params)
{
    if ($params['filename'] !== 'ordersadd')
        return;

    try
    {
        $cloudProducts = Capsule::table('tblproducts')->where('servertype', 'h1pcloud')->select('id', 'configoption1 as remoteProductId')->get();
        $vpsProducts   = Capsule::table('tblproducts')->where('servertype', 'h1pvps')->pluck('id');
    }
    catch (Exception $ex)
    {
        logActivity(sprintf('hook_h1papi_orderAddOsTemplateList: unable to load H1P cloud and/or vps products, error: %s, message: %s', get_class($ex), $ex->getMessage()));
        return;
    }

    if (count($cloudProducts) == 0)
        return;

    // add Host1Plus library autoloader
    require_once __DIR__ . '/vendor/autoload.php';

    // attempt to load H1P API Addon Module
    list($apiUrl, $apiKey, $error) = h1papi_getApiUrlKey();
    if ($error != '')
    {
        logActivity("hook_h1papi_orderAddOsTemplateList: {$error}");
        return;
    }

    // prep variables
    $transport = new Transport($apiUrl, $apiKey);
    $cacheDir  = __DIR__ . '/cache';
    $past      = time() - 24 * 60 * 60;

    // check and validate cache directory
    try
    {
        Cache::InitDir($cacheDir);
    }
    catch (Exception $ex)
    {
        logActivity('hook_h1papi_orderAddOsTemplateList: ' . sprintf(Errors::CacheInit, $ex->getMessage()));
        return;
    }

    // try to load current Cloud Server OS Template cache available in the directory
    $cloudOsTemplateCache = [];
    try
    {
        $cloudCacheFile       = "{$cacheDir}/cloudOsTemplates.json";
        $cloudOsTemplateCache = Cache::GetContents($cloudCacheFile);
    }
    catch (InvalidArgumentException $ex)
    {
        logActivity('hook_h1papi_orderAddOsTemplateList: ' . sprintf(Errors::CacheAction, 'get', 'OS Template', $ex->getMessage()));
        if ($ex->getCode() != 3)
            return;
    }
    catch (Exception $ex)
    {
        logActivity('hook_h1papi_orderAddOsTemplateList: ' . sprintf(Errors::CacheAction, 'get', 'OS Template', $ex->getMessage()));
        return;
    }

    // if Cloud Os Template Cache data structure is invalid or was updated more than 24 hours ago attempt to load it from remote H1P API
    // save the newly generated cache into $cloudCacheFile
    if (!isset($cloudOsTemplateCache['date']) || !isset($cloudOsTemplateCache['items']) || count($cloudOsTemplateCache['items']) == 0 || $cloudOsTemplateCache['date'] <= $past)
    {
        $cloudClient = new CloudClient($transport);
        $cloudOsTemplateCache['items'] = [];

        try
        {
            foreach ($cloudProducts as $product)
            {
                $cloudOsTemplateCache['items'][$product->id] = $cloudClient->getTemplates((int)$product->remoteProductId);
            }
        }
        catch (Exception $ex)
        {
            logActivity('hook_h1papi_orderAddOsTemplateList: ' . sprintf(Errors::FindItemExt, 'os templates', get_class($ex), $ex->getMessage()));
            return;
        }

        try
        {
            Cache::PutContents($cloudCacheFile, $cloudOsTemplateCache);
        }
        catch (Exception $ex)
        {
            logActivity('hook_h1papi_orderAddOsTemplateList: ' . sprintf(Errors::CacheAction, 'save', 'OS Template', $ex->getMessage()));
        }
    }

    // try to load current VPS OS Template cache available in the directory
    $vpsOsTemplateCache = [];
    try
    {
        $vpsCacheFile       = "{$cacheDir}/vpsOsTemplates.json";
        $vpsOsTemplateCache = Cache::GetContents($vpsCacheFile);
    }
    catch (InvalidArgumentException $ex)
    {
        logActivity('hook_h1papi_orderAddOsTemplateList: ' . sprintf(Errors::CacheAction, 'get', 'OS Template', $ex->getMessage()));
        if ($ex->getCode() != 3)
            return;
    }
    catch (Exception $ex)
    {
        logActivity('hook_h1papi_orderAddOsTemplateList: ' . sprintf(Errors::CacheAction, 'get', 'OS Template', $ex->getMessage()));
        return;
    }

    // if VPS Os Template Cache data structure is invalid or was updated more than 24 hours ago attempt to load it from remote H1P API
    // save the newly generated cache into $vpsCacheFile
    if (!isset($vpsOsTemplateCache['date']) || !isset($vpsOsTemplateCache['items']) || count($vpsOsTemplateCache['items']) == 0 || $vpsOsTemplateCache['date'] <= $past)
    {
        // prepare Transport and Vps communication clients
        $vpsClient = new VpsClient($transport);

        try
        {
            $vpsOsTemplateCache['items'] = $vpsClient->getOsTemplates();
        }
        catch (Exception $ex)
        {
            logActivity('hook_h1papi_orderAddOsTemplateList: ' . sprintf(Errors::FindItemExt, 'os templates', get_class($ex), $ex->getMessage()));
            return;
        }

        try
        {
            Cache::PutContents($vpsCacheFile, $vpsOsTemplateCache);
        }
        catch (Exception $ex)
        {
            logActivity('hook_h1papi_orderAddOsTemplateList: ' . sprintf(Errors::CacheAction, 'save', 'OS Template', $ex->getMessage()));
        }
    }

    // prepare json arrays to be sent to javascript
    $cloudOsJson = json_encode($cloudOsTemplateCache['items']);
    if (json_last_error() !== JSON_ERROR_NONE)
    {
        logActivity('hook_h1papi_orderAddOsTemplateList: ' . sprintf(Errors::EncodeJson, 'OS Template list', json_last_error_msg()));
        return;
    }

    $vpsOsJson = json_encode($vpsOsTemplateCache['items']);
    if (json_last_error() !== JSON_ERROR_NONE)
    {
        logActivity('hook_h1papi_orderAddOsTemplateList: ' . sprintf(Errors::EncodeJson, 'OS Template list', json_last_error_msg()));
        return;
    }

    $cloudProductIds = [];
    foreach($cloudProducts as $product)
    {
        $cloudProductIds[] = $product->id;
    }

    $cloudProductJson = json_encode($cloudProductIds);

    if (json_last_error() !== JSON_ERROR_NONE)
    {
        logActivity('hook_h1papi_orderAddOsTemplateList: ' . sprintf(Errors::EncodeJson, 'OS Template list', json_last_error_msg()));
        return;
    }

    $vpsProductJson = json_encode($vpsProducts);
    if (json_last_error() !== JSON_ERROR_NONE)
    {
        logActivity('hook_h1papi_orderAddOsTemplateList: ' . sprintf(Errors::EncodeJson, 'OS Template list', json_last_error_msg()));
        return;
    }

    return <<<HTML
        <script>

            var cloudProductJson = JSON.parse('$cloudProductJson');
            var vpsProductJson = JSON.parse('$vpsProductJson');
        
            var cloudTemplates = JSON.parse('$cloudOsJson');
            var vzTemplates = JSON.parse('$vpsOsJson');

            var osTemplateFieldName = 'osTemplate';
            
            /**
            *  Function will replace OS Template ID text field with select box
            *     
            * @param field OS Template ID text field
            */
            var osFieldReplacer = function (field) {
                var name = field.attr('name'),
                    id = field.attr('id'),
                    product = field.closest('div.product'),
                    select = product.find('select[name="pid[]"]'),
                    pidSelected = parseInt( select.val() ),
                    label = field.closest('tr').find('.fieldlabel');
                
                // if VZ
                if (label.length && label.text() === osTemplateFieldName && 
                    vpsProductJson.indexOf( pidSelected ) !== -1) {
                    var templates = vzTemplates,
                        s = $("<select class='form-control' name='"+name+"' id='"+id+"' />");
                    
                    if (templates.length) {
                        for(var key in templates) {
                            $("<option />", {value: templates[key].id, text: templates[key].name}).appendTo(s);
                        }
                        
                        field.replaceWith(s);
                    }
                }
                
                // if Cloud
                if (label.length && label.text() === osTemplateFieldName && 
                    cloudProductJson.indexOf( pidSelected ) !== -1) {
                    var templates = cloudTemplates[pidSelected],
                        s = $("<select class='form-control' name='"+name+"' id='"+id+"' />");
                    
                    if (templates.length) {
                        for(var key in templates) {
                            $("<option />", {value: templates[key].id, text: templates[key].name}).appendTo(s);
                        }
                        
                        field.replaceWith(s);
                    }
                }
            };

            $(document).ajaxSuccess(
                function(event, xhr, settings){ 
                    if( settings.data.indexOf('action=getconfigoptions') !== -1 ){
                        var selectField = $('select[name="pid[]"]');
                        var pidSelected = parseInt(selectField.val());

                        if (vpsProductJson.indexOf( pidSelected ) !== -1 || cloudProductJson.indexOf( pidSelected ) !== -1) {
                            var osTemplateLabel = selectField
                                    .closest('div.product')
                                    .find('td:contains("'+osTemplateFieldName+'")'),
                                field = osTemplateLabel
                                    .closest('tr')
                                    .find('input[type="text"][name^="customfield"]');       
                                    osFieldReplacer(field);
                        }
                    }
                }
            );

        </script>
HTML;
}

function h1papi_getApiUrlKey()
{
    try
    {
        $addonSettings = Capsule::table('tbladdonmodules')->where('module', 'h1papi')->whereIn('setting', ['option1', 'option2'])->pluck('value', 'setting');
        if (count($addonSettings) != 2)
            return ['', '', 'Host1Plus API Addon Module is not configured: failed to retrieve API URL and Key parameters'];

        return [$addonSettings['option1'], $addonSettings['option2'], ''];
    }
    catch (Exception $ex)
    {
        return ['', '', sprintf('failed to retrieve Host1Plus API Addon Modules settings, error: %s, message: %s', get_class($ex), $ex->getMessage())];
    }
}

add_hook('AdminAreaHeaderOutput', 0, 'h1papi_orderAddOsTemplateList');